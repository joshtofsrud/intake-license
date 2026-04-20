<?php
namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantAddon extends Model
{
    use HasUuids;
    protected $table = 'tenant_addons';
    protected $fillable = [
        'tenant_id','name','description','price_cents',
        'default_duration_minutes','is_active','sort_order',
    ];
    protected $casts = [
        'is_active'                => 'boolean',
        'price_cents'              => 'integer',
        'default_duration_minutes' => 'integer',
        'sort_order'               => 'integer',
    ];

    public function serviceAddons(): HasMany
    {
        return $this->hasMany(TenantServiceAddon::class, 'addon_id');
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(
            TenantServiceItem::class, 'tenant_service_addons',
            'addon_id', 'service_item_id'
        )->using(TenantServiceAddon::class)
         ->withPivot(['id', 'override_duration_minutes', 'override_price_cents', 'sort_order'])
         ->withTimestamps();
    }

    public function usageCount(): int
    {
        return $this->serviceAddons()->count();
    }
}
