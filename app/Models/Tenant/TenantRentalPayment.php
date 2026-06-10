<?php
// MARKER-PATCH-217

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * RAIL 2 — the rental payment ledger. Column-for-column mirror of
 * TenantAppointmentPayment so revenue + reconciliation extend with one
 * UNION arm each.
 *
 * kind:   charge | refund | deposit_capture
 * source: desk | online | extension
 *
 * Refunds are NEGATIVE amount_cents rows (sale-payments convention).
 * Deposit AUTHORIZATIONS never write here — an auth is not money.
 */
class TenantRentalPayment extends Model
{
    use HasUuids;

    protected $table = 'tenant_rental_payments';

    protected $fillable = [
        'tenant_id', 'rental_id', 'amount_cents',
        'kind', 'source', 'method',
        'external_reference', 'recorded_by_user_id', 'recorded_at', 'notes',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'recorded_at'  => 'datetime',
    ];

    public function rental(): BelongsTo
    {
        return $this->belongsTo(TenantRental::class, 'rental_id');
    }
}
