<?php

namespace App\Models\Tenant;

use App\Models\PlatformDistributorCatalog;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The architectural keystone of POS.
 *
 * THE CATALOG/SHOP COLUMN-PAIR PATTERN:
 *   catalog_*  ← overwritten by nightly distributor sync.
 *   shop_*     ← never touched by sync. Set once, stays set.
 *
 * Effective values resolve via the effective*() methods below.
 *
 * This makes the Ascend RMS / Lightspeed pain pattern (catalog updates
 * clobbering shop overrides) STRUCTURALLY IMPOSSIBLE.
 *
 * Stock counts are PER-LOCATION. Read stock through InventoryService,
 * not from the computed_stock_count column directly. The column is a
 * tenant-aggregate cache for fast list views; per-location stock lives
 * on tenant_inventory_item_locations.
 */
class TenantInventoryItem extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'tenant_inventory_items';

    protected $fillable = [
        'tenant_id',
        'category_id',
        'sku',
        'name',
        'display_subtitle',
        'description',
        'color',
        'size',
        'distributor_catalog_id',
        'catalog_cost_cents',
        'catalog_msrp_cents',
        'catalog_map_cents',
        'catalog_case_quantity',
        'catalog_upc',
        'catalog_title_seen',
        'catalog_synced_at',
        'price_ack_at',
        'price_ack_by',
        'shop_cost_cents',
        'shop_sell_price_cents',
        'shop_case_quantity',
        'shop_reorder_threshold',
        'shop_reorder_quantity',
        'shop_bin_location',
        'computed_stock_count',
        'allow_oversell',
        'is_active',
        'is_stock_tracked',
        'tax_class_code',
        'default_vendor_id',
    ];

    protected $casts = [
        'catalog_cost_cents' => 'integer',
        'catalog_msrp_cents' => 'integer',
        'catalog_map_cents' => 'integer',
        'catalog_case_quantity' => 'integer',
        'catalog_synced_at' => 'datetime',
        'price_ack_at' => 'datetime',
        'shop_cost_cents' => 'integer',
        'shop_sell_price_cents' => 'integer',
        'shop_case_quantity' => 'integer',
        'shop_reorder_threshold' => 'integer',
        'shop_reorder_quantity' => 'integer',
        'computed_stock_count' => 'integer',
        'allow_oversell' => 'boolean',
        'is_active' => 'boolean',
        'is_stock_tracked' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TenantInventoryCategory::class, 'category_id');
    }

    public function distributorCatalog(): BelongsTo
    {
        return $this->belongsTo(PlatformDistributorCatalog::class, 'distributor_catalog_id');
    }

    public function locations(): HasMany
    {
        return $this->hasMany(TenantInventoryItemLocation::class, 'inventory_item_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(TenantInventoryMovement::class, 'inventory_item_id');
    }

    // ─── Catalog/Shop fallback accessors ─────────────────────────────────
    // Effective value = shop value if set, else catalog value.

    public function effectiveCostCents(): ?int
    {
        return $this->shop_cost_cents ?? $this->catalog_cost_cents;
    }

    public function effectiveSellPriceCents(): ?int
    {
        return $this->shop_sell_price_cents ?? $this->catalog_msrp_cents;
    }

    public function effectiveCaseQuantity(): ?int
    {
        return $this->shop_case_quantity ?? $this->catalog_case_quantity;
    }

    /**
     * For UPC: shop never overrides, catalog is the source.
     * Method exists for symmetry with other effective*() calls.
     */
    public function effectiveUpc(): ?string
    {
        return $this->catalog_upc;
    }

    /**
     * Whether this item is currently linked to an active distributor catalog row.
     */
    public function hasActiveCatalogLink(): bool
    {
        return $this->distributor_catalog_id !== null
            && $this->distributorCatalog
            && $this->distributorCatalog->is_active;
    }

    // ─── Vendor / Special Order relationships ────────────────────────────
    // Added by patch 84. The pivot is authoritative for "which vendors
    // can supply this item" — default_vendor_id is a convenience pointer
    // that usually matches the preferred pivot row, but can diverge.

    public function defaultVendor(): BelongsTo
    {
        return $this->belongsTo(TenantVendor::class, 'default_vendor_id');
    }

    /**
     * All vendors that source this item, through the pivot.
     * Use ->withPivot() data for vendor_sku / unit_cost / lead_time.
     */
    public function vendors(): BelongsToMany
    {
        return $this->belongsToMany(
            TenantVendor::class,
            'tenant_inventory_item_vendors',
            'inventory_item_id',
            'vendor_id'
        )
            ->using(TenantInventoryItemVendor::class)
            ->withPivot(['vendor_sku', 'unit_cost_cents', 'lead_time_days', 'is_preferred', 'last_ordered_at', 'distributor_code', 'distributor_catalog_id', 'live_cost_cents', 'live_avail', 'live_checked_at'])
            ->withTimestamps();
    }

    public function specialOrders(): HasMany
    {
        return $this->hasMany(TenantSpecialOrder::class, 'inventory_item_id');
    }

    /**
     * The preferred vendor pivot row, if one is marked. Falls back to
     * defaultVendor() if no preferred is set in the pivot. Returns null
     * if no source is configured.
     */
    public function preferredVendor(): ?TenantVendor
    {
        $preferred = $this->vendors()->wherePivot('is_preferred', true)->first();
        return $preferred ?: $this->defaultVendor;
    }

    /**
     * Sum of quantities on all open special orders for this item.
     * Useful for the "On special order: X" stat on the item detail page.
     */
    public function onOrderCount(): int
    {
        return (int) $this->specialOrders()
            ->whereIn('status', TenantSpecialOrder::STATUSES_OPEN)
            ->sum('quantity');
    }
}
