#!/bin/bash
# transfer-movements-fix — inventory audit transfer findings, plus a fatal
# the audit did not catch.
#   1. RECEIVING A TRANSFER ALWAYS FATALED. markReceived() called
#      incrementStock(reason: ...) but that method has no $reason parameter,
#      so PHP threw "Unknown named parameter $reason" every time. The reason
#      text now rides in notes. (Verified by reflection against the real
#      signature; every named argument in this file is now checked valid.)
#   2. Movement types were wrong: transfer-out was written as a SALE and
#      transfer-in as a RECEIVE, polluting sales reporting and audit history.
#      decrementStock gains an optional $movementType (default 'sale', so no
#      other caller changes) and transfers now write transfer_out /
#      transfer_in — the types the movements enum has always supported.
#   3. Quantity sent could exceed quantity requested (a request for 1 could
#      dispatch 100). Now capped at the requested quantity; sending less is
#      still a legitimate partial.
#   4. Cancelling an IN-TRANSIT transfer silently stranded stock — it had been
#      deducted from the source and was never returned. Cancel now restores
#      the sent quantity to the source location with its own transfer_in
#      movement, under the same tenant assertions as the rest of the service.
# No routes, no migrations.
set -e
cd "$(git rev-parse --show-toplevel)"
if grep -q "MARKER-TRANSFER-MOVEMENTS" app/Services/Tenant/TransferRequestService.php; then
  echo "transfer-movements-fix already applied — aborting."; exit 1
fi
if ! grep -q "MARKER-TRANSFER-SCOPE" app/Services/Tenant/TransferRequestService.php; then
  echo "wrong base (transfer-scope fix missing) — aborting."; exit 1
fi

cat > 'app/Services/Pos/InventoryService.php' <<'TMOV_0_EOF'
<?php

namespace App\Services\Pos;

use App\Events\Pos\InventoryStockChanged;
use App\Events\Pos\LowStockReached;
use App\Events\Pos\OversoldStock;
use App\Exceptions\Pos\InsufficientStockException;
use App\Exceptions\Pos\InvalidQuantityException;
use App\Exceptions\Pos\TenantMismatchException;
use App\Models\Tenant;
use App\Models\Tenant\TenantInventoryItem;
use App\Models\Tenant\TenantInventoryItemLocation;
use App\Models\Tenant\TenantInventoryMovement;
use App\Models\Tenant\TenantLocation;
use App\Models\Tenant\TenantUser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * InventoryService — the only authorized writer to inventory stock.
 *
 * INVARIANTS THIS SERVICE ENFORCES:
 *
 * 1. Movements are the source of truth. Stock count = SUM(quantity_delta)
 *    for an item AND location. The computed_stock_count cache columns are
 *    maintained by this service alone.
 *
 * 2. Atomicity per operation. Every public method runs inside DB::transaction.
 *    The movement row, the per-location cache, and the tenant-aggregate cache
 *    are all written in the same transaction. If anything fails, all roll back.
 *
 * 3. Row-level locking on cache writes. SELECT ... FOR UPDATE on the
 *    item_location row before reading current stock. Serializes concurrent
 *    operations on the same (item, location).
 *
 * 4. Tenant scoping enforced. Every method asserts the item and location
 *    belong to the passed tenant. Cross-tenant operations throw immediately.
 *
 * 5. Snapshot on write. Every movement captures item name, SKU, and the
 *    effective cost at the moment of the movement.
 *
 * 6. Forgiving on missing rows. If an item has no row in
 *    tenant_inventory_item_locations for a given location, decrement
 *    auto-creates it with stock=0 rather than throwing. The register
 *    never blocks the cashier on missing-row issues.
 *
 * 7. Deterministic locking on transfers. Transfers lock both location
 *    rows in ascending UUID order to prevent deadlocks between concurrent
 *    transfers in opposite directions.
 *
 * 8. Events fire on success. InventoryStockChanged fires after every write.
 *    LowStockReached fires when a decrement crosses below threshold.
 *    OversoldStock fires when a decrement goes negative.
 */
class InventoryService
{
    // ─── Public API: Stock Mutations ──────────────────────────────────

