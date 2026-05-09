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
 *
 * Refunds are explicitly NOT handled here. A refund of a paid sale goes
 * through the register's existing refund flow (P2 sale modal), which calls
 * AppointmentPaymentService::refund() with the right sale linkage.
 */
class AppointmentRegisterBridgeService
{
    public function __construct(
        protected AppointmentPaymentService $payments,
    ) {}

    /**
     * @return array{action: 'paid_in_full' | 'sale_created' | 'overage' | 'noop',
     *               sale_id?: string, balance_due_cents?: int, overage_cents?: int}
     */
    public function onAppointmentEnteringCommittedStatus(TenantAppointment $appointment): array
    {
        // Always recompute the cache from the ledger before deciding — the
        // status hook may have run inventory commits between the last cache
        // recompute and now, but more importantly we want to be authoritative.
        $appointment->refresh();
        $paid    = (int) $appointment->paid_cents;
        $total   = (int) $appointment->total_cents;
        $balance = $total - $paid;

        // No line items priced yet — nothing to bill, nothing to do.
        if ($total <= 0) {
            return ['action' => 'noop'];
        }

        if ($balance === 0) {
            // Already paid in full (e.g. fully prepaid at booking).
            $appointment->update(['payment_status' => 'paid']);
            return ['action' => 'paid_in_full'];
        }

        if ($balance < 0) {
            // Customer paid more than final total — tenant owes the difference.
            $appointment->update(['payment_status' => 'overage']);
            return ['action' => 'overage', 'overage_cents' => -$balance];
        }

        // balance > 0 — create the draft register sale.
        $sale = $this->createDraftSaleForBalance($appointment, $balance);

        // Recalc cache so payment_status flips to 'pending_balance' (status
        // logic in AppointmentPaymentService accounts for the now-existing
        // open sale).
        $this->payments->recalcCache($appointment);

        return [
            'action'            => 'sale_created',
            'sale_id'           => $sale->id,
            'balance_due_cents' => $balance,
        ];
    }

    /**
     * Reverse path: voids any open draft sale for this appointment.
     * Called when status leaves the committed set.
     *
     * Returns the count of voided sales (usually 0 or 1).
     */
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

    /**
     * Public void path — exposed so the controller can offer "Edit (voids draft)".
     * Fails (returns false) if the sale is already paid; that path goes through
     * refund instead.
     */
    public function voidDraftSale(TenantSale $sale, string $reason = 'manual'): bool
    {
        if (in_array($sale->payment_status, ['paid'], true)) {
            // Money already moved — must go through refund flow, not void.
            return false;
        }

        DB::transaction(function () use ($sale) {
            // Drop any ledger rows attached to this sale (in case partial
            // payments were applied before the void).
            $this->payments->voidForSale($sale);

            // Mark the sale cancelled. We don't hard-delete because audit
            // trail matters — staff can see "this sale was voided at X by Y".
            $sale->update([
                'status'         => 'cancelled',
                'payment_status' => 'unpaid',
            ]);
        });

        return true;
    }

