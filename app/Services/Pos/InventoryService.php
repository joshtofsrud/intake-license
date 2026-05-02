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
    ): TenantInventoryMovement {
        $this->assertTenantOwnsResources($tenant, $item, $location);
        $this->assertPositiveQuantity($quantity);

        return DB::transaction(function () use (
            $tenant, $item, $location, $quantity, $referenceType,
            $referenceId, $tenantUser, $reason, $notes
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
                movementType: 'sale',
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
