<?php

namespace App\Console\Commands;

use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantAppointmentPayment;
use App\Models\Tenant\TenantSale;
use App\Services\Tenant\AppointmentPaymentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * MARKER-PATCH-174 — One-shot correction for appointments whose ledger shows a
 * false overage caused by the deposit-double-count bug.
 *
 * Root cause (fixed forward in AppointmentRegisterBridgeService): the
 * auto-created "balance" sale itemized the FULL job and recorded the FULL
 * total into the appointment ledger, instead of the outstanding balance. So
 * an appointment with a $450 deposit + a $535.97 balance sale summed to
 * $985.97 against a $535.97 job — overage = exactly the deposit.
 *
 * This command repairs ONE appointment (by ra_number) to look exactly like a
 * record the fixed bridge would have produced:
 *   - Reduce the inflated balance ledger row to the true balance.
 *   - On that row's sale, append a non-taxable "Deposit applied" credit line
 *     and reduce its subtotal/total by the same amount (so the receipt and
 *     revenue reports are honest too — not just the appointment banner).
 *   - Recompute the appointment's paid_cents + payment_status from the ledger.
 *
 * SAFETY: it refuses to act unless reducing the single inflated register_sale
 * row makes the ledger net to EXACTLY total_cents. That signature is unique to
 * the deposit-double-count bug, so a genuine customer overpayment (which won't
 * reconcile to total) is left untouched. Always run --dry-run first.
 *
 * Usage:
 *   php artisan intake:fix-deposit-overage ITO-052726-4AK29 --dry-run
 *   php artisan intake:fix-deposit-overage ITO-052726-4AK29
 */
class FixAppointmentDepositOverage extends Command
{
    protected $signature = 'intake:fix-deposit-overage {ra_number} {--dry-run} {--force}';
    protected $description = 'Repair an appointment whose ledger shows a false overage from the deposit-double-count bug.';

    public function handle(AppointmentPaymentService $payments): int
    {
        $ra = $this->argument('ra_number');
        $dry = (bool) $this->option('dry-run');

        $matches = TenantAppointment::where('ra_number', $ra)->get();
        if ($matches->isEmpty()) {
            $this->error("No appointment found with ra_number {$ra}.");
            return 1;
        }
        if ($matches->count() > 1) {
            $this->error("ra_number {$ra} matched {$matches->count()} appointments across tenants — refusing to guess.");
            return 1;
        }
        $appt = $matches->first();

        $total = (int) $appt->total_cents;
        $ledger = TenantAppointmentPayment::where('appointment_id', $appt->id)
            ->orderBy('recorded_at')
            ->get();
        $sum = (int) $ledger->sum('amount_cents');
        $overage = $sum - $total;

        $this->line('');
        $this->info("Appointment {$ra}  ({$appt->id})");
        $this->line("  total_cents : {$total}");
        $this->line("  ledger_sum  : {$sum}");
        $this->line("  paid_cents  : " . (int) $appt->paid_cents . "  status=" . $appt->payment_status);
        $this->line('');

        if ($overage <= 0) {
            $this->info("No overage (ledger_sum <= total). Nothing to fix.");
            return 0;
        }

        // The inflated row is the most recent register_sale row large enough to
        // absorb the overage. Reducing it by the overage must net the ledger to
        // exactly total — that is the deposit-double-count fingerprint.
        $candidate = $ledger
            ->filter(fn ($r) => $r->source === TenantAppointmentPayment::SOURCE_REGISTER_SALE
                && (int) $r->amount_cents >= $overage)
            ->sortByDesc('recorded_at')
            ->first();

        if (! $candidate) {
            $this->error("No register_sale ledger row large enough to absorb the overage of {$overage}.");
            $this->error("This does not match the deposit-double-count signature — NOT touching it. Investigate manually.");
            return 1;
        }

        $newRowAmount = (int) $candidate->amount_cents - $overage;
        $projectedSum = $sum - $overage; // == total by construction
        if ($projectedSum !== $total) {
            $this->error("Projected ledger sum {$projectedSum} != total {$total}. Refusing — signature mismatch.");
            return 1;
        }

        $sale = $candidate->register_sale_id
            ? TenantSale::where('id', $candidate->register_sale_id)->first()
            : null;

        $this->info("Plan:");
        $this->line("  • Ledger row {$candidate->id} (kind={$candidate->kind}, sale={$candidate->register_sale_id})");
        $this->line("      amount_cents {$candidate->amount_cents} -> {$newRowAmount}");
        if ($sale) {
            $this->line("  • Sale {$sale->sale_number} ({$sale->id})");
            $this->line("      add credit line 'Deposit applied'  {$overage} cents (negative)");
            $this->line("      subtotal_cents {$sale->subtotal_cents} -> " . ((int) $sale->subtotal_cents - $overage));
            $this->line("      total_cents    {$sale->total_cents} -> " . ((int) $sale->total_cents - $overage));
        } else {
            $this->warn("  • Ledger row has no linked sale; will fix ledger + recalc only.");
        }
        $this->line("  • Recompute appointment paid_cents -> {$total}, status -> paid");
        $this->line('');

        if ($dry) {
            $this->comment("--dry-run: no changes written.");
            return 0;
        }

        if (! $this->option('force') && ! $this->confirm("Apply this correction?")) {
            $this->line("Aborted.");
            return 0;
        }

        DB::transaction(function () use ($appt, $candidate, $newRowAmount, $sale, $overage, $payments) {
            // 1) Correct the inflated ledger row to the true balance.
            $candidate->amount_cents = $newRowAmount;
            $candidate->notes = trim(($candidate->notes ? $candidate->notes . ' ' : '')
                . '[patch-174: corrected from deposit-double-count]');
            $candidate->save();

            // 2) Make the sale honest: full itemization minus a deposit credit.
            if ($sale) {
                DB::table('tenant_sale_items')->insert([
                    'id'                => (string) Str::uuid(),
                    'tenant_id'         => $sale->tenant_id,
                    'sale_id'           => $sale->id,
                    'type'              => 'open_item',
                    'name_snapshot'     => 'Deposit applied',
                    'quantity'          => 1,
                    'unit_price_cents'  => -$overage,
                    'tax_cents'         => 0,
                    'tax_rate_snapshot' => null,
                    'is_taxable'        => false,
                    'line_total_cents'  => -$overage,
                    'position'          => 999,
                    'notes'             => 'patch-174: prior deposit applied to appointment ' . ($appt->ra_number ?? ''),
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ]);
                $sale->subtotal_cents = (int) $sale->subtotal_cents - $overage;
                $sale->total_cents    = (int) $sale->total_cents - $overage;
                $sale->save();
            }

            // 3) Recompute cache + status from the corrected ledger.
            $payments->recalcCache($appt->fresh());
        });

        $fresh = $appt->fresh();
        $this->info("Done. paid_cents={$fresh->paid_cents}  payment_status={$fresh->payment_status}");
        return 0;
    }
}
