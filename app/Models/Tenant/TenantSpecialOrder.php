<?php

namespace App\Models\Tenant;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Special order — one row per "this many units of this item, going
 * to this destination."
 *
 * STATUS LIFECYCLE:
 *   needed   → soft request, not yet ordered (booking-flow or staff intent)
 *   ordered  → committed to a vendor with PO number + expected date
 *   arrived  → on the receiving bench, waiting to be pulled
 *   pulled   → consumed at register or appointment completion
 *   cancelled → killed at any prior state
 *
 * PARTIAL RECEIPT MECHANIC:
 *   When a vendor short-ships, the service layer SPLITS the row: the
 *   original becomes "arrived" with the received qty, and a new sibling
 *   is created with parent_id = original.id, status=ordered, qty=the
 *   remaining. See $this->children() and $this->parent() relationships.
 *
 * BATCH GROUPING:
 *   Rows that were created together (multi-customer batch) share a
 *   batch_id UUID. See $this->siblings() and the inBatch() scope.
 */
class TenantSpecialOrder extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'tenant_special_orders';

    protected $fillable = [
        'tenant_id',
        'so_number',
        'inventory_item_id',
        'item_name_snapshot',
        'quantity',
        'customer_id',
        'appointment_id',
        'sale_id',      // MARKER-SO-SALE-LINK
        'sale_item_id', // MARKER-SO-SALE-LINK
        'vendor_id',
        'vendor_reference',
        'po_number',
        'vendor_invoice_number',
        'vendor_invoice_date',
        'status',
        'created_from',
        'unit_cost_cents_estimated',
        'unit_cost_cents_actual',
        'expected_arrival_date',
        'ordered_at',
        'arrived_at',
        'pulled_at',
        'cancelled_at',
        'deposit_cents',
        'deposit_paid_at',
        'deposit_payment_ref',
        'batch_id',
        'parent_id',
        'cancellation_reason',
        'created_by_user_id',
    ];

    protected $casts = [
        'quantity'                  => 'integer',
        'unit_cost_cents_estimated' => 'integer',
        'unit_cost_cents_actual'    => 'integer',
        'expected_arrival_date'     => 'date',
        'vendor_invoice_date'       => 'date',
        'ordered_at'                => 'datetime',
        'arrived_at'                => 'datetime',
        'pulled_at'                 => 'datetime',
        'cancelled_at'              => 'datetime',
        'deposit_cents'             => 'integer',
        'deposit_paid_at'           => 'datetime',
    ];

    public const STATUS_NEEDED    = 'needed';
    public const STATUS_ORDERED   = 'ordered';
    public const STATUS_ARRIVED   = 'arrived';
    public const STATUS_PULLED    = 'pulled';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES_OPEN    = [self::STATUS_NEEDED, self::STATUS_ORDERED, self::STATUS_ARRIVED];
    public const STATUSES_ACTIVE  = [self::STATUS_ORDERED, self::STATUS_ARRIVED];
    public const STATUSES_CLOSED  = [self::STATUS_PULLED, self::STATUS_CANCELLED];

    // ─── Relationships ──────────────────────────────────────

    public function tenant(): BelongsTo      { return $this->belongsTo(Tenant::class); }
    public function item(): BelongsTo        { return $this->belongsTo(TenantInventoryItem::class, 'inventory_item_id'); }
    public function customer(): BelongsTo    { return $this->belongsTo(TenantCustomer::class, 'customer_id'); }
    public function appointment(): BelongsTo { return $this->belongsTo(TenantAppointment::class, 'appointment_id'); }
    public function vendor(): BelongsTo      { return $this->belongsTo(TenantVendor::class, 'vendor_id'); }
    public function createdBy(): BelongsTo   { return $this->belongsTo(TenantUser::class, 'created_by_user_id'); }
    public function notes(): HasMany         { return $this->hasMany(TenantSpecialOrderNote::class, 'special_order_id')->orderBy('created_at'); }

    /**
     * Parent SO if this row was spawned by a partial-receipt split.
     * Null = this is the original (or no split has happened yet).
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Sibling rows from a partial-receipt split. The parent is NOT
     * included here — only siblings spawned from it.
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    // ─── Scopes ─────────────────────────────────────────────

    /** Status in needed/ordered/arrived — anything not done/cancelled. */
    public function scopeOpen($q)
    {
        return $q->whereIn('status', self::STATUSES_OPEN);
    }

    /** Status in ordered/arrived — committed and live. */
    public function scopeActive($q)
    {
        return $q->whereIn('status', self::STATUSES_ACTIVE);
    }

    public function scopeAwaitingArrival($q)
    {
        return $q->where('status', self::STATUS_ORDERED);
    }

    public function scopeArrivedBench($q)
    {
        return $q->where('status', self::STATUS_ARRIVED);
    }

    /**
     * Ordered + past expected arrival. Used by the dashboard "overdue"
     * triage tile and the SO list overdue tab.
     */
    public function scopeOverdue($q)
    {
        return $q->where('status', self::STATUS_ORDERED)
            ->whereNotNull('expected_arrival_date')
            ->whereDate('expected_arrival_date', '<', now()->toDateString());
    }

    /** Soft requests from booking flow. */
    public function scopeSoftRequests($q)
    {
        return $q->where('created_from', 'booking')
            ->where('status', self::STATUS_NEEDED);
    }

    public function scopeForCustomer($q, string $customerId)
    {
        return $q->where('customer_id', $customerId);
    }

    public function scopeForAppointment($q, string $appointmentId)
    {
        return $q->where('appointment_id', $appointmentId);
    }

    public function scopeForVendor($q, string $vendorId)
    {
        return $q->where('vendor_id', $vendorId);
    }

    public function scopeInBatch($q, string $batchId)
    {
        return $q->where('batch_id', $batchId);
    }

    // ─── Helpers ────────────────────────────────────────────

    public function isOpen(): bool
    {
        return in_array($this->status, self::STATUSES_OPEN, true);
    }

    public function isClosed(): bool
    {
        return in_array($this->status, self::STATUSES_CLOSED, true);
    }

    public function isPartial(): bool
    {
        // True if this row is itself a partial-split child, OR if any
        // children exist spawned from this row.
        return $this->parent_id !== null || $this->children()->exists();
    }

    /**
     * All rows in the same batch. Includes the current row.
     * Returns a Builder (not a Relation) — call ->get() to materialize.
     */
    public function batchSiblings()
    {
        if ($this->batch_id === null) {
            // Single-row "batch" — return a query that yields just this row.
            return self::query()->where('id', $this->id);
        }
        return self::query()->where('batch_id', $this->batch_id);
    }

    /**
     * Outstanding deposit balance in cents. Estimated total minus
     * deposit already collected. Returns 0 if no estimate set.
     */
    public function depositOutstandingCents(): int
    {
        if ($this->unit_cost_cents_estimated === null) {
            return 0;
        }
        $estimatedTotal = $this->unit_cost_cents_estimated * $this->quantity;
        return max(0, $estimatedTotal - (int) $this->deposit_cents);
    }
}
