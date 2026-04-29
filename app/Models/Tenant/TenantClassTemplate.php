<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TenantClassTemplate extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'tenant_class_templates';

    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'description',
        'duration_minutes',
        'default_capacity',
        'service_item_id',
        'instructor_resource_id',
        'price_cents',
        'is_active',
        'rrule',
        'metadata',
    ];

    protected $casts = [
        'duration_minutes'  => 'integer',
        'default_capacity'  => 'integer',
        'price_cents'       => 'integer',
        'is_active'         => 'boolean',
        'metadata'          => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Tenant::class);
    }

    public function serviceItem(): BelongsTo
    {
        return $this->belongsTo(TenantServiceItem::class, 'service_item_id');
    }

    public function instructorResource(): BelongsTo
    {
        return $this->belongsTo(TenantResource::class, 'instructor_resource_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(TenantClassSession::class, 'class_template_id');
    }

    public function upcomingSessions(): HasMany
    {
        return $this->sessions()->where('starts_at', '>', now())->orderBy('starts_at');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeForTenant(Builder $q, string $tenantId): Builder
    {
        return $q->where('tenant_id', $tenantId);
    }

    public function priceCentsFormatted(): string
    {
        return '$' . number_format($this->price_cents / 100, 2);
    }
}
