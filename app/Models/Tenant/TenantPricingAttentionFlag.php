<?php
// MARKER-PATCH-HLC3B

namespace App\Models\Tenant;

use App\Models\PlatformDistributorCatalog;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reason an item needs a human's pricing eyes. Written by the tier-2 sync
 * (vanish reasons) and the pricing-attention surface (below_map / off_msrp).
 */
class TenantPricingAttentionFlag extends Model
{
    use HasUuids;

    protected $table = 'tenant_pricing_attention_flags';

    public const REASON_COST_VANISHED = 'cost_vanished';
    public const REASON_MAP_VANISHED  = 'map_vanished';
    public const REASON_MSRP_VANISHED = 'msrp_vanished';
    public const REASON_BELOW_MAP     = 'below_map';
    public const REASON_OFF_MSRP      = 'off_msrp';
    public const REASON_TITLE_CHANGED = 'title_changed';

    protected $fillable = [
        'tenant_id',
        'inventory_item_id',
        'distributor_catalog_id',
        'reason',
        'detail',
        'status',
        'resolved_at',
        'resolved_by',
    ];

    protected $casts = [
        'detail'      => 'array',
        'resolved_at' => 'datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(TenantInventoryItem::class, 'inventory_item_id');
    }

    public function distributorCatalog(): BelongsTo
    {
        return $this->belongsTo(PlatformDistributorCatalog::class, 'distributor_catalog_id');
    }
}
