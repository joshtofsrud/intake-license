<?php
namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A physical inventory item consumed during an appointment.
 *
 * Snapshot-on-write: name, sku, unit price, cost, and taxability are all
 * frozen at add-time so the appointment record is durable even if the
 * inventory catalog changes later.
 *
 * committed_at tracks when stock was actually decremented:
 *   null    → not yet committed (still in pre-completion status)
 *   set     → stock has been decremented; on refund/cancel we increment back
 */
class TenantAppointmentPart extends Model
{
    use HasUuids;

    protected $table = 'tenant_appointment_parts';

    protected $fillable = [
        'appointment_id',
        'inventory_item_id',
        'appointment_asset_id', // MARKER-PATCH-158-G4 — pins this part to a specific asset card
        'item_name_snapshot',
        'item_sku_snapshot',
        'quantity',
        'unit_price_cents',
        'unit_price_cents_override',
        'cost_cents_at_time',
        'is_taxable',
        'committed_at',
        'is_special_order',   // MARKER-PATCH-419 — per-line "add to special orders" (default on)
        'special_order_id',   // MARKER-PATCH-419 — link to the needed SO this line spawned
    ];

    protected $casts = [
        'quantity'                  => 'integer',
        'unit_price_cents'          => 'integer',
        'unit_price_cents_override' => 'integer',
        'cost_cents_at_time'        => 'integer',
        'is_taxable'                => 'boolean',
        'committed_at'              => 'datetime',
        'is_special_order'          => 'boolean', // MARKER-PATCH-419
    ];

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(TenantAppointment::class, 'appointment_id');
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(TenantInventoryItem::class, 'inventory_item_id');
    }

    // MARKER-PATCH-419 — the needed special order this line spawned, if any.
    public function specialOrder(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Tenant\TenantSpecialOrder::class, 'special_order_id');
    }

    // MARKER-PATCH-158-G4 — nullable; null means "loose" on the appointment
    public function appointmentAsset(): BelongsTo
    {
        return $this->belongsTo(TenantAppointmentAsset::class, 'appointment_asset_id');
    }

    /** Effective unit price = override if set, otherwise the snapshot. */
    public function effectiveUnitPriceCents(): int
    {
        return $this->unit_price_cents_override ?? $this->unit_price_cents;
    }

    /** Total line price = effective unit price × quantity. */
    public function lineTotalCents(): int
    {
        return $this->effectiveUnitPriceCents() * $this->quantity;
    }

    public function isCommitted(): bool
    {
        return $this->committed_at !== null;
    }
}
