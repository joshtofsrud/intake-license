<?php
namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantCalendarBreak extends Model
{
    use HasUuids;

    protected $table = 'tenant_calendar_breaks';

    protected $fillable = [
        'tenant_id',
        'resource_id',
        'label',
        'starts_at',
        'ends_at',
        'is_recurring',
        'recurrence_type',
        'recurrence_config',
        'recurrence_until',
        'created_by_user_id',
    ];

    protected $casts = [
        'starts_at'         => 'datetime',
        'ends_at'           => 'datetime',
        'is_recurring'      => 'boolean',
        'recurrence_config' => 'array',
        'recurrence_until'  => 'date',
    ];

    public function resource(): BelongsTo
    {
        return $this->belongsTo(TenantResource::class, 'resource_id');
    }

    public function scopeForTenant(Builder $q, string $tenantId): Builder
    {
        return $q->where('tenant_id', $tenantId);
    }

    public function scopeShopWide(Builder $q): Builder
    {
        return $q->whereNull('resource_id');
    }
}
