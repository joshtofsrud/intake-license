<?php

namespace App\Models\Tenant;

use App\Models\PlatformDistributorCatalog;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Line item on a receive shipment.
 *
 * Three resolution paths at commit:
 *   - matched (inventory_item_id set, status='received')   → writes movement
 *   - unexpected (no item match yet, status='unexpected_*') → shop decides
 *   - backorder (status='backorder')                       → no movement
 */
class TenantInventoryReceiveShipmentItem extends Model
{
    use HasUuids;

    protected $table = 'tenant_inventory_receive_shipment_items';

    protected $fillable = [
        'tenant_id',
        'shipment_id',
        'inventory_item_id',
        'name',
        'sku',
        'upc',
        'distributor_catalog_id',
        'expected_quantity',
        'received_quantity',
        'status',
        'unit_cost_cents',
        'total_cost_cents',
        'notes',
    ];

    protected $casts = [
        'expected_quantity' => 'integer',
        'received_quantity' => 'integer',
        'unit_cost_cents' => 'integer',
        'total_cost_cents' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(TenantInventoryReceiveShipment::class, 'shipment_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(TenantInventoryItem::class, 'inventory_item_id');
    }

    public function distributorCatalog(): BelongsTo
    {
        return $this->belongsTo(PlatformDistributorCatalog::class, 'distributor_catalog_id');
    }

    public function isMatched(): bool
    {
        return $this->inventory_item_id !== null;
    }

    public function isUnexpected(): bool
    {
        return str_starts_with($this->status, 'unexpected_');
    }

    public function isBackorder(): bool
    {
        return $this->status === 'backorder';
    }
}
