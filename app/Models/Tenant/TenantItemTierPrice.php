<?php
namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantItemTierPrice extends Model
{
    use HasUuids;
    protected $table    = 'tenant_item_tier_prices';
    protected $fillable = ['tenant_id','item_id','tier_id','price_cents'];
    protected $casts    = ['price_cents' => 'integer'];
}
