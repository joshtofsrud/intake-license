<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MARKER-SERIAL-FOUNDATION — one serialized unit of an inventory item.
 *
 * Quantities still come from the movement ledger; a unit is the identity
 * behind one of those counted pieces. On-hand pieces without a unit row are
 * the ones that "need a serial" (stock that predates tracking).
 */
class TenantInventoryUnit extends Model
{
    use HasUuids;

    protected $table = 'tenant_inventory_units';

    public const STATUS_IN_STOCK    = 'in_stock';
    public const STATUS_SOLD        = 'sold';
    public const STATUS_WRITTEN_OFF = 'written_off';

    protected $fillable = [
        'tenant_id', 'inventory_item_id', 'location_id', 'serial', 'serial_key', 'status',
        'cost_cents', 'received_shipment_id', 'received_shipment_item_id', 'received_at',
        'sale_id', 'sold_at', 'written_off_at', 'write_off_reason', 'notes', 'created_by',
    ];

    protected $casts = [
        'cost_cents'     => 'integer',
        'received_at'    => 'datetime',
        'sold_at'        => 'datetime',
        'written_off_at' => 'datetime',
    ];

    /** The form a serial is compared in: upper-case, no spaces. */
    public static function keyFor(string $serial): string
    {
        return strtoupper(preg_replace('/\s+/', '', $serial));
    }

    /** The form a serial is stored and shown in: trimmed, inner spaces collapsed. */
    public static function clean(string $serial): string
    {
        return trim(preg_replace('/\s+/', ' ', $serial));
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(TenantInventoryItem::class, 'inventory_item_id')->withTrashed();
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(TenantLocation::class, 'location_id');
    }
}
