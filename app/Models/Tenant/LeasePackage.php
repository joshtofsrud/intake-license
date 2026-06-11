<?php
// MARKER-PATCH-229

namespace App\Models\Tenant;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A lease package — a season-long tier (e.g. "Junior Complete"). Owns no
 * units; owns slots that describe what to pull from the fleet. Price is a
 * flat seasonal amount in cents.
 */
class LeasePackage extends Model
{
    use HasUuids;

    protected $table = 'lease_packages';

    protected $fillable = [
        'tenant_id', 'name', 'subtitle', 'season_price_cents',
        'deposit_cents', 'active', 'sort_order', 'archived_at',
    ];

    protected $casts = [
        'season_price_cents' => 'integer',
        'deposit_cents'      => 'integer',
        'active'             => 'boolean',
        'sort_order'         => 'integer',
        'archived_at'        => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function slots(): HasMany
    {
        return $this->hasMany(LeasePackageSlot::class, 'package_id')->orderBy('sort_order');
    }

    public function scopeActive($q)
    {
        return $q->whereNull('archived_at');
    }
}
