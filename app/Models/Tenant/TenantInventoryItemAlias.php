<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** MARKER-ITEM-ALIASES — an identifier that used to belong to this item. */
class TenantInventoryItemAlias extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'tenant_inventory_item_aliases';

    protected $fillable = ['tenant_id', 'inventory_item_id', 'code', 'kind', 'source', 'from_item_id', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(TenantInventoryItem::class, 'inventory_item_id');
    }
}
