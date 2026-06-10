<?php
// MARKER-PATCH-217

namespace App\Models\Tenant;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A rental category (Mountain, E-bike, Kids…) carrying the rate card and
 * the default deposit. Units may override per-column.
 */
class TenantRentalCategory extends Model
{
    use HasUuids;

    protected $table = 'tenant_rental_categories';

    protected $fillable = [
        'tenant_id', 'name',
        'hourly_rate_cents', 'daily_rate_cents', 'weekend_rate_cents',
        'deposit_cents', 'sort_order', 'archived_at',
    ];

    protected $casts = [
        'hourly_rate_cents'  => 'integer',
        'daily_rate_cents'   => 'integer',
        'weekend_rate_cents' => 'integer',
        'deposit_cents'      => 'integer',
        'sort_order'         => 'integer',
        'archived_at'        => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(TenantRentalUnit::class, 'category_id');
    }

    public function scopeActive($q)
    {
        return $q->whereNull('archived_at');
    }
}