    /**
     * Decrement stock for an item at a location.
     *
     * Called by: PosSaleService on commit, future RefundService for non-restock refunds.
     *
     * @throws TenantMismatchException
     * @throws InvalidQuantityException
     * @throws InsufficientStockException When stock would go negative AND allow_oversell=false
     */
    public function decrementStock(
        Tenant $tenant,
        TenantInventoryItem $item,
        TenantLocation $location,
        int $quantity,
        string $referenceType,
        ?string $referenceId,
        ?TenantUser $tenantUser = null,
        ?string $reason = null,
        ?string $notes = null,
        // MARKER-TRANSFER-MOVEMENTS — callers that are not sales (transfers)
        // can label their own movement. Defaults to the historical 'sale'.
        ?string $movementType = 'sale',
    ): TenantInventoryMovement {
        $this->assertTenantOwnsResources($tenant, $item, $location);
        $this->assertPositiveQuantity($quantity);

        return DB::transaction(function () use (
            $tenant, $item, $location, $quantity, $referenceType,
            $referenceId, $tenantUser, $reason, $notes, $movementType
        ) {
            $itemLocation = $this->getOrCreateItemLocationLocked($tenant, $item, $location);
            $stockBefore = $itemLocation->computed_stock_count;
            $stockAfter = $stockBefore - $quantity;
            $wasAboveThreshold = !$itemLocation->isLowStock();

            // Refuse if oversell would happen on a strict item
            if ($stockAfter < 0 && !$item->allow_oversell) {
                throw new InsufficientStockException($item, $location, $stockBefore, $quantity);
            }

            $movement = $this->writeMovement(
                tenant: $tenant,
                item: $item,
                location: $location,
                quantityDelta: -$quantity,
                movementType: $movementType ?: 'sale', // MARKER-TRANSFER-MOVEMENTS
                referenceType: $referenceType,
                referenceId: $referenceId,
                tenantUser: $tenantUser,
                costCentsAtTime: $item->effectiveCostCents(),
                reason: $reason,
                notes: $notes,
            );

            $this->applyDeltaToCaches($item, $itemLocation, -$quantity);

            // Fire events AFTER cache updates so listeners see fresh state
            event(new InventoryStockChanged($movement));

            // Refresh the item location to reflect the new count for low-stock check
            $itemLocation->refresh();
            $isNowBelowThreshold = $itemLocation->isLowStock();
            if ($wasAboveThreshold && $isNowBelowThreshold) {
                event(new LowStockReached($itemLocation));
            }

            // Oversold event fires only when we genuinely went negative
            if ($stockAfter < 0) {
                event(new OversoldStock($movement, $stockBefore, $stockAfter));
            }

            return $movement;
        });
    }

    /**
     * Increment stock for an item at a location.
     *
     * Called by: receive shipment commit, refund-with-restock, any positive correction.
     *
     * costCentsAtTime should reflect the actual landed cost for receives —
     * for restocks from refund, pass the original sale's cost-at-time.
     */
    public function incrementStock(
        Tenant $tenant,
        TenantInventoryItem $item,
        TenantLocation $location,
        int $quantity,
        string $referenceType,
        ?string $referenceId,
        ?TenantUser $tenantUser = null,
        ?int $costCentsAtTime = null,
        ?string $movementType = 'receive',
        ?string $notes = null,
    ): TenantInventoryMovement {
        $this->assertTenantOwnsResources($tenant, $item, $location);
        $this->assertPositiveQuantity($quantity);

        return DB::transaction(function () use (
            $tenant, $item, $location, $quantity, $referenceType,
            $referenceId, $tenantUser, $costCentsAtTime, $movementType, $notes
        ) {
            $itemLocation = $this->getOrCreateItemLocationLocked($tenant, $item, $location);

            $movement = $this->writeMovement(
                tenant: $tenant,
                item: $item,
                location: $location,
                quantityDelta: $quantity,
                movementType: $movementType,
                referenceType: $referenceType,
                referenceId: $referenceId,
                tenantUser: $tenantUser,
                costCentsAtTime: $costCentsAtTime ?? $item->effectiveCostCents(),
                notes: $notes,
            );

            $this->applyDeltaToCaches($item, $itemLocation, $quantity);

            event(new InventoryStockChanged($movement));

            return $movement;
        });
    }

