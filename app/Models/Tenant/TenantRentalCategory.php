<?php

namespace App\Models\Tenant;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A rental category (Mountain, E-bike, Kids…) — pure grouping for
 * browsing and filters. rates live on the UNIT.
 */
class TenantRentalCategory extends Model
{
    use HasUuids;

    protected $table = 'tenant_rental_categories';

    protected $fillable = [
        'tenant_id', 'name', 'size_axis', 'sort_order', 'archived_at',
    ];

    protected $casts = [
        'sort_order'  => 'integer',
        'archived_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(TenantRentalUnit::class, 'category_id');
    }

    public function models(): HasMany
    {
        return $this->hasMany(TenantRentalModel::class, 'category_id');
    }

    public function scopeActive($q)
    {
        return $q->whereNull('archived_at');
    }
}
