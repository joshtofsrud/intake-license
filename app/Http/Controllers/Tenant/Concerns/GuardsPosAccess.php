<?php

namespace App\Http\Controllers\Tenant\Concerns;

use App\Services\FeatureAccessService;

/**
 * GuardsPosAccess
 *
 * Limit-style enforcement for tenants without the `pos` capability.
 *
 * Today's practical audience: Branded WITHOUT the POS add-on.
 * Starter tenants are blocked from the register and inventory entirely
 * by RequireRetailCapability (patch 72/73), so they never reach these
 * checks. The trait is written tier-agnostic so it stays correct if a
 * future tier sits between Branded and Scale, or if Master Admin grants
 * retail to a Starter via the addon override.
 *
 * Cap shape — INTENTIONALLY non-fatal:
 *   - 121st item is blocked at the add point.
 *   - Existing items above the cap (from a downgrade) are untouched.
 *   - Edits to existing items, restocking, ringing them on the
 *     register — all work normally regardless of count.
 *
 * Bricking a downgraded tenant's existing catalog is hostile and creates
 * support pain. The cap surfaces friction at the ADD point — the moment
 * a tenant is investing in growing their catalog is the moment POS's
 * tools start to actually matter.
 */
trait GuardsPosAccess
{
    /**
     * Hard cap on count of active inventory items for a tenant without
     * `pos`. 121st item is blocked. Existing items above the cap (from
     * a prior POS-enabled state) are untouched; only NEW adds are blocked.
     */
    public const POS_INVENTORY_HARD_CAP = 120;

    protected function tenantHasPos($tenant): bool
    {
        return app(FeatureAccessService::class)->hasAddon($tenant, 'pos');
    }

    /**
     * Count active, non-archived inventory items. Mirrors the count used
     * elsewhere — `is_active=true` AND not soft-deleted. The model's
     * SoftDeletes trait handles `deleted_at` automatically on queries
     * via Eloquent (not via raw DB::table).
     */
    protected function countActiveInventoryItems($tenant): int
    {
        return \App\Models\Tenant\TenantInventoryItem::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->count();
    }

    /**
     * For the inventory index banner. Returns cap state for the view
     * regardless of whether the cap is currently being hit. When the
     * tenant has `pos`, item_count and remaining come back null — the
     * view uses pos_enabled to skip rendering entirely.
     */
    protected function inventoryCapContext($tenant): array
    {
        $hasPos = $this->tenantHasPos($tenant);
        $count  = $hasPos ? null : $this->countActiveInventoryItems($tenant);
        $cap    = self::POS_INVENTORY_HARD_CAP;

        return [
            'pos_enabled' => $hasPos,
            'item_count'  => $count,
            'cap'         => $cap,
            'at_or_over'  => !$hasPos && $count !== null && $count >= $cap,
            'remaining'   => !$hasPos && $count !== null ? max(0, $cap - $count) : null,
        ];
    }

    /**
     * Hard-cap check at item add. Returns true if the tenant has `pos`
     * OR is under the cap. The caller handles the cap-hit UX — typically
     * a redirect back to the inventory index with a flash error message.
     */
    protected function inventoryAddIsAllowed($tenant): bool
    {
        if ($this->tenantHasPos($tenant)) {
            return true;
        }
        return $this->countActiveInventoryItems($tenant) < self::POS_INVENTORY_HARD_CAP;
    }
}
