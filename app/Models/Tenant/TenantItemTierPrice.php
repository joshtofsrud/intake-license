<?php
namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantItemTierPrice extends Model
{
    use HasUuids;
    protected $table    = 'tenant_item_tier_prices';
    protected $fillable = ['tenant_id','item_id','tier_id','price_cents'];
    protected $casts    = ['price_cents' => 'integer'];

    public function tier(): BelongsTo
    {
        return $this->belongsTo(TenantServiceTier::class, 'tier_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(TenantServiceItem::class, 'item_id');
    }
}
