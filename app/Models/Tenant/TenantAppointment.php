<?php
namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use App\Models\Tenant;
use App\Support\AppointmentStatus;

class TenantAppointment extends Model
{
    use HasUuids;
    protected $table    = 'tenant_appointments';
    protected $fillable = [
        'tenant_id','customer_id','resource_id','location_id','ra_number',
        'customer_first_name','customer_last_name','customer_email','customer_phone',
        'appointment_date','appointment_time','appointment_end_time',
        'promised_at', // MARKER-PATCH-311
        'pickup_outreach_pending', // MARKER-PICKUP-OUTREACH
        'delivery_resolution', 'delivery_resolved_at',            // MARKER-DELIVERY-RESOLUTION
        'delivery_resolved_by_user_id', 'delivery_snooze_until',  // MARKER-DELIVERY-RESOLUTION
        'total_duration_minutes','prep_before_minutes_snapshot','cleanup_after_minutes_snapshot',
        'slot_weight','slot_weight_auto','slot_weight_overridden',
        'receiving_method_snapshot','receiving_time_snapshot','tracking_number',
        'status','payment_status','payment_method',
        'stripe_payment_intent_id','paypal_order_id',
        'subtotal_cents','tax_cents','total_cents','paid_cents','staff_notes',
        'invoice_note','invoice_terms', // MARKER-PATCH-204
        'needs_time_review',
        'reminded_at', // MARKER-PATCH-154
        'completed_at', // MARKER-PATCH-481
    ];
    protected $casts = [
        'appointment_date'         => 'date',
        'total_duration_minutes'         => 'integer',
        'prep_before_minutes_snapshot'   => 'integer',
        'cleanup_after_minutes_snapshot' => 'integer',
        'slot_weight'                    => 'integer',
        'slot_weight_auto'         => 'integer',
        'slot_weight_overridden'   => 'boolean',
        'needs_time_review'        => 'boolean',
        'subtotal_cents'           => 'integer',
        'tax_cents'                => 'integer',
        'total_cents'              => 'integer',
        'paid_cents'               => 'integer',
        'reminded_at'              => 'datetime', // MARKER-PATCH-154
        'promised_at'              => 'datetime', // MARKER-PATCH-311
        'completed_at'             => 'datetime', // MARKER-PATCH-481
    ];

    // MARKER-PATCH-481 — stamp the actual completion instant once, on the first
    // transition into a done state, from any write path. Pairs with promised_at to
    // measure late_completion; never overwritten (records the first completion).
    protected static function booted(): void
    {
        static::saving(function (self $appt) {
            if (! $appt->completed_at
                && $appt->isDirty('status')
                && in_array($appt->status, ['completed', 'shipped', 'closed'], true)) {
                $appt->completed_at = tnow()->utc();
            }
        });

        // MARKER-PATCH-482 — once a completion is stamped, evaluate quality signals
        // (late_completion) for the customer's recovery history.
        static::saved(function (self $appt) {
            if ($appt->wasChanged('completed_at') && $appt->completed_at && $appt->customer_id) {
                app(\App\Services\Tenant\RecoverySignalService::class)->evaluate($appt);
            }
        });

        // MARKER-PATCH-485 — a shop-side date move on a live appointment (one the
        // customer was already expecting) is a reschedule signal. New bookings and
        // pending/cancelled rows don't count.
        static::saved(function (self $appt) {
            if ($appt->wasChanged('appointment_date')
                && ! $appt->wasRecentlyCreated
                && $appt->customer_id
                && ! in_array($appt->status, ['pending', 'cancelled', 'refunded'], true)) {
                app(\App\Services\Tenant\RecoverySignalService::class)
                    ->reschedule($appt, $appt->getOriginal('appointment_date'));
            }
        });
    }

    public function tenant(): BelongsTo    { return $this->belongsTo(Tenant::class); }
    public function customer(): BelongsTo  { return $this->belongsTo(TenantCustomer::class, 'customer_id'); }
    public function resource(): BelongsTo  { return $this->belongsTo(TenantResource::class, 'resource_id'); }
    public function location(): BelongsTo  { return $this->belongsTo(TenantLocation::class, 'location_id'); }
    public function items(): HasMany       { return $this->hasMany(TenantAppointmentItem::class, 'appointment_id'); }
    public function addons(): HasMany      { return $this->hasMany(TenantAppointmentAddon::class, 'appointment_id'); }

