<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** MARKER-ORDER-SHIPMENTS: one box leaving one place. */
class TenantOrderShipment extends Model
{
    use HasUuids;

    protected $table = 'tenant_order_shipments';

    protected $fillable = [
        'tenant_id', 'order_id', 'source', 'warehouse_id', 'warehouse_name', 'handling_days',
        'shipping_cents', 'status', 'carrier', 'tracking_number', 'shipped_at',
    ];

    protected $casts = ['shipping_cents' => 'integer', 'handling_days' => 'integer', 'shipped_at' => 'datetime'];

    public function order(): BelongsTo { return $this->belongsTo(TenantOrder::class, 'order_id'); }
    public function items(): HasMany   { return $this->hasMany(TenantOrderShipmentItem::class, 'shipment_id'); }

    public function isSupplier(): bool { return $this->source === 'supplier'; }
}
