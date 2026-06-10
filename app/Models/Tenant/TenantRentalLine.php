<?php
// MARKER-PATCH-217

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line on a rental: a unit or an add-on. Snapshot-on-write — renames
 * and repricing never rewrite history. Scopes via rental_id (no tenant_id),
 * same convention as TenantAppointmentItem.
 */
class TenantRentalLine extends Model
{
    use HasUuids;

    protected $table = 'tenant_rental_lines';

    protected $fillable = [
        'rental_id', 'kind', 'unit_id', 'addon_id',
        'name_snapshot', 'rate_mode_snapshot', 'rate_cents_snapshot',
        'quantity', 'duration_units', 'line_total_cents', 'sort_order',
    ];

    protected $casts = [
        'rate_cents_snapshot' => 'integer',
        'quantity'            => 'integer',
        'duration_units'      => 'integer',
        'line_total_cents'    => 'integer',
        'sort_order'          => 'integer',
    ];

    public function rental(): BelongsTo
    {
        return $this->belongsTo(TenantRental::class, 'rental_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(TenantRentalUnit::class, 'unit_id');
    }

    public function addon(): BelongsTo
    {
        return $this->belongsTo(TenantRentalAddon::class, 'addon_id');
    }
}
