<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** MARKER-ORDER-SHIPMENTS: the units of one order line that travel in one shipment. */
class TenantOrderShipmentItem extends Model
{
    use HasUuids;

    protected $table = 'tenant_order_shipment_items';

    protected $fillable = ['shipment_id', 'order_item_id', 'quantity', 'special_quantity'];

    protected $casts = ['quantity' => 'integer', 'special_quantity' => 'integer'];

    public function shipment(): BelongsTo  { return $this->belongsTo(TenantOrderShipment::class, 'shipment_id'); }
    public function orderItem(): BelongsTo { return $this->belongsTo(TenantOrderItem::class, 'order_item_id'); }
}
