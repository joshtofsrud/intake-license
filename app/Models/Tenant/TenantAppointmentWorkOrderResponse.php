<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantAppointmentWorkOrderResponse extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'tenant_appointment_work_order_responses';

    protected $fillable = [
        'tenant_id',
        'appointment_id',
        'field_id',
        'appointment_asset_id', // MARKER-PATCH-158-G5 — pins this response to a specific asset card
        'field_label_snapshot',
        'response_value',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Tenant::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(TenantAppointment::class, 'appointment_id');
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(TenantWorkOrderField::class, 'field_id');
    }

    // MARKER-PATCH-158-G5
    public function appointmentAsset(): BelongsTo
    {
        return $this->belongsTo(TenantAppointmentAsset::class, 'appointment_asset_id');
    }
}
