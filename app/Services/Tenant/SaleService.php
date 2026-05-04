<?php

namespace App\Services\Tenant;

use App\Models\Tenant\TenantSale;
use App\Models\Tenant\TenantSaleItem;
use App\Models\Tenant\TenantSaleCounter;
use App\Models\Tenant\TenantServiceItem;
use App\Models\Tenant\TenantInventoryItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class SaleService
{
    public function __construct(protected InventoryService $inventory)
    {
    }

    /**
     * Generate the next sale_number for a tenant on a given date.
     * Uses row-level lock-and-increment on tenant_sale_counters to prevent
     * collision under concurrent register writes.
     *
     * Format: S-YYYYMMDD-### (zero-padded sequence).
     */
    public function nextSaleNumber(string $tenantId, ?string $saleDate = null): string
    {
        $date = $saleDate ? Carbon::parse($saleDate) : Carbon::today();
        $datePart = $date->format('Ymd');

        return DB::transaction(function () use ($tenantId, $date, $datePart) {
            $counter = TenantSaleCounter::where('tenant_id', $tenantId)
                ->whereDate('counter_date', $date->toDateString())
                ->lockForUpdate()
                ->first();

            if (!$counter) {
                $counter = TenantSaleCounter::create([
                    'tenant_id'    => $tenantId,
                    'counter_date' => $date->toDateString(),
                    'last_seq'     => 0,
                ]);
            }

            $counter->increment('last_seq');
            $seq = str_pad((string) $counter->last_seq, 3, '0', STR_PAD_LEFT);

            return "S-{$datePart}-{$seq}";
        });
    }

    /**
     * Create a sale and its line items atomically.
     */
    public function createSale(array $data): TenantSale
    {
        $items = $data['items'] ?? [];
        unset($data['items']);

        // Customer-required-for-service validation
        $hasServiceLine = collect($items)->contains(fn ($i) => ($i['type'] ?? null) === 'service');
        if ($hasServiceLine && empty($data['customer_id'])) {
            throw new SaleValidationException(
                'Customer is required when the sale has any service line.'
            );
        }

        // Location is required (DB allows null for legacy/imports; app enforces presence).
        if (empty($data['location_id'])) {
            throw new SaleValidationException(
                'location_id is required to create a sale.'
            );
        }

        return DB::transaction(function () use ($data, $items) {
            $tenantId = $data['tenant_id'];
            $saleDate = $data['sale_date'] ?? Carbon::today()->toDateString();

            $sale = TenantSale::create([
                'tenant_id'          => $tenantId,
                'sale_number'        => $this->nextSaleNumber($tenantId, $saleDate),
                'sale_date'          => $saleDate,
                'status'             => $data['status'] ?? 'pending',
                'payment_status'     => $data['payment_status'] ?? 'unpaid',
                'customer_id'        => $data['customer_id'] ?? null,
                'assigned_staff_id'  => $data['assigned_staff_id'] ?? null,
                'appointment_id'     => $data['appointment_id'] ?? null,
                'rang_up_by_user_id' => $data['rang_up_by_user_id'],
                'location_id'        => $data['location_id'],
                'payment_method'     => $data['payment_method'] ?? null,
                'payment_reference'  => $data['payment_reference'] ?? null,
                'paid_at'            => $data['paid_at'] ?? null,
                'notes'              => $data['notes'] ?? null,
                'subtotal_cents'     => 0,
                'discount_cents'     => 0,
                'tax_cents'          => 0,
                'surcharge_cents'    => 0,
                'tip_cents'          => (int) ($data['tip_cents'] ?? 0),
                'total_cents'        => 0,
            ]);

            $position = 0;
            foreach ($items as $itemData) {
                $this->createSaleItem($sale, $itemData, $position++);
            }

            // Decrement inventory for product lines, in same transaction.
            // Throws InventoryStockException if stock insufficient and no allow_oversell.
            $sale->load('items');
            foreach ($sale->items as $line) {
                if ($line->type === 'product') {
                    $this->inventory->decrementForSaleItem($sale, $line, $data['location_id']);
                }
            }

            return $this->recalculate($sale->fresh('items'));
        });
    }

    /**
     * Create a single line item on a sale, snapshotting fields from source.
     */
    protected function createSaleItem(TenantSale $sale, array $data, int $position): TenantSaleItem
    {
        $type = $data['type'] ?? 'open_item';

        $name           = $data['name_snapshot'] ?? null;
        $description    = $data['description_snapshot'] ?? null;
        $unitPriceCents = $data['unit_price_cents'] ?? null;
        $costCents      = $data['cost_cents_snapshot'] ?? null;
        $isTaxable      = $data['is_taxable'] ?? true;
        $serviceId      = $data['service_id'] ?? null;
        $inventoryItemId= $data['inventory_item_id'] ?? null;
        $giftCardId     = $data['gift_card_id'] ?? null;

        // Snapshot from source records when available
        if ($type === 'service' && $serviceId) {
            $service = TenantServiceItem::find($serviceId);
            if ($service) {
                $name           = $name           ?? $service->name;
                $description    = $description    ?? $service->description;
                $unitPriceCents = $unitPriceCents ?? (int) ($service->price_cents ?? 0);
            }
        } elseif ($type === 'product' && $inventoryItemId) {
            $item = TenantInventoryItem::find($inventoryItemId);
            if ($item) {
                $name           = $name           ?? ($item->name ?? '');
                $description    = $description    ?? ($item->description ?? null);
                $unitPriceCents = $unitPriceCents ?? (int) ($item->effectiveSellPriceCents() ?? 0);
                $costCents      = $costCents      ?? (int) ($item->effectiveCostCents() ?? 0);
                // tax_class_code may be 'exempt'; default true otherwise
                $isTaxable      = $data['is_taxable'] ?? (($item->tax_class_code ?? null) !== 'exempt');
            }
        }

        if ($name === null || $name === '') {
            throw new SaleValidationException(
                'Sale item is missing a name_snapshot (required for type=' . $type . ').'
            );
        }
        if ($unitPriceCents === null) {
            throw new SaleValidationException(
                'Sale item is missing unit_price_cents (required for type=' . $type . ').'
            );
        }

        $quantity      = (float) ($data['quantity'] ?? 1);
        $discountCents = (int) ($data['discount_cents'] ?? 0);

        $grossCents     = (int) round($unitPriceCents * $quantity);
        $lineTotalCents = max(0, $grossCents - $discountCents);

        return TenantSaleItem::create([
            'tenant_id'           => $sale->tenant_id,
            'sale_id'             => $sale->id,
            'type'                => $type,
            'service_id'          => $serviceId,
            'inventory_item_id'   => $inventoryItemId,
            'gift_card_id'        => $giftCardId,
            'name_snapshot'       => $name,
            'description_snapshot'=> $description,
            'cost_cents_snapshot' => $costCents,
            'quantity'            => $quantity,
            'unit_price_cents'    => $unitPriceCents,
            'discount_cents'      => $discountCents,
            'tax_rate_snapshot'   => null,
            'is_taxable'          => $isTaxable,
            'tax_cents'           => 0,
            'tip_cents'           => 0,
            'line_total_cents'    => $lineTotalCents,
            'assigned_staff_id'   => $data['assigned_staff_id'] ?? null,
            'position'            => $data['position'] ?? $position,
            'notes'               => $data['notes'] ?? null,
        ]);
    }

    /**
     * Recompute sale totals from its items + tenant settings.
     * Does NOT include surcharge — that is settlement-time logic.
     */
    public function recalculate(TenantSale $sale): TenantSale
    {
        $tenant = $sale->tenant;
        $taxRate = (float) ($tenant->default_tax_rate ?? 0);
        $taxServicesByDefault = (bool) ($tenant->tax_services_default ?? true);

        $subtotal = 0;
        $discount = 0;
        $tax      = 0;

        foreach ($sale->items as $item) {
            $subtotal += $item->line_total_cents;
            $discount += $item->discount_cents;

            $shouldTax = $item->is_taxable
                && ($item->type !== 'service' || $taxServicesByDefault);

            if ($shouldTax && $taxRate > 0) {
                $lineTax = (int) round($item->line_total_cents * ($taxRate / 100));
                if ($lineTax !== $item->tax_cents
                    || (string) $taxRate !== (string) $item->tax_rate_snapshot) {
                    $item->update([
                        'tax_cents'         => $lineTax,
                        'tax_rate_snapshot' => $taxRate,
                    ]);
                }
                $tax += $lineTax;
            } else {
                if ($item->tax_cents !== 0 || $item->tax_rate_snapshot !== null) {
                    $item->update([
                        'tax_cents'         => 0,
                        'tax_rate_snapshot' => null,
                    ]);
                }
            }
        }

        $total = $subtotal + $tax + $sale->tip_cents + $sale->surcharge_cents;

        $sale->update([
            'subtotal_cents' => $subtotal,
            'discount_cents' => $discount,
            'tax_cents'      => $tax,
            'total_cents'    => $total,
        ]);

        return $sale->fresh('items');
    }

    /**
     * Create a refund row referencing an original sale.
     * Refunds are negative-effect sale rows: line items mirror the originals,
     * inventory is restocked via InventoryService::incrementForRefund(), and
     * the original's payment_status flips to 'refunded' (full) or 'partial'.
     *
     * Expected $data:
     *   tenant_id (required)
     *   original_sale_id (required)
     *   rang_up_by_user_id (required)
     *   refund_method (required) — cash | card | check | store_credit | mark_paid
     *   reason (optional)
     *   notes (optional)
     *   item_ids (required, array of original sale_item ids to refund)
     */
    public function createRefund(array $data): TenantSale
    {
        $original = TenantSale::where('id', $data['original_sale_id'] ?? null)
            ->where('tenant_id', $data['tenant_id'] ?? null)
            ->with('items')
            ->first();

        if (!$original) {
            throw new SaleValidationException('Original sale not found.');
        }
        if ($original->payment_status !== 'paid' && $original->payment_status !== 'partial') {
            throw new SaleValidationException('Only paid sales can be refunded.');
        }
        if ($original->refund_of_sale_id !== null) {
            throw new SaleValidationException('Cannot refund a refund row.');
        }
        if (empty($data['item_ids']) || !is_array($data['item_ids'])) {
            throw new SaleValidationException('No items selected to refund.');
        }

        $itemsToRefund = $original->items->whereIn('id', $data['item_ids']);
        if ($itemsToRefund->isEmpty()) {
            throw new SaleValidationException('No matching items to refund.');
        }
        if (empty($original->location_id)) {
            throw new SaleValidationException('Original sale has no location_id; cannot refund.');
        }

        return DB::transaction(function () use ($data, $original, $itemsToRefund) {
            $tenantId = $data['tenant_id'];
            $today = Carbon::today()->toDateString();
            $reason = trim((string) ($data['reason'] ?? ''));
            $notes  = trim((string) ($data['notes'] ?? ''));
            $combinedNotes = trim($reason . ($reason && $notes ? "\n" : '') . $notes);

            $refund = TenantSale::create([
                'tenant_id'          => $tenantId,
                'sale_number'        => $this->nextSaleNumber($tenantId, $today),
                'sale_date'          => $today,
                'status'             => 'completed',
                'payment_status'     => 'refunded',
                'customer_id'        => $original->customer_id,
                'assigned_staff_id'  => null,
                'appointment_id'     => null,
                'rang_up_by_user_id' => $data['rang_up_by_user_id'],
                'refund_of_sale_id'  => $original->id,
                'location_id'        => $original->location_id,
                'notes'              => $combinedNotes !== '' ? $combinedNotes : null,
                'subtotal_cents'     => 0,
                'discount_cents'     => 0,
                'tax_cents'          => 0,
                'surcharge_cents'    => 0,
                'tip_cents'          => 0,
                'total_cents'        => 0,
                'paid_at'            => Carbon::now(),
                'payment_method'     => $data['refund_method'],
            ]);

            $position = 0;
            foreach ($itemsToRefund as $orig) {
                $line = TenantSaleItem::create([
                    'tenant_id'           => $tenantId,
                    'sale_id'             => $refund->id,
                    'type'                => $orig->type,
                    'service_id'          => $orig->service_id,
                    'inventory_item_id'   => $orig->inventory_item_id,
                    'gift_card_id'        => $orig->gift_card_id,
                    'name_snapshot'       => $orig->name_snapshot,
                    'description_snapshot'=> $orig->description_snapshot,
                    'cost_cents_snapshot' => $orig->cost_cents_snapshot,
                    'quantity'            => $orig->quantity,
                    'unit_price_cents'    => $orig->unit_price_cents,
                    'discount_cents'      => $orig->discount_cents,
                    'tax_rate_snapshot'   => $orig->tax_rate_snapshot,
                    'is_taxable'          => $orig->is_taxable,
                    'tax_cents'           => $orig->tax_cents,
                    'tip_cents'           => 0,
                    'line_total_cents'    => $orig->line_total_cents,
                    'assigned_staff_id'   => null,
                    'position'            => $position++,
                    'notes'               => null,
                ]);

                // Restock inventory for refunded product lines
                if ($line->type === 'product') {
                    $this->inventory->incrementForRefund($refund, $line, $original->location_id);
                }
            }

            // Flip original's payment_status:
            //   - all items refunded -> 'refunded'
            //   - some items refunded -> 'partial'
            $allRefunded = $itemsToRefund->count() === $original->items->count();
            $original->update([
                'payment_status' => $allRefunded ? 'refunded' : 'partial',
            ]);

            return $this->recalculate($refund->fresh('items'));
        });
    }
}
