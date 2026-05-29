<?php

namespace App\Services\Tenant;

use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantSale;
use App\Models\Tenant\TenantSaleItem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bridges the appointment lifecycle to the register sale lifecycle.
 *
 * Two main entry points:
 *
 *   onAppointmentEnteringCommittedStatus()
 *     Called from the appointment controller's status hook when an
 *     appointment moves into completed / shipped / closed. Reads the
 *     ledger to compute balance_due, then takes one of three branches:
 *       balance_due == 0 → no register sale; appointment marked paid
 *       balance_due >  0 → create draft register sale with exploded lines
 *       balance_due <  0 → no register sale; appointment marked overage
 *
 *   onAppointmentLeavingCommittedStatus()
 *     Called when an appointment moves out of committed back into a
 *     pre-committed status (or to cancelled). Voids any open draft sale
 *     for the appointment, which through voidForSale() drops its ledger
 *     rows and recomputes paid_cents.
 */
class AppointmentRegisterBridgeService
{
    public function __construct(
        protected AppointmentPaymentService $payments,
    ) {}

    public function onAppointmentEnteringCommittedStatus(TenantAppointment $appointment): array
    {
        $appointment->refresh();
        $paid    = (int) $appointment->paid_cents;
        $total   = (int) $appointment->total_cents;
        $balance = $total - $paid;

        if ($total <= 0) {
            return ['action' => 'noop'];
        }

        if ($balance === 0) {
            $appointment->update(['payment_status' => 'paid']);
            return ['action' => 'paid_in_full'];
        }

        if ($balance < 0) {
            $appointment->update(['payment_status' => 'overage']);
            return ['action' => 'overage', 'overage_cents' => -$balance];
        }

        $sale = $this->createDraftSaleForBalance($appointment, $balance);
        $this->payments->recalcCache($appointment);

        return [
            'action'            => 'sale_created',
            'sale_id'           => $sale->id,
            'balance_due_cents' => $balance,
        ];
    }

    public function onAppointmentLeavingCommittedStatus(TenantAppointment $appointment): int
    {
        $voided = 0;
        $sales = $appointment->sales()
            ->whereNotIn('status', ['cancelled', 'closed'])
            ->where('payment_status', 'draft')
            ->get();

        foreach ($sales as $sale) {
            $this->voidDraftSale($sale, 'appointment_status_reverted');
            $voided++;
        }

        return $voided;
    }

    public function voidDraftSale(TenantSale $sale, string $reason = 'manual'): bool
    {
        if (in_array($sale->payment_status, ['paid'], true)) {
            return false;
        }

        DB::transaction(function () use ($sale) {
            $this->payments->voidForSale($sale);
            $sale->update([
                'status'         => 'cancelled',
                'payment_status' => 'unpaid',
            ]);
        });

        return true;
    }

