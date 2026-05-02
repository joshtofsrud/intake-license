<?php

namespace App\Models\Tenant;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-defined inventory category.
 *
 * Supports parent_id hierarchy even though v1 UI is flat. Adding hierarchy
 * later is a re-key migration over millions of items × 500 tenants — the
 * column is here from day one.
 *
 * tax_class_code is forward-compat for per-item tax classes.
 */
class TenantInventoryCategory extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'tenant_inventory_categories';

    protected $fillable = [
        'tenant_id',
        'parent_id',
        'name',
        'slug',
        'sort_order',
        'tax_class_code',
        'source',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function inventoryItems(): HasMany
    {
        return $this->hasMany(TenantInventoryItem::class, 'category_id');
    }
}
