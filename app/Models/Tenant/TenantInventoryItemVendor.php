<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

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
        'vendor_sku',
        'unit_cost_cents',
        'lead_time_days',
        'is_preferred',
        'last_ordered_at',
    ];

    protected $casts = [
        'unit_cost_cents' => 'integer',
        'lead_time_days'  => 'integer',
        'is_preferred'    => 'boolean',
        'last_ordered_at' => 'datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(TenantInventoryItem::class, 'inventory_item_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(TenantVendor::class, 'vendor_id');
    }
}
