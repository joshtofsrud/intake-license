<?php
namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantReceivingMethod extends Model
{
    use HasUuids;
    protected $table    = 'tenant_receiving_methods';
    protected $fillable = ['tenant_id','name','slug','description','ask_for_time','ask_for_tracking','is_active','sort_order'];
    protected $casts    = ['ask_for_time' => 'boolean', 'ask_for_tracking' => 'boolean', 'is_active' => 'boolean'];
}
