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
        'duration_minutes_snapshot',
    ];
    protected $casts = [
        'price_cents'               => 'integer',
        'duration_minutes_snapshot' => 'integer',
    ];

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(TenantAppointment::class, 'appointment_id');
    }

    public function addon(): BelongsTo
    {
        return $this->belongsTo(TenantAddon::class, 'addon_id');
    }
}
