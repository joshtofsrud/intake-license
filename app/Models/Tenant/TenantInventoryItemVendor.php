<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use App\Models\PlatformDistributorCatalog;

/**
 * Item-to-vendor pivot, modeled as a full Pivot subclass.
 *
 * Why a full model instead of a generic Pivot:
 *   - Pivot has its own meaningful data (vendor_sku, unit_cost_cents,
 *     lead_time_days, is_preferred, last_ordered_at).
 *   - We need to query the pivot directly ("items where lead_time > 7d").
 *   - We need typed relationships back to item and vendor.
 */
class TenantInventoryItemVendor extends Pivot
{
    use HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $table = 'tenant_inventory_item_vendors';

    protected $fillable = [
        'inventory_item_id',
        'vendor_id',
        'distributor_code',
        'distributor_catalog_id',
        'vendor_sku',
        'unit_cost_cents',
        'live_cost_cents',
        'live_avail',
        'live_checked_at',
        'lead_time_days',
        'is_preferred',
        'last_ordered_at',
    ];

    protected $casts = [
        'unit_cost_cents' => 'integer',
        'live_cost_cents'  => 'integer',
        'live_avail'       => 'integer',
        'lead_time_days'  => 'integer',
        'is_preferred'    => 'boolean',
        'last_ordered_at' => 'datetime',
        'live_checked_at' => 'datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(TenantInventoryItem::class, 'inventory_item_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(TenantVendor::class, 'vendor_id');
    }

    /**
     * The distributor catalog row this source links to (null for local-only
     * suppliers). Carries the distributor's variant number, MAP, etc.
     */
    public function distributorCatalog(): BelongsTo
    {
        return $this->belongsTo(PlatformDistributorCatalog::class, 'distributor_catalog_id');
    }
}
