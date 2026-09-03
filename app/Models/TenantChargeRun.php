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
