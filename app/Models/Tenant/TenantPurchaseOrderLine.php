<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** MARKER-PO */
class TenantPurchaseOrderLine extends Model
{
    use HasUuids;

    protected $table = 'tenant_purchase_order_lines';

    protected $fillable = [
        'tenant_id', 'purchase_order_id', 'inventory_item_id', 'name_snapshot', 'vendor_sku',
        'qty_ordered', 'qty_received', 'qty_cancelled', 'unit_cost_cents', 'expected_date',
        'added_after_placed', 'cancel_reason', 'sort',
    ];

    protected $casts = [
        'qty_ordered' => 'integer',
        'qty_received' => 'integer',
        'qty_cancelled' => 'integer',
        'unit_cost_cents' => 'integer',
        'expected_date' => 'date',
        'added_after_placed' => 'boolean',
    ];

    public function purchaseOrder(): BelongsTo { return $this->belongsTo(TenantPurchaseOrder::class, 'purchase_order_id'); }
    public function item(): BelongsTo          { return $this->belongsTo(TenantInventoryItem::class, 'inventory_item_id'); }
    public function specialOrders(): HasMany   { return $this->hasMany(TenantSpecialOrder::class, 'purchase_order_line_id')->orderBy('created_at'); }

    /** The PO, loaded once (safe inside loops). */
    public function parentPo(): ?TenantPurchaseOrder
    {
        if (! $this->relationLoaded('purchaseOrder')) {
            $this->setRelation('purchaseOrder', $this->purchaseOrder()->first());
        }

        return $this->getRelation('purchaseOrder');
    }

    /** What the vendor still owes on this line. */
    public function owed(): int
    {
        return max(0, (int) $this->qty_ordered - (int) $this->qty_received - (int) $this->qty_cancelled);
    }

    /** Units received beyond what was ordered. */
    public function over(): int
    {
        return max(0, (int) $this->qty_received - ((int) $this->qty_ordered - (int) $this->qty_cancelled));
    }

    /** Units on this line that belong to open special orders. */
    public function specialOrderUnits(): int
    {
        return (int) $this->specialOrders->whereIn('status', [TenantSpecialOrder::STATUS_NEEDED, TenantSpecialOrder::STATUS_ORDERED])->sum('quantity');
    }
}
