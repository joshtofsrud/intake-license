<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MARKER-PATCH-560 — Online Retail Wave 1. Order lines carry snapshots
 * (name/image/variant/price) so history renders forever regardless of
 * catalog churn; inventory_item_id stays for the sale bridge and stock.
 */
class TenantOrderItem extends Model
{
    use HasUuids;

    protected $table = 'tenant_order_items';

    protected $fillable = [
        'tenant_id', 'order_id', 'inventory_item_id', 'distributor_catalog_id',
        'name_snapshot', 'image_snapshot', 'variant_snapshot',
        'unit_price_cents', 'quantity', 'line_total_cents', 'position',
    ];

    protected $casts = [
        'unit_price_cents' => 'integer',
        'quantity'         => 'decimal:3',
        'line_total_cents' => 'integer',
        'position'         => 'integer',
    ];

    public function order(): BelongsTo { return $this->belongsTo(TenantOrder::class, 'order_id'); }
    public function inventoryItem(): BelongsTo { return $this->belongsTo(TenantInventoryItem::class, 'inventory_item_id'); }
}
