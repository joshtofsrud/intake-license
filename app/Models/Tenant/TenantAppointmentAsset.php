<?php
// MARKER-PATCH-158-A

namespace App\Models\Tenant;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pivot model: a customer asset attached to a specific appointment.
 *
 * Snapshots name + identifier at attachment time so that renaming the
 * underlying asset later doesn't rewrite history. Subtotal denormalized
 * for the right-rail rollup; recalculated whenever items/addons change.
 */
class TenantAppointmentAsset extends Model
{
    use HasUuids;

    protected $table = 'tenant_appointment_assets';

    protected $fillable = [
        'tenant_id',
        'appointment_id',
        'customer_asset_id',
        'asset_name_snapshot',
        'identifier_snapshot',
        'sort_order',
        'subtotal_cents',
    ];

    protected $casts = [
        'sort_order'     => 'integer',
        'subtotal_cents' => 'integer',
    ];

    // ----------------------------------------------------------------
    // Relations
    // ----------------------------------------------------------------
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(TenantAppointment::class, 'appointment_id');
    }

    /**
     * The persistent customer asset this row references. Survives even if
     * the underlying asset is archived — archive doesn't cascade.
     */
    public function customerAsset(): BelongsTo
    {
        return $this->belongsTo(TenantCustomerAsset::class, 'customer_asset_id');
    }

    /**
     * Services pinned to this asset on this appointment.
     */
    public function items(): HasMany
    {
        return $this->hasMany(TenantAppointmentItem::class, 'appointment_asset_id');
    }

    /**
     * Add-ons pinned to this asset on this appointment.
     */
    public function addons(): HasMany
    {
        return $this->hasMany(TenantAppointmentAddon::class, 'appointment_asset_id');
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    /**
     * Recompute subtotal from attached items + addons. Caller is responsible
     * for persisting; this just returns the new value to keep transactions
     * explicit at the call site.
     */
    public function computeSubtotalCents(): int
    {
        $items  = $this->items()->sum('price_cents');
        $addons = $this->addons()->sum('price_cents');
        return (int) $items + (int) $addons;
    }

    /**
     * Recompute and persist. For convenience when caller doesn't care about
     * the value.
     */
    public function refreshSubtotal(): void
    {
        $this->update(['subtotal_cents' => $this->computeSubtotalCents()]);
    }
}
