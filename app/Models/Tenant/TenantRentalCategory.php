<?php
// MARKER-PATCH-217

namespace App\Models\Tenant;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A rental category (Mountain, E-bike, Kids…) — pure grouping for
 * browsing and filters. MARKER-PATCH-218B: rates live on the UNIT.
 */
class TenantRentalCategory extends Model
{
    use HasUuids;

    protected $table = 'tenant_rental_categories';

    protected $fillable = [
        'tenant_id', 'name', 'size_axis', 'sort_order', 'archived_at', // MARKER-PATCH-226
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

    public function models(): HasMany // MARKER-PATCH-226
    {
        return $this->hasMany(TenantRentalModel::class, 'category_id');
    }

    public function scopeActive($q)
    {
        return $q->whereNull('archived_at');
    }
}
