<?php
namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantItemAddon extends Model
{
    use HasUuids;
    protected $table    = 'tenant_item_addons';
    protected $fillable = ['item_id','addon_id'];
}
