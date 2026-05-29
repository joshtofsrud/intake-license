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
 * MARKER-PATCH-174B — One-shot correction for appointments whose ledger shows a
 * false overage caused by the deposit-double-count bug.
 *
 * Root cause (fixed forward in AppointmentRegisterBridgeService): the
 * auto-created "balance" sale re-itemized the FULL job and recorded the FULL
 * total into the appointment ledger, instead of the outstanding balance. With
 * a deposit already on the ledger this summed past the job total -> "overage"
 * (overage == deposit).
 *
 * This repairs ONE appointment to match what the fixed bridge now produces:
 *   - Collapse the inflated balance sale's line items to a single positive
 *     "Service balance (after $X deposit)" line at the true balance.
 *   - Set that sale's subtotal/tax/total to the balance (tax already lives in
 *     the appointment's locked total; the balance line is non-taxable).
 *   - Reduce the inflated balance ledger row to the true balance.
 *   - Recompute the appointment's paid_cents + payment_status.
 *
 * ALL VALUES POSITIVE — money columns are unsigned; no negative line items.
 *
 * SAFETY: refuses to act unless reducing the single inflated register_sale
 * ledger row makes the ledger net to EXACTLY total_cents. That signature is
 * unique to the deposit-double-count bug, so a genuine overpayment (which
 * won't reconcile to total) is left untouched. Always run --dry-run first.
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
        $ra  = $this->argument('ra_number');
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

        $total  = (int) $appt->total_cents;
        $ledger = TenantAppointmentPayment::where('appointment_id', $appt->id)
            ->orderBy('recorded_at')->get();
        $sum     = (int) $ledger->sum('amount_cents');
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

        // The inflated row: the most recent register_sale row big enough to
        // absorb the overage. Reducing it by the overage must net the ledger to
        // exactly total — the deposit-double-count fingerprint.
        $candidate = $ledger
            ->filter(fn ($r) => $r->source === TenantAppointmentPayment::SOURCE_REGISTER_SALE
                && (int) $r->amount_cents >= $overage)
            ->sortByDesc('recorded_at')
            ->first();

        if (! $candidate) {
            $this->error("No register_sale ledger row large enough to absorb overage {$overage}.");
            $this->error("Does not match the deposit-double-count signature — NOT touching it.");
            return 1;
        }

        $newRowAmount   = (int) $candidate->amount_cents - $overage;   // the true balance
        $depositApplied = $total - $newRowAmount;                      // what was already paid
        $projectedSum   = $sum - $overage;                             // == total by construction
        if ($projectedSum !== $total) {
            $this->error("Projected ledger sum {$projectedSum} != total {$total}. Refusing — signature mismatch.");
            return 1;
        }

        $sale = $candidate->register_sale_id
            ? TenantSale::where('id', $candidate->register_sale_id)->first()
            : null;

        $label = $depositApplied > 0
            ? 'Service balance — ' . ($appt->ra_number ?? 'appointment')
                . ' (after ' . format_money($depositApplied) . ' deposit)'
            : 'Service balance — ' . ($appt->ra_number ?? 'appointment');

        $this->info("Plan:");
        $this->line("  • Ledger row {$candidate->id} (kind={$candidate->kind})");
        $this->line("      amount_cents {$candidate->amount_cents} -> {$newRowAmount}");
        if ($sale) {
            $itemCount = DB::table('tenant_sale_items')->where('sale_id', $sale->id)->count();
            $this->line("  • Sale {$sale->sale_number} ({$sale->id})");
            $this->line("      replace {$itemCount} line item(s) with ONE positive line:");
            $this->line("        \"{$label}\"  = {$newRowAmount} cents");
            $this->line("      subtotal_cents {$sale->subtotal_cents} -> {$newRowAmount}");
            $this->line("      tax_cents      {$sale->tax_cents} -> 0");
            $this->line("      total_cents    {$sale->total_cents} -> {$newRowAmount}");
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

        DB::transaction(function () use ($appt, $candidate, $newRowAmount, $sale, $label, $payments) {
            // 1) Correct the inflated ledger row to the true balance.
            $candidate->amount_cents = $newRowAmount;
            $candidate->notes = trim(($candidate->notes ? $candidate->notes . ' ' : '')
                . '[patch-174b: corrected from deposit-double-count]');
            $candidate->save();

            // 2) Collapse the balance sale to a single positive balance line.
            if ($sale) {
                DB::table('tenant_sale_items')->where('sale_id', $sale->id)->delete();
                DB::table('tenant_sale_items')->insert([
                    'id'                => (string) Str::uuid(),
                    'tenant_id'         => $sale->tenant_id,
                    'sale_id'           => $sale->id,
                    'type'              => 'open_item',
                    'name_snapshot'     => $label,
                    'quantity'          => 1,
                    'unit_price_cents'  => $newRowAmount,
                    'tax_cents'         => 0,
                    'tax_rate_snapshot' => null,
                    'is_taxable'        => false,
                    'line_total_cents'  => $newRowAmount,
                    'position'          => 0,
                    'notes'             => 'patch-174b: outstanding balance for appointment ' . ($appt->ra_number ?? ''),
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ]);
                $sale->subtotal_cents = $newRowAmount;
                $sale->tax_cents      = 0;
                $sale->total_cents    = $newRowAmount;
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
