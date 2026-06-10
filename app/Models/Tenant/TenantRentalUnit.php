<?php
// MARKER-PATCH-217

namespace App\Models\Tenant;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single rentable unit — its own bookable resource with its own
 * condition history. Persisted status covers available | maintenance |
 * retired only; "out" is always DERIVED from the active rental so it can
 * never go stale.
 */
class TenantRentalUnit extends Model
{
    use HasUuids;

    protected $table = 'tenant_rental_units';

    protected $fillable = [
        'tenant_id', 'location_id', 'category_id',
        'name', 'identifier', 'size', 'status',
        'available_for_rent', 'online_booking', 'buffer_minutes',
        'condition_template_id',
        'hourly_rate_cents', 'daily_rate_cents',
        'weekend_rate_cents', 'deposit_cents',
        'acquired_at', 'metadata', 'notes', 'archived_at',
    ];

    protected $casts = [
        'available_for_rent'           => 'boolean',
        'online_booking'               => 'boolean',
        'buffer_minutes'               => 'integer',
        'hourly_rate_cents'            => 'integer',
        'daily_rate_cents'             => 'integer',
        'weekend_rate_cents'           => 'integer',
        'deposit_cents'                => 'integer',
        'acquired_at'                  => 'date',
        'metadata'                     => 'array',
        'archived_at'                  => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TenantRentalCategory::class, 'category_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(TenantLocation::class, 'location_id');
    }

    public function conditionTemplate(): BelongsTo
    {
        return $this->belongsTo(TenantRentalConditionTemplate::class, 'condition_template_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(TenantRentalLine::class, 'unit_id');
    }

    public function scopeActive($q)
    {
        return $q->whereNull('archived_at')->where('status', '!=', 'retired');
    }

    /**
     * MARKER-PATCH-218B — rates live on the unit (no category fallback).
     * Method names kept stable for future call sites (pricing, public
     * site, extensions). Null rate = not offered at that duration.
     */
    public function effectiveHourlyCents(): ?int
    {
        return $this->hourly_rate_cents;
    }

    public function effectiveDailyCents(): ?int
    {
        return $this->daily_rate_cents;
    }

    public function effectiveWeekendCents(): ?int
    {
        return $this->weekend_rate_cents;
    }

    public function effectiveDepositCents(): int
    {
        return (int) ($this->deposit_cents ?? 0);
    }
}
