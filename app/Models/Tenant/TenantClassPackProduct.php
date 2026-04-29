<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TenantClassPackProduct extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'tenant_class_pack_products';

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'credit_count',
        'expiry_days',
        'price_cents',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'credit_count' => 'integer',
        'expiry_days'  => 'integer',
        'price_cents'  => 'integer',
        'is_active'    => 'boolean',
        'metadata'     => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Tenant::class);
    }

    public function customerPacks(): HasMany
    {
        return $this->hasMany(TenantCustomerPack::class, 'product_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeForTenant(Builder $q, string $tenantId): Builder
    {
        return $q->where('tenant_id', $tenantId);
    }

    public function priceCentsFormatted(): string
    {
        return '$' . number_format($this->price_cents / 100, 2);
    }

    public function pricePerCredit(): float
    {
        return $this->credit_count > 0 ? $this->price_cents / $this->credit_count : 0;
    }
}