    // MARKER-PATCH-158-A — multi-asset support
    public function assets(): HasMany
    {
        return $this->hasMany(TenantAppointmentAsset::class, 'appointment_id')->orderBy('sort_order');
    }
    public function parts(): HasMany       { return $this->hasMany(TenantAppointmentPart::class, 'appointment_id'); }
    public function responses(): HasMany   { return $this->hasMany(TenantAppointmentResponse::class, 'appointment_id'); }
    public function notes(): HasMany       { return $this->hasMany(TenantAppointmentNote::class, 'appointment_id')->orderBy('created_at'); }
    public function charges(): HasMany     { return $this->hasMany(TenantAppointmentCharge::class, 'appointment_id'); }
    // MARKER-PATCH-176 — payments now live on the linked SALE ledger (sales-as-
    // money). An appointment reaches its payments THROUGH its sale(s):
    // appointment_id on tenant_sales, sale_id on tenant_sale_payments. Same row
    // shape (kind/amount_cents/recorded_at) so existing reads keep working.
    public function payments(): \Illuminate\Database\Eloquent\Relations\HasManyThrough
    {
        return $this->hasManyThrough(
            TenantSalePayment::class,
            TenantSale::class,
            'appointment_id', // FK on tenant_sales -> appointments
            'sale_id',        // FK on tenant_sale_payments -> sales
            'id',             // local key on appointments
            'id'              // local key on sales
        )->orderBy('tenant_sale_payments.recorded_at');
    }
    public function sales(): HasMany       { return $this->hasMany(TenantSale::class, 'appointment_id'); }
    public function specialOrders(): HasMany { return $this->hasMany(TenantSpecialOrder::class, 'appointment_id'); }

    public function scopeActive($q)        { return $q->whereNotIn('status', AppointmentStatus::terminalStatuses()); }
    public function customerName(): string
    {
        // MARKER-PATCH-421 — live customer via customer_id is the source of truth;
        // the snapshot is only a fallback for a deleted customer record.
        return $this->customer
            ? trim($this->customer->fullName())
            : trim(($this->customer_first_name ?? '') . ' ' . ($this->customer_last_name ?? ''));
    }
    public function isPaid(): bool         { return $this->payment_status === 'paid'; }

    public function customerVisibleMinutes(): int
    {
        $total = 0;
        foreach ($this->items as $item) {
            $total += (int) ($item->duration_minutes_snapshot ?? 0);
        }
        foreach ($this->addons as $addon) {
            $total += (int) ($addon->duration_minutes_snapshot ?? 0);
        }
        return $total;
    }

    public static function generateRaNumber(string $tenantId, ?string $appointmentDate = null): string
    {
        $date = $appointmentDate ? new \DateTimeImmutable($appointmentDate) : new \DateTimeImmutable('today');
        $datePart = $date->format('mdy');
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $random = '';
            for ($i = 0; $i < 5; $i++) {
                $random .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $candidate = "ITO-{$datePart}-{$random}";
            $exists = static::where('tenant_id', $tenantId)->where('ra_number', $candidate)->exists();
            if (!$exists) return $candidate;
        }
        throw new \RuntimeException('Could not generate a unique RA number after 6 attempts.');
    }

    public function workOrderResponses()
    {
        return $this->hasMany(TenantAppointmentWorkOrderResponse::class, 'appointment_id');
    }

    public function workOrderFields()
    {
        return $this->hasMany(TenantWorkOrderField::class, 'tenant_id', 'tenant_id')
            ->orderBy('sort_order');
    }

    /**
     * Sum the ledger. Authoritative — paid_cents column is just a cache.
     * Use this when you need to be sure (e.g. in the status hook).
     */
    public function paidCentsFromLedger(): int
    {
        // Allow callers to use already-loaded relation without an extra query.
        if ($this->relationLoaded('payments')) {
            return (int) $this->payments->sum('amount_cents');
        }
        return (int) $this->payments()->sum('amount_cents');
    }

    /**
     * What the customer still owes. Negative means tenant owes customer (overage).
     * Reads from cached paid_cents — load payments and call paidCentsFromLedger()
     * if you need ledger-truth.
     */
    public function balanceDueCents(): int
    {
        return (int) $this->total_cents - (int) $this->paid_cents;
    }

    /**
     * Whether there's an active (non-voided) register sale tied to this
     * appointment. If true, the appointment is locked from edits.
     *
     * "Active" = sale exists, status is not 'cancelled'.
     */
    public function hasActiveRegisterSale(): bool
    {
        if ($this->relationLoaded('sales')) {
            return $this->sales->where('status', '!=', 'cancelled')->isNotEmpty();
        }
        return $this->sales()->where('status', '!=', 'cancelled')->exists();
    }

    /**
     * The single open draft sale created by the auto-send-on-Completed flow.
     * Returns null if there is no sale, or if the sale is closed/paid.
     *
     * Uses the SaleService convention: drafts have payment_status='draft'.
     */
    public function openRegisterSale(): ?TenantSale
    {
        return $this->sales()
            ->whereNotIn('status', ['cancelled', 'closed'])
            ->where('payment_status', 'draft')
            ->latest('created_at')
            ->first();
    }

    /**
     * MARKER-PATCH-194 — a live payment-link sale awaiting the customer:
     * has a Stripe checkout session, not yet paid, not cancelled. Drives the
     * "payment pending" banner so a link sent from this appointment is visible
     * and trackable instead of floating until it resolves.
     */
    public function pendingPaymentLinkSale(): ?TenantSale
    {
        return $this->sales()
            ->whereNotNull('checkout_session_id')
            ->whereNotIn('status', ['cancelled', 'closed', 'completed'])
            ->whereNotIn('payment_status', ['paid', 'refunded'])
            ->latest('created_at')
            ->first();
    }

}
