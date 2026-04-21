<?php

namespace App\Models\Tenant;

use App\Models\Addon;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * TenantFeatureAddon — pivot between tenants and the addon catalog.
 *
 * Tracks which tenant has which addon, how they got it, and its billing state.
 * This is DISTINCT from TenantAddon (the service-addon model used by the
 * services admin, e.g. "extra 15 minutes" on a massage).
 *
 * Table: tenant_addons (the feature-addon pivot table created by the
 * addon framework migration 2026_04_20_000004).
 */
class TenantFeatureAddon extends Model
{
    protected $table = 'tenant_addons';

    protected $fillable = [
        'tenant_id',
        'addon_code',
        'source',
        'status',
        'stripe_subscription_item_id',
        'stripe_price_id',
        'activated_at',
        'canceling_at',
        'current_period_end',
        'expired_at',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'activated_at' => 'datetime',
        'canceling_at' => 'datetime',
        'current_period_end' => 'datetime',
        'expired_at' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function addon()
    {
        return $this->belongsTo(Addon::class, 'addon_code', 'code');
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['active', 'canceling', 'failed_payment']);
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['active', 'canceling', 'failed_payment'], true);
    }

    public function isCanceling(): bool
    {
        return $this->status === 'canceling';
    }
}
