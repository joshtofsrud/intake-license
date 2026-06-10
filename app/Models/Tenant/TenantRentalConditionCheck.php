<?php
// MARKER-PATCH-217

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A condition checklist run (check_out or check_in) against a unit on a
 * rental. A flagged check_in is what authorizes a deposit capture.
 */
class TenantRentalConditionCheck extends Model
{
    use HasUuids;

    protected $table = 'tenant_rental_condition_checks';

    protected $fillable = [
        'rental_id', 'unit_id', 'phase', 'results', 'flagged',
        'notes', 'photos', 'performed_by_user_id', 'performed_at',
    ];

    protected $casts = [
        'results'      => 'array',
        'photos'       => 'array',
        'flagged'      => 'boolean',
        'performed_at' => 'datetime',
    ];

    public function rental(): BelongsTo
    {
        return $this->belongsTo(TenantRental::class, 'rental_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(TenantRentalUnit::class, 'unit_id');
    }
}
