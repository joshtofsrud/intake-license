<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Platform-level distributor catalog row.
 *
 * Shared across ALL tenants. NOT tenant-scoped. One row per
 * (distributor_code, upc) — globally unique.
 *
 * Tenants don't write to this table directly. The DistributorCatalogService
 * (future) syncs from each distributor's feed nightly and upserts here.
 */
class PlatformDistributorCatalog extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'platform_distributor_catalogs';

    protected $fillable = [
        'distributor_code',
        'distributor_name',
        'upc',
        'product_key',
        'manufacturer_sku',
        'distributor_variant_no',
        'name',
        'display_name',
        'display_subtitle',
        'manufacturer',
        'category',
        'description',
        'cost_cents',
        'msrp_cents',
        'map_cents',
        'case_quantity',
        'last_synced_at',
        'distributor_product_no',
        'distributor_variant_id',
        'ean',
        'brand_id',
        'config',
        'size_id',
        'color_id',
        'category_id',
        'category_path',
        'item_group',
        'taxable',
        'alt_prices',
        'prev_cost_cents',
        'prev_map_cents',
        'prev_msrp_cents',
        'images',
        'uom',
        'weight',
        'dimensions',
        'ground_only',
        'hazmat_type',
        'freight_class',
        'dropship_fulfillable',
        'fulfillment_caps',
        'source_status_id',
        'source_status_label',
        'canonical_status',
        'is_sellable',
        'attributes',
        'source_modified_at',
        'source_raw',
        'is_active',
    ];

    protected $casts = [
        'cost_cents' => 'integer',
        'msrp_cents' => 'integer',
        'map_cents' => 'integer',
        'case_quantity' => 'integer',
        'is_active' => 'boolean',
        'last_synced_at' => 'datetime',
        'source_modified_at' => 'datetime',
        'taxable' => 'boolean',
        'ground_only' => 'boolean',
        'dropship_fulfillable' => 'boolean',
        'is_sellable' => 'boolean',
        'prev_cost_cents' => 'integer',
        'prev_map_cents' => 'integer',
        'prev_msrp_cents' => 'integer',
        'weight' => 'decimal:3',
        'alt_prices' => 'array',
        'images' => 'array',
        'dimensions' => 'array',
        'fulfillment_caps' => 'array',
        'attributes' => 'array',
        'source_raw' => 'array',
    ];

    /**
     * Tenant items that link to this catalog row for catalog_* values.
     */
    public function tenantInventoryItems(): HasMany
    {
        return $this->hasMany(Tenant\TenantInventoryItem::class, 'distributor_catalog_id');
    }
}