    /**
     * Transfer stock between two locations within the same tenant.
     *
     * Writes paired movements: transfer_out at source, transfer_in at destination.
     * Both share the same reference_id (a generated transfer UUID) so they can
     * be reported as a unit.
     *
     * Locks rows in ascending location UUID order to prevent deadlocks.
     *
     * @return array{0: TenantInventoryMovement, 1: TenantInventoryMovement}
     */
    public function transferStock(
        Tenant $tenant,
        TenantInventoryItem $item,
        TenantLocation $fromLocation,
        TenantLocation $toLocation,
        int $quantity,
        ?TenantUser $tenantUser = null,
        ?string $notes = null,
    ): array {
        $this->assertTenantOwnsResources($tenant, $item, $fromLocation);
        $this->assertTenantOwnsResources($tenant, $item, $toLocation);
        $this->assertPositiveQuantity($quantity);

        if ($fromLocation->id === $toLocation->id) {
            throw new InvalidQuantityException('Cannot transfer to the same location.');
        }

        $transferId = (string) Str::uuid();

        return DB::transaction(function () use (
            $tenant, $item, $fromLocation, $toLocation, $quantity, $tenantUser, $notes, $transferId
        ) {
            // Deterministic lock order: lock both rows sorted by location UUID
            $locations = collect([$fromLocation, $toLocation])
                ->sortBy('id')
                ->values();

            $firstLocked = $this->getOrCreateItemLocationLocked($tenant, $item, $locations[0]);
            $secondLocked = $this->getOrCreateItemLocationLocked($tenant, $item, $locations[1]);

            // Map back to from/to for write logic
            $fromItemLocation = $firstLocked->location_id === $fromLocation->id ? $firstLocked : $secondLocked;
            $toItemLocation = $firstLocked->location_id === $toLocation->id ? $firstLocked : $secondLocked;

            // Source decrement check — refuse if oversell off and would go negative
            $stockAfterFrom = $fromItemLocation->computed_stock_count - $quantity;
            if ($stockAfterFrom < 0 && !$item->allow_oversell) {
                throw new InsufficientStockException($item, $fromLocation, $fromItemLocation->computed_stock_count, $quantity);
            }

            $costAtTime = $item->effectiveCostCents();

            // Write OUT movement
            $outMovement = $this->writeMovement(
                tenant: $tenant,
                item: $item,
                location: $fromLocation,
                quantityDelta: -$quantity,
                movementType: 'transfer_out',
                referenceType: 'transfer',
                referenceId: $transferId,
                tenantUser: $tenantUser,
                costCentsAtTime: $costAtTime,
                notes: $notes,
            );
            $this->applyDeltaToCaches($item, $fromItemLocation, -$quantity);

            // Write IN movement
            $inMovement = $this->writeMovement(
                tenant: $tenant,
                item: $item,
                location: $toLocation,
                quantityDelta: $quantity,
                movementType: 'transfer_in',
                referenceType: 'transfer',
                referenceId: $transferId,
                tenantUser: $tenantUser,
                costCentsAtTime: $costAtTime,
                notes: $notes,
            );
            $this->applyDeltaToCaches($item, $toItemLocation, $quantity);

            // NOTE: tenant-aggregate cache is unchanged for transfers — the totals
            // across locations stay the same. We pass only the per-location delta
            // to applyDeltaToCaches and skip the aggregate update inside it for
            // transfer movements. (Implemented inside applyDeltaToCaches.)

            event(new InventoryStockChanged($outMovement));
            event(new InventoryStockChanged($inMovement));

            return [$outMovement, $inMovement];
        });
    }

