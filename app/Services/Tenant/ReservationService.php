<?php

namespace App\Services\Tenant;

use App\Models\Tenant\TenantInventoryItem;
use App\Models\Tenant\TenantInventoryItemLocation;
use App\Models\Tenant\TenantInventoryReservation;
use App\Models\Tenant\TenantSale;
use App\Models\Tenant\TenantSaleItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * MARKER-RESERVE — the only thing allowed to change reserved_count.
 *
 * Every method must be called inside the caller's DB::transaction(), the
 * same contract InventoryService already has: a reservation that outlives a
 * rolled-back sale would hold stock for nobody.
 *
 * Reserving moves AVAILABLE, not on hand. The unit is still on the shelf and
 * still counts when someone counts the shelf; it simply cannot be sold to
 * anyone but the person it is held for. The movement rows say that in so
 * many words, so a count that disagrees with the register has an answer in
 * the history.
 */
class ReservationService
{
    /**
     * Hold $qty of the line's item at $locationId for this sale.
     * Throws InventoryStockException if that many are not available.
     */
    public function reserve(TenantSale $sale, TenantSaleItem $line, string $locationId, ?int $qty = null): TenantInventoryReservation
    {
        if ($line->type !== 'product' || ! $line->inventory_item_id) {
            throw new \InvalidArgumentException('Only product lines can be reserved.');
        }

        $qty = $qty ?? (int) ceil((float) $line->quantity);
        if ($qty <= 0) {
            throw new \InvalidArgumentException('Reservation quantity must be positive.');
        }

        $item = TenantInventoryItem::where('id', $line->inventory_item_id)
            ->where('tenant_id', $sale->tenant_id)->firstOrFail();

        $loc = TenantInventoryItemLocation::where('inventory_item_id', $item->id)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->first();

        if (! $loc) {
            $loc = TenantInventoryItemLocation::create([
                'tenant_id'            => $sale->tenant_id,
                'inventory_item_id'    => $item->id,
                'location_id'          => $locationId,
                'computed_stock_count' => 0,
                'reserved_count'       => 0,
                'is_active'            => true,
            ]);
            $loc = TenantInventoryItemLocation::where('id', $loc->id)->lockForUpdate()->first();
        }

        $available = $loc->computed_stock_count - $loc->reserved_count;

        if ($available < $qty && ! $item->allow_oversell) {
            throw new InventoryStockException(
                "Cannot hold {$qty} of {$item->name} here: {$loc->computed_stock_count} on hand, "
                . "{$loc->reserved_count} already held, {$available} available."
            );
        }

        $res = TenantInventoryReservation::create([
            'id'                => (string) Str::uuid(),
            'tenant_id'         => $sale->tenant_id,
            'inventory_item_id' => $item->id,
            'location_id'       => $locationId,
            'sale_id'           => $sale->id,
            'sale_item_id'      => $line->id,
            'quantity'          => $qty,
            'status'            => TenantInventoryReservation::ACTIVE,
            'created_at'        => now(),
        ]);

        $this->movement($sale->tenant_id, $item, $locationId, 'reserve', $qty, $res->id,
            'Held for ' . ($sale->sale_number ?: 'sale'));

        $loc->reserved_count += $qty;
        $loc->save();

        return $res;
    }

    /**
     * Give the units back to available. Cancellation, expiry, a line removed.
     */
    public function release(TenantInventoryReservation $res, string $reason): void
    {
        if ($res->status !== TenantInventoryReservation::ACTIVE) {
            return; // already ended — idempotent, never double-counts
        }

        $loc = TenantInventoryItemLocation::where('inventory_item_id', $res->inventory_item_id)
            ->where('location_id', $res->location_id)
            ->lockForUpdate()
            ->first();

        $item = TenantInventoryItem::find($res->inventory_item_id);

        $res->forceFill([
            'status'          => TenantInventoryReservation::RELEASED,
            'released_reason' => $reason,
            'ended_at'        => now(),
        ])->save();

        if ($item) {
            $this->movement($res->tenant_id, $item, $res->location_id, 'release', -$res->quantity, $res->id,
                'Released: ' . $reason);
        }

        if ($loc) {
            $loc->reserved_count = max(0, $loc->reserved_count - $res->quantity);
            $loc->save();
        }
    }

    /**
     * The held units leave with the customer. Ends the reservation WITHOUT a
     * release movement: the sale's own decrement is what takes the stock, and
     * a release here would show available going up and down in the same second.
     * Called by InventoryService just before it decrements for the same line.
     */
    public function consume(TenantInventoryReservation $res): void
    {
        if ($res->status !== TenantInventoryReservation::ACTIVE) {
            return;
        }

        $loc = TenantInventoryItemLocation::where('inventory_item_id', $res->inventory_item_id)
            ->where('location_id', $res->location_id)
            ->lockForUpdate()
            ->first();

        $res->forceFill([
            'status'   => TenantInventoryReservation::CONSUMED,
            'ended_at' => now(),
        ])->save();

        if ($loc) {
            $loc->reserved_count = max(0, $loc->reserved_count - $res->quantity);
            $loc->save();
        }
    }

    /** Active reservations held for THIS sale line at this location. */
    public function activeFor(TenantSaleItem $line, string $locationId): \Illuminate\Support\Collection
    {
        return TenantInventoryReservation::active()
            ->where('sale_item_id', $line->id)
            ->where('location_id', $locationId)
            ->get();
    }

    /** Release everything a sale holds. Used on cancel. */
    public function releaseAllForSale(TenantSale $sale, string $reason): int
    {
        $n = 0;
        foreach (TenantInventoryReservation::active()->where('sale_id', $sale->id)->get() as $res) {
            $this->release($res, $reason);
            $n++;
        }

        return $n;
    }

    private function movement(string $tenantId, TenantInventoryItem $item, string $locationId,
                              string $type, int $delta, string $refId, string $note): void
    {
        DB::table('tenant_inventory_movements')->insert([
            'id'                 => (string) Str::uuid(),
            'tenant_id'          => $tenantId,
            'inventory_item_id'  => $item->id,
            'location_id'        => $locationId,
            'movement_type'      => $type,
            'reference_type'     => 'reservation',
            'reference_id'       => $refId,
            'quantity_delta'     => $delta,
            'item_name_snapshot' => $item->name,
            'item_sku_snapshot'  => $item->sku,
            'cost_cents_at_time' => $item->effectiveCostCents(),
            'tenant_user_id'     => \Illuminate\Support\Facades\Auth::guard('tenant')->id(),
            'reason'             => 'reservation',
            'notes'              => $note,
            'created_at'         => now(),
        ]);
    }
}
