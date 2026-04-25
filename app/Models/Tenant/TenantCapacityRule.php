<?php
namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantCapacityRule extends Model
{
    use HasUuids;
    protected $table    = 'tenant_capacity_rules';
    protected $fillable = ['tenant_id','rule_type','day_of_week','specific_date','max_appointments','note','open_time','close_time','slot_interval_minutes'];
    protected $casts    = ['specific_date' => 'date', 'max_appointments' => 'integer'];
}
