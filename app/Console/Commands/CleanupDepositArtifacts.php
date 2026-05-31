<?php

namespace App\Console\Commands;

use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantSale;
use App\Services\Tenant\AppointmentPaymentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-PATCH-178A — Clean up the two artifacts the old deposit-as-sale flow
 * produced:
 *
 *   1. ORPHAN deposit-sales: rows that are unpaid, have ZERO ledger payments,
 *      and are NOT meaningfully linked (appointment_id NULL) OR duplicate a
 *      real paid sale on the same appointment. No real money — safe to void.
 *
 *   2. STALE appointment caches: paid_cents / payment_status that disagree with
 *      the appointment's through-ledger sum (because a payment landed on a
 *      different sale than the cache was tracking). Recompute from the ledger.
 *
 * Read-only by default. --dry-run shows the plan; without it you confirm.
 * Voiding sets status='cancelled' + payment_status retained — we do NOT hard
 * delete (per ops rules: no permanent deletes).
 *
 *   php artisan intake:cleanup-deposit-artifacts --dry-run
 *   php artisan intake:cleanup-deposit-artifacts
 */
class CleanupDepositArtifacts extends Command
{
    protected $signature = 'intake:cleanup-deposit-artifacts {--dry-run} {--force}';
    protected $description = 'Void orphan deposit-sales and recompute stale appointment payment caches.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        // --- 1. Orphan deposit-sales ---
        $orphans = TenantSale::query()
            ->whereNull('refund_of_sale_id')
            ->whereIn('payment_status', ['unpaid', 'draft'])
            ->whereDoesntHave('payments')
            ->where(function ($q) {
                $q->whereNull('appointment_id')
                  ->orWhereNotNull('appointment_id');
            })
            ->where(function ($q) {
                $q->where('notes', 'like', '%Deposit%')
                  ->orWhere('sale_number', 'like', 'DP-%');
            })
            ->whereNotIn('status', ['cancelled', 'closed'])
            ->get()
            ->filter(function ($s) {
                // Keep it ONLY if it's truly redundant: no ledger AND
                // (no appointment, or the appointment has another sale that
                // DOES carry money).
                if (!$s->appointment_id) return true;
                $siblingPaid = TenantSale::where('appointment_id', $s->appointment_id)
                    ->where('id', '!=', $s->id)
                    ->whereHas('payments')
                    ->exists();
                return $siblingPaid;
            });

        $this->info('Orphan deposit-sales to void: ' . $orphans->count());
        foreach ($orphans as $s) {
            $this->line("  {$s->sale_number}  status={$s->payment_status}  appt=" . ($s->appointment_id ?? '-') . "  total={$s->total_cents}");
        }

        // --- 2. Stale appointment caches ---
        $stale = collect();
        TenantAppointment::chunk(200, function ($rows) use (&$stale) {
            foreach ($rows as $a) {
                $ledger = (int) $a->payments()->sum('tenant_sale_payments.amount_cents');
                if ($ledger !== (int) $a->paid_cents) {
                    $stale->push([$a, $ledger]);
                }
            }
        });

        $this->info('Appointments with stale paid_cents cache: ' . $stale->count());
        foreach ($stale as [$a, $ledger]) {
            $this->line("  {$a->ra_number}  cached={$a->paid_cents} -> ledger={$ledger}  total={$a->total_cents}");
        }

        if ($dry) {
            $this->comment('--dry-run: no changes written.');
            return 0;
        }
        if (!$orphans->count() && !$stale->count()) {
            $this->info('Nothing to clean up.');
            return 0;
        }
        if (!$this->option('force') && !$this->confirm('Apply these changes?')) {
            $this->line('Aborted.');
            return 0;
        }

        DB::transaction(function () use ($orphans, $stale) {
            foreach ($orphans as $s) {
                $s->status = 'cancelled';
                $s->notes = trim(($s->notes ? $s->notes . ' ' : '') . '[patch-178a: voided orphan deposit-sale]');
                $s->save();
            }
            foreach ($stale as [$a, $ledger]) {
                $a->paid_cents = $ledger;
                $total = (int) $a->total_cents;
                if ($ledger <= 0) {
                    $a->payment_status = 'unpaid';
                } elseif ($ledger < $total) {
                    $a->payment_status = 'partial';
                } elseif ($ledger >= $total && $total > 0) {
                    $a->payment_status = ($ledger > $total) ? 'overage' : 'paid';
                }
                $a->save();
            }
        });

        $this->info('Done. Voided ' . $orphans->count() . ' orphan sale(s), recomputed ' . $stale->count() . ' appointment cache(s).');
        return 0;
    }
}
