<?php

namespace App\Models\Tenant;

use App\Models\PlatformDistributorCatalog;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'description',
        'distributor_catalog_id',
        'catalog_cost_cents',
        'catalog_msrp_cents',
        'catalog_case_quantity',
        'catalog_upc',
        'catalog_synced_at',
        'shop_cost_cents',
        'shop_sell_price_cents',
        'shop_case_quantity',
        'shop_reorder_threshold',
        'shop_reorder_quantity',
        'shop_bin_location',
        'computed_stock_count',
        'allow_oversell',
        'is_active',
        'tax_class_code',
    ];

    protected $casts = [
        'catalog_cost_cents' => 'integer',
        'catalog_msrp_cents' => 'integer',
        'catalog_case_quantity' => 'integer',
        'catalog_synced_at' => 'datetime',
        'shop_cost_cents' => 'integer',
        'shop_sell_price_cents' => 'integer',
        'shop_case_quantity' => 'integer',
        'shop_reorder_threshold' => 'integer',
        'shop_reorder_quantity' => 'integer',
        'computed_stock_count' => 'integer',
        'allow_oversell' => 'boolean',
        'is_active' => 'boolean',
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
}