    /**
     * Build the draft sale: copy services + addons + parts as exploded
     * line items. Distributes the appointment's tax_cents proportionally
     * across lines so the register's per-line recalc sums back to the
     * same total tax the appointment had.
     *
     * Type mapping:
     *   appointment service → 'service'
     *   appointment addon   → 'service'
     *   appointment part    → 'open_item' (NOT product, to avoid double inventory decrement)
     */
    private function createDraftSaleForBalance(TenantAppointment $appointment, int $balanceCents): TenantSale
    {
        return DB::transaction(function () use ($appointment, $balanceCents) {
            $saleNumber = $this->generateSaleNumber($appointment->tenant_id);

            // MARKER-PATCH-174 — how much has already been paid (deposit /
            // prior payments). The balance sale must NET to the outstanding
            // balance, so we itemize the full job then subtract this via a
            // credit line below. Forgetting this was the deposit-double-count bug.
            $depositApplied = max(0, (int) $appointment->total_cents - $balanceCents);

            // Resolve location: appointment's wins; tenant default fallback.
            $locationId = $appointment->location_id ?: \App\Models\Tenant\TenantLocation::query()
                ->where('tenant_id', $appointment->tenant_id)
                ->where('is_active', 1)
                ->orderByDesc('is_default')
                ->orderBy('created_at')
                ->value('id');

            $sale = TenantSale::create([
                'id'                  => (string) Str::uuid(),
                'tenant_id'           => $appointment->tenant_id,
                'sale_number'         => $saleNumber,
                'sale_date'           => now()->toDateString(),
                'status'              => 'pending',
                'payment_status'      => 'draft',
                'customer_id'         => $appointment->customer_id,
                'appointment_id'      => $appointment->id,
                'location_id'         => $locationId,
                'rang_up_by_user_id'  => Auth::guard('tenant')->id() ?? $this->fallbackUserId($appointment),
                // MARKER-PATCH-174 — net of the deposit credit appended below.
                // Tax stays at the full job tax (a deposit is a prior payment,
                // not a price cut); subtotal + tax still equals total.
                'subtotal_cents'      => (int) $appointment->subtotal_cents - $depositApplied,
                'tax_cents'           => (int) $appointment->tax_cents,
                'total_cents'         => $balanceCents,
                'tax_locked'          => true,
                'notes'               => 'Auto-created from appointment ' . ($appointment->ra_number ?? $appointment->id),
            ]);

            // Build the line spec list first so we know subtotal-without-tax
            // and can distribute the appointment's tax proportionally.
            $lineSpecs = [];

            foreach ($appointment->items as $item) {
                $unitPrice = (int) ($item->price_cents_override ?? $item->price_cents);
                $lineSpecs[] = [
                    'type'             => 'service',
                    'name'             => $item->item_name_snapshot,
                    'quantity'         => 1,
                    'unit_price_cents' => $unitPrice,
                    'notes'            => 'From appointment ' . ($appointment->ra_number ?? ''),
                ];
            }

            foreach ($appointment->addons as $addon) {
                $unitPrice = (int) ($addon->price_cents_override ?? $addon->price_cents);
                $lineSpecs[] = [
                    'type'             => 'service',
                    'name'             => '+ ' . $addon->addon_name_snapshot,
                    'quantity'         => 1,
                    'unit_price_cents' => $unitPrice,
                    'notes'            => 'From appointment ' . ($appointment->ra_number ?? ''),
                ];
            }

            foreach ($appointment->parts as $part) {
                $lineSpecs[] = [
                    'type'             => 'open_item',
                    'name'             => $part->item_name_snapshot,
                    'quantity'         => (int) $part->quantity,
                    'unit_price_cents' => (int) $part->effectiveUnitPriceCents(),
                    'notes'            => 'Inventory committed via appointment ' . ($appointment->ra_number ?? ''),
                ];
            }

            // Compute pre-tax subtotal across all line specs.
            $preTaxSubtotal = 0;
            foreach ($lineSpecs as &$spec) {
                $spec['line_subtotal_cents'] = $spec['unit_price_cents'] * $spec['quantity'];
                $preTaxSubtotal += $spec['line_subtotal_cents'];
            }
            unset($spec);

            // Distribute appointment's tax across lines proportionally.
            $totalAppointmentTaxCents = (int) $appointment->tax_cents;
            $taxRate = ($preTaxSubtotal > 0 && $totalAppointmentTaxCents > 0)
                ? round($totalAppointmentTaxCents / $preTaxSubtotal, 4)
                : 0.0;

            $taxAssigned = 0;
            $lastIdx = count($lineSpecs) - 1;

            foreach ($lineSpecs as $i => &$spec) {
                if ($preTaxSubtotal === 0 || $totalAppointmentTaxCents === 0) {
                    $spec['tax_cents']         = 0;
                    $spec['tax_rate_snapshot'] = 0;
                    $spec['is_taxable']        = false;
                    continue;
                }

                if ($i === $lastIdx) {
                    // Last line absorbs rounding remainder so total matches.
                    $spec['tax_cents'] = $totalAppointmentTaxCents - $taxAssigned;
                } else {
                    $spec['tax_cents'] = (int) round(
                        $spec['line_subtotal_cents'] * $totalAppointmentTaxCents / $preTaxSubtotal
                    );
                    $taxAssigned += $spec['tax_cents'];
                }

                $spec['tax_rate_snapshot'] = $taxRate;
                $spec['is_taxable']        = true;
            }
            unset($spec);

            // MARKER-PATCH-174 — append the deposit/prior-payment credit as a
            // final non-taxable line so the sale itemizes the full job for an
            // honest receipt but nets to the real balance. Added AFTER the tax
            // distribution loop so it never skews the proportional tax split.
            if ($depositApplied > 0) {
                $lineSpecs[] = [
                    'type'                => 'open_item',
                    'name'                => 'Deposit applied',
                    'quantity'            => 1,
                    'unit_price_cents'    => -$depositApplied,
                    'line_subtotal_cents' => -$depositApplied,
                    'tax_cents'           => 0,
                    'tax_rate_snapshot'   => null,
                    'is_taxable'          => false,
                    'notes'               => 'Prior payments applied to appointment ' . ($appointment->ra_number ?? ''),
                ];
            }

            $position = 0;
            foreach ($lineSpecs as $spec) {
                $spec['position'] = $position++;
                $this->createSaleLine($sale, $spec);
            }

            return $sale;
        });
    }

