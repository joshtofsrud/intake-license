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
        'model_id', // MARKER-PATCH-226
        'name', 'identifier', 'size', 'status',
        'identifier_values', // MARKER-FLEET-IDENT
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
        'identifier_values'            => 'array', // MARKER-FLEET-IDENT
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

    public function model(): BelongsTo // MARKER-PATCH-226
    {
        return $this->belongsTo(TenantRentalModel::class, 'model_id');
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
     * MARKER-PATCH-226 — rates live on the MODEL now. These methods stay
     * the seam every caller (pricing, availability, public site,
     * extensions) reads through, so moving rates up required no caller
     * changes. Read through the loaded model; fall back to the unit's own
     * legacy columns only if the model relation isn't loaded/available
     * (defensive during the one-release column overlap).
     */
    public function effectiveHourlyCents(): ?int
    {
        return $this->model?->hourly_rate_cents ?? $this->hourly_rate_cents;
    }

    public function effectiveDailyCents(): ?int
    {
        return $this->model?->daily_rate_cents ?? $this->daily_rate_cents;
    }

    public function effectiveWeekendCents(): ?int
    {
        return $this->model?->weekend_rate_cents ?? $this->weekend_rate_cents;
    }

    public function effectiveSeasonalCents(): ?int // MARKER-PATCH-226
    {
        return $this->model?->seasonal_rate_cents;
    }

    public function effectiveDepositCents(): int
    {
        return (int) ($this->model?->deposit_cents ?? $this->deposit_cents ?? 0);
    }

    public function effectiveConditionTemplateId(): ?string
    {
        return $this->model?->condition_template_id ?? $this->condition_template_id;
    }
}
