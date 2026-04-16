<?php
namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantAppointmentItem extends Model
{
    use HasUuids;
    protected $table    = 'tenant_appointment_items';
    protected $fillable = ['appointment_id','item_id','tier_id','item_name_snapshot','tier_name_snapshot','price_cents'];
    protected $casts    = ['price_cents' => 'integer'];
}