    /**
     * Manual stock adjustment — sets the absolute count for an item at a location.
     *
     * Writes a single movement with quantity_delta = newCount - currentStock.
     * Reason is REQUIRED for audit clarity ("damaged in transit", "found in storeroom").
     */
    public function adjustStock(
        Tenant $tenant,
        TenantInventoryItem $item,
        TenantLocation $location,
        int $newCount,
        string $reason,
        ?TenantUser $tenantUser = null,
        ?string $notes = null,
    ): TenantInventoryMovement {
        $this->assertTenantOwnsResources($tenant, $item, $location);

        if ($newCount < 0) {
            throw new InvalidQuantityException('Adjustment newCount must be non-negative.');
        }

        if (trim($reason) === '') {
            throw new InvalidQuantityException('Adjustment requires a non-empty reason.');
        }

        return DB::transaction(function () use (
            $tenant, $item, $location, $newCount, $reason, $tenantUser, $notes
        ) {
            $itemLocation = $this->getOrCreateItemLocationLocked($tenant, $item, $location);
            $delta = $newCount - $itemLocation->computed_stock_count;

            $movement = $this->writeMovement(
                tenant: $tenant,
                item: $item,
                location: $location,
                quantityDelta: $delta,
                movementType: 'adjustment',
                referenceType: 'manual',
                referenceId: null,
                tenantUser: $tenantUser,
                costCentsAtTime: $item->effectiveCostCents(),
                reason: $reason,
                notes: $notes,
            );

            $this->applyDeltaToCaches($item, $itemLocation, $delta);

            event(new InventoryStockChanged($movement));

            return $movement;
        });
    }

    /**
     * Set initial stock for an item at a location.
     *
     * Used during item creation flow when the tenant says "I have 50 of these
     * at Downtown right now." Writes an 'initial' movement, idempotent enough
     * that calling it twice would write two initial movements (which is the
     * correct audit trail — the second initial is its own event).
     */
    public function recordInitialStock(
        Tenant $tenant,
        TenantInventoryItem $item,
        TenantLocation $location,
        int $quantity,
        ?TenantUser $tenantUser = null,
    ): TenantInventoryMovement {
        return $this->incrementStock(
            tenant: $tenant,
            item: $item,
            location: $location,
            quantity: $quantity,
            referenceType: 'manual',
            referenceId: null,
            tenantUser: $tenantUser,
            movementType: 'initial',
            notes: 'Initial stock recorded',
        );
    }

    // ─── Public API: Stock Reads ───────────────────────────────────────

    /**
     * Get current stock from the source-of-truth (sum of movements).
     *
     * Slower than the cache — use for verification / reconciliation, not
     * hot-path register reads.
     *
     * Pass null location to get tenant-wide total across all locations.
     */
    public function getCurrentStock(
        TenantInventoryItem $item,
        ?TenantLocation $location = null,
    ): int {
        $query = TenantInventoryMovement::query()
            ->where('inventory_item_id', $item->id);

        if ($location !== null) {
            $query->where('location_id', $location->id);
        }

        return (int) $query->sum('quantity_delta');
    }

    /**
     * Get current stock from the cache.
     *
     * Fast — hot-path register reads use this.
     */
    public function getCurrentStockFromCache(
        TenantInventoryItem $item,
        ?TenantLocation $location = null,
    ): int {
        if ($location === null) {
            return (int) $item->computed_stock_count;
        }

        $itemLocation = TenantInventoryItemLocation::query()
            ->where('inventory_item_id', $item->id)
            ->where('location_id', $location->id)
            ->first();

        return $itemLocation ? (int) $itemLocation->computed_stock_count : 0;
    }

    /**
     * Compare cache to truth. Returns drift = actual - cached.
     * Drift of zero means cache is healthy. Anything else is a bug to investigate.
     *
     * @return array{cached: int, actual: int, drift: int}
     */
    public function reconcileStock(
        TenantInventoryItem $item,
        ?TenantLocation $location = null,
    ): array {
        $cached = $this->getCurrentStockFromCache($item, $location);
        $actual = $this->getCurrentStock($item, $location);

        return [
            'cached' => $cached,
            'actual' => $actual,
            'drift' => $actual - $cached,
        ];
    }

    /**
     * All items at or below their reorder threshold.
     *
     * Pass null location for tenant-wide low stock (any location below threshold).
     */
    public function lowStockItems(
        Tenant $tenant,
        ?TenantLocation $location = null,
    ): Collection {
        $query = TenantInventoryItemLocation::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->whereHas('item', fn ($q) => $q->where('is_active', true))
            ->with(['item', 'location']);

        if ($location !== null) {
            $query->where('location_id', $location->id);
        }

        return $query->get()->filter(fn ($il) => $il->isLowStock())->values();
    }

