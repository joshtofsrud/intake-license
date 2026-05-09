<?php

namespace App\Models\Tenant;

use App\Models\Tenant;
use App\Models\TenantUser;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per money event against an appointment.
 *
 * The ledger is the source of truth. tenant_appointments.paid_cents is a
 * cached SUM(amount_cents) for fast list queries — kept in sync by
 * AppointmentPaymentService.
 *
 * Refund chain: refund row has negative amount_cents and reference_payment_id
 * pointing to the deposit/balance row being refunded. Walk the chain to
 * reconstruct full payment history.
 */
class TenantAppointmentPayment extends Model
{
    use HasUuids;

    protected $table = 'tenant_appointment_payments';

    public const KIND_DEPOSIT        = 'deposit';
    public const KIND_BALANCE        = 'balance';
    public const KIND_REFUND         = 'refund';
    public const KIND_OVERAGE_REFUND = 'overage_refund';

    public const SOURCE_BOOKING_FLOW   = 'booking_flow';
    public const SOURCE_REGISTER_SALE  = 'register_sale';
    public const SOURCE_MANUAL_ENTRY   = 'manual_entry';
    public const SOURCE_STRIPE_WEBHOOK = 'stripe_webhook';

    protected $fillable = [
        'tenant_id',
        'appointment_id',
        'amount_cents',
        'kind',
        'source',
        'method',
        'register_sale_id',
        'reference_payment_id',
        'external_reference',
        'recorded_by_user_id',
        'recorded_at',
        'notes',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'recorded_at'  => 'datetime',
    ];

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(TenantAppointment::class, 'appointment_id');
    }

    public function registerSale(): BelongsTo
    {
        return $this->belongsTo(TenantSale::class, 'register_sale_id');
    }

    public function referencePayment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reference_payment_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'recorded_by_user_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /** True if this row records money coming in (positive amount). */
    public function isInbound(): bool
    {
        return $this->amount_cents > 0;
    }

    /** True if this row records money going out (negative amount). */
    public function isRefund(): bool
    {
        return in_array($this->kind, [self::KIND_REFUND, self::KIND_OVERAGE_REFUND], true);
    }

    /** Display string for the source + method combo. */
    public function methodLabel(): string
    {
        return match ($this->method) {
            'cash'          => 'Cash',
            'card_terminal' => 'Card terminal',
            'check'         => 'Check',
            'store_credit'  => 'Store credit',
            'mark_paid'     => 'Marked paid (no charge)',
            'stripe'        => 'Stripe',
            'paypal'        => 'PayPal',
            default         => 'Other',
        };
    }
}
