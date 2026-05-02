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
        'manufacturer_sku',
        'name',
        'manufacturer',
        'category',
        'description',
        'cost_cents',
        'msrp_cents',
        'case_quantity',
        'last_synced_at',
        'is_active',
    ];

    protected $casts = [
        'cost_cents' => 'integer',
        'msrp_cents' => 'integer',
        'case_quantity' => 'integer',
        'is_active' => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    /**
     * Tenant items that link to this catalog row for catalog_* values.
     */
    public function tenantInventoryItems(): HasMany
    {
        return $this->hasMany(Tenant\TenantInventoryItem::class, 'distributor_catalog_id');
    }
}
