<?php

namespace App\Models\Tenant;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * MARKER-PATCH-560 — Online Retail Wave 1.
 *
 * Cart and order are one row: `status` walks
 *   cart -> pending_payment -> paid -> fulfilling -> fulfilled -> completed
 * with cancelled/abandoned as terminals. A cart has no order_number and no
 * customer; both arrive at checkout. `sale_id` links the TenantSale that
 * OrderService creates on payment — the ledger, reports, and the customer
 * timeline all hang off that sale, not off this row.
 */
class TenantOrder extends Model
{
    use HasUuids;

    protected $table = 'tenant_orders';

    public const STATUS_CART            = 'cart';
    public const STATUS_PENDING_PAYMENT = 'pending_payment';
    public const STATUS_PAID            = 'paid';
    public const STATUS_FULFILLING      = 'fulfilling';
    public const STATUS_FULFILLED       = 'fulfilled';
    public const STATUS_COMPLETED       = 'completed';
    public const STATUS_CANCELLED       = 'cancelled';
    public const STATUS_ABANDONED       = 'abandoned';

    public const OPEN_STATUSES = [
        self::STATUS_PAID, self::STATUS_FULFILLING, self::STATUS_FULFILLED,
    ];

    protected $fillable = [
        'tenant_id', 'order_number', 'token', 'status', 'payment_method', 'customer_id',
        'contact_first_name', 'contact_last_name', 'contact_email', 'contact_phone',
        'fulfillment_type', 'fulfillment_address', 'fulfillment_notes', 'wants_install',
        'location_id', 'sale_id',
        'payment_status', 'stripe_payment_intent_id', 'card_brand', 'card_last4', 'paid_at',
        'subtotal_cents', 'discount_cents', 'tax_cents', 'shipping_cents', 'total_cents',
        'metadata',
    ];

    protected $casts = [
        'fulfillment_address' => 'array',
        'wants_install'       => 'boolean',
        'paid_at'             => 'datetime',
        'metadata'            => 'array',
        'subtotal_cents'      => 'integer',
        'discount_cents'      => 'integer',
        'tax_cents'           => 'integer',
        'shipping_cents'      => 'integer',
        'total_cents'         => 'integer',
    ];

    // ---- relations -------------------------------------------------

    public function tenant(): BelongsTo   { return $this->belongsTo(Tenant::class); }
    public function items(): HasMany      { return $this->hasMany(TenantOrderItem::class, 'order_id')->orderBy('position'); }
    public function customer(): BelongsTo { return $this->belongsTo(TenantCustomer::class, 'customer_id'); }
    public function sale(): BelongsTo     { return $this->belongsTo(TenantSale::class, 'sale_id'); }
    public function location(): BelongsTo { return $this->belongsTo(TenantLocation::class, 'location_id'); }

    // ---- helpers ---------------------------------------------------

    public function isCart(): bool { return $this->status === self::STATUS_CART; }

    public function contactName(): string
    {
        return trim(($this->contact_first_name ?? '') . ' ' . ($this->contact_last_name ?? ''));
    }

    /** Fresh unguessable token for guest cart retrieval. */
    public static function newToken(): string
    {
        return Str::random(48);
    }

    /**
     * O-YYYYMMDD-### — per-tenant, per-day sequence, assigned when a cart
     * becomes a real order at checkout. Wrapped in a transaction upstream.
     */
    public static function nextOrderNumber(string $tenantId): string
    {
        $prefix = 'O-' . tnow()->format('Ymd') . '-';
        $last = static::query()
            ->where('tenant_id', $tenantId)
            ->where('order_number', 'like', $prefix . '%')
            ->orderByDesc('order_number')
            ->value('order_number');
        $seq = $last ? ((int) substr($last, -3)) + 1 : 1;
        return $prefix . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }
}

