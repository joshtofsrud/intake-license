<?php
namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantAppointmentResponse extends Model
{
    use HasUuids;
    protected $table    = 'tenant_appointment_responses';
    protected $fillable = ['appointment_id','field_key_snapshot','field_label_snapshot','response_value'];
}
