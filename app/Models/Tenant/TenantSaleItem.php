<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Tenant;

class TenantSaleItem extends Model
{
    use HasUuids;

    protected $table = 'tenant_sale_items';

    protected $fillable = [
        'tenant_id', 'sale_id', 'type',
        'service_id', 'inventory_item_id', 'gift_card_id', 'metadata', // MARKER-GIFTCARDS
        'name_snapshot', 'description_snapshot', 'cost_cents_snapshot',
        'quantity', 'unit_price_cents', 'discount_cents',
        'tax_rate_snapshot', 'is_taxable', 'tax_cents',
        'tip_cents', 'line_total_cents',
        'assigned_staff_id', 'position', 'notes',
        'original_sale_item_id', 'disposition', // MARKER-REFUND-QTY
    ];

    protected $casts = [
        'metadata' => 'array', // MARKER-GIFTCARDS
        'quantity'            => 'decimal:3',
        'unit_price_cents'    => 'integer',
        'discount_cents'      => 'integer',
        'tax_rate_snapshot'   => 'decimal:3',
        'is_taxable'          => 'boolean',
        'tax_cents'           => 'integer',
        'tip_cents'           => 'integer',
        'line_total_cents'    => 'integer',
        'cost_cents_snapshot' => 'integer',
        'position'            => 'integer',
    ];

    public function tenant(): BelongsTo        { return $this->belongsTo(Tenant::class); }
    public function sale(): BelongsTo          { return $this->belongsTo(TenantSale::class, 'sale_id'); }
    public function service(): BelongsTo       { return $this->belongsTo(TenantServiceItem::class, 'service_id'); }
    public function inventoryItem(): BelongsTo { return $this->belongsTo(TenantInventoryItem::class, 'inventory_item_id'); }
    public function assignedStaff(): BelongsTo { return $this->belongsTo(TenantUser::class, 'assigned_staff_id'); }

    public function scopeOfType($q, string $type)  { return $q->where('type', $type); }
    public function scopeServices($q)              { return $q->where('type', 'service'); }
    public function scopeProducts($q)              { return $q->where('type', 'product'); }

    public function isService(): bool   { return $this->type === 'service'; }
    public function isProduct(): bool   { return $this->type === 'product'; }
    public function isOpenItem(): bool  { return $this->type === 'open_item'; }
    public function isGiftCard(): bool  { return $this->type === 'gift_card'; }
}
