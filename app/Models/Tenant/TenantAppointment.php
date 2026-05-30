<?php
namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use App\Models\Tenant;

class TenantAppointment extends Model
{
    use HasUuids;
    protected $table    = 'tenant_appointments';
    protected $fillable = [
        'tenant_id','customer_id','resource_id','location_id','ra_number',
        'customer_first_name','customer_last_name','customer_email','customer_phone',
        'appointment_date','appointment_time','appointment_end_time',
        'total_duration_minutes','prep_before_minutes_snapshot','cleanup_after_minutes_snapshot',
        'slot_weight','slot_weight_auto','slot_weight_overridden',
        'receiving_method_snapshot','receiving_time_snapshot','tracking_number',
        'status','payment_status','payment_method',
        'stripe_payment_intent_id','paypal_order_id',
        'subtotal_cents','tax_cents','total_cents','paid_cents','staff_notes',
        'needs_time_review',
        'reminded_at', // MARKER-PATCH-154
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
    ];

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

    public function scopeActive($q)        { return $q->whereNotIn('status', ['cancelled','refunded']); }
    public function customerName(): string { return $this->customer_first_name . ' ' . $this->customer_last_name; }
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

}
