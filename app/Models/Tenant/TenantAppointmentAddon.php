<?php
namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantAppointmentAddon extends Model
{
    use HasUuids;
    protected $table    = 'tenant_appointment_addons';
    protected $fillable = [
        'appointment_id',
        'addon_id',
        'addon_name_snapshot',
        'price_cents',
        'price_cents_override',
        'duration_minutes_snapshot',
        'duration_minutes_override',
    ];
    protected $casts = [
        'price_cents'               => 'integer',
        'price_cents_override'      => 'integer',
        'duration_minutes_snapshot' => 'integer',
        'duration_minutes_override' => 'integer',
    ];

    /** Effective price = override if set, otherwise the snapshot. */
    public function effectivePriceCents(): int
    {
        return $this->price_cents_override ?? $this->price_cents;
    }

    /** Effective duration = override if set, otherwise the snapshot. */
    public function effectiveDurationMinutes(): int
    {
        return $this->duration_minutes_override ?? $this->duration_minutes_snapshot;
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(TenantAppointment::class, 'appointment_id');
    }

    public function addon(): BelongsTo
    {
        return $this->belongsTo(TenantAddon::class, 'addon_id');
    }
}
