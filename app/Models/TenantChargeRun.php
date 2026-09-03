<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// MARKER-BILLING-CHARGE — one attempt to settle a balance, and what became of it.
class TenantChargeRun extends Model
{
    use HasUuids;

    protected $table = 'tenant_charge_runs';

    protected $fillable = [
        'tenant_id', 'status', 'amount_cents', 'message_count', 'idempotency_key',
        // MARKER-BILLING-TAX-ROOM — zero until tax is switched on, but the
        // shape is right so enabling it is not a migration against live money.
        'subtotal_cents', 'tax_cents', 'tax_jurisdiction', 'tax_rate',
        'stripe_payment_intent_id', 'failure_code', 'failure_message', 'attempts',
        'next_attempt_at', 'refunded_cents', 'resolution_reason', 'resolved_by',
        'resolved_at', 'charged_at',
    ];

    protected $casts = [
        'next_attempt_at' => 'datetime',
        'resolved_at'     => 'datetime',
        'charged_at'      => 'datetime',
    ];

    public const PENDING     = 'pending';
    public const CHARGING    = 'charging';
    public const CHARGED     = 'charged';
    public const FAILED      = 'failed';
    public const WRITTEN_OFF = 'written_off';
    public const REFUNDED    = 'refunded';

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** MARKER-BILLING-TAX-ROOM — true once anything is actually taxed. */
    public function hasTax(): bool
    {
        return (int) $this->tax_cents > 0;
    }

    public function subtotalCents(): int
    {
        return (int) ($this->subtotal_cents ?? $this->amount_cents);
    }

    public function describeStatus(): string
    {
        return match ($this->status) {
            self::CHARGED     => 'Charged',
            self::CHARGING    => 'In progress',
            self::FAILED      => 'Failed',
            self::WRITTEN_OFF => 'Written off',
            self::REFUNDED    => 'Refunded',
            default           => 'Pending',
        };
    }
}
