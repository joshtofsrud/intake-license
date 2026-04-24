<?php
namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantResource extends Model
{
    use HasUuids;

    protected $table = 'tenant_resources';

    protected $fillable = [
        'tenant_id',
        'name',
        'subtitle',
        'color_hex',
        'type',
        'staff_user_id',
        'sort_order',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
        'metadata'   => 'array',
    ];

    // ------------------------------------------------------------------
    // Relations
    // ------------------------------------------------------------------

    public function appointments(): HasMany
    {
        return $this->hasMany(TenantAppointment::class, 'resource_id');
    }

    public function breaks(): HasMany
    {
        return $this->hasMany(TenantCalendarBreak::class, 'resource_id');
    }

    public function walkinHolds(): HasMany
    {
        return $this->hasMany(TenantWalkinHold::class, 'resource_id');
    }

    public function staffUser(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'staff_user_id');
    }

    // ------------------------------------------------------------------
    // Query scopes — grep-able call sites at scale
    // ------------------------------------------------------------------

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeOrdered(Builder $q): Builder
    {
        return $q->orderBy('sort_order')->orderBy('name');
    }

    public function scopeForTenant(Builder $q, string $tenantId): Builder
    {
        return $q->where('tenant_id', $tenantId);
    }

    public function scopeOfType(Builder $q, string $type): Builder
    {
        return $q->where('type', $type);
    }

    // ------------------------------------------------------------------
    // Display helpers
    // ------------------------------------------------------------------

    /**
     * Full label for calendar headers, e.g. "Maya · senior stylist"
     * Falls back to just the name if no subtitle.
     */
    public function displayLabel(): string
    {
        return $this->subtitle
            ? "{$this->name} · {$this->subtitle}"
            : $this->name;
    }

    /**
     * Stable color for frontend — guarantees a hex even if the
     * column somehow got nulled. Defaults to neutral gray.
     */
    public function displayColor(): string
    {
        return $this->color_hex ?: '#8A8A82';
    }
}
