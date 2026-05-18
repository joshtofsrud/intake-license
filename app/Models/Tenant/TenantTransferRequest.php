<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * patch-100a: transfer request created when register sale
 * oversells an item and staff clicks "Request transfer".
 *
 * Status flow:
 *   pending → fulfilled (when fulfilling location moves the stock)
 *   pending → cancelled (no longer needed)
 */
class TenantTransferRequest extends Model
{
    use HasUuids;

    public const STATUS_PENDING    = 'pending';
    public const STATUS_IN_TRANSIT = 'in_transit';
    public const STATUS_FULFILLED  = 'fulfilled';
    public const STATUS_CANCELLED  = 'cancelled';

    protected $table = 'tenant_transfer_requests';

    protected $fillable = [
        'tenant_id',
        'inventory_item_id',
        'to_location_id',
        'from_location_id',
        'quantity',
        'requested_by_user_id',
        'sale_id',
        'status',
        'notes',
        'fulfilled_at',
        'fulfilled_by_user_id',
        'quantity_sent',
        'sent_at',
        'sent_by_user_id',
    ];

    protected $casts = [
        'quantity'      => 'integer',
        'quantity_sent' => 'integer',
        'fulfilled_at'  => 'datetime',
        'sent_at'       => 'datetime',
    ];

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(TenantInventoryItem::class, 'inventory_item_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(TenantLocation::class, 'to_location_id');
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(TenantLocation::class, 'from_location_id');
    }
}
