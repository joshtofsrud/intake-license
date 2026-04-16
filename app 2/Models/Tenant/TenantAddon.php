<?php
namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantAddon extends Model
{
    use HasUuids;
    protected $table    = 'tenant_addons';
    protected $fillable = ['tenant_id','name','description','price_cents','is_active','sort_order'];
    protected $casts    = ['is_active' => 'boolean', 'price_cents' => 'integer'];
}
