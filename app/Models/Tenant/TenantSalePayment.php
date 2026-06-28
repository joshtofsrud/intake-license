<?php

namespace App\Models\Tenant;

use App\Models\Tenant;
use App\Models\TenantUser;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MARKER-PATCH-175 — One row per money event against a SALE.
 *
 * The sale is the single money object; this ledger is the source of truth for
 * "how much has been paid on this sale." tenant_sales.payment_status is a
 * derived cache kept in sync by SalePaymentService::recalcStatus().
 *
 * Refund chain: a refund row has negative amount_cents and reference_payment_id
 * pointing to the payment being reversed.
 */
class TenantSalePayment extends Model
{
    use HasUuids;

    protected $table = 'tenant_sale_payments';

    public const KIND_DEPOSIT        = 'deposit';
    public const KIND_BALANCE        = 'balance';
    public const KIND_PAYMENT        = 'payment';
    public const KIND_REFUND         = 'refund';
    public const KIND_OVERAGE_REFUND = 'overage_refund';

    public const SOURCE_REGISTER            = 'register';
    public const SOURCE_BOOKING_FLOW        = 'booking_flow';
    public const SOURCE_MANUAL_ENTRY        = 'manual_entry';
    public const SOURCE_STRIPE_WEBHOOK      = 'stripe_webhook';
    public const SOURCE_DIRECT_PAYMENT_LINK = 'direct_payment_link';

    protected $fillable = [
        'tenant_id',
        'sale_id',
        'customer_id', // MARKER-PATCH-176 — always set for standalone refunds
        'amount_cents',
        'kind',
        'source',
        'method',
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

    public function sale(): BelongsTo
    {
        return $this->belongsTo(TenantSale::class, 'sale_id');
    }

    // MARKER-PATCH-176C — back-compat alias. The old appointment-payment model
    // exposed registerSale() (the sale that produced the payment, via
    // register_sale_id). On the sale ledger that IS the sale (via sale_id), so
    // registerSale() aliases sale(). Keeps the appointment detail view + the
    // 'payments.registerSale' eager-load working unchanged. Null-safe for
    // standalone refunds (sale_id null) — callers use optional()/?->.
    public function registerSale(): BelongsTo
    {
        return $this->belongsTo(TenantSale::class, 'sale_id');
    }

    // MARKER-PATCH-176 — a refund always has a customer; sale is optional.
    public function customer(): BelongsTo
    {
        return $this->belongsTo(TenantCustomer::class, 'customer_id');
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

    public function isInbound(): bool
    {
        return $this->amount_cents > 0;
    }

    public function isRefund(): bool
    {
        return in_array($this->kind, [self::KIND_REFUND, self::KIND_OVERAGE_REFUND], true);
    }

    public function methodLabel(): string
    {
        return match ($this->method) {
            'cash'          => 'Cash',
            'card_terminal' => 'Card terminal',
            'card'          => 'Card', // MARKER-PATCH-463 — manual/recorded card refunds & payments
            'check'         => 'Check',
            'store_credit'  => 'Store credit',
            'mark_paid'     => 'Marked paid (no charge)',
            'stripe'        => 'Stripe',
            'paypal'        => 'PayPal',
            default         => 'Other',
        };
    }
}
