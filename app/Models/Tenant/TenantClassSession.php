<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TenantClassSession extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'tenant_class_sessions';

    protected $fillable = [
        'tenant_id',
        'class_template_id',
        'starts_at',
        'ends_at',
        'instructor_snapshot',
        'instructor_resource_id',
        'capacity_snapshot',
        'status',
        'notes',
        'session_notes_override',
        'metadata',
    ];

    protected $casts = [
        'starts_at'         => 'datetime',
        'ends_at'           => 'datetime',
        'capacity_snapshot' => 'integer',
        'metadata'          => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Tenant::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(TenantClassTemplate::class, 'class_template_id');
    }

    public function instructorResource(): BelongsTo
    {
        return $this->belongsTo(TenantResource::class, 'instructor_resource_id');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(TenantClassRegistration::class, 'class_session_id');
    }

    public function activeRegistrations(): HasMany
    {
        return $this->registrations()->whereIn('status', ['registered', 'checked_in']);
    }

    public function waitlist(): HasMany
    {
        return $this->registrations()->where('status', 'waitlisted')->orderBy('waitlist_position');
    }

    public function scopeForTenant(Builder $q, string $tenantId): Builder
    {
        return $q->where('tenant_id', $tenantId);
    }

    public function scopeUpcoming(Builder $q): Builder
    {
        return $q->where('starts_at', '>', now())->orderBy('starts_at');
    }

    public function scopeStatus(Builder $q, string $status): Builder
    {
        return $q->where('status', $status);
    }

    public function spotsRemaining(): int
    {
        return max(0, $this->capacity_snapshot - $this->activeRegistrations()->count());
    }

    public function isFull(): bool
    {
        return $this->spotsRemaining() === 0;
    }

    public function isBookable(): bool
    {
        return in_array($this->status, ['scheduled', 'confirmed']) && !$this->isFull();
    }
}
