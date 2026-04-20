<?php
namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantAppointmentItem extends Model
{
    use HasUuids;
    protected $table    = 'tenant_appointment_items';
    protected $fillable = [
        'appointment_id',
        'service_item_id',
        'item_name_snapshot',
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

    public function serviceItem(): BelongsTo
    {
        return $this->belongsTo(TenantServiceItem::class, 'service_item_id');
    }
}
