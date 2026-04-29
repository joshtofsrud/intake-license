<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TenantCustomerPack extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'tenant_customer_packs';

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'product_id',
        'credits_total',
        'credits_remaining',
        'expires_at',
        'status',
        'stripe_payment_intent_id',
        'metadata',
    ];

    protected $casts = [
        'credits_total'     => 'integer',
        'credits_remaining' => 'integer',
        'expires_at'        => 'date',
        'metadata'          => 'array',
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
        return $this->belongsTo(TenantClassPackProduct::class, 'product_id');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(TenantClassRegistration::class, 'pack_id');
    }

    public function scopeForTenant(Builder $q, string $tenantId): Builder
    {
        return $q->where('tenant_id', $tenantId);
    }

    public function scopeUsable(Builder $q): Builder
    {
        return $q->where('status', 'active')
                 ->where('credits_remaining', '>', 0)
                 ->where('expires_at', '>=', now()->toDateString());
    }

    public function scopeConsumptionOrder(Builder $q): Builder
    {
        return $q->usable()->orderBy('expires_at');
    }

    public function isUsable(): bool
    {
        return $this->status === 'active'
            && $this->credits_remaining > 0
            && $this->expires_at->gte(now()->toDateString());
    }

    public function deductCredit(): void
    {
        $this->decrement('credits_remaining');

        if ($this->credits_remaining === 0) {
            $this->update(['status' => 'exhausted']);
        }
    }
}
