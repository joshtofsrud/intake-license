<?php
namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantNavItem extends Model
{
    use HasUuids;
    protected $table    = 'tenant_nav_items';
    protected $fillable = ['tenant_id','label','url','is_external','open_in_new_tab','sort_order'];
    protected $casts    = ['is_external' => 'boolean', 'open_in_new_tab' => 'boolean'];
}
