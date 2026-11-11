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
    /**
     * $overrideReserved lets an owner sell a unit
     * held for someone else's layaway. It releases the blocking reservations
     * (a real, attributable movement on the other plan's history) rather than
     * quietly ignoring them, so the other plan's line is visibly without stock
     * instead of invisibly so.
     */
    public function decrementForSaleItem(TenantSale $sale, TenantSaleItem $item, string $locationId, bool $overrideReserved = false, ?int $qtyOverride = null): void
    {
        if ($item->type !== 'product' || !$item->inventory_item_id) {
            return; // service / open_item / gift_card lines don't touch inventory
        }

        // MARKER-ORDER-SHIPMENTS: shipped by a supplier; never on this shop's shelf
        if (! empty(((array) $item->metadata)['dropship'])) {
            return;
        }

        $qty = $qtyOverride ?? (int) ceil((float) $item->quantity);
        if ($qty <= 0) {
            return;
        }

        // MARKER-SO-HANDOVER: a line that is a special order. Units not here
        // yet take no stock now — they take it at handover — so a pre-paid
        // order no longer drives stock negative. Units that have arrived are
        // handed over now: their hold is used and the order is closed.
        // (A $qtyOverride call IS the handover of deferred units: skip this.)
        $handedOverSo = null;
        if ($qtyOverride === null) {
            $so = \App\Services\Tenant\SpecialOrderService::linkToSaleLine($sale, $item);
            if ($so) {
                if (in_array($so->status, [\App\Models\Tenant\TenantSpecialOrder::STATUS_NEEDED, \App\Models\Tenant\TenantSpecialOrder::STATUS_ORDERED], true)) {
                    $deferred = min((int) $so->quantity, $qty);
                    $so->forceFill(['line_stock_deferred' => true])->save();
                    $qty -= $deferred;
                    if ($qty <= 0) {
                        return;
                    }
                } elseif ($so->status === \App\Models\Tenant\TenantSpecialOrder::STATUS_ARRIVED) {
                    // its own hold may sit on the order rather than this line
                    app(ReservationService::class)->consumeForSpecialOrder($so->id);
                    $handedOverSo = $so;
                }
            }
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

        // a unit held for someone else is not available. If
        // this very line holds a reservation (a layaway completing), consume it
        // first so it does not block itself; then check against what remains
        // held for others. This is the one place the rule is enforced for
        // every sale path — register, draft commit, storefront.
        foreach (app(ReservationService::class)->activeFor($item, $locationId) as $own) {
            app(ReservationService::class)->consume($own);
        }
        $loc->refresh();

        $heldForOthers = (int) $loc->reserved_count;
        $newLocStock   = $loc->computed_stock_count - $qty;

        // take the hold off someone else's plan,
        // on purpose, with a movement row that says so. Only ever reached when
        // the capability was checked and the person confirmed the consequence.
        if ($overrideReserved && ($newLocStock - $heldForOthers) < 0 && $heldForOthers > 0) {
            $need = $heldForOthers - max(0, $newLocStock);
            $freed = 0;
            $affected = [];

            $blocking = \App\Models\Tenant\TenantInventoryReservation::active()
                ->where('inventory_item_id', $invItem->id)
                ->where('location_id', $locationId)
                // MARKER-SO-HANDOVER: a special-order hold has no sale; != alone skips NULL
                ->where(fn ($q) => $q->whereNull('sale_id')->orWhere('sale_id', '!=', $sale->id))
                ->orderByDesc('created_at') // the newest promise is the least settled
                ->get();

            foreach ($blocking as $res) {
                if ($freed >= $need) {
                    break;
                }
                app(ReservationService::class)->release($res, 'sold_to_another_customer');
                $freed += $res->quantity;
                $affected[] = $res->sale_id;
                if ($res->special_order_id) {
                    // MARKER-SO-HANDOVER: their part is gone; the order needs placing again
                    app(\App\Services\Tenant\SpecialOrderService::class)->backToNeeded(
                        $res->special_order_id, 'Its part was sold to another customer on ' . ($sale->sale_number ?: 'a register sale') . '. Order it again.'
                    );
                }
            }

            \Illuminate\Support\Facades\Log::warning('reserve-override: reserved stock sold to another customer', [
                'tenant'        => $sale->tenant_id,
                'item'          => $invItem->id,
                'item_name'     => $invItem->name,
                'location'      => $locationId,
                'sold_on_sale'  => $sale->id,
                'freed_units'   => $freed,
                'affected_sales'=> array_values(array_unique($affected)),
                'by'            => \Illuminate\Support\Facades\Auth::guard('tenant')->id(),
            ]);

            $loc->refresh();
            $heldForOthers = (int) $loc->reserved_count;
        }

        if (($newLocStock - $heldForOthers) < 0 && !$invItem->allow_oversell) {
            throw new InventoryStockException(
                "Insufficient stock for {$invItem->name} at this location: "
                . "have {$loc->computed_stock_count}, {$heldForOthers} held on layaway, need {$qty}."
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

        if ($handedOverSo) {
            app(\App\Services\Tenant\SpecialOrderService::class)->markHandedOver($handedOverSo, 'Handed over on sale ' . ($sale->sale_number ?: 'at the register') . '.');
        }
    }

    /**
     * Increment inventory for a refunded product line.
     */
    public function incrementForRefund(TenantSale $refund, TenantSaleItem $item, string $locationId, ?int $qtyOverride = null): void
    {
        if ($item->type !== 'product' || !$item->inventory_item_id) {
            return;
        }

        // MARKER-ORDER-SHIPMENTS: a supplier-shipped line never left this shop's shelf, so nothing goes back on it
        if (! empty(((array) $item->metadata)['dropship'])) {
            return;
        }

        // MARKER-SO-HANDOVER: a caller may return fewer units than the line —
        // special-order units that never left stock are not "returned".
        $qty = $qtyOverride ?? (int) ceil((float) $item->quantity);
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

        // MARKER-SO-HANDOVER: a part that is a special order. Not here yet →
        // it is not used now (the person already chose "complete without it");
        // it is used when it arrives and is handed over. Here → its hold is
        // used and the order is handed over with the job.
        $partSo = $part->special_order_id
            ? \App\Models\Tenant\TenantSpecialOrder::where('tenant_id', $appointment->tenant_id)->find($part->special_order_id)
            : null;
        if ($partSo && in_array($partSo->status, [\App\Models\Tenant\TenantSpecialOrder::STATUS_NEEDED, \App\Models\Tenant\TenantSpecialOrder::STATUS_ORDERED], true)) {
            return;
        }
        if ($partSo && $partSo->status === \App\Models\Tenant\TenantSpecialOrder::STATUS_ARRIVED) {
            // the part is used where it is held, whichever store the job is at
            $heldAt = app(ReservationService::class)->activeForSpecialOrder($partSo->id)->first()?->location_id;
            if ($heldAt) {
                $locationId = $heldAt;
            }
            app(ReservationService::class)->consumeForSpecialOrder($partSo->id);
        } else {
            $partSo = null;
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

        // a job pulling a part cannot take a unit that is
        // held for a layaway. Same rule as sales; parts hold nothing of their
        // own so there is nothing to consume first.
        $heldForOthers = (int) $loc->reserved_count;
        $newLocStock   = $loc->computed_stock_count - $qty;

        if (($newLocStock - $heldForOthers) < 0 && !$invItem->allow_oversell) {
            throw new InventoryStockException(
                "Insufficient stock for {$invItem->name} at this location: "
                . "have {$loc->computed_stock_count}, {$heldForOthers} held on layaway, need {$qty}."
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

        if ($partSo) {
            app(\App\Services\Tenant\SpecialOrderService::class)->markHandedOver($partSo, 'Used on work order ' . ($appointment->ra_number ?: '') . '.');
        }
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

        // MARKER-SO-HANDOVER: give it back where it was taken (a held special
        // order is used at the store holding it, which may not be the job's).
        $takenAt = DB::table('tenant_inventory_movements')
            ->where('reference_type', 'appointment_part')->where('reference_id', $part->id)
            ->where('movement_type', 'appointment')
            ->orderByDesc('created_at')->value('location_id');
        if ($takenAt) {
            $locationId = $takenAt;
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

        // MARKER-SO-HANDOVER: the job was reopened, so a special-order part it
        // used is back on the bench for this customer, held again.
        if ($part->special_order_id) {
            app(\App\Services\Tenant\SpecialOrderService::class)->backToArrived($part->special_order_id, $locationId, 'Back on the bench: work order ' . ($appointment->ra_number ?: '') . ' was reopened.');
        }
    }
}
