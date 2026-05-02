<?php

namespace App\Models\Tenant;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Receiving header — one row per shipment received at a location.
 *
 * Status: draft → committed (one-way). Voided is a soft-delete equivalent
 * for shipments that never actually arrived.
 *
 * On commit, every line item with status='received' AND inventory_item_id
 * IS NOT NULL writes an inventory movement of type 'receive'.
 */
class TenantInventoryReceiveShipment extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'tenant_inventory_receive_shipments';

    protected $fillable = [
        'tenant_id',
        'location_id',
        'shipment_number',
        'distributor_code',
        'distributor_name',
        'purchase_order_id',
        'status',
        'received_date',
        'shipping_cost_cents',
        'expected_count',
        'received_count',
        'backorder_count',
        'unexpected_count',
        'notes',
        'created_by_tenant_user_id',
        'committed_by_tenant_user_id',
        'committed_at',
    ];

    protected $casts = [
        'received_date' => 'date',
        'shipping_cost_cents' => 'integer',
        'expected_count' => 'integer',
        'received_count' => 'integer',
        'backorder_count' => 'integer',
        'unexpected_count' => 'integer',
        'committed_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(TenantLocation::class, 'location_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TenantInventoryReceiveShipmentItem::class, 'shipment_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'created_by_tenant_user_id');
    }

    public function committedBy(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'committed_by_tenant_user_id');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isCommitted(): bool
    {
        return $this->status === 'committed';
    }

    public function isVoided(): bool
    {
        return $this->status === 'voided';
    }
}
