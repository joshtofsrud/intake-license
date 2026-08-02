<?php

namespace App\Models\Tenant;

use App\Models\PlatformDistributorCatalog;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-scoped vendor.
 *
 * A vendor is "someone I buy from" — QBP, Hawley, Amazon Business, the
 * local distributor down the street, etc. Distinct from
 * PlatformDistributorCatalog (the global sync source); a vendor can
 * optionally link to one via distributor_catalog_id when there's a
 * platform-level relationship.
 */
class TenantVendor extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'tenant_vendors';

    protected $fillable = [
        'tenant_id',
        'name',
        'contact_email',
        'contact_phone',
        'website',
        'account_number',
        'distributor_catalog_id',
        'notes',
        'is_active',
        'free_freight_cents', // MARKER-SO-PLACEMENT
        'program_discount_pct', 'distributor_code', // MARKER-VENDOR-NET-COST
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'free_freight_cents' => 'integer', // MARKER-SO-PLACEMENT
        'program_discount_pct' => 'decimal:2', // MARKER-VENDOR-NET-COST
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * MARKER-VENDOR-NET-COST — apply this vendor's program discount.
     *
     * Null cost stays null: "we don't know what this costs" must not become
     * "it's free", which would make it win every lowest-price comparison.
     */
    public function netCostCents(?int $listCents): ?int
    {
        if ($listCents === null) {
            return null;
        }

        $pct = (float) ($this->program_discount_pct ?? 0);
        if ($pct <= 0) {
            return $listCents;
        }

        return (int) round($listCents * (1 - min($pct, 100) / 100));
    }

    public function distributorCatalog(): BelongsTo
    {
        return $this->belongsTo(PlatformDistributorCatalog::class, 'distributor_catalog_id');
    }

    /**
     * Items sourced from this vendor, through the pivot.
     */
    public function items(): BelongsToMany
    {
        return $this->belongsToMany(
            TenantInventoryItem::class,
            'tenant_inventory_item_vendors',
            'vendor_id',
            'inventory_item_id'
        )
            ->using(TenantInventoryItemVendor::class)
            ->withPivot(['vendor_sku', 'unit_cost_cents', 'lead_time_days', 'is_preferred', 'last_ordered_at'])
            ->withTimestamps();
    }

    /**
     * Special orders placed with this vendor.
     */
    public function specialOrders(): HasMany
    {
        return $this->hasMany(TenantSpecialOrder::class, 'vendor_id');
    }

    /**
     * Receive shipments from this vendor. Only finds shipments that
     * were linked to this vendor by FK — legacy receive shipments
     * with vendor_name string but no vendor_id will not appear here.
     */
    public function receiveShipments(): HasMany
    {
        return $this->hasMany(TenantInventoryReceiveShipment::class, 'vendor_id');
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }
}