    /**
     * Build the draft sale: copy services + addons + parts as exploded
     * line items. Customer attached. Subtotal/tax matches the appointment.
     *
     * IMPORTANT — type mapping:
     *   appointment service → 'service' (no inventory side effects)
     *   appointment addon   → 'service' (also no inventory)
     *   appointment part    → 'open_item'  ← NOT 'product', see below
     *
     * Why parts become open_item: P3's AppointmentInventoryService already
     * decremented stock when the appointment moved to Completed. If we
     * copied parts as type='product' with inventory_item_id set, the
     * register's sale-close hook would decrement them AGAIN. open_item
     * carries the price + name without triggering inventory mechanics. The
     * sale stays purely a billing instrument; P3 owns the inventory truth.
     */
    private function createDraftSaleForBalance(TenantAppointment $appointment, int $balanceCents): TenantSale
    {
        return DB::transaction(function () use ($appointment, $balanceCents) {
            $saleNumber = $this->generateSaleNumber($appointment->tenant_id);

            $sale = TenantSale::create([
                'id'                  => (string) Str::uuid(),
                'tenant_id'           => $appointment->tenant_id,
                'sale_number'         => $saleNumber,
                'sale_date'           => now()->toDateString(),
                'status'              => 'pending',     // draft
                'payment_status'      => 'draft',        // matches SaleService::commitDraft expectations
                'customer_id'         => $appointment->customer_id,
                'appointment_id'      => $appointment->id,
                'rang_up_by_user_id'  => Auth::guard('tenant')->id() ?? $this->fallbackUserId($appointment),
                'subtotal_cents'      => (int) $appointment->subtotal_cents,
                'tax_cents'           => (int) $appointment->tax_cents,
                'total_cents'         => (int) $appointment->total_cents,
                'notes'               => 'Auto-created from appointment ' . ($appointment->ra_number ?? $appointment->id),
            ]);

            $position = 0;

            // Services — type=service (no inventory side effects regardless)
            foreach ($appointment->items as $item) {
                $unitPrice = (int) ($item->price_cents_override ?? $item->price_cents);
                $this->createSaleLine($sale, [
                    'type'             => 'service',
                    'name'             => $item->item_name_snapshot,
                    'quantity'         => 1,
                    'unit_price_cents' => $unitPrice,
                    'position'         => $position++,
                    'notes'            => 'From appointment ' . ($appointment->ra_number ?? ''),
                ]);
            }

            // Addons — also type=service (they're service extras)
            foreach ($appointment->addons as $addon) {
                $unitPrice = (int) ($addon->price_cents_override ?? $addon->price_cents);
                $this->createSaleLine($sale, [
                    'type'             => 'service',
                    'name'             => '+ ' . $addon->addon_name_snapshot,
                    'quantity'         => 1,
                    'unit_price_cents' => $unitPrice,
                    'position'         => $position++,
                    'notes'            => 'From appointment ' . ($appointment->ra_number ?? ''),
                ]);
            }

            // Parts — open_item (NOT product) so register sale-close does
            // not double-decrement stock. P3 already owns inventory.
            foreach ($appointment->parts as $part) {
                $this->createSaleLine($sale, [
                    'type'             => 'open_item',
                    'name'             => $part->item_name_snapshot,
                    'quantity'         => (int) $part->quantity,
                    'unit_price_cents' => (int) $part->effectiveUnitPriceCents(),
                    'position'         => $position++,
                    'notes'            => 'Inventory committed via appointment ' . ($appointment->ra_number ?? ''),
                ]);
            }

            return $sale;
        });
    }

    /**
     * Light-weight sale-line insert. Direct DB write because we don't want
     * to trip any sale-line side-effects (inventory etc) — the appointment
     * already owns its inventory commits via P3.
     */
    private function createSaleLine(TenantSale $sale, array $data): void
    {
        $unitPrice = (int) $data['unit_price_cents'];
        $qty       = (int) $data['quantity'];
        $lineTotal = $unitPrice * $qty;

        DB::table('tenant_sale_items')->insert([
            'id'                 => (string) Str::uuid(),
            'tenant_id'          => $sale->tenant_id,
            'sale_id'            => $sale->id,
            'type'               => $data['type'],
            'name_snapshot'      => $data['name'],
            'quantity'           => $qty,
            'unit_price_cents'   => $unitPrice,
            'line_total_cents'   => $lineTotal,
            'is_taxable'         => true,
            'position'           => $data['position'],
            'notes'              => $data['notes'] ?? null,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    /**
     * Generate a sale_number. Tries to use the existing SaleService if
     * available; falls back to a timestamp-based unique value.
     *
     * The fallback isn't ideal for human-readable receipts but is a safe
     * unique value when the SaleService isn't loaded.
     */
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

        // Fallback: query the counter directly.
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

    /**
     * Stripe webhook + headless contexts have no Auth user. The sale needs
     * a non-null rang_up_by_user_id. Use the tenant's first owner-role user
     * as the system fallback.
     */
    private function fallbackUserId(TenantAppointment $appointment): ?string
    {
        return DB::table('tenant_users')
            ->where('tenant_id', $appointment->tenant_id)
            ->orderBy('created_at')
            ->value('id');
    }
}
