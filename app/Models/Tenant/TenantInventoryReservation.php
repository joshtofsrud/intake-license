<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** MARKER-RESERVE — a quantity of an item held for a sale line, at a location. */
class TenantInventoryReservation extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'tenant_inventory_reservations';

    protected $fillable = [
        'tenant_id', 'inventory_item_id', 'location_id', 'sale_id', 'sale_item_id',
        'quantity', 'status', 'released_reason', 'created_at', 'ended_at',
    ];

    protected $casts = [
        'quantity'   => 'integer',
        'created_at' => 'datetime',
        'ended_at'   => 'datetime',
    ];

    public const ACTIVE   = 'active';
    public const RELEASED = 'released';
    public const CONSUMED = 'consumed';

    public function item(): BelongsTo
    {
        return $this->belongsTo(TenantInventoryItem::class, 'inventory_item_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(TenantSale::class, 'sale_id');
    }

    public function scopeActive($q)
    {
        return $q->where('status', self::ACTIVE);
    }
}
