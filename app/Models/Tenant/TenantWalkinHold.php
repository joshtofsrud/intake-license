<?php
namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantWalkinHold extends Model
{
    use HasUuids;

    protected $table = 'tenant_walkin_holds';

    protected $fillable = [
        'tenant_id',
        'resource_id',
        'starts_at',
        'ends_at',
        'auto_release_at',
        'notes',
        'is_recurring',
        'recurrence_type',
        'recurrence_config',
        'recurrence_until',
        'converted_to_appointment_id',
        'converted_at',
        'created_by_user_id',
    ];

    protected $casts = [
        'starts_at'         => 'datetime',
        'ends_at'           => 'datetime',
        'auto_release_at'   => 'datetime',
        'converted_at'      => 'datetime',
        'is_recurring'      => 'boolean',
        'recurrence_config' => 'array',
        'recurrence_until'  => 'date',
    ];

    public function resource(): BelongsTo
    {
        return $this->belongsTo(TenantResource::class, 'resource_id');
    }

    public function convertedToAppointment(): BelongsTo
    {
        return $this->belongsTo(TenantAppointment::class, 'converted_to_appointment_id');
    }

    public function scopeForTenant(Builder $q, string $tenantId): Builder
    {
        return $q->where('tenant_id', $tenantId);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('converted_at');
    }

    public function scopeConverted(Builder $q): Builder
    {
        return $q->whereNotNull('converted_at');
    }
}
