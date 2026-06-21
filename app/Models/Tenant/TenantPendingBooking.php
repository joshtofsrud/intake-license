<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MARKER-PATCH-382 — A held booking slot awaiting deposit payment. Materializes
 * into a TenantAppointment only after the PaymentIntent succeeds; otherwise it
 * expires and the reaper drops it, so a declined or abandoned card never leaves
 * an appointment behind.
 */
class TenantPendingBooking extends Model
{
    use HasUuids;

    protected $table = 'tenant_pending_bookings';

    protected $fillable = [
        'tenant_id', 'token', 'status',
        'resource_id', 'booking_date', 'appointment_time', 'slot_weight',
        'total_cents', 'stripe_payment_intent_id', 'payload',
        'contact_email', 'contact_name', 'appointment_id', 'expires_at',
    ];

    protected $casts = [
        'payload'      => 'array',
        'booking_date' => 'date',
        'expires_at'   => 'datetime',
        'slot_weight'  => 'integer',
        'total_cents'  => 'integer',
    ];

    /* Relations */
    public function tenant(): BelongsTo      { return $this->belongsTo(\App\Models\Tenant::class, 'tenant_id'); }
    public function resource(): BelongsTo    { return $this->belongsTo(TenantResource::class, 'resource_id'); }
    public function appointment(): BelongsTo { return $this->belongsTo(TenantAppointment::class, 'appointment_id'); }

    /* Helpers */
    public function isPending(): bool      { return $this->status === 'pending'; }
    public function isMaterialized(): bool { return $this->status === 'materialized'; }

    /** A hold still occupies its slot only while pending and unexpired. */
    public function isActiveHold(): bool
    {
        return $this->status === 'pending'
            && $this->expires_at
            && $this->expires_at->isFuture();
    }
}