    // ─── Internal: Locking & Writes ────────────────────────────────────

    /**
     * Get the item-location row with a row-level lock, creating it if missing.
     *
     * Forgiving: if no row exists, create with stock=0 rather than throwing.
     * This means decrement can never fail because of a missing row.
     */
    protected function getOrCreateItemLocationLocked(
        Tenant $tenant,
        TenantInventoryItem $item,
        TenantLocation $location,
    ): TenantInventoryItemLocation {
        // First try with a row-level lock
        $itemLocation = TenantInventoryItemLocation::query()
            ->where('inventory_item_id', $item->id)
            ->where('location_id', $location->id)
            ->lockForUpdate()
            ->first();

        if ($itemLocation !== null) {
            return $itemLocation;
        }

        // Forgiving path — create with stock=0
        $itemLocation = TenantInventoryItemLocation::create([
            'tenant_id' => $tenant->id,
            'inventory_item_id' => $item->id,
            'location_id' => $location->id,
            'computed_stock_count' => 0,
            'shop_reorder_threshold' => null,
            'shop_reorder_quantity' => null,
            'shop_bin_location' => null,
            'is_active' => true,
        ]);

        // Re-acquire with lock for the rest of the transaction
        return TenantInventoryItemLocation::query()
            ->where('id', $itemLocation->id)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Apply a delta to both caches: per-location and tenant-aggregate.
     *
     * Transfer movements (transfer_out, transfer_in) update only the
     * per-location cache — tenant-aggregate is unchanged because totals
     * across locations stay the same.
     */
    protected function applyDeltaToCaches(
        TenantInventoryItem $item,
        TenantInventoryItemLocation $itemLocation,
        int $delta,
    ): void {
        // Per-location cache always updates
        $itemLocation->increment('computed_stock_count', $delta);

        // Tenant-aggregate updates only when the movement actually changes
        // total stock for the item across the tenant. Transfers don't.
        // We can detect transfer movements by checking the most recent
        // movement type, but it's cleaner to always recompute the aggregate
        // from the per-location rows after the per-location update.
        $totalAcrossLocations = (int) TenantInventoryItemLocation::query()
            ->where('inventory_item_id', $item->id)
            ->sum('computed_stock_count');

        $item->update(['computed_stock_count' => $totalAcrossLocations]);
    }

    /**
     * Write a single movement row with all the required snapshot fields.
     */
    protected function writeMovement(
        Tenant $tenant,
        TenantInventoryItem $item,
        TenantLocation $location,
        int $quantityDelta,
        string $movementType,
        string $referenceType,
        ?string $referenceId,
        ?TenantUser $tenantUser,
        ?int $costCentsAtTime,
        ?string $reason = null,
        ?string $notes = null,
    ): TenantInventoryMovement {
        // Use direct insert because the model overrides save/update for
        // append-only enforcement. We need a one-shot insert that bypasses
        // those guards safely (we are the service that's allowed to write).
        $id = (string) Str::uuid();

        DB::table('tenant_inventory_movements')->insert([
            'id' => $id,
            'tenant_id' => $tenant->id,
            'inventory_item_id' => $item->id,
            'location_id' => $location->id,
            'quantity_delta' => $quantityDelta,
            'movement_type' => $movementType,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'item_name_snapshot' => $item->name,
            'item_sku_snapshot' => $item->sku,
            'cost_cents_at_time' => $costCentsAtTime,
            'reason' => $reason,
            'notes' => $notes,
            'tenant_user_id' => $tenantUser?->id,
            'created_at' => now(),
        ]);

        return TenantInventoryMovement::find($id);
    }

    // ─── Internal: Tenant scope assertions ─────────────────────────────

    protected function assertTenantOwnsResources(
        Tenant $tenant,
        TenantInventoryItem $item,
        TenantLocation $location,
    ): void {
        if ($item->tenant_id !== $tenant->id) {
            throw new TenantMismatchException("Item {$item->id} does not belong to tenant {$tenant->id}.");
        }
        if ($location->tenant_id !== $tenant->id) {
            throw new TenantMismatchException("Location {$location->id} does not belong to tenant {$tenant->id}.");
        }
    }

    protected function assertPositiveQuantity(int $quantity): void
    {
        if ($quantity <= 0) {
            throw new InvalidQuantityException("Quantity must be a positive integer, got {$quantity}.");
        }
    }
}
TMOV_0_EOF

cat > 'app/Services/Tenant/TransferRequestService.php' <<'TMOV_1_EOF'
<?php

namespace App\Services\Tenant;

use App\Models\Tenant\TenantInventoryItem;
use App\Models\Tenant\TenantInventoryItemLocation;
use App\Models\Tenant\TenantTransferRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * patch-100a: lightweight service for creating + closing
 * transfer requests from the register and (later) admin UI.
 */
class TransferRequestService
{
    /**
     * Create a pending transfer request.
     *
     * Required: tenant_id, inventory_item_id, to_location_id, quantity.
     * Optional: from_location_id (auto-suggested if omitted),
     *           requested_by_user_id, sale_id, notes.
     */
    public function create(array $data): TenantTransferRequest
    {
        foreach (['tenant_id', 'inventory_item_id', 'to_location_id', 'quantity'] as $req) {
            if (empty($data[$req])) {
                throw new InvalidArgumentException("{$req} is required.");
            }
        }

        return DB::transaction(function () use ($data) {
            // MARKER-TRANSFER-SCOPE — every id crossing a request boundary
            // proves ownership before anything reads or writes through it.
            // Previously only presence was checked, so a foreign item id
            // could seed a transfer whose source auto-suggest then selected
            // ANOTHER TENANT'S stock location.
            $tenantId = (string) $data['tenant_id'];
            self::assertOwned(TenantInventoryItem::class, $data['inventory_item_id'], $tenantId, 'inventory item');
            self::assertOwned(\App\Models\Tenant\TenantLocation::class, $data['to_location_id'], $tenantId, 'destination location');
            if (!empty($data['from_location_id'])) {
                self::assertOwned(\App\Models\Tenant\TenantLocation::class, $data['from_location_id'], $tenantId, 'source location');
            }
            if (!empty($data['sale_id'])) {
                self::assertOwned(\App\Models\Tenant\TenantSale::class, $data['sale_id'], $tenantId, 'sale');
            }

            // Auto-suggest a source location: any OTHER location with positive stock.
            $fromLocationId = $data['from_location_id'] ?? null;
            if (!$fromLocationId) {
                $candidate = TenantInventoryItemLocation::where('tenant_id', $tenantId) // MARKER-TRANSFER-SCOPE
                    ->where('inventory_item_id', $data['inventory_item_id'])
                    ->where('location_id', '!=', $data['to_location_id'])
                    ->where('computed_stock_count', '>', 0)
                    ->orderByDesc('computed_stock_count')
                    ->first();
                $fromLocationId = $candidate?->location_id;
            }

            return TenantTransferRequest::create([
                'id'                   => (string) Str::uuid(),
                'tenant_id'            => $data['tenant_id'],
                'inventory_item_id'    => $data['inventory_item_id'],
                'to_location_id'       => $data['to_location_id'],
                'from_location_id'     => $fromLocationId,
                'quantity'             => max(1, (int) $data['quantity']),
                'requested_by_user_id' => $data['requested_by_user_id'] ?? null,
                'sale_id'              => $data['sale_id'] ?? null,
                'status'               => TenantTransferRequest::STATUS_PENDING,
                'notes'                => $data['notes'] ?? null,
            ]);
        });
    }

