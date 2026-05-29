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
                // MARKER-PATCH-174B — sales-as-money model: this balance-
                // collection sale stores the OUTSTANDING balance, not the full
                // job. The appointment holds the itemization and is the service
                // record; sales carry the money. One positive "Service balance"
                // line is built below (no negative line items — money columns
                // are unsigned). Tax is already baked into the appointment's
                // locked total, so the balance line is non-taxable here.
                'subtotal_cents'      => $balanceCents,
                'tax_cents'           => 0,
                'total_cents'         => $balanceCents,
                'tax_locked'          => true,
                'notes'               => 'Auto-created from appointment ' . ($appointment->ra_number ?? $appointment->id),
            ]);

            // Build the line spec list first so we know subtotal-without-tax
            // and can distribute the appointment's tax proportionally.
            // MARKER-PATCH-174B — single positive "Service balance" line for
            // the outstanding amount. We deliberately do NOT re-itemize the job:
            // the appointment already holds the full itemization and is the
            // service-record of truth, and re-listing it on the sale is what
            // double-counted revenue. A balance-collection sale is a payment,
            // so one line is correct and keeps every value positive (money
            // columns are unsigned).
            $depositApplied = max(0, (int) $appointment->total_cents - $balanceCents);
            $balanceLabel = $depositApplied > 0
                ? 'Service balance — ' . ($appointment->ra_number ?? 'appointment')
                    . ' (after ' . format_money($depositApplied) . ' deposit)'
                : 'Service balance — ' . ($appointment->ra_number ?? 'appointment');

            $this->createSaleLine($sale, [
                'type'                => 'open_item',
                'name'                => $balanceLabel,
                'quantity'            => 1,
                'unit_price_cents'    => $balanceCents,
                'line_subtotal_cents' => $balanceCents,
                'tax_cents'           => 0,
                'tax_rate_snapshot'   => null,
                'is_taxable'          => false,
                'position'            => 0,
                'notes'               => 'Outstanding balance for appointment ' . ($appointment->ra_number ?? ''),
            ]);

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