<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TenantCustomerMembership extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'tenant_customer_memberships';

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'product_id',
        'status',
        'current_period_start',
        'current_period_end',
        'classes_used_this_period',
        'stripe_subscription_id',
        'metadata',
    ];

    protected $casts = [
        'current_period_start'     => 'date',
        'current_period_end'       => 'date',
        'classes_used_this_period' => 'integer',
        'metadata'                 => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Tenant::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(TenantCustomer::class, 'customer_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(TenantClassMembershipProduct::class, 'product_id');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(TenantClassRegistration::class, 'membership_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', 'active');
    }

    public function scopeForTenant(Builder $q, string $tenantId): Builder
    {
        return $q->where('tenant_id', $tenantId);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function hasCapacity(): bool
    {
        if ($this->product->isUnlimited()) {
            return true;
        }

        return $this->classes_used_this_period < $this->product->monthly_limit;
    }

    public function canCoverClass(): bool
    {
        return $this->isActive() && $this->hasCapacity();
    }
}
