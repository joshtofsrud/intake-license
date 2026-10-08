<?php

namespace App\Support;

use App\Models\Tenant\TenantInventoryCategory;
use App\Models\Tenant\TenantInventoryItem;
use App\Models\Tenant\TenantInventoryUnit;

/**
 * which items track serial numbers, and how many
 * on-hand pieces still need one.
 *
 * A category tracks serials when it, or any category above it, is switched
 * on. The answer is worked out once per request per tenant.
 */
class SerialTracking
{
    /** @var array<string, array<string, true>> */
    private static array $tracked = [];

    /** @return array<string, true> category ids (as keys) that track serials */
    public static function trackedCategoryIds(string $tenantId): array
    {
        if (isset(self::$tracked[$tenantId])) {
            return self::$tracked[$tenantId];
        }

        $cats = TenantInventoryCategory::where('tenant_id', $tenantId)
            ->get(['id', 'parent_id', 'track_serials'])
            ->keyBy('id');

        $out = [];
        foreach ($cats as $id => $c) {
            $cur = $c;
            $seen = [];
            while ($cur && ! isset($seen[$cur->id])) {
                $seen[$cur->id] = true;
                if ($cur->track_serials) {
                    $out[$id] = true;
                    break;
                }
                $cur = $cur->parent_id ? ($cats[$cur->parent_id] ?? null) : null;
            }
        }

        return self::$tracked[$tenantId] = $out;
    }

    /** The nearest category (itself or above) that switched tracking on, or null. */
    public static function trackingSource(TenantInventoryCategory $category): ?TenantInventoryCategory
    {
        $cur = $category;
        $seen = [];
        while ($cur && ! isset($seen[$cur->id])) {
            $seen[$cur->id] = true;
            if ($cur->track_serials) {
                return $cur;
            }
            $cur = $cur->parent_id
                ? TenantInventoryCategory::where('tenant_id', $category->tenant_id)->find($cur->parent_id)
                : null;
        }
        return null;
    }

    public static function isTracked(?TenantInventoryItem $item): bool
    {
        if (! $item || ! $item->category_id) {
            return false;
        }
        return isset(self::trackedCategoryIds($item->tenant_id)[$item->category_id]);
    }

    /**
     * On-hand pieces with no serial yet, per location id. Stock that was on the
     * shelf before tracking was switched on (or adjusted in by count) lands here.
     *
     * @return array<string, int>
     */
    public static function needsSerialByLocation(TenantInventoryItem $item): array
    {
        $onHand = $item->locations()->get(['location_id', 'computed_stock_count'])
            ->mapWithKeys(fn ($l) => [$l->location_id => max(0, (int) $l->computed_stock_count)]);

        $withSerial = TenantInventoryUnit::where('tenant_id', $item->tenant_id)
            ->where('inventory_item_id', $item->id)
            ->where('status', TenantInventoryUnit::STATUS_IN_STOCK)
            ->selectRaw('location_id, count(*) as n')->groupBy('location_id')
            ->pluck('n', 'location_id');

        $out = [];
        foreach ($onHand as $loc => $n) {
            $gap = $n - (int) ($withSerial[$loc] ?? 0);
            if ($gap > 0) {
                $out[$loc] = $gap;
            }
        }
        return $out;
    }

    /** Where a serial currently sits in stock, if it does. */
    public static function inStock(string $tenantId, string $serial): ?TenantInventoryUnit
    {
        return TenantInventoryUnit::where('tenant_id', $tenantId)
            ->where('serial_key', TenantInventoryUnit::keyFor($serial))
            ->where('status', TenantInventoryUnit::STATUS_IN_STOCK)
            ->with(['item:id,name', 'location:id,name'])
            ->first();
    }
}
