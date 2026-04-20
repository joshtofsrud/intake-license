<?php
namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantServiceItem extends Model
{
    use HasUuids;
    protected $table = 'tenant_service_items';
    protected $fillable = [
        'tenant_id','category_id','name','slug','description','image_url',
        'price_cents','prep_before_minutes','duration_minutes','cleanup_after_minutes',
        'slot_weight','is_active','sort_order',
    ];
    protected $casts = [
        'is_active'             => 'boolean',
        'price_cents'           => 'integer',
        'prep_before_minutes'   => 'integer',
        'duration_minutes'      => 'integer',
        'cleanup_after_minutes' => 'integer',
        'slot_weight'           => 'integer',
        'sort_order'            => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(TenantServiceCategory::class, 'category_id');
    }

    public function serviceAddons(): HasMany
    {
        return $this->hasMany(TenantServiceAddon::class, 'service_item_id')->orderBy('sort_order');
    }

    public function addons(): BelongsToMany
    {
        return $this->belongsToMany(
            TenantAddon::class, 'tenant_service_addons',
            'service_item_id', 'addon_id'
        )->using(TenantServiceAddon::class)
         ->withPivot(['id', 'override_duration_minutes', 'override_price_cents', 'sort_order'])
         ->withTimestamps();
    }

    public function customerMinutes(): int
    {
        $total = (int) $this->duration_minutes;
        foreach ($this->serviceAddons as $pivot) $total += $pivot->effectiveDuration();
        return $total;
    }

    public function wallClockMinutes(): int
    {
        $total = (int) $this->prep_before_minutes
               + (int) $this->duration_minutes
               + (int) $this->cleanup_after_minutes;
        foreach ($this->serviceAddons as $pivot) $total += $pivot->effectiveDuration();
        return $total;
    }
}
