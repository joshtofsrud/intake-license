<?php
namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantAppointmentAddon extends Model
{
    use HasUuids;
    protected $table    = 'tenant_appointment_addons';
    protected $fillable = ['appointment_id','addon_id','addon_name_snapshot','price_cents'];
    protected $casts    = ['price_cents' => 'integer'];
}
