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

    // MARKER-CLASSES-P1: a customer may hold several memberships, so the phase 0
    // one-active key and its active_slot upkeep are gone. The active_slot
    // column is dropped in a later release (migrations stay additive).

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'product_id',
        'status',
        'current_period_start',
        'current_period_end',
        'classes_used_this_period',
        'stripe_subscription_id',
        'sale_item_id', // MARKER-CLASSES-P1
        // MARKER-CLASSES-P2: billing
        'billing_mode', 'renewal_price_cents', 'stripe_payment_method_id', 'card_brand', 'card_last4',
        'failed_attempts', 'next_retry_at', 'last_charge_error', 'cancel_at_period_end', 'pause_until',
        'metadata',
    ];

    protected $casts = [
        'current_period_start'     => 'date',
        'current_period_end'       => 'date',
        'classes_used_this_period' => 'integer',
        'renewal_price_cents'      => 'integer',
        'failed_attempts'          => 'integer',
        'next_retry_at'            => 'datetime',
        'cancel_at_period_end'     => 'boolean',
        'pause_until'              => 'date',
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

    /** MARKER-CLASSES-P2: past_due still books while its renewal is being collected (7 days). */
    public function canCoverClass(): bool
    {
        return in_array($this->status, ['active', 'past_due'], true) && $this->hasCapacity();
    }

    /** What staff see about how this membership is paid. */
    public function billingLabel(): string
    {
        return match ($this->billing_mode) {
            'card'  => 'Card' . ($this->card_brand ? ' ' . ucfirst($this->card_brand) : '') . ($this->card_last4 ? ' ending ' . $this->card_last4 : '') . ' · renews automatically',
            'desk'  => 'Pays at the desk',
            default => 'Given by staff · no charge',
        };
    }
}