    /**
     * patch-102 markSent — source location sends the items.
     * Decrements source stock, sets status=in_transit, records
     * quantity_sent (may be partial), sent_at, sent_by.
     */
    public function markSent(string $id, int $quantitySent, ?string $byUserId = null): TenantTransferRequest
    {
        return DB::transaction(function () use ($id, $quantitySent, $byUserId) {
            $tr = TenantTransferRequest::lockForUpdate()->findOrFail($id);
            if ($tr->status !== TenantTransferRequest::STATUS_PENDING) {
                throw new InvalidArgumentException("Transfer request is not pending (status={$tr->status}).");
            }
            if ($quantitySent < 1) {
                throw new InvalidArgumentException('Quantity sent must be at least 1.');
            }
            // MARKER-TRANSFER-MOVEMENTS — a request for 1 could be dispatched
            // as 100. Sending less than requested is a legitimate partial.
            if ($quantitySent > (int) $tr->quantity) {
                throw new InvalidArgumentException(sprintf(
                    'Cannot send %d — only %d were requested.',
                    $quantitySent,
                    (int) $tr->quantity
                ));
            }
            if (!$tr->from_location_id) {
                throw new InvalidArgumentException('Transfer request has no source location set.');
            }

            // MARKER-TRANSFER-SCOPE — the tenant comes from the transfer row
            // itself, never inferred from a related record, and both the item
            // and the source location must belong to it. Defense in depth: a
            // row poisoned before this fix can no longer move foreign stock.
            $tr->load('inventoryItem', 'fromLocation');
            $item = $tr->inventoryItem;
            $fromLoc = $tr->fromLocation;

            if (!$item || !$fromLoc) {
                throw new InvalidArgumentException('Item or source location missing.');
            }
            self::assertRowTenant($item->tenant_id, $tr->tenant_id, 'inventory item');
            self::assertRowTenant($fromLoc->tenant_id, $tr->tenant_id, 'source location');

            $tenant = \App\Models\Tenant::find($tr->tenant_id);
            if (!$tenant) {
                throw new InvalidArgumentException('Transfer request tenant not found.');
            }

            // Decrement source stock — uses the Pos InventoryService primitive,
            // which writes a movement row with referenceType='transfer_request'.
            app(\App\Services\Pos\InventoryService::class)->decrementStock(
                tenant: $tenant,
                item: $item,
                location: $fromLoc,
                quantity: $quantitySent,
                referenceType: 'transfer_request',
                referenceId: $tr->id,
                tenantUser: $byUserId ? \App\Models\Tenant\TenantUser::find($byUserId) : null,
                reason: 'Transfer out',
                notes: "To {$tr->toLocation?->name}",
                movementType: 'transfer_out', // MARKER-TRANSFER-MOVEMENTS — was recorded as a SALE
            );

            $tr->status = TenantTransferRequest::STATUS_IN_TRANSIT;
            $tr->quantity_sent = $quantitySent;
            $tr->sent_at = now();
            $tr->sent_by_user_id = $byUserId;
            $tr->save();
            return $tr;
        });
    }

