<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** MARKER-LAYAWAY — the schedule and policy for one layaway sale. */
class TenantLayawayPlan extends Model
{
    use HasUuids;

    protected $table = 'tenant_layaway_plans';

    protected $fillable = [
        'tenant_id', 'sale_id', 'customer_id', 'location_id', 'status',
        'term_days', 'frequency', 'grace_days', 'policy', 'collect_by',
        'next_due_on', 'scheduled_amount_cents', 'completed_at', 'cancelled_at',
        'cancel_reason', 'restocking_fee_cents', 'refund_cents',
    ];

    protected $casts = [
        'policy'                 => 'array',
        'collect_by'             => 'date',
        'next_due_on'            => 'date',
        'completed_at'           => 'datetime',
        'cancelled_at'           => 'datetime',
        'term_days'              => 'integer',
        'grace_days'             => 'integer',
        'scheduled_amount_cents' => 'integer',
        'restocking_fee_cents'   => 'integer',
        'refund_cents'           => 'integer',
    ];

    public const ACTIVE    = 'active';
    public const READY     = 'ready';
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';

    public function sale(): BelongsTo
    {
        return $this->belongsTo(TenantSale::class, 'sale_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(TenantCustomer::class, 'customer_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(TenantInventoryReservation::class, 'sale_id', 'sale_id');
    }

    public function specialOrders(): HasMany
    {
        return $this->hasMany(TenantSpecialOrder::class, 'sale_id', 'sale_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::ACTIVE, self::READY], true);
    }

    /** True while any linked special order has not reached the bench. */
    public function awaitingArrival(): bool
    {
        return $this->specialOrders()
            ->whereIn('status', [TenantSpecialOrder::STATUS_NEEDED, TenantSpecialOrder::STATUS_ORDERED])
            ->exists();
    }

    public function isOverdue(): bool
    {
        return $this->status === self::ACTIVE
            && $this->next_due_on
            && $this->next_due_on->copy()->addDays($this->grace_days)->isPast();
    }
}
