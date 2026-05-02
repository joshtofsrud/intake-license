<?php

namespace App\Models\Tenant;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-location stock for a single inventory item.
 *
 * One row per (item, location). Stock count truth is SUM of movements
 * for this item AND location. This row caches the count for fast queries.
 *
 * Per-location overrides: shop_reorder_threshold, shop_reorder_quantity,
 * shop_bin_location. Different locations may have different reorder
 * behavior even for the same item.
 *
 * NEVER set computed_stock_count directly. Always go through InventoryService.
 */
class TenantInventoryItemLocation extends Model
{
    use HasUuids;

    protected $table = 'tenant_inventory_item_locations';

    protected $fillable = [
        'tenant_id',
        'inventory_item_id',
        'location_id',
        'computed_stock_count',
        'shop_reorder_threshold',
        'shop_reorder_quantity',
        'shop_bin_location',
        'is_active',
    ];

    protected $casts = [
        'computed_stock_count' => 'integer',
        'shop_reorder_threshold' => 'integer',
        'shop_reorder_quantity' => 'integer',
        'is_active' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(TenantInventoryItem::class, 'inventory_item_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(TenantLocation::class, 'location_id');
    }

    /**
     * Effective reorder threshold — falls back to item-level value when null.
     */
    public function effectiveReorderThreshold(): ?int
    {
        return $this->shop_reorder_threshold ?? $this->item?->shop_reorder_threshold;
    }

    /**
     * Effective reorder quantity — falls back to item-level value when null.
     */
    public function effectiveReorderQuantity(): ?int
    {
        return $this->shop_reorder_quantity ?? $this->item?->shop_reorder_quantity;
    }

    /**
     * True when current stock has fallen at or below the reorder threshold.
     */
    public function isLowStock(): bool
    {
        $threshold = $this->effectiveReorderThreshold();
        if ($threshold === null) {
            return false;
        }
        return $this->computed_stock_count <= $threshold;
    }
}
