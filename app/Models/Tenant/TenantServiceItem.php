<?php
namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantServiceItem extends Model
{
    use HasUuids;
    protected $table    = 'tenant_service_items';
    protected $fillable = ['tenant_id','category_id','name','slug','description','image_url','is_active','sort_order'];
    protected $casts    = ['is_active' => 'boolean'];

    public function category(): BelongsTo    { return $this->belongsTo(TenantServiceCategory::class, 'category_id'); }
    public function tierPrices(): HasMany    { return $this->hasMany(TenantItemTierPrice::class, 'item_id'); }
    public function addons(): HasMany        { return $this->hasMany(TenantItemAddon::class, 'item_id'); }
}
