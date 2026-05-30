<?php

namespace App\Console\Commands;

use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantSale;
use App\Models\Tenant\TenantSalePayment;
use App\Services\Tenant\SalePaymentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-PATCH-176B — Backfill ledger rows for paid sales that never got one.
 *
 * Cause: sales rung up while php-fpm was serving stale (pre-patch-176) opcache
 * never ran the create-hook, so they have payment_status=paid but zero rows in
 * tenant_sale_payments. This sweeps them up using the SAME logic the live hook
 * uses, so the result is identical to what would have been written.
 *
 * Scoped tight: only paid, non-refund sales with ZERO existing ledger rows.
 * Idempotent — re-running skips any sale that now has a row. --dry-run first.
 *
 *   php artisan intake:backfill-missing-sale-payments --dry-run
 *   php artisan intake:backfill-missing-sale-payments
 */
class BackfillMissingSalePayments extends Command
{
    protected $signature = 'intake:backfill-missing-sale-payments {--dry-run} {--force}';
    protected $description = 'Write ledger rows for paid sales missing them (stale-opcache gap).';

    public function handle(SalePaymentService $payments): int
    {
        $dry = (bool) $this->option('dry-run');

        $missing = TenantSale::where('payment_status', 'paid')
            ->whereNull('refund_of_sale_id')
            ->get()
            ->filter(fn ($s) => $s->payments()->count() === 0);

        if ($missing->isEmpty()) {
            $this->info('No paid sales are missing ledger rows. Nothing to do.');
            return 0;
        }

        $this->info($missing->count() . ' sale(s) missing a ledger row:');
        foreach ($missing as $s) {
            $kind = $s->appointment_id ? 'deposit/balance' : 'payment';
            $this->line("  {$s->sale_number}  total={$s->total_cents}  appt=" . ($s->appointment_id ?? '-') . "  -> {$kind}");
        }

        if ($dry) {
            $this->comment('--dry-run: no rows written.');
            return 0;
        }
        if (! $this->option('force') && ! $this->confirm('Write ledger rows for these sales?')) {
            $this->line('Aborted.');
            return 0;
        }

        $appointmentsToRefresh = [];

        foreach ($missing as $s) {
            DB::transaction(function () use ($s, $payments, &$appointmentsToRefresh) {
                // Kind matches the live create-hook: first row on an appointment
                // sale = deposit, later = balance; walk-in = payment.
                if ($s->appointment_id) {
                    $existingOnAppt = TenantSalePayment::whereIn(
                        'sale_id',
                        TenantSale::where('appointment_id', $s->appointment_id)->pluck('id')
                    )->count();
                    $kind = $existingOnAppt === 0
                        ? TenantSalePayment::KIND_DEPOSIT
                        : TenantSalePayment::KIND_BALANCE;
                } else {
                    $kind = TenantSalePayment::KIND_PAYMENT;
                }

                $payments->record(
                    sale:              $s,
                    amountCents:       (int) $s->total_cents,
                    kind:              $kind,
                    source:            TenantSalePayment::SOURCE_REGISTER,
                    method:            $s->payment_method ?? 'other',
                    externalReference: $s->payment_reference,
                    notes:             "patch-176b backfill (stale-opcache gap) for sale {$s->sale_number}",
                    recordedAt:        $s->paid_at ?? $s->created_at,
                );

                if ($s->appointment_id) {
                    $appointmentsToRefresh[$s->appointment_id] = true;
                }
            });
            $this->line("  wrote row for {$s->sale_number}");
        }

        foreach (array_keys($appointmentsToRefresh) as $apptId) {
            $appt = TenantAppointment::find($apptId);
            if ($appt) {
                $appt->paid_cents = (int) $appt->payments()->sum('tenant_sale_payments.amount_cents');
                $appt->save();
                $this->line("  refreshed appointment {$appt->ra_number} paid_cents={$appt->paid_cents}");
            }
        }

        $this->info('Done.');
        return 0;
    }
}
