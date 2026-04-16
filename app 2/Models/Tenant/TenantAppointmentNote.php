<?php
namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantAppointmentNote extends Model
{
    use HasUuids;
    public    $timestamps = false;
    protected $table      = 'tenant_appointment_notes';
    protected $fillable   = ['appointment_id','user_id','note_type','is_customer_visible','note_content','created_at'];
    protected $casts      = ['is_customer_visible' => 'boolean', 'created_at' => 'datetime'];
}
