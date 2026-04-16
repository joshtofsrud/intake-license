<?php
namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantServiceTier extends Model
{
    use HasUuids;
    protected $table    = 'tenant_service_tiers';
    protected $fillable = ['tenant_id','name','slug','description','is_active','sort_order'];
    protected $casts    = ['is_active' => 'boolean'];
}