    /**
     * patch-102 markReceived — destination location receives the items.
     * Increments destination stock, sets status=fulfilled.
     * Reuses fulfilled_at + fulfilled_by_user_id columns for "received".
     */
    public function markReceived(string $id, ?string $byUserId = null): TenantTransferRequest
    {
        return DB::transaction(function () use ($id, $byUserId) {
            $tr = TenantTransferRequest::lockForUpdate()->findOrFail($id);
            if ($tr->status !== TenantTransferRequest::STATUS_IN_TRANSIT) {
                throw new InvalidArgumentException("Transfer request is not in transit (status={$tr->status}).");
            }

            // MARKER-TRANSFER-SCOPE — see markSent.
            $tr->load('inventoryItem', 'toLocation');
            $item = $tr->inventoryItem;
            $toLoc = $tr->toLocation;

            if (!$item || !$toLoc) {
                throw new InvalidArgumentException('Item or destination location missing.');
            }
            self::assertRowTenant($item->tenant_id, $tr->tenant_id, 'inventory item');
            self::assertRowTenant($toLoc->tenant_id, $tr->tenant_id, 'destination location');

            $tenant = \App\Models\Tenant::find($tr->tenant_id);
            if (!$tenant) {
                throw new InvalidArgumentException('Transfer request tenant not found.');
            }

            $qty = (int) ($tr->quantity_sent ?? $tr->quantity);

            app(\App\Services\Pos\InventoryService::class)->incrementStock(
                tenant: $tenant,
                item: $item,
                location: $toLoc,
                quantity: $qty,
                referenceType: 'transfer_request',
                referenceId: $tr->id,
                tenantUser: $byUserId ? \App\Models\Tenant\TenantUser::find($byUserId) : null,
                // MARKER-TRANSFER-MOVEMENTS — incrementStock has no $reason
                // parameter: this call threw "Unknown named parameter $reason"
                // every time, so receiving a transfer ALWAYS fataled. The
                // reason now rides in notes, and the movement is typed.
                movementType: 'transfer_in',
                notes: "Transfer in — from {$tr->fromLocation?->name}",
            );

            $tr->status = TenantTransferRequest::STATUS_FULFILLED;
            $tr->fulfilled_at = now();
            $tr->fulfilled_by_user_id = $byUserId;
            $tr->save();
            return $tr;
        });
    }

