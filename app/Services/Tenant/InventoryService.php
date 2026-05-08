<?php

namespace App\Services\Tenant;

use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantAppointmentPart;
use App\Models\Tenant\TenantInventoryItem;
use App\Models\Tenant\TenantInventoryItemLocation;
use App\Models\Tenant\TenantSale;
use App\Models\Tenant\TenantSaleItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InventoryService
{
    /**
     * Decrement inventory for a sold product line.
     *
     * Atomically:
     *   - lockForUpdate on tenant_inventory_item_locations row (prevents oversell race)
     *   - validate stock unless item.allow_oversell
     *   - append tenant_inventory_movements row (append-only audit log)
     *   - decrement tenant_inventory_item_locations.computed_stock_count
     *   - decrement tenant_inventory_items.computed_stock_count (tenant-aggregate cache)
     *
     * Caller MUST already be inside a DB::transaction() — this method does
     * NOT open its own. Laravel's nested transaction support means the caller's
     * commit is what makes these writes durable; if the caller rolls back,
     * the inventory changes roll back with the sale.
     *
     * Throws InventoryStockException if stock would go negative and the
     * item does not allow_oversell.
     */
    public function decrementForSaleItem(TenantSale $sale, TenantSaleItem $item, string $locationId): void
    {
        if ($item->type !== 'product' || !$item->inventory_item_id) {
            return; // service / open_item / gift_card lines don't touch inventory
        }

        $qty = (int) ceil((float) $item->quantity);
        if ($qty <= 0) {
            return;
        }

        $invItem = TenantInventoryItem::where('id', $item->inventory_item_id)
            ->where('tenant_id', $sale->tenant_id)
            ->first();

        if (!$invItem) {
            throw new InventoryStockException(
                "Inventory item not found for sale line {$item->id}."
            );
        }

        $loc = TenantInventoryItemLocation::where('inventory_item_id', $invItem->id)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->first();

        if (!$loc) {
            $loc = TenantInventoryItemLocation::create([
                'tenant_id'            => $sale->tenant_id,
                'inventory_item_id'    => $invItem->id,
                'location_id'          => $locationId,
                'computed_stock_count' => 0,
                'is_active'            => true,
            ]);
            $loc = TenantInventoryItemLocation::where('id', $loc->id)->lockForUpdate()->first();
        }

        $newLocStock = $loc->computed_stock_count - $qty;

        if ($newLocStock < 0 && !$invItem->allow_oversell) {
            throw new InventoryStockException(
                "Insufficient stock for {$invItem->name} at this location: "
                . "have {$loc->computed_stock_count}, need {$qty}."
            );
        }

        DB::table('tenant_inventory_movements')->insert([
            'id'                  => (string) Str::uuid(),
            'tenant_id'           => $sale->tenant_id,
            'inventory_item_id'   => $invItem->id,
            'quantity_delta'      => -$qty,
            'movement_type'       => 'sale',
            'reference_type'      => 'sale_item',
            'reference_id'        => $item->id,
            'item_name_snapshot'  => $invItem->name,
            'item_sku_snapshot'   => $invItem->sku,
            'cost_cents_at_time'  => $invItem->effectiveCostCents(),
            'location_id'         => $locationId,
            'reason'              => null,
            'notes'               => null,
            'created_at'          => now(),
        ]);

        $loc->computed_stock_count = $newLocStock;
        $loc->save();

        $invItem->decrement('computed_stock_count', $qty);
    }

    /**
     * Increment inventory for a refunded product line.
     */
    public function incrementForRefund(TenantSale $refund, TenantSaleItem $item, string $locationId): void
    {
        if ($item->type !== 'product' || !$item->inventory_item_id) {
            return;
        }

        $qty = (int) ceil((float) $item->quantity);
        if ($qty <= 0) {
            return;
        }

        $invItem = TenantInventoryItem::where('id', $item->inventory_item_id)
            ->where('tenant_id', $refund->tenant_id)
            ->first();

        if (!$invItem) {
            return;
        }

        $loc = TenantInventoryItemLocation::where('inventory_item_id', $invItem->id)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->first();

        if (!$loc) {
            $loc = TenantInventoryItemLocation::create([
                'tenant_id'            => $refund->tenant_id,
                'inventory_item_id'    => $invItem->id,
                'location_id'          => $locationId,
                'computed_stock_count' => 0,
                'is_active'            => true,
            ]);
            $loc = TenantInventoryItemLocation::where('id', $loc->id)->lockForUpdate()->first();
        }

        DB::table('tenant_inventory_movements')->insert([
            'id'                  => (string) Str::uuid(),
            'tenant_id'           => $refund->tenant_id,
            'inventory_item_id'   => $invItem->id,
            'quantity_delta'      => $qty,
            'movement_type'       => 'refund',
            'reference_type'      => 'sale_item',
            'reference_id'        => $item->id,
            'item_name_snapshot'  => $invItem->name,
            'item_sku_snapshot'   => $invItem->sku,
            'cost_cents_at_time'  => $invItem->effectiveCostCents(),
            'location_id'         => $locationId,
            'reason'              => null,
            'notes'               => null,
            'created_at'          => now(),
        ]);

        $loc->computed_stock_count = $loc->computed_stock_count + $qty;
        $loc->save();

        $invItem->increment('computed_stock_count', $qty);
    }

    // -------------------------------------------------------------------
    // Appointment parts: same atomic pattern as sale lines, but writes
    // movement_type='appointment' / 'appointment_refund' and a different
    // reference_type so the audit log is honest.
    //
    // Caller MUST be inside a DB::transaction(). Same contract as sale
    // methods above.
    // -------------------------------------------------------------------

    /**
     * Decrement inventory for an appointment part on completion.
     * Sets the part's committed_at so the inverse path knows what to undo.
     *
     * Throws InventoryStockException if stock would go negative and the
     * item does not allow_oversell.
     */
    public function decrementForAppointmentPart(
        TenantAppointment $appointment,
        TenantAppointmentPart $part,
        string $locationId
    ): void {
        if (!$part->inventory_item_id) {
            return; // ad-hoc parts (no inventory link) can't move stock
        }
        if ($part->isCommitted()) {
            return; // already decremented; idempotent guard
        }

        $qty = (int) $part->quantity;
        if ($qty <= 0) {
            return;
        }

        $invItem = TenantInventoryItem::where('id', $part->inventory_item_id)
            ->where('tenant_id', $appointment->tenant_id)
            ->first();

        if (!$invItem) {
            throw new InventoryStockException(
                "Inventory item not found for appointment part {$part->id}."
            );
        }

        $loc = TenantInventoryItemLocation::where('inventory_item_id', $invItem->id)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->first();

        if (!$loc) {
            $loc = TenantInventoryItemLocation::create([
                'tenant_id'            => $appointment->tenant_id,
                'inventory_item_id'    => $invItem->id,
                'location_id'          => $locationId,
                'computed_stock_count' => 0,
                'is_active'            => true,
            ]);
            $loc = TenantInventoryItemLocation::where('id', $loc->id)->lockForUpdate()->first();
        }

        $newLocStock = $loc->computed_stock_count - $qty;

        if ($newLocStock < 0 && !$invItem->allow_oversell) {
            throw new InventoryStockException(
                "Insufficient stock for {$invItem->name} at this location: "
                . "have {$loc->computed_stock_count}, need {$qty}."
            );
        }

        DB::table('tenant_inventory_movements')->insert([
            'id'                  => (string) Str::uuid(),
            'tenant_id'           => $appointment->tenant_id,
            'inventory_item_id'   => $invItem->id,
            'quantity_delta'      => -$qty,
            'movement_type'       => 'appointment',
            'reference_type'      => 'appointment_part',
            'reference_id'        => $part->id,
            'item_name_snapshot'  => $invItem->name,
            'item_sku_snapshot'   => $invItem->sku,
            'cost_cents_at_time'  => $invItem->effectiveCostCents(),
            'location_id'         => $locationId,
            'reason'              => null,
            'notes'               => null,
            'created_at'          => now(),
        ]);

        $loc->computed_stock_count = $newLocStock;
        $loc->save();

        $invItem->decrement('computed_stock_count', $qty);

        $part->committed_at = now();
        $part->save();
    }

    /**
     * Increment inventory for a previously-committed appointment part.
     * Used when:
     *   - the appointment is refunded
     *   - the appointment is cancelled while in completed/shipped/closed
     *   - the appointment is moved back to a pre-completion status
     * Clears committed_at so the part can be re-decremented if the
     * appointment moves forward again.
     */
    public function incrementForAppointmentPart(
        TenantAppointment $appointment,
        TenantAppointmentPart $part,
        string $locationId
    ): void {
        if (!$part->inventory_item_id) {
            return;
        }
        if (!$part->isCommitted()) {
            return; // wasn't decremented; nothing to give back
        }

        $qty = (int) $part->quantity;
        if ($qty <= 0) {
            return;
        }

        $invItem = TenantInventoryItem::where('id', $part->inventory_item_id)
            ->where('tenant_id', $appointment->tenant_id)
            ->first();

        if (!$invItem) {
            // Catalog row gone — clear committed_at anyway so we don't
            // try forever, but log will show the orphan via item_name_snapshot.
            $part->committed_at = null;
            $part->save();
            return;
        }

        $loc = TenantInventoryItemLocation::where('inventory_item_id', $invItem->id)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->first();

        if (!$loc) {
            $loc = TenantInventoryItemLocation::create([
                'tenant_id'            => $appointment->tenant_id,
                'inventory_item_id'    => $invItem->id,
                'location_id'          => $locationId,
                'computed_stock_count' => 0,
                'is_active'            => true,
            ]);
            $loc = TenantInventoryItemLocation::where('id', $loc->id)->lockForUpdate()->first();
        }

        DB::table('tenant_inventory_movements')->insert([
            'id'                  => (string) Str::uuid(),
            'tenant_id'           => $appointment->tenant_id,
            'inventory_item_id'   => $invItem->id,
            'quantity_delta'      => $qty,
            'movement_type'       => 'appointment_refund',
            'reference_type'      => 'appointment_part',
            'reference_id'        => $part->id,
            'item_name_snapshot'  => $invItem->name,
            'item_sku_snapshot'   => $invItem->sku,
            'cost_cents_at_time'  => $invItem->effectiveCostCents(),
            'location_id'         => $locationId,
            'reason'              => null,
            'notes'               => null,
            'created_at'          => now(),
        ]);

        $loc->computed_stock_count = $loc->computed_stock_count + $qty;
        $loc->save();

        $invItem->increment('computed_stock_count', $qty);

        $part->committed_at = null;
        $part->save();
    }
}
