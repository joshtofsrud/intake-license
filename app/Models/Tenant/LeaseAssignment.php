<?php
// MARKER-PATCH-230

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One unit pulled from the fleet for a lease's season. Carries snapshots so
 * the lease record stays truthful even if the unit is later renamed or
 * retired. Active assignments block the unit in the availability brain.
 */
class LeaseAssignment extends Model
{
    use HasUuids;

    protected $table = 'lease_assignments';

    protected $fillable = [
        'tenant_id', 'lease_id', 'slot_id', 'unit_id',
        'unit_name_snapshot', 'unit_serial_snapshot', 'category_name_snapshot',
        'return_condition', 'returned_at',
    ];

    protected $casts = [
        'returned_at' => 'datetime',
    ];

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class, 'lease_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(TenantRentalUnit::class, 'unit_id');
    }
}
