<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** MARKER-ITEM-IMAGES — joins a TenantMedia row to an inventory item. */
class TenantInventoryItemImage extends Model
{
    use HasUuids;

    protected $table = 'tenant_inventory_item_images';

    protected $fillable = ['tenant_id', 'inventory_item_id', 'media_id', 'sort_order'];

    protected $casts = ['sort_order' => 'integer'];

    public function media(): BelongsTo
    {
        return $this->belongsTo(TenantMedia::class, 'media_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(TenantInventoryItem::class, 'inventory_item_id');
    }
}
