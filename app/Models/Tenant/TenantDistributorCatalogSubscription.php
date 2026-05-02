<?php

namespace App\Models\Tenant;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-tenant toggle for which distributor catalogs are active.
 *
 * Bike shop on BTI + QBP but not J&B = two rows, both is_active=true,
 * no row for J&B.
 *
 * UPC scan only searches across distributors the tenant has subscribed to.
 *
 * Decoupled from billing — Bike Pack add-on gates whether ANY distributor
 * sync is available, but within that, the tenant chooses which feeds.
 */
class TenantDistributorCatalogSubscription extends Model
{
    use HasUuids;

    protected $table = 'tenant_distributor_catalog_subscriptions';

    protected $fillable = [
        'tenant_id',
        'distributor_code',
        'is_active',
        'account_number',
        'credentials_encrypted',
        'last_sync_at',
        'last_sync_status',
        'last_sync_error',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'credentials_encrypted' => 'array',
        'last_sync_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
