<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * MARKER-PO: one vendor, one ship-to location. Lines carry what was ordered,
 * received and cancelled; "still owed" is ordered − received − cancelled and
 * is what counts as on order.
 */
class TenantPurchaseOrder extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'tenant_purchase_orders';

    public const DRAFT = 'draft';
    public const PLACED = 'placed';
    public const PARTIAL = 'partial';
    public const RECEIVED = 'received';
    public const CLOSED_SHORT = 'closed_short';
    public const CANCELLED = 'cancelled';

    public const OPEN = [self::PLACED, self::PARTIAL];

    public const VIA = ['email' => 'Emailed', 'website' => 'Ordered on their website', 'phone' => 'Phoned in', 'other' => 'Other'];

    protected $fillable = [
        'tenant_id', 'po_number', 'vendor_id', 'location_id', 'status',
        'placed_at', 'placed_by_user_id', 'placed_via', 'sent_to_email', 'vendor_reference',
        'expected_date', 'notes_vendor', 'notes_internal', 'changed_since_placed', 'changes_note',
        'received_at', 'closed_at', 'closed_reason', 'created_by_user_id',
    ];

    protected $casts = [
        'placed_at' => 'datetime',
        'received_at' => 'datetime',
        'closed_at' => 'datetime',
        'expected_date' => 'date',
        'changed_since_placed' => 'boolean',
    ];

    public function vendor(): BelongsTo   { return $this->belongsTo(TenantVendor::class, 'vendor_id')->withTrashed(); }
    public function location(): BelongsTo { return $this->belongsTo(TenantLocation::class, 'location_id'); }
    public function lines(): HasMany      { return $this->hasMany(TenantPurchaseOrderLine::class, 'purchase_order_id')->orderBy('sort')->orderBy('created_at'); }
    public function events(): HasMany     { return $this->hasMany(TenantPurchaseOrderEvent::class, 'purchase_order_id')->orderByDesc('created_at'); }
    public function placedBy(): BelongsTo { return $this->belongsTo(TenantUser::class, 'placed_by_user_id'); }

    public function isDraft(): bool { return $this->status === self::DRAFT; }
    public function isOpen(): bool  { return in_array($this->status, self::OPEN, true); }

    public function statusLabel(): string
    {
        return [
            self::DRAFT => 'Draft', self::PLACED => 'Placed', self::PARTIAL => 'Partly received',
            self::RECEIVED => 'Received', self::CLOSED_SHORT => 'Closed short', self::CANCELLED => 'Cancelled',
        ][$this->status] ?? ucfirst((string) $this->status);
    }

    /** CSS modifier for the status pill (shares special-order pill colours). */
    public function statusPill(): string
    {
        return [
            self::DRAFT => 'draft', self::PLACED => 'ordered', self::PARTIAL => 'ordered',
            self::RECEIVED => 'arrived', self::CLOSED_SHORT => 'pulled', self::CANCELLED => 'cancelled',
        ][$this->status] ?? 'pulled';
    }

    /** An open PO with a line still owed whose date has passed. */
    public function isOverdue(): bool
    {
        if (! $this->isOpen()) {
            return false;
        }
        $today = now()->toDateString();
        $this->loadMissing('lines');
        foreach ($this->lines as $l) {
            $d = $l->expected_date ?? $this->expected_date;
            if ($l->owed() > 0 && $d && $d->toDateString() < $today) {
                return true;
            }
        }
        return false;
    }

    public function owedUnits(): int  { return (int) $this->loadMissing('lines')->lines->sum(fn ($l) => $l->owed()); }
    public function valueCents(): int { return (int) $this->loadMissing('lines')->lines->sum(fn ($l) => $l->qty_ordered * (int) $l->unit_cost_cents); }
    public function owedValueCents(): int { return (int) $this->loadMissing('lines')->lines->sum(fn ($l) => $l->owed() * (int) $l->unit_cost_cents); }
}
