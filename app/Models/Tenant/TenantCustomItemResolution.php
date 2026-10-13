<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/** MARKER-CUSTOM-ITEMS: a decision about a typed-in register line. */
class TenantCustomItemResolution extends Model
{
    protected $table = 'tenant_custom_item_resolutions';

    protected $fillable = [
        'tenant_id', 'name_key', 'price_cents', 'status', 'inventory_item_id',
        'reason', 'ignore_always', 'display_name', 'resolved_by_user_id', 'resolved_at',
    ];

    protected $casts = [
        'price_cents'   => 'integer',
        'ignore_always' => 'boolean',
        'resolved_at'   => 'datetime',
    ];

    public function item()
    {
        return $this->belongsTo(TenantInventoryItem::class, 'inventory_item_id')->withTrashed();
    }
}