    /**
     * Light-weight sale-line insert. Tax fields are pre-computed by
     * createDraftSaleForBalance so the register's recalc preserves the
     * appointment's tax allocation.
     *
     * line_total_cents follows the existing schema convention:
     *   (unit_price * quantity) - discount + tax
     */
    private function createSaleLine(TenantSale $sale, array $data): void
    {
        $unitPrice = (int) $data['unit_price_cents'];
        $qty       = (int) $data['quantity'];
        $taxCents  = (int) ($data['tax_cents'] ?? 0);
        $subtotal  = $data['line_subtotal_cents'] ?? ($unitPrice * $qty);

        DB::table('tenant_sale_items')->insert([
            'id'                 => (string) Str::uuid(),
            'tenant_id'          => $sale->tenant_id,
            'sale_id'            => $sale->id,
            'type'               => $data['type'],
            'name_snapshot'      => $data['name'],
            'quantity'           => $qty,
            'unit_price_cents'   => $unitPrice,
            'tax_cents'          => $taxCents,
            'tax_rate_snapshot'  => $data['tax_rate_snapshot'] ?? null,
            'is_taxable'         => (bool) ($data['is_taxable'] ?? false),
            'line_total_cents'   => $subtotal,
            'position'           => $data['position'],
            'notes'              => $data['notes'] ?? null,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    private function generateSaleNumber(string $tenantId): string
    {
        if (class_exists(\App\Services\Tenant\SaleService::class)
            && method_exists(\App\Services\Tenant\SaleService::class, 'nextSaleNumber')) {
            try {
                return app(\App\Services\Tenant\SaleService::class)->nextSaleNumber($tenantId);
            } catch (\Throwable $e) {
                // Fall through to fallback
            }
        }

        $today = now()->format('Ymd');
        $prefix = "S-{$today}-";
        $maxNumber = DB::table('tenant_sales')
            ->where('tenant_id', $tenantId)
            ->where('sale_number', 'like', $prefix . '%')
            ->orderByDesc('sale_number')
            ->value('sale_number');

        $next = 1;
        if ($maxNumber) {
            $parts = explode('-', $maxNumber);
            $lastNum = (int) end($parts);
            $next = $lastNum + 1;
        }

        return $prefix . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }

    private function fallbackUserId(TenantAppointment $appointment): ?string
    {
        return DB::table('tenant_users')
            ->where('tenant_id', $appointment->tenant_id)
            ->orderBy('created_at')
            ->value('id');
    }
}