    /**
     * Legacy alias retained so 100B controllers calling markFulfilled
     * still work — they'll only succeed if status is in_transit.
     */
    public function markFulfilled(string $id, ?string $byUserId = null): TenantTransferRequest
    {
        return $this->markReceived($id, $byUserId);
    }

    public function cancel(string $id): TenantTransferRequest
    {
        return DB::transaction(function () use ($id) {
            $tr = TenantTransferRequest::lockForUpdate()->findOrFail($id);
            if ($tr->status === TenantTransferRequest::STATUS_FULFILLED) {
                throw new InvalidArgumentException('Already fulfilled, cannot cancel.');
            }

            // MARKER-TRANSFER-MOVEMENTS — cancelling an IN-TRANSIT transfer
            // used to silently strand the stock: it had already been deducted
            // from the source and was never given back. The goods return to
            // the source location with their own transfer_in movement.
            if ($tr->status === TenantTransferRequest::STATUS_IN_TRANSIT) {
                $tr->load('inventoryItem', 'fromLocation');
                $item    = $tr->inventoryItem;
                $fromLoc = $tr->fromLocation;
                if (!$item || !$fromLoc) {
                    throw new InvalidArgumentException('Cannot cancel: item or source location missing.');
                }
                self::assertRowTenant($item->tenant_id, $tr->tenant_id, 'inventory item');
                self::assertRowTenant($fromLoc->tenant_id, $tr->tenant_id, 'source location');

                $tenant = \App\Models\Tenant::find($tr->tenant_id);
                if (!$tenant) {
                    throw new InvalidArgumentException('Transfer request tenant not found.');
                }

                $qty = (int) ($tr->quantity_sent ?? 0);
                if ($qty > 0) {
                    app(\App\Services\Pos\InventoryService::class)->incrementStock(
                        tenant: $tenant,
                        item: $item,
                        location: $fromLoc,
                        quantity: $qty,
                        referenceType: 'transfer_request',
                        referenceId: $tr->id,
                        movementType: 'transfer_in',
                        notes: 'Transfer cancelled in transit — returned to source',
                    );
                }
            }

            $tr->status = TenantTransferRequest::STATUS_CANCELLED;
            $tr->save();
            return $tr;
        });
    }

    /**
     * MARKER-TRANSFER-SCOPE — assert a record exists AND belongs to the given
     * tenant. Existence alone is not authorization: unguessable ids leak
     * through shared browsers, screenshots, and support threads.
     */
    protected static function assertOwned(string $modelClass, string $id, string $tenantId, string $label): void
    {
        $owns = $modelClass::where('id', $id)->where('tenant_id', $tenantId)->exists();
        if (! $owns) {
            throw new InvalidArgumentException("That {$label} does not belong to this tenant.");
        }
    }

    /** MARKER-TRANSFER-SCOPE — compare a loaded row's tenant against the expected one. */
    protected static function assertRowTenant(?string $rowTenantId, ?string $expectedTenantId, string $label): void
    {
        if (! $rowTenantId || ! $expectedTenantId || $rowTenantId !== $expectedTenantId) {
            throw new InvalidArgumentException("Transfer {$label} belongs to a different tenant — refusing to move stock.");
        }
    }
}
TMOV_1_EOF

echo "transfer-movements-fix applied — server: git pull (no migrate, no view:clear)"
