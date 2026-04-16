<?php
namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantAppointmentCharge extends Model
{
    use HasUuids;
    public    $timestamps = false;
    protected $table      = 'tenant_appointment_charges';
    protected $fillable   = ['appointment_id','description','amount_cents','is_paid','created_at'];
    protected $casts      = ['is_paid' => 'boolean', 'amount_cents' => 'integer', 'created_at' => 'datetime'];
}
