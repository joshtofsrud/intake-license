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
        'track_serials',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'track_serials' => 'boolean',
    ];

    /**
     * MARKER-CATEGORY-SLUG-FREED: a deleted category keeps its row (soft delete)
     * but gives its slug back. The unique index on (tenant, slug) counts deleted
     * rows too, so without this, re-adding a deleted name was a 500. Freeing the
     * slug on delete means no "-2, -3" growth no matter how many are deleted.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $c) {
            if (method_exists($c, 'isForceDeleting') && $c->isForceDeleting()) return;
            static::withTrashed()->whereKey($c->getKey())->update([
                'slug' => self::freedSlug((string) $c->slug, (string) $c->getKey()),
            ]);
        });
    }

    public static function freedSlug(string $slug, string $id): string
    {
        if (str_contains($slug, '~del-')) return $slug;
        return mb_substr($slug, 0, 110) . '~del-' . substr($id, -12);
    }

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
