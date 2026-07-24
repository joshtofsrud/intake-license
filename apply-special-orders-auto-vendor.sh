#!/bin/bash
# special-orders-auto-vendor — vendor assignment becomes automatic and
# configurable, so the manual picker is the exception rather than the routine.
#   NEW SETTINGS TAB: Settings -> Ordering -> "Special orders — vendor
#   assignment", with three rules:
#     · Preferred vendor (default) — the is_preferred row on the item,
#       falling back to whoever you ordered from most recently
#     · Lowest price — cheapest live cost (catalog cost as fallback) among
#       vendors that carry it, PREFERRING vendors that actually show stock,
#       because auto-assigning to a vendor with none only makes work. Falls
#       back to cheapest overall when nobody has stock, and to the preferred
#       vendor when no cost is known anywhere.
#     · Don't assign automatically — leave it blank
#   Verified in isolation: cheapest-with-stock ($116.50) correctly beats
#   cheapest-overall ($115.00 at zero stock); falls back when nobody stocks it.
#   vendor_assigned_rule records WHICH rule chose the vendor (or 'manual'),
#   so an automatic choice is explainable on the row.
#   ALSO: closes the draft-timing race in the register. Draft saving is
#   debounced, so a fast "Add to order" click could create the order before
#   cart.draft_id existed — leaving it with no sale link, the exact orphan
#   class this feature prevents. The draft is now flushed first, with a
#   single retry that proceeds unlinked rather than blocking the sale.
#   Vendor options are always scoped through the item and this tenant's
#   active vendors (the item-vendor pivot deliberately has no tenant_id).
# No routes. Server: MIGRATION REQUIRED, then view:clear.
set -e
cd "$(git rev-parse --show-toplevel)"
if grep -q "MARKER-SO-AUTOVENDOR" app/Services/Tenant/SpecialOrderService.php; then
  echo "special-orders-auto-vendor already applied — aborting."; exit 1
fi
if ! grep -q "MARKER-SO-PLACEMENT" app/Http/Controllers/Tenant/SpecialOrderController.php; then
  echo "special-orders-placement not applied — wrong base, aborting."; exit 1
fi

cat > 'database/migrations/2026_07_23_000006_add_vendor_rule_to_tenant_special_orders.php' <<'SOAV_0_EOF'
<?php

// MARKER-SO-AUTOVENDOR — records WHICH rule chose a vendor, so an automatic
// assignment is explainable on the row rather than mysterious, and so
// hand-picked choices are distinguishable from automatic ones.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_special_orders', function (Blueprint $t) {
            // preferred | lowest_price | manual | null (none assigned)
            $t->string('vendor_assigned_rule', 24)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_special_orders', function (Blueprint $t) {
            $t->dropColumn('vendor_assigned_rule');
        });
    }
};
SOAV_0_EOF

cat > 'app/Models/Tenant/TenantSpecialOrder.php' <<'SOAV_1_EOF'
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
        'source_confirmed_at', 'source_confirmed_by_user_id', // MARKER-SO-ORIGIN
        'vendor_assigned_rule', // MARKER-SO-AUTOVENDOR
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
SOAV_1_EOF

cat > 'app/Services/Tenant/SpecialOrderService.php' <<'SOAV_2_EOF'
<?php

namespace App\Services\Tenant;

use App\Models\Tenant\TenantInventoryItem;
use App\Models\Tenant\TenantInventoryItemVendor;
use App\Models\Tenant\TenantSpecialOrder;
use App\Models\Tenant\TenantSpecialOrderCounter;
use App\Models\Tenant\TenantSpecialOrderNote;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * SpecialOrderService — business logic for the SO lifecycle.
 *
 * STATE MACHINE:
 *
 *     needed  ─┐
 *              ├──► ordered ──► arrived ──► pulled
 *     needed  ─┘
 *
 *     any open state ──► cancelled
 *
 * Notes:
 *   - 'needed' can transition directly to 'ordered' (vendor confirmed)
 *     or to 'cancelled' (request killed before any vendor commitment).
 *   - 'pulled' is terminal. Cannot un-pull.
 *   - 'cancelled' is terminal. Use create() to start fresh if you
 *     want to re-order something that was cancelled.
 *
 * PARTIAL RECEIPT MECHANIC:
 *   When markArrived() is called with $receivedQty < quantity, the
 *   service splits the row:
 *     - Original row → status=arrived, quantity=$receivedQty, sets arrived_at
 *     - New sibling row → parent_id=original.id, status=ordered,
 *       quantity=remaining, expected_arrival_date preserved, same
 *       vendor/customer/appointment/etc. New so_number issued.
 *
 *   The original audit history (notes, ordered_at, ordered_at, etc.) stays
 *   with the original row. The sibling has its own short history starting
 *   from its creation.
 *
 * VENDOR LEAD-TIME LEARNING:
 *   When an SO transitions to 'arrived', the pivot row for (item, vendor)
 *   gets last_ordered_at updated. The lead_time_days field on the pivot
 *   is computed on-demand by averaging actual ordered-to-arrived days
 *   for that pair — we don't try to maintain a running average. Cheap
 *   enough at read time; reconsider if a tenant ever has 10k+ SOs per
 *   vendor.
 */
class SpecialOrderService
{
    // ────────────────────────────────────────────────────────
    //  SO numbering (per-tenant counter)
    // ────────────────────────────────────────────────────────

    /**
     * Atomically increment the per-tenant counter and return the next
     * SO number formatted as "SO-{n}" (zero-padded to 4 digits).
     *
     * Wraps in a DB transaction + lockForUpdate so concurrent SO
     * creations from two staff members can't collide.
     */
    public function nextSpecialOrderNumber(string $tenantId): string
    {
        return DB::transaction(function () use ($tenantId) {
            $counter = TenantSpecialOrderCounter::where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->first();

            if (!$counter) {
                $counter = TenantSpecialOrderCounter::create([
                    'tenant_id'   => $tenantId,
                    'next_number' => 1,
                ]);
            }

            $n = $counter->next_number;
            $counter->next_number = $n + 1;
            $counter->save();

            return 'SO-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
        });
    }

    // ────────────────────────────────────────────────────────
    //  Creation
    // ────────────────────────────────────────────────────────

    /**
     * Create a new SO row. Validates required fields per the chosen
     * initial status, assigns a SO number, snapshots the item name,
     * and writes a "Created" system note.
     *
     * Expected $data keys:
     *   tenant_id            (required)
     *   inventory_item_id    (nullable - "not yet catalogued")
     *   item_name_snapshot   (required - free text fallback when no item)
     *   quantity             (required, > 0)
     *   customer_id          (nullable)
     *   appointment_id       (nullable)
     *   vendor_id            (required if status='ordered'; nullable if 'needed')
     *   po_number            (nullable - usually set at markOrdered)
     *   vendor_reference     (nullable)
     *   status               (default 'needed')
     *   created_from         (default 'manual')
     *   unit_cost_cents_estimated (nullable)
     *   expected_arrival_date (nullable)
     *   deposit_cents        (nullable, default 0)
     *   deposit_paid_at      (nullable)
     *   deposit_payment_ref  (nullable)
     *   batch_id             (nullable)
     *   parent_id            (nullable - set by splitForPartialReceipt only)
     *   created_by_user_id   (nullable)
     *   notes                (nullable - first user note to seed the thread)
     */
    public function create(array $data): TenantSpecialOrder
    {
        // MARKER-SO-AUTOVENDOR — resolve the vendor once, up front, so the
        // rule that chose it can be recorded alongside it.
        $auto = empty($data['vendor_id'])
            ? self::autoAssignVendor($data['tenant_id'], $data['inventory_item_id'] ?? null)
            : ['vendor_id' => null, 'rule' => null];

        if (empty($data['tenant_id'])) {
            throw new SpecialOrderValidationException('tenant_id is required.');
        }
        if (empty($data['item_name_snapshot'])) {
            throw new SpecialOrderValidationException('item_name_snapshot is required.');
        }
        if (!isset($data['quantity']) || (int) $data['quantity'] < 1) {
            throw new SpecialOrderValidationException('quantity must be at least 1.');
        }

        $status = $data['status'] ?? TenantSpecialOrder::STATUS_NEEDED;
        if (!in_array($status, [
            TenantSpecialOrder::STATUS_NEEDED,
            TenantSpecialOrder::STATUS_ORDERED,
        ], true)) {
            throw new SpecialOrderValidationException(
                "Initial status must be 'needed' or 'ordered', got '{$status}'."
            );
        }

        // If creating directly as 'ordered', vendor + ETA + PO are
        // expected. Soft-create with status=needed if those are missing
        // and let the caller transition explicitly via markOrdered().
        if ($status === TenantSpecialOrder::STATUS_ORDERED) {
            if (empty($data['vendor_id'])) {
                throw new SpecialOrderValidationException(
                    "Cannot create with status='ordered' without a vendor_id."
                );
            }
        }

        return DB::transaction(function () use ($data, $status) {
            $soNumber = $this->nextSpecialOrderNumber($data['tenant_id']);

            $so = TenantSpecialOrder::create([
                'tenant_id'                 => $data['tenant_id'],
                'so_number'                 => $soNumber,
                'inventory_item_id'         => $data['inventory_item_id'] ?? null,
                'item_name_snapshot'        => $data['item_name_snapshot'],
                'quantity'                  => (int) $data['quantity'],
                'customer_id'               => $data['customer_id'] ?? null,
                'appointment_id'            => $data['appointment_id'] ?? null,
                'sale_id'                   => $data['sale_id'] ?? null,      // MARKER-SO-SALE-LINK
                'sale_item_id'              => $data['sale_item_id'] ?? null, // MARKER-SO-SALE-LINK
                // MARKER-SO-AUTOVENDOR — an order with no vendor cannot be
                // grouped or placed, so one is chosen automatically by the
                // tenant's rule unless the caller named one.
                'vendor_id'                 => $data['vendor_id'] ?? ($auto['vendor_id'] ?? null),
                'vendor_assigned_rule'      => $data['vendor_id'] ? 'manual' : ($auto['rule'] ?? null),
                'po_number'                 => $data['po_number'] ?? null,
                'vendor_reference'          => $data['vendor_reference'] ?? null,
                'status'                    => $status,
                'created_from'              => $data['created_from'] ?? 'manual',
                'unit_cost_cents_estimated' => $data['unit_cost_cents_estimated'] ?? null,
                'expected_arrival_date'     => $data['expected_arrival_date'] ?? null,
                'ordered_at'                => $status === TenantSpecialOrder::STATUS_ORDERED ? now() : null,
                'deposit_cents'             => $data['deposit_cents'] ?? 0,
                'deposit_paid_at'           => $data['deposit_paid_at'] ?? null,
                'deposit_payment_ref'       => $data['deposit_payment_ref'] ?? null,
                'batch_id'                  => $data['batch_id'] ?? null,
                'parent_id'                 => $data['parent_id'] ?? null,
                'created_by_user_id'        => $data['created_by_user_id'] ?? null,
            ]);

            // System note recording creation. Includes the creation context
            // so audit history is honest about where this came from.
            $createdFromHuman = match ($so->created_from) {
                'register'    => 'register sale',
                'appointment' => 'work order',
                'item'        => 'item detail page',
                'booking'     => 'customer booking flow',
                default       => 'manual entry',
            };
            $this->writeSystemNote($so, "Created from {$createdFromHuman}.");

            // If the caller provided a seed note, persist it as a user note.
            if (!empty($data['notes'])) {
                $this->addNote($so->id, $data['created_by_user_id'] ?? null, $data['notes']);
            }

            return $so->fresh();
        });
    }

    // ────────────────────────────────────────────────────────
    //  MARKER-PATCH-419 — appointment-part bridge
    // ────────────────────────────────────────────────────────

    /**
     * Reconcile the special order for a single work-order part line to match
     * its is_special_order checkbox. Inventory-linked parts only — custom
     * one-off charges are never ordered. Retracts only while the SO is still
     * a soft 'needed' request; an already-ordered PO is left alone.
     */
    public function syncForAppointmentPart(\App\Models\Tenant\TenantAppointmentPart $part, ?string $userId = null): void
    {
        // Custom items (no inventory link) can't be special-ordered.
        if (empty($part->inventory_item_id)) {
            if ($part->special_order_id) {
                $part->forceFill(['special_order_id' => null])->saveQuietly();
            }
            return;
        }

        $part->loadMissing('appointment', 'inventoryItem');
        $appt = $part->appointment;
        if (!$appt) {
            return;
        }

        $linked = $part->special_order_id
            ? TenantSpecialOrder::find($part->special_order_id)
            : null;
        $liveStatuses = [
            TenantSpecialOrder::STATUS_NEEDED,
            TenantSpecialOrder::STATUS_ORDERED,
            TenantSpecialOrder::STATUS_ARRIVED,
        ];
        $linkedIsLive = $linked && in_array($linked->status, $liveStatuses, true);

        if ($part->is_special_order) {
            if ($linkedIsLive) {
                // Keep qty aligned while it's still a soft request.
                if ($linked->status === TenantSpecialOrder::STATUS_NEEDED
                    && (int) $linked->quantity !== (int) $part->quantity) {
                    $linked->update(['quantity' => (int) $part->quantity]);
                }
                return;
            }

            $so = $this->create([
                'tenant_id'                 => $appt->tenant_id,
                'item_name_snapshot'        => $part->item_name_snapshot,
                'quantity'                  => (int) $part->quantity,
                'inventory_item_id'         => $part->inventory_item_id,
                'customer_id'               => $appt->customer_id,
                'appointment_id'            => $appt->id,
                'vendor_id'                 => $part->inventoryItem?->default_vendor_id,
                'status'                    => TenantSpecialOrder::STATUS_NEEDED,
                'created_from'              => 'appointment',
                'created_by_user_id'        => $userId,
                'unit_cost_cents_estimated' => $part->cost_cents_at_time,
            ]);
            $part->forceFill(['special_order_id' => $so->id])->saveQuietly();
            return;
        }

        // Unchecked.
        if ($linked && $linked->status === TenantSpecialOrder::STATUS_NEEDED) {
            // Still just a request — safe to retract.
            $this->cancel($linked->id, 'Removed from work order (part special-order unchecked).');
            $part->forceFill(['special_order_id' => null])->saveQuietly();
        } elseif ($linkedIsLive) {
            // Already ordered/arrived — can't un-order from a checkbox; re-check it.
            $part->forceFill(['is_special_order' => true])->saveQuietly();
        } else {
            $part->forceFill(['special_order_id' => null])->saveQuietly();
        }
    }

    // ────────────────────────────────────────────────────────
    //  Transitions
    // ────────────────────────────────────────────────────────

    /**
     * needed → ordered. Requires vendor_id, po_number, and an
     * expected_arrival_date. Estimated cost optional but recommended.
     *
     * $data keys consumed:
     *   vendor_id (required)
     *   po_number (required)
     *   vendor_reference (optional - their PO ack number)
     *   expected_arrival_date (required, date string)
     *   unit_cost_cents_estimated (optional)
     */
    public function markOrdered(string $id, array $data): TenantSpecialOrder
    {
        return DB::transaction(function () use ($id, $data) {
            $so = $this->findOrFail($id);
            $this->validateTransition($so, TenantSpecialOrder::STATUS_ORDERED);

            if (empty($data['vendor_id'])) {
                throw new SpecialOrderValidationException(
                    'vendor_id is required to mark an SO ordered.'
                );
            }
            if (empty($data['po_number'])) {
                throw new SpecialOrderValidationException(
                    'po_number is required to mark an SO ordered.'
                );
            }
            if (empty($data['expected_arrival_date'])) {
                throw new SpecialOrderValidationException(
                    'expected_arrival_date is required to mark an SO ordered.'
                );
            }

            $so->update([
                'status'                    => TenantSpecialOrder::STATUS_ORDERED,
                'vendor_id'                 => $data['vendor_id'],
                'po_number'                 => $data['po_number'],
                'vendor_reference'          => $data['vendor_reference'] ?? $so->vendor_reference,
                'expected_arrival_date'     => $data['expected_arrival_date'],
                'unit_cost_cents_estimated' => $data['unit_cost_cents_estimated'] ?? $so->unit_cost_cents_estimated,
                'ordered_at'                => now(),
            ]);

            $this->writeSystemNote(
                $so->fresh(),
                "Marked ordered. PO {$data['po_number']} · ETA {$data['expected_arrival_date']}."
            );

            return $so->fresh();
        });
    }

    /**
     * ordered → arrived. Supports partial receipt: when $receivedQty
     * is provided and less than $so->quantity, the row is split via
     * splitForPartialReceipt() before marking arrived.
     *
     * Also writes the vendor invoice fields if provided, and updates
     * the item↔vendor pivot's last_ordered_at.
     *
     * @param int|null $receivedQty       null = full receipt
     * @param int|null $actualUnitCostCents null = use estimated
     * @param string|null $vendorInvoiceNumber  optional, set at receiving
     * @param string|null $vendorInvoiceDate    optional, date string
     */
    public function markArrived(
        string $id,
        ?int $receivedQty = null,
        ?int $actualUnitCostCents = null,
        ?string $vendorInvoiceNumber = null,
        ?string $vendorInvoiceDate = null
    ): TenantSpecialOrder {
        $result = DB::transaction(function () use ($id, $receivedQty, $actualUnitCostCents, $vendorInvoiceNumber, $vendorInvoiceDate) {
            $so = $this->findOrFail($id);
            $this->validateTransition($so, TenantSpecialOrder::STATUS_ARRIVED);

            $totalQty = (int) $so->quantity;
            $received = $receivedQty ?? $totalQty;

            if ($received < 1) {
                throw new SpecialOrderValidationException(
                    'Received quantity must be at least 1 (cancel the SO instead).'
                );
            }
            if ($received > $totalQty) {
                throw new SpecialOrderValidationException(
                    "Received quantity ({$received}) cannot exceed ordered quantity ({$totalQty})."
                );
            }

            // Partial receipt: split the row before marking arrived.
            if ($received < $totalQty) {
                $this->splitForPartialReceipt($so, $received);
                // After split, $so has quantity=$received. Refresh.
                $so = $so->fresh();
            }

            $so->update([
                'status'                 => TenantSpecialOrder::STATUS_ARRIVED,
                'arrived_at'             => now(),
                'unit_cost_cents_actual' => $actualUnitCostCents ?? $so->unit_cost_cents_actual,
                'vendor_invoice_number'  => $vendorInvoiceNumber ?? $so->vendor_invoice_number,
                'vendor_invoice_date'    => $vendorInvoiceDate ?? $so->vendor_invoice_date,
            ]);

            // Vendor pivot housekeeping — update last_ordered_at for
            // the (item, vendor) pair so future "preferred vendor" reads
            // can recompute lead time fresh.
            $this->updateVendorLeadTime($so->fresh());

            $partialNote = $received < $totalQty
                ? " (partial: {$received} of {$totalQty})"
                : '';
            $this->writeSystemNote($so->fresh(), "Marked arrived{$partialNote}.");

            return $so->fresh();
        });

        // patch-93 dispatch SpecialOrderArrived — fires AFTER the DB
        // transaction commits, so listeners can assume durable state.
        event(new \App\Events\SpecialOrders\SpecialOrderArrived($result));

        return $result;
    }

    /**
     * arrived → pulled. Called when the line is rung up at register
     * or when an appointment containing the SO is completed.
     */
    public function markPulled(string $id): TenantSpecialOrder
    {
        return DB::transaction(function () use ($id) {
            $so = $this->findOrFail($id);
            $this->validateTransition($so, TenantSpecialOrder::STATUS_PULLED);

            $so->update([
                'status'    => TenantSpecialOrder::STATUS_PULLED,
                'pulled_at' => now(),
            ]);

            $this->writeSystemNote($so->fresh(), 'Marked pulled.');

            return $so->fresh();
        });
    }

    /**
     * Any non-terminal status → cancelled. Records the reason if given.
     * Does NOT refund deposits — that's a controller-layer Stripe call;
     * the service just records that the SO is no longer active.
     */
    public function cancel(string $id, ?string $reason = null): TenantSpecialOrder
    {
        return DB::transaction(function () use ($id, $reason) {
            $so = $this->findOrFail($id);
            $this->validateTransition($so, TenantSpecialOrder::STATUS_CANCELLED);

            $so->update([
                'status'              => TenantSpecialOrder::STATUS_CANCELLED,
                'cancelled_at'        => now(),
                'cancellation_reason' => $reason,
            ]);

            $noteBody = $reason ? "Cancelled: {$reason}" : 'Cancelled.';
            $this->writeSystemNote($so->fresh(), $noteBody);

            return $so->fresh();
        });
    }

    // ────────────────────────────────────────────────────────
    //  Partial-receipt row split
    // ────────────────────────────────────────────────────────

    /**
     * Split an ordered SO into two rows because the vendor short-shipped:
     *   - The current row's quantity is reduced to $receivedQty
     *     (caller will then mark it arrived)
     *   - A new sibling row is created with parent_id pointing at the
     *     current row, quantity = remaining, status=ordered
     *
     * The new sibling preserves vendor, customer, appointment, batch,
     * expected_arrival_date, and estimated cost. Its created_from is
     * carried over too. The deposit, however, stays with the parent
     * row — splits don't redistribute money already collected.
     *
     * Returns the new sibling. The parent (current) row mutation
     * happens in place; caller should refresh it.
     */
    public function splitForPartialReceipt(TenantSpecialOrder $so, int $receivedQty): TenantSpecialOrder
    {
        $totalQty = (int) $so->quantity;
        $remaining = $totalQty - $receivedQty;

        if ($receivedQty < 1 || $receivedQty >= $totalQty) {
            throw new SpecialOrderValidationException(
                "Split requires 1 <= receivedQty < quantity (got {$receivedQty} of {$totalQty})."
            );
        }
        if ($so->status !== TenantSpecialOrder::STATUS_ORDERED) {
            throw new SpecialOrderValidationException(
                "Only 'ordered' rows can be partial-receipt split (got status='{$so->status}')."
            );
        }

        return DB::transaction(function () use ($so, $receivedQty, $remaining) {
            // Reduce the parent's quantity in place.
            $so->update(['quantity' => $receivedQty]);

            // Spawn the remainder as a new sibling, parent_id pointing back.
            $remainderNumber = $this->nextSpecialOrderNumber($so->tenant_id);

            $remainderRow = TenantSpecialOrder::create([
                'tenant_id'                 => $so->tenant_id,
                'so_number'                 => $remainderNumber,
                'inventory_item_id'         => $so->inventory_item_id,
                'item_name_snapshot'        => $so->item_name_snapshot,
                'quantity'                  => $remaining,
                'customer_id'               => $so->customer_id,
                'appointment_id'            => $so->appointment_id,
                'vendor_id'                 => $so->vendor_id,
                'po_number'                 => $so->po_number,
                'vendor_reference'          => $so->vendor_reference,
                'status'                    => TenantSpecialOrder::STATUS_ORDERED,
                'created_from'              => $so->created_from,
                'unit_cost_cents_estimated' => $so->unit_cost_cents_estimated,
                'expected_arrival_date'     => $so->expected_arrival_date,
                'ordered_at'                => $so->ordered_at,
                'batch_id'                  => $so->batch_id,
                'parent_id'                 => $so->id,
                'created_by_user_id'        => $so->created_by_user_id,
            ]);

            $this->writeSystemNote(
                $so->fresh(),
                "Partial receipt — split into {$so->so_number} (received {$receivedQty}) "
                . "and {$remainderNumber} (remaining {$remaining})."
            );
            $this->writeSystemNote(
                $remainderRow,
                "Spawned from {$so->so_number} after partial receipt. {$remaining} units remain on order."
            );

            return $remainderRow;
        });
    }

    // ────────────────────────────────────────────────────────
    //  Notes
    // ────────────────────────────────────────────────────────

    /**
     * Append a note to an SO's notes thread. User notes have a
     * tenant_user_id and is_system=false. System notes pass userId=null
     * (or whatever the system "user" is) and isSystem=true.
     */
    public function addNote(
        string $specialOrderId,
        ?string $userId,
        string $body,
        bool $isSystem = false
    ): TenantSpecialOrderNote {
        if (trim($body) === '') {
            throw new SpecialOrderValidationException('Note body cannot be empty.');
        }

        return TenantSpecialOrderNote::create([
            'special_order_id' => $specialOrderId,
            'tenant_user_id'   => $isSystem ? null : $userId,
            'is_system'        => $isSystem,
            'body'             => $body,
        ]);
    }

    // ────────────────────────────────────────────────────────
    //  Internal helpers
    // ────────────────────────────────────────────────────────

    /**
     * Transition rules in one place. Throws on illegal.
     */
    protected function validateTransition(TenantSpecialOrder $so, string $newStatus): void
    {
        $from = $so->status;
        $allowed = match ($newStatus) {
            TenantSpecialOrder::STATUS_ORDERED   => [TenantSpecialOrder::STATUS_NEEDED],
            TenantSpecialOrder::STATUS_ARRIVED   => [TenantSpecialOrder::STATUS_ORDERED],
            TenantSpecialOrder::STATUS_PULLED    => [TenantSpecialOrder::STATUS_ARRIVED],
            TenantSpecialOrder::STATUS_CANCELLED => TenantSpecialOrder::STATUSES_OPEN,
            default => [],
        };

        if (!in_array($from, $allowed, true)) {
            throw new SpecialOrderValidationException(
                "Cannot transition from '{$from}' to '{$newStatus}' on SO {$so->so_number}."
            );
        }
    }

    /**
     * Find an SO by id or throw. Subclassed in tests to scope by
     * tenant; here we just bare findOrFail since controllers will
     * scope before calling.
     */
    protected function findOrFail(string $id): TenantSpecialOrder
    {
        return TenantSpecialOrder::findOrFail($id);
    }

    protected function writeSystemNote(TenantSpecialOrder $so, string $body): void
    {
        $this->addNote($so->id, null, $body, true);
    }

    /**
     * After an SO arrives, touch the (item, vendor) pivot row's
     * last_ordered_at. Doesn't recompute lead_time_days — that's
     * a read-side concern, computed on demand from the SO history.
     *
     * Silent no-op if the SO has no item or no vendor, or no pivot
     * row exists for the pair yet. (The pivot row may legitimately
     * not exist for the first SO from a new vendor — caller can
     * create it explicitly via the item.vendors() relationship.)
     */
    protected function updateVendorLeadTime(TenantSpecialOrder $so): void
    {
        if (!$so->inventory_item_id || !$so->vendor_id) {
            return;
        }

        try {
            $pivot = TenantInventoryItemVendor::where('inventory_item_id', $so->inventory_item_id)
                ->where('vendor_id', $so->vendor_id)
                ->first();

            if ($pivot) {
                $pivot->update(['last_ordered_at' => now()]);
            }
        } catch (\Throwable $e) {
            // Never let pivot housekeeping fail the arrival transition.
            // Log and continue.
            Log::warning('SpecialOrderService::updateVendorLeadTime failed', [
                'so_id'     => $so->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * MARKER-SO-SALE-LINK — the vendor this tenant would normally buy this
     * item from: the preferred row in the item-vendor catalog, else the most
     * recently ordered, else none.
     */
    public static function preferredVendorId(string $tenantId, ?string $inventoryItemId): ?string
    {
        if (! $inventoryItemId) {
            return null;
        }

        // The item-vendor pivot deliberately carries no tenant_id (see its
        // migration), so scope through the item and confirm the vendor is
        // this tenant's before returning it.
        $ownsItem = \App\Models\Tenant\TenantInventoryItem::where('id', $inventoryItemId)
            ->where('tenant_id', $tenantId)
            ->exists();
        if (! $ownsItem) {
            return null;
        }

        $vendorId = \App\Models\Tenant\TenantInventoryItemVendor::query()
            ->where('inventory_item_id', $inventoryItemId)
            ->orderByDesc('is_preferred')
            ->orderByDesc('last_ordered_at')
            ->value('vendor_id');

        if (! $vendorId) {
            return null;
        }

        return \App\Models\Tenant\TenantVendor::where('id', $vendorId)
            ->where('tenant_id', $tenantId)
            ->exists() ? $vendorId : null;
    }

    /**
     * MARKER-SO-AUTOVENDOR — choose a vendor by the tenant's rule.
     *
     *   off           — leave it blank
     *   preferred     — the is_preferred row, else most recently ordered
     *   lowest_price  — cheapest live cost (falling back to catalog cost),
     *                   PREFERRING vendors that actually show stock, because
     *                   auto-assigning to a vendor with none just makes work.
     *                   Falls back to cheapest overall when nobody has stock.
     *
     * @return array{vendor_id: ?string, rule: ?string}
     */
    public static function autoAssignVendor(string $tenantId, ?string $inventoryItemId): array
    {
        $none = ['vendor_id' => null, 'rule' => null];

        if (! $inventoryItemId) {
            return $none;
        }

        $tenant = \App\Models\Tenant::find($tenantId);
        $rule = (string) (($tenant->settings['special_orders']['auto_assign_vendor'] ?? 'preferred'));
        if ($rule === 'off') {
            return $none;
        }

        // The item-vendor pivot deliberately carries no tenant_id, so scope
        // through the item and keep only this tenant's active vendors.
        $ownsItem = \App\Models\Tenant\TenantInventoryItem::where('id', $inventoryItemId)
            ->where('tenant_id', $tenantId)->exists();
        if (! $ownsItem) {
            return $none;
        }

        $vendorIds = \App\Models\Tenant\TenantVendor::where('tenant_id', $tenantId)
            ->where('is_active', true)->pluck('id');
        if ($vendorIds->isEmpty()) {
            return $none;
        }

        $rows = \App\Models\Tenant\TenantInventoryItemVendor::where('inventory_item_id', $inventoryItemId)
            ->whereIn('vendor_id', $vendorIds)
            ->get();
        if ($rows->isEmpty()) {
            return $none;
        }

        if ($rule === 'lowest_price') {
            $priced = $rows->filter(fn ($r) => ($r->live_cost_cents ?? $r->unit_cost_cents) !== null);
            if ($priced->isEmpty()) {
                // No cost known anywhere — fall through to preferred instead
                // of picking arbitrarily.
                $rule = 'preferred';
            } else {
                $inStock = $priced->filter(fn ($r) => (int) ($r->live_avail ?? 0) > 0);
                $pool    = $inStock->isNotEmpty() ? $inStock : $priced;
                $pick    = $pool->sortBy(fn ($r) => $r->live_cost_cents ?? $r->unit_cost_cents)->first();

                return ['vendor_id' => $pick->vendor_id, 'rule' => 'lowest_price'];
            }
        }

        $pick = $rows->sortByDesc(fn ($r) => [(int) $r->is_preferred, optional($r->last_ordered_at)->timestamp ?? 0])->first();

        return $pick ? ['vendor_id' => $pick->vendor_id, 'rule' => 'preferred'] : $none;
    }
}
SOAV_2_EOF

cat > 'app/Http/Controllers/Tenant/SettingsController.php' <<'SOAV_3_EOF'
<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Sms\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Unified settings controller. Absorbs the previous BrandingController so the
 * settings page is a single tabbed view. The `tab` request input discriminates
 * which group of fields to validate and persist.
 *
 * Tabs:
 *  - business      currency, timezone, booking, tax, drop-off methods (CRUD via ReceivingMethodController)
 *  - branding      shop name, tagline, logos, colors, typography
 *  - communication email sender details, SMS provider config, notification toggles
 *  - account       custom domain (booking URL is read-only)
 *  - appearance    admin theme
 *  - payments      Stripe + PayPal API keys
 */
class SettingsController extends Controller
{
    public function index()
    {
        $tenant = tenant();
        $receivingMethods = \App\Models\Tenant\TenantReceivingMethod::where('tenant_id', $tenant->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $paymentMethods = \App\Models\Tenant\TenantPaymentMethod::bootstrapFor($tenant); // MARKER-PATCH-629
        return view('tenant.settings.index', compact('receivingMethods', 'paymentMethods'));
    }

    public function update(Request $request)
    {
        $tenant = tenant();
        $tab    = $request->input('tab', 'business');

        return match ($tab) {
            'business'      => $this->updateBusiness($request, $tenant),
            'branding'      => $this->updateBranding($request, $tenant),
            'communication' => $this->updateCommunication($request, $tenant),
            'account'       => $this->updateAccount($request, $tenant),
            'appearance'    => $this->updateAppearance($request, $tenant),
            'payments'      => $this->updatePayments($request, $tenant),
            'tags'          => $this->updateTags($request, $tenant), // MARKER-PATCH-315
            'ordering'      => $this->updateOrdering($request, $tenant), // MARKER-SO-AUTOVENDOR
            default         => back()->with('error', 'Unknown tab.'),
        };
    }

    // -------------------------------------------------------------------
    // MARKER-SO-AUTOVENDOR — how special orders choose a vendor.
    // -------------------------------------------------------------------
    private function updateOrdering(Request $request, $tenant)
    {
        $request->validate([
            'so_auto_assign_vendor' => ['required', 'in:preferred,lowest_price,off'],
        ]);

        $settings = $tenant->settings ?? [];
        $so = (array) ($settings['special_orders'] ?? []);
        $so['auto_assign_vendor'] = $request->input('so_auto_assign_vendor');
        $settings['special_orders'] = $so;
        $tenant->update(['settings' => $settings]);

        return back()->with('success', 'Ordering settings saved.');
    }

    // -------------------------------------------------------------------
    // MARKER-PATCH-315 — Work-order tag settings (toggles, lead time,
    // paper width, thermal logo). Stored in the tenant settings JSON.
    // -------------------------------------------------------------------
    private function updateTags(Request $request, $tenant)
    {
        $request->validate([
            'wot_lead_days' => ['nullable', 'integer', 'min:0', 'max:30'],
            'wot_paper'     => ['nullable', 'in:80mm,58mm'],
            'wot_header_text' => ['nullable', 'string', 'max:500'], // MARKER-PATCH-330
            'wot_footer_text' => ['nullable', 'string', 'max:500'], // MARKER-PATCH-330
            'wot_logo'      => ['nullable', 'image', 'max:2048'],
        ]);

        $settings = $tenant->settings ?? [];
        $wot = (array) ($settings['work_order_tag'] ?? []);

        $wot['enabled']       = (bool) $request->input('wot_enabled');
        $wot['show_header']   = (bool) $request->input('wot_show_header');
        $wot['show_phone']    = (bool) $request->input('wot_show_phone');
        $wot['show_bike']     = (bool) $request->input('wot_show_bike');
        $wot['show_services'] = (bool) $request->input('wot_show_services');
        $wot['show_note']     = (bool) $request->input('wot_show_note');
        $wot['show_qr']       = (bool) $request->input('wot_show_qr');
        $wot['show_stub']     = (bool) $request->input('wot_show_stub');
        $wot['lead_days']     = $request->filled('wot_lead_days') ? (int) $request->input('wot_lead_days') : 3;
        $wot['paper']         = $request->input('wot_paper', '80mm');
        $wot['logo_size']     = in_array($request->input('wot_logo_size'), ['small', 'medium', 'large', 'xl'], true) ? $request->input('wot_logo_size') : 'medium'; // MARKER-PATCH-317
        $wot['feed_mm']       = max(0, min(40, (int) $request->input('wot_feed_mm', 0))); // MARKER-PATCH-320
        $wot['header_text']   = trim((string) $request->input('wot_header_text', '')); // MARKER-PATCH-330
        $wot['footer_text']   = trim((string) $request->input('wot_footer_text', '')); // MARKER-PATCH-330

        if ($request->hasFile('wot_logo')) {
            $wot['logo_path'] = $request->file('wot_logo')->store("tenants/{$tenant->id}/work-order-tag", 'public');
        } elseif ($request->input('wot_logo_remove') === '1') {
            $wot['logo_path'] = null;
        }

        $settings['work_order_tag'] = $wot;
        $tenant->update(['settings' => $settings]);

        return back()->with('success', 'Work-order tag settings saved.');
    }

    // -------------------------------------------------------------------
    // Business: currency, timezone, booking window, classes, tax
    // -------------------------------------------------------------------
    private function updateBusiness(Request $request, $tenant)
    {
        $request->validate([
            'currency'             => ['required', 'string', 'size:3'],
            'currency_symbol'      => ['required', 'string', 'max:5'],
            'timezone'             => ['required', 'string', 'max:64'],
            'booking_window_days'  => ['required', 'integer', 'min:1', 'max:365'],
            'min_notice_hours'     => ['required', 'integer', 'min:0', 'max:168'],
            'classes_enabled'      => ['nullable', 'boolean'],
            'deliveries_enabled'   => ['nullable', 'boolean'], // MARKER-PATCH-156
            'multi_asset_enabled'  => ['nullable', 'boolean'], // MARKER-PATCH-158-B
            'asset_label_singular' => ['nullable', 'string', 'max:30'], // MARKER-PATCH-215
            'asset_label_plural'   => ['nullable', 'string', 'max:30'], // MARKER-PATCH-215
            'asset_label_singular' => ['nullable', 'string', 'max:30'], // MARKER-PATCH-215
            'asset_label_plural'   => ['nullable', 'string', 'max:30'], // MARKER-PATCH-215
            'asset_label_singular' => ['nullable', 'string', 'max:30'], // MARKER-PATCH-215
            'asset_label_plural'   => ['nullable', 'string', 'max:30'], // MARKER-PATCH-215
            'default_tax_rate'     => ['nullable', 'numeric', 'min:0', 'max:25'],
            'tax_services_default' => ['nullable', 'boolean'],
            'tax_supports_exempt'  => ['nullable', 'boolean'],
        ]);

        $tenant->update([
            'currency'             => $request->input('currency'),
            'currency_symbol'      => $request->input('currency_symbol'),
            'timezone'             => $request->input('timezone'),
            'booking_window_days'  => (int) $request->input('booking_window_days'),
            'min_notice_hours'     => (int) $request->input('min_notice_hours'),
            'classes_enabled'      => (bool) $request->input('classes_enabled'),
            'deliveries_enabled'   => (bool) $request->input('deliveries_enabled'), // MARKER-PATCH-156
            'multi_asset_enabled'  => (bool) $request->input('multi_asset_enabled'), // MARKER-PATCH-158-B
            'asset_label_singular' => $request->filled('asset_label_singular') ? trim($request->input('asset_label_singular')) : 'item',  // MARKER-PATCH-215
            'asset_label_plural'   => $request->filled('asset_label_plural')   ? trim($request->input('asset_label_plural'))   : 'items', // MARKER-PATCH-215
            'asset_label_singular' => $request->filled('asset_label_singular') ? trim($request->input('asset_label_singular')) : 'item',  // MARKER-PATCH-215
            'asset_label_plural'   => $request->filled('asset_label_plural')   ? trim($request->input('asset_label_plural'))   : 'items', // MARKER-PATCH-215
            'asset_label_singular' => $request->filled('asset_label_singular') ? trim($request->input('asset_label_singular')) : 'item',  // MARKER-PATCH-215
            'asset_label_plural'   => $request->filled('asset_label_plural')   ? trim($request->input('asset_label_plural'))   : 'items', // MARKER-PATCH-215
            'default_tax_rate'     => $request->filled('default_tax_rate')
                ? (float) $request->input('default_tax_rate')
                : null,
            'tax_services_default' => (bool) $request->input('tax_services_default'),
            'tax_supports_exempt'  => (bool) $request->input('tax_supports_exempt'),
        ]);

        return back()->with('success', 'Business settings saved.');
    }

    // -------------------------------------------------------------------
    // Branding: shop identity, logos, colors, typography
    // (formerly BrandingController::update tab=appearance, file uploads + colors)
    // -------------------------------------------------------------------
    private function updateBranding(Request $request, $tenant)
    {
        $request->validate([
            'name'              => ['required', 'string', 'max:255'],
            'tagline'           => ['nullable', 'string', 'max:255'],
            'accent_color'      => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'text_color'        => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'bg_color'          => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'font_heading'      => ['nullable', 'string', 'max:100'],
            'font_body'         => ['nullable', 'string', 'max:100'],
            'logo_size_admin'   => ['nullable', 'integer', 'min:16', 'max:80'],
            'logo_size_booking' => ['nullable', 'integer', 'min:16', 'max:120'],
        ]);

        $data = $request->only([
            'name', 'tagline', 'accent_color', 'text_color',
            'bg_color', 'font_heading', 'font_body',
            'logo_size_admin', 'logo_size_booking',
        ]);

        if ($request->hasFile('logo')) {
            $request->validate(['logo' => ['image', 'max:2048']]);
            $path = $request->file('logo')->store("tenants/{$tenant->id}/logo", 'public');
            $data['logo_url'] = asset('storage/' . $path);
        }

        if ($request->hasFile('logo_light')) {
            $request->validate(['logo_light' => ['image', 'max:2048']]);
            $path = $request->file('logo_light')->store("tenants/{$tenant->id}/logo", 'public');
            $data['logo_light_url'] = asset('storage/' . $path);
        }

        if ($request->hasFile('favicon')) {
            $request->validate(['favicon' => ['image', 'max:512']]);
            $path = $request->file('favicon')->store("tenants/{$tenant->id}/favicon", 'public');
            $data['favicon_url'] = asset('storage/' . $path);
        }

        $tenant->update($data);

        return back()->with('success', 'Branding saved.');
    }

    // -------------------------------------------------------------------
    // Communication: email sender, SMS provider, notification toggles
    // -------------------------------------------------------------------
    private function updateCommunication(Request $request, $tenant)
    {
        $request->validate([
            // Email
            'email_from_name'    => ['nullable', 'string', 'max:255'],
            'email_from_address' => ['nullable', 'email', 'max:255'],
            'email_reply_to'     => ['nullable', 'email', 'max:255'],
            'notification_email' => ['nullable', 'email', 'max:255'],
            // SMS
            // MARKER-PATCH-224 — sms_* moved to Settings\MessagingController.
            // MARKER-PATCH-406 — notification toggles moved to Communication Center
        ]);

        // Don't overwrite an existing token with empty input — the form posts
        // MARKER-PATCH-224 — sms_*/twilio_* are owned by
        // Settings\MessagingController now. Writing them here would null
        // the messaging config on every unrelated settings save.
        $tenant->update([
            'email_from_name'    => $request->input('email_from_name'),
            'email_from_address' => $request->input('email_from_address'),
            'email_reply_to'     => $request->input('email_reply_to'),
            'notification_email' => $request->input('notification_email'),
        ]);

        // MARKER-PATCH-406 — notification toggles now owned by CommunicationController

        return back()->with('success', 'Communication settings saved.');
    }

    // -------------------------------------------------------------------
    // Account: custom domain
    // (booking URL is read-only display; subscription/billing also read-only)
    // -------------------------------------------------------------------
    private function updateAccount(Request $request, $tenant)
    {
        if (in_array($tenant->plan_tier, ['branded', 'scale', 'custom'])) {
            $request->validate([
                // MARKER-PATCH-120-SETTINGS-CONTROLLER - tenant_domains is the new source of truth
                // 'custom_domain' => ['nullable', 'string', 'max:253',
                //     'regex:/^([a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/'],
            ]);
            // $tenant->update(['custom_domain' => $request->input('custom_domain') ?: null]); // MARKER-PATCH-120-SETTINGS-CONTROLLER
        }
        return back()->with('success', 'Account settings saved.');
    }

    // -------------------------------------------------------------------
    // Appearance: admin theme
    // -------------------------------------------------------------------
    private function updateAppearance(Request $request, $tenant)
    {
        $request->validate([
            'admin_theme' => ['required', 'in:b,c'],
        ]);
        $settings = $tenant->settings ?? [];
        $settings['admin_theme'] = $request->input('admin_theme');
        $tenant->update(['settings' => $settings]);
        return back()->with('success', 'Appearance saved.');
    }

    // -------------------------------------------------------------------
    // Payments: Stripe + PayPal API keys (preserved verbatim from old controller)
    // -------------------------------------------------------------------
    private function updatePayments(Request $request, $tenant)
    {
        $settings = $tenant->settings ?? [];

        // MARKER-PATCH-388 — legacy booking-deposit stripe_* keys retired.
        // Booking deposits now run on Direct Payments (register_payments_* keys).

        // MARKER-PATCH-169 — Direct Payments bridge feature.
        // Register card-sale keys, namespaced separately from the booking-deposit
        // Stripe keys above (which power BookingController via App\Services\StripeService).
        // Only saved if the tenant has direct_payments_enabled set by master admin;
        // otherwise the form fields don\'t render and the inputs come back empty,
        // which is fine.
        if ($tenant->direct_payments_enabled) {
            // MARKER-PATCH-618 — tenant-level on/off for card + payment-link tenders
            // (master flag stays the capability gate; this is the tenant's switch).
            $settings['stripe_register_enabled'] = (bool) $request->input('stripe_register_enabled');
            $settings['square_enabled']          = (bool) $request->input('square_enabled');

            $settings['register_payments_mode']           = $request->input('register_payments_mode', 'test');
            $settings['register_payments_test_pk']        = $request->input('register_payments_test_pk', '');
            $settings['register_payments_test_sk']        = $request->input('register_payments_test_sk', '');
            $settings['register_payments_live_pk']        = $request->input('register_payments_live_pk', '');
            $settings['register_payments_live_sk']        = $request->input('register_payments_live_sk', '');
            $settings['register_payments_webhook_secret'] = $request->input('register_payments_webhook_secret', '');

            // MARKER-PATCH-473 — Square (tenant-connected) credentials
            $settings['square_payments_mode']           = $request->input('square_payments_mode', 'sandbox');
            $settings['square_sandbox_app_id']          = $request->input('square_sandbox_app_id', '');
            $settings['square_sandbox_location_id']     = $request->input('square_sandbox_location_id', '');
            $settings['square_sandbox_access_token']    = $request->input('square_sandbox_access_token', '');
            $settings['square_production_app_id']       = $request->input('square_production_app_id', '');
            $settings['square_production_location_id']  = $request->input('square_production_location_id', '');
            $settings['square_production_access_token'] = $request->input('square_production_access_token', '');
            $settings['square_webhook_signature_key']   = $request->input('square_webhook_signature_key', '');
        }

        $settings['paypal_enabled']        = (bool) $request->input('paypal_enabled');
        $settings['paypal_mode']           = $request->input('paypal_mode', 'sandbox');
        $settings['paypal_test_client_id'] = $request->input('paypal_test_client_id', '');
        $settings['paypal_test_secret']    = $request->input('paypal_test_secret', '');
        $settings['paypal_live_client_id'] = $request->input('paypal_live_client_id', '');
        $settings['paypal_live_secret']    = $request->input('paypal_live_secret', '');

        // MARKER-PATCH-618 — Venmo / Cash App manual tenders (peer-to-peer pay links).
        // Handles are stored bare (no @ / $); the link helper adds the scheme.
        // MARKER-PATCH-629 — venmo/cashapp keys retired here: owned by
        // tenant_payment_methods and written back via syncLegacyKeys().

        $tenant->update(['settings' => $settings]);
        return back()->with('success', 'Payment settings saved.');
    }

    // -------------------------------------------------------------------
    // POST endpoint: send a test SMS to verify Twilio configuration.
    // Uses the tenant's *saved* credentials, so user must save before testing.
    // -------------------------------------------------------------------
    // MARKER-PATCH-468 — toggle asset tracking from the Services-page banner
    public function toggleAssetTracking(Request $request): JsonResponse
    {
        $tenant = tenant();
        $enabled = (bool) $request->input('enabled');
        $tenant->update(['multi_asset_enabled' => $enabled]);
        return response()->json(['ok' => true, 'enabled' => $enabled]);
    }

    // MARKER-PATCH-473 — verify the tenant's pasted Square credentials
    public function verifySquareConnection(Request $request): JsonResponse
    {
        $tenant = tenant();
        if (! ($tenant->direct_payments_enabled ?? false)) {
            return response()->json(['ok' => false, 'message' => 'Payments are not enabled for this account.'], 403);
        }
        $result = (new \App\Services\Tenant\SquarePaymentsService($tenant))->verifyConnection();
        return response()->json($result);
    }

    public function sendTestSms(Request $request): JsonResponse
    {
        $request->validate([
            'to' => ['required', 'string', 'max:32'],
        ]);

        $tenant = tenant();

        // MARKER-PATCH-224 — managed numbers send on platform creds; only
        // require tenant creds when no platform fallback exists.
        $hasCreds = ($tenant->twilio_account_sid && $tenant->twilio_auth_token)
            || (config('services.twilio.sid') && config('services.twilio.token')); // MARKER-PATCH-224B
        if (! $tenant->sms_enabled || ! $tenant->sms_from_number || ! $hasCreds) {
            return response()->json([
                'ok'    => false,
                'error' => 'SMS is not enabled or credentials are missing. Save your settings first, then try again.',
            ], 422);
        }

        try {
            SmsService::send(
                $tenant,
                $request->input('to'),
                sprintf('Intake test message from %s. SMS is configured correctly.', $tenant->name)
            );
            return response()->json(['ok' => true, 'message' => 'Test SMS sent. Check the recipient phone.']);
        } catch (\Throwable $e) {
            return response()->json([
                'ok'    => false,
                'error' => 'Send failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}

SOAV_3_EOF

cat > 'resources/views/tenant/settings/index.blade.php' <<'SOAV_4_EOF'
@extends('layouts.tenant.app')
@php
  /*
   * Unified settings page. Six tabs, JS-switched (no URL params).
   * Each tab is its own form; one save button per tab in a sticky save bar.
   * Drop-off methods CRUD lives in the Business tab and uses its own
   * dedicated endpoints (tenant.receiving-methods.*) — preserved verbatim
   * from the previous settings/branding split.
   */
  $pageTitle  = 'Settings';
  $s          = $currentTenant->settings ?? [];
  $currencies = ['USD'=>'$','CAD'=>'CA$','GBP'=>'£','EUR'=>'€','AUD'=>'A$','NZD'=>'NZ$'];
  $fonts      = ['Inter','Poppins','DM Sans','Nunito','Lato','Raleway','Montserrat','Playfair Display','Merriweather'];

  // Admin theme stored in settings JSON. Default to 'c' (dark).
  $adminTheme = $s['admin_theme'] ?? 'c';
  if ($adminTheme === 'a') $adminTheme = 'c';

  // Notification toggles default to ON via Tenant::notificationEnabled().
  $notifyBookingEmail = $currentTenant->notificationEnabled('booking_confirmation_email');
  $notifyBookingSms   = $currentTenant->notificationEnabled('booking_confirmation_sms');

  // MARKER-PATCH-152C — delivery scheduled toggles
  $notifyDeliveryEmail = $currentTenant->notificationEnabled('delivery_scheduled_email');
  $notifyDeliverySms   = $currentTenant->notificationEnabled('delivery_scheduled_sms');

  // MARKER-PATCH-154 — appointment reminder toggles
  $notifyApptReminderEmail = $currentTenant->notificationEnabled('appointment_reminder_email');
  $notifyApptReminderSms   = $currentTenant->notificationEnabled('appointment_reminder_sms');

  // MARKER-PATCH-155 — delivery reminder toggles
  $notifyDeliveryReminderEmail = $currentTenant->notificationEnabled('delivery_reminder_email');
  $notifyDeliveryReminderSms   = $currentTenant->notificationEnabled('delivery_reminder_sms');

  // SMS auth token: don't render the actual value back to the form. Show
  // a masked placeholder if one is set, blank if not. Controller treats
  // an empty submission as "leave unchanged."
  $hasTwilioToken = (bool) $currentTenant->twilio_auth_token;
@endphp

@push('styles')
<style>
/* -------------------------------------------------------------------------
 * Settings page chrome
 * ------------------------------------------------------------------------- */
.set-head {
  display:flex; align-items:flex-start; justify-content:space-between;
  gap:16px; margin-bottom:18px; flex-wrap:wrap;
}
.set-booking-chip {
  display:inline-flex; align-items:center; gap:6px;
  padding:7px 12px; border-radius:99px;
  border:0.5px solid var(--ia-border);
  background:var(--ia-surface);
  font-size:12px; color:var(--ia-text);
  text-decoration:none;
  transition:background var(--ia-t), border-color var(--ia-t);
  white-space:nowrap;
}
.set-booking-chip:hover { background:var(--ia-hover); border-color:var(--ia-border-strong); }
.set-booking-chip svg { opacity:.55; }

/* Tabs */
.set-tabs {
  display:flex; gap:0;
  border-bottom:0.5px solid var(--ia-border);
  margin-bottom:20px;
  overflow-x:auto;
  scrollbar-width:none;
}
.set-tabs::-webkit-scrollbar { display:none; }
.set-tab {
  padding:10px 18px; font-size:13px; color:var(--ia-text-muted);
  cursor:pointer; border-bottom:2px solid transparent;
  background:transparent; border-left:none; border-right:none; border-top:none;
  font-family:inherit; transition:color .12s, border-color .12s;
  white-space:nowrap;
}
.set-tab:hover { color:var(--ia-text); }
.set-tab.active { color:var(--ia-text); border-bottom-color:var(--ia-accent); }

/* Panes */
.set-pane { display:none; }
.set-pane.active { display:block; }

/* MARKER-PATCH-150-POLISH-A — responsive card grid */
.set-section {
  display: block;
  max-width: 1200px;
}
/* Each card in a settings form becomes a grid cell.
   Cards default to ~half width (min 420px). Cards with .set-card--wide
   span the full row. Save bars and headers are always full-row. */
.set-section .ia-card {
  margin-bottom: 0;
}
.set-section--grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(420px, 1fr));
  gap: 18px;
  /* MARKER-PATCH-150-POLISH-C — same-row cards match heights */
  align-items: stretch;
}
.set-section--grid > .ia-card { display: flex; flex-direction: column; }
.set-section--grid .set-card--wide,
.set-section--grid .set-savebar {
  grid-column: 1 / -1;
}
@media (max-width: 880px) {
  .set-section--grid { grid-template-columns: 1fr; }
}

/* Save bar — sticky at top of pane, dims when no changes */
.set-savebar {
  position:sticky; top:0; z-index:5;
  background:var(--ia-bg);
  margin:-6px -6px 16px;
  padding:10px 6px;
  border-bottom:0.5px solid transparent;
  display:flex; align-items:center; justify-content:space-between;
  gap:12px; flex-wrap:wrap;
  transition:border-color .15s;
}
.set-savebar.dirty { border-bottom-color:var(--ia-border); }
.set-savebar-msg {
  font-size:12px; color:var(--ia-text-dim);
  transition:color .15s;
}
.set-savebar.dirty .set-savebar-msg { color:var(--ia-text); }
.set-savebar-actions { display:flex; gap:8px; }
.set-save-btn {
  font-size:13px; padding:8px 16px;
  border-radius:var(--ia-r-md);
  border:0.5px solid var(--ia-accent);
  background:var(--ia-accent); color:var(--ia-accent-text);
  cursor:pointer; font-family:inherit; font-weight:500;
  transition:opacity .15s, filter .15s;
}
.set-save-btn:hover { filter:brightness(1.08); }
.set-save-btn:disabled,
.set-savebar:not(.dirty) .set-save-btn {
  opacity:.4; cursor:not-allowed; filter:none;
}
.set-discard-btn {
  font-size:13px; padding:8px 14px;
  border-radius:var(--ia-r-md);
  border:0.5px solid var(--ia-border);
  background:transparent; color:var(--ia-text-muted);
  cursor:pointer; font-family:inherit;
  transition:background .12s;
}
.set-discard-btn:hover { background:var(--ia-hover); color:var(--ia-text); }
.set-savebar:not(.dirty) .set-discard-btn { display:none; }

/* "Coming soon" sections (Locations, etc.) */
.set-coming-soon {
  position:relative;
  border:0.5px dashed var(--ia-border);
  border-radius:var(--ia-r-lg);
  padding:18px 20px;
  margin-bottom:20px;
  opacity:.55;
}
.set-coming-soon-pill {
  position:absolute; top:14px; right:14px;
  font-size:10px; padding:3px 9px; border-radius:99px;
  background:var(--ia-surface-2); color:var(--ia-text-dim);
  text-transform:uppercase; letter-spacing:.06em; font-weight:600;
}
.set-coming-soon-title {
  font-size:14px; font-weight:500; margin-bottom:4px;
}
.set-coming-soon-desc {
  font-size:12px; color:var(--ia-text-muted); line-height:1.5;
  max-width:520px;
}

/* Provider toggle (Stripe / PayPal) — preserved from old settings page */
.provider-card {
  border:0.5px solid var(--ia-border);
  border-radius:var(--ia-r-lg);
  padding:20px; margin-bottom:16px;
  transition:border-color .12s;
}
.provider-card.enabled { border-color:var(--ia-accent); }
.provider-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:0; }
.provider-fields {
  margin-top:16px; padding-top:16px;
  border-top:0.5px solid var(--ia-border);
  display:none;
}
.provider-card.enabled .provider-fields { display:block; }
.prov-toggle-btn {
  width:38px; height:22px; background:var(--ia-border);
  border-radius:11px; position:relative;
  cursor:pointer; border:none; outline:none;
  transition:background .12s; flex-shrink:0;
}
.prov-toggle-btn.on { background:var(--ia-accent); }
.prov-toggle-btn::after {
  content:''; position:absolute; top:3px; left:3px;
  width:16px; height:16px; border-radius:50%;
  background:white; transition:transform .12s;
}
.prov-toggle-btn.on::after { transform:translateX(16px); }

/* Domain badge (preserved) */
.domain-badge {
  font-size:11px; padding:3px 10px;
  border-radius:20px; font-weight:500; margin-left:8px;
}
.domain-badge.basic   { background:var(--ia-surface-2); color:var(--ia-text-muted); }
.domain-badge.branded { background:#EEEDFE; color:#534AB7; }
.domain-badge.scale   { background:#E1F5EE; color:#0F6E56; }
.domain-badge.custom  { background:#EAF3DE; color:#3B6D11; }

/* notif-row styles removed — patch-406 (toggles moved to Communication Center) */

/* Color swatch (branding tab) */
.color-swatch-row {
  display:flex; gap:10px; align-items:center; margin-top:6px;
}
.color-swatch {
  width:36px; height:36px;
  border-radius:var(--ia-r-md);
  border:0.5px solid var(--ia-border);
  overflow:hidden; cursor:pointer; flex-shrink:0;
}
.color-swatch input[type=color] {
  width:52px; height:52px; margin:-8px;
  border:none; cursor:pointer; background:none; padding:0;
}

/* Logo previews (branding tab) */
.logo-preview { height:40px; border-radius:6px; margin-bottom:8px; display:block; }
.logo-preview-dark {
  background:#111; padding:6px 10px; border-radius:6px;
  margin-bottom:8px; display:inline-block;
}
.logo-preview-dark img { height:32px; }

/* Theme picker (appearance tab) */
.theme-grid {
  display:grid; grid-template-columns:repeat(2,1fr);
  gap:12px; margin-top:8px; max-width:420px;
}
.theme-card {
  border:0.5px solid var(--ia-border);
  border-radius:var(--ia-r-lg);
  padding:14px; cursor:pointer; transition:all .12s;
  position:relative;
}
.theme-card:hover { border-color:var(--ia-accent); }
.theme-card.selected { border-color:var(--ia-accent); background:var(--ia-accent-soft); }
.theme-card input { position:absolute; opacity:0; width:0; height:0; }
.theme-preview {
  height:60px; border-radius:var(--ia-r-md);
  overflow:hidden; margin-bottom:8px; display:flex;
}
.theme-label { font-size:12px; font-weight:500; text-align:center; }
.preview-b-wrap { flex:1; display:flex; flex-direction:column; }
.preview-b-top  { height:12px; background:#ffffff; border-bottom:0.5px solid #e8e8e4; }
.preview-b-main { flex:1; background:#ffffff; }
.preview-c-side { width:35%; background:#0c0c0c; }
.preview-c-main { flex:1; background:#111111; }

/* SMS test status flash */
.sms-test-status {
  margin-top:10px; font-size:12px; padding:8px 12px;
  border-radius:var(--ia-r-md);
  display:none;
}
.sms-test-status.success { display:block; background:rgba(120,200,120,.10); color:#78c878; border:0.5px solid rgba(120,200,120,.25); }
.sms-test-status.error   { display:block; background:rgba(240,149,149,.10); color:#F09595; border:0.5px solid rgba(240,149,149,.25); }
</style>
@endpush

@section('content')

<div class="set-head">
  <div>
    <h1 class="ia-page-title" style="margin-bottom:4px">Settings</h1>
    <p class="ia-page-subtitle" style="margin:0">Configure your shop's operational preferences and branding.</p>
  </div>
  <a href="{{ $currentTenant->bookingUrl() }}" target="_blank" rel="noopener noreferrer" class="set-booking-chip">
    <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
      <path d="M5 9L9 5M9 5H5.5M9 5v3.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>
      <rect x="2" y="2" width="10" height="10" rx="2" stroke="currentColor" stroke-width="1.2"/>
    </svg>
    Open booking page
  </a>
</div>

{{-- MARKER-PATCH-165 — success flash removed; the global layout renders it once at the top. --}}
@if($errors->any())
<div style="padding:10px 14px;margin-bottom:16px;border-radius:var(--ia-r-md);background:rgba(240,149,149,.10);border:0.5px solid rgba(240,149,149,.25);font-size:13px;color:#F09595">
  @foreach($errors->all() as $err){{ $err }}<br>@endforeach
</div>
@endif

<div class="set-tabs" role="tablist">
  <button type="button" class="set-tab active" data-tab="business"      role="tab">Business</button>
  <button type="button" class="set-tab"        data-tab="branding"      role="tab">Branding</button>
  <button type="button" class="set-tab"        data-tab="communication" role="tab">Communication</button>
  <button type="button" class="set-tab"        data-tab="account"       role="tab">Account</button>
  <button type="button" class="set-tab"        data-tab="payments"      role="tab">Payments</button>
  <button type="button" class="set-tab"        data-tab="tags"          role="tab">Print &amp; receipts</button>{{-- MARKER-PATCH-315 / 339 --}}
  <button type="button" class="set-tab"        data-tab="ordering"      role="tab">Ordering</button>{{-- MARKER-SO-AUTOVENDOR --}}
</div>

{{-- =====================================================================
     BUSINESS — currency, timezone, booking, tax, drop-off methods
     ===================================================================== --}}
<div class="set-pane active" id="pane-business" role="tabpanel">

  <form method="POST" action="{{ route('tenant.settings.update') }}" class="set-section set-section--grid" data-dirty-form>
    @csrf @method('PATCH')
    <input type="hidden" name="tab" value="business">

    <div class="set-savebar" data-savebar>
      <span class="set-savebar-msg"></span><!-- MARKER-PATCH-165 — populated by JS -->
      <div class="set-savebar-actions">
        <button type="button" class="set-discard-btn" data-discard>Discard</button>
        <button type="submit" class="set-save-btn">Save business settings</button>
      </div>
    </div>

    {{-- Currency --}}
    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Currency</span></div>
      <div class="ia-input-grid-2">
        <div class="ia-form-group">
          <label class="ia-form-label">Currency code</label>
          <select name="currency" class="ia-input">
            @foreach($currencies as $code => $sym)
              <option value="{{ $code }}" @selected($currentTenant->currency === $code)>{{ $code }} ({{ $sym }})</option>
            @endforeach
          </select>
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Currency symbol</label>
          <input type="text" name="currency_symbol" class="ia-input"
            value="{{ old('currency_symbol', $currentTenant->currency_symbol) }}" maxlength="5">
        </div>
      </div>
    </div>

    {{-- Timezone --}}
    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Timezone</span></div>
      <div class="ia-form-group">
        <label class="ia-form-label">Your local timezone</label>
        <select name="timezone" class="ia-input">
          @php
            $tzGroups = [
              'United States' => [
                'America/Los_Angeles' => 'Pacific (Los Angeles)',
                'America/Denver'      => 'Mountain (Denver)',
                'America/Phoenix'     => 'Mountain — no DST (Phoenix)',
                'America/Chicago'     => 'Central (Chicago)',
                'America/New_York'    => 'Eastern (New York)',
                'America/Anchorage'   => 'Alaska (Anchorage)',
                'Pacific/Honolulu'    => 'Hawaii (Honolulu)',
              ],
              'Canada' => [
                'America/Vancouver' => 'Pacific (Vancouver)',
                'America/Edmonton'  => 'Mountain (Edmonton)',
                'America/Winnipeg'  => 'Central (Winnipeg)',
                'America/Toronto'   => 'Eastern (Toronto)',
                'America/Halifax'   => 'Atlantic (Halifax)',
              ],
              'Other' => [
                'UTC'              => 'UTC',
                'Europe/London'    => 'London',
                'Europe/Paris'     => 'Paris',
                'Australia/Sydney' => 'Sydney',
              ],
            ];
            $currentTz = old('timezone', $currentTenant->timezone ?? 'America/Los_Angeles');
          @endphp
          @foreach($tzGroups as $groupName => $zones)
            <optgroup label="{{ $groupName }}">
              @foreach($zones as $tz => $label)
                <option value="{{ $tz }}" @selected($currentTz === $tz)>{{ $label }}</option>
              @endforeach
            </optgroup>
          @endforeach
        </select>
        <p style="font-size:12px;opacity:.5;margin-top:6px">
          Determines what counts as "today" on your calendar and dashboard. Stored timestamps are unaffected.
        </p>
      </div>
    </div>

    {{-- Booking window --}}
    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Booking window</span></div>
      <div class="ia-input-grid-2">
        <div class="ia-form-group">
          <label class="ia-form-label">How far ahead can customers book?</label>
          <input type="number" name="booking_window_days" class="ia-input" min="1" max="365"
            value="{{ old('booking_window_days', $currentTenant->booking_window_days ?? 60) }}">
          <p style="font-size:11px;opacity:.4;margin-top:4px">Days from today</p>
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Minimum notice required</label>
          <input type="number" name="min_notice_hours" class="ia-input" min="0" max="168"
            value="{{ old('min_notice_hours', $currentTenant->min_notice_hours ?? 24) }}">
          <p style="font-size:11px;opacity:.4;margin-top:4px">0 = same-day bookings allowed</p>
        </div>
      </div>
    </div>

    {{-- Class bookings --}}
    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Class bookings</span></div>
      <div style="padding:6px 0;display:flex;align-items:center;justify-content:space-between;gap:16px">
        <div>
          <div style="font-size:14px;font-weight:500">Enable class bookings</div>
          <div style="font-size:12px;opacity:.5;margin-top:2px">Adds a Classes section to your admin and a customer-facing /classes page.</div>
        </div>
        <input type="hidden" name="classes_enabled" id="classes_enabled_input" value="{{ $currentTenant->classes_enabled ? '1' : '0' }}">
        <button type="button"
          class="ia-toggle {{ $currentTenant->classes_enabled ? 'on' : '' }}"
          id="classes-toggle-btn"
          aria-label="Enable class bookings">
          <span class="ia-toggle-sr">{{ $currentTenant->classes_enabled ? 'Enabled' : 'Disabled' }}</span>
        </button>
      </div>
    </div>

    {{-- MARKER-PATCH-156 — Deliveries --}}
    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Deliveries</span></div>
      <div style="padding:6px 0;display:flex;align-items:center;justify-content:space-between;gap:16px">
        <div>
          <div style="font-size:14px;font-weight:500">Enable deliveries</div>
          <div style="font-size:12px;opacity:.5;margin-top:2px">Internal pickup &amp; dropoff scheduling. Adds a Deliveries pill to your Schedule menu.</div>
        </div>
        <input type="hidden" name="deliveries_enabled" id="deliveries_enabled_input" value="{{ $currentTenant->deliveries_enabled ? '1' : '0' }}">
        <button type="button"
          class="ia-toggle {{ $currentTenant->deliveries_enabled ? 'on' : '' }}"
          id="deliveries-toggle-btn"
          aria-label="Enable deliveries">
          <span class="ia-toggle-sr">{{ $currentTenant->deliveries_enabled ? 'Enabled' : 'Disabled' }}</span>
        </button>
      </div>
    </div>

    {{-- MARKER-PATCH-158-B — Multi-asset --}}
    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Multi-asset appointments</span></div>
      <div style="padding:6px 0;display:flex;align-items:center;justify-content:space-between;gap:16px">
        <div>
          <div style="font-size:14px;font-weight:500">Track customer assets</div>
          <div style="font-size:12px;opacity:.5;margin-top:2px">Track bikes, vehicles, pets, or other items per customer, and attach multiple to a single appointment. Useful for family drop-offs, fleet servicing, or multi-pet appointments.</div>
        </div>
        <input type="hidden" name="multi_asset_enabled" id="multi_asset_enabled_input" value="{{ $currentTenant->multi_asset_enabled ? '1' : '0' }}">
        <button type="button"
          class="ia-toggle {{ $currentTenant->multi_asset_enabled ? 'on' : '' }}"
          id="multi-asset-toggle-btn"
          aria-label="Enable multi-asset tracking">
          <span class="ia-toggle-sr">{{ $currentTenant->multi_asset_enabled ? 'Enabled' : 'Disabled' }}</span>
        </button>
      </div>
      {{-- MARKER-PATCH-215 — what this tenant calls its assets (drives customer booking copy) --}}
      <div class="ia-input-grid-2" style="margin-top:14px;padding-top:14px;border-top:1px solid var(--ia-border,rgba(255,255,255,.08))">
        <div class="ia-form-group">
          <label class="ia-form-label">What you call one (singular)</label>
          <input type="text" name="asset_label_singular" class="ia-input" maxlength="30"
            placeholder="item" value="{{ old('asset_label_singular', $currentTenant->asset_label_singular) }}">
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Plural</label>
          <input type="text" name="asset_label_plural" class="ia-input" maxlength="30"
            placeholder="items" value="{{ old('asset_label_plural', $currentTenant->asset_label_plural) }}">
        </div>
      </div>
      <div style="font-size:12px;opacity:.5;margin-top:8px">Shown on your customer booking page — e.g. “bike”, “vehicle”, “pet”. Leave blank for “item”.</div>
    </div>

    {{-- Tax --}}
    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Sales tax</span></div>
      <div class="ia-form-group">
        <label class="ia-form-label">Default tax rate (%)</label>
        <input type="number" name="default_tax_rate" class="ia-input" step="0.001" min="0" max="25"
          style="max-width:200px"
          value="{{ old('default_tax_rate', $currentTenant->default_tax_rate) }}"
          placeholder="e.g. 8.875">
        <p style="font-size:11px;opacity:.5;margin-top:6px;line-height:1.5">
          Applied to taxable items at checkout. Leave blank if you don't collect sales tax.
        </p>
      </div>

      <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;padding:10px 0;border-top:0.5px solid var(--ia-border);margin-top:8px">
        <div>
          <div style="font-size:13px;font-weight:500">Services are taxable by default</div>
          <div style="font-size:12px;opacity:.5;margin-top:2px">Per-service overrides available later when editing a service.</div>
        </div>
        <input type="hidden" name="tax_services_default" id="tax_services_default_input" value="{{ ($currentTenant->tax_services_default ?? true) ? '1' : '0' }}">
        <button type="button"
          class="ia-toggle {{ ($currentTenant->tax_services_default ?? true) ? 'on' : '' }}"
          id="tax-services-toggle-btn"
          aria-label="Services are taxable by default">
          <span class="ia-toggle-sr">{{ ($currentTenant->tax_services_default ?? true) ? 'Yes' : 'No' }}</span>
        </button>
      </div>

      <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;padding:10px 0;border-top:0.5px solid var(--ia-border)">
        <div>
          <div style="font-size:13px;font-weight:500">Customers can be tax-exempt</div>
          <div style="font-size:12px;opacity:.5;margin-top:2px">Adds a "tax exempt" toggle to customer records (useful for non-profits, resellers).</div>
        </div>
        <input type="hidden" name="tax_supports_exempt" id="tax_supports_exempt_input" value="{{ ($currentTenant->tax_supports_exempt ?? false) ? '1' : '0' }}">
        <button type="button"
          class="ia-toggle {{ ($currentTenant->tax_supports_exempt ?? false) ? 'on' : '' }}"
          id="tax-exempt-toggle-btn"
          aria-label="Customers can be tax-exempt">
          <span class="ia-toggle-sr">{{ ($currentTenant->tax_supports_exempt ?? false) ? 'Yes' : 'No' }}</span>
        </button>
      </div>
    </div>

    {{-- Locations (coming soon) --}}
    <div class="set-coming-soon">
      <span class="set-coming-soon-pill">Add-on</span>
      <div class="set-coming-soon-title">Locations</div>
      <div class="set-coming-soon-desc">
        Run multiple shops from one Intake account — separate calendars, staff, and reporting per location.
        Available as a paid add-on. Talk to support to enable.
      </div>
    </div>

  </form>

  {{-- Drop-off methods (separate block — own endpoints, not part of the main form) --}}
  <div class="set-section set-section--grid">
    <div class="ia-card set-card--wide" style="margin-bottom:20px">
      <div class="ia-card-head" style="display:flex;align-items:center;justify-content:space-between">
        <span class="ia-card-title">Drop-off methods</span>
        <span style="font-size:11px;opacity:.45">Shown on the booking page so customers tell you how they're getting items to you</span>
      </div>

      <div style="padding:14px 16px">
        <form id="add-method-form" style="display:grid;grid-template-columns:1.2fr 1.6fr auto;gap:10px;align-items:end">
          @csrf
          <div>
            <label class="ia-label" style="display:block;margin-bottom:5px">Name</label>
            <input type="text" name="name" required maxlength="120" placeholder="e.g. Walk-in" class="ia-input" style="width:100%">
          </div>
          <div>
            <label class="ia-label" style="display:block;margin-bottom:5px">Description (optional)</label>
            <input type="text" name="description" maxlength="500" placeholder="e.g. Stop by during business hours" class="ia-input" style="width:100%">
          </div>
          <div>
            <button type="submit" class="ia-btn ia-btn--primary">Add</button>
          </div>
        </form>
        <div style="display:flex;gap:18px;margin-top:10px;font-size:12px">
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
            <input type="checkbox" form="add-method-form" name="ask_for_time" value="1"> Ask for arrival time
          </label>
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
            <input type="checkbox" form="add-method-form" name="ask_for_tracking" value="1"> Ask for shipment tracking number
          </label>
        </div>
      </div>

      @if($receivingMethods->isEmpty())
        <div style="padding:24px;text-align:center;border-top:0.5px solid var(--ia-border)">
          <div style="font-size:13px;opacity:.55">No drop-off methods yet. Add your first one above.</div>
        </div>
      @else
        <div id="method-list" style="border-top:0.5px solid var(--ia-border)">
          @foreach($receivingMethods as $m)
            <div class="method-row" data-method-id="{{ $m->id }}"
                 style="display:grid;grid-template-columns:auto 1.2fr 1.6fr auto auto auto;gap:12px;align-items:center;padding:10px 16px;border-bottom:0.5px solid var(--ia-border);{{ $m->is_active ? '' : 'opacity:.45' }}">
              <div class="drag-handle" style="cursor:grab;opacity:.4;font-size:14px;user-select:none">⋮⋮</div>
              <input type="text" data-field="name" value="{{ $m->name }}" maxlength="120" class="ia-input method-edit" style="width:100%">
              <input type="text" data-field="description" value="{{ $m->description }}" maxlength="500" placeholder="—" class="ia-input method-edit" style="width:100%">
              <label style="display:flex;align-items:center;gap:5px;font-size:11px;cursor:pointer;white-space:nowrap" title="Show a time field on the booking page when this method is selected">
                <input type="checkbox" data-field="ask_for_time" {{ $m->ask_for_time ? 'checked' : '' }} class="method-edit-toggle">
                <span>Time</span>
              </label>
              <label style="display:flex;align-items:center;gap:5px;font-size:11px;cursor:pointer;white-space:nowrap" title="Show a tracking-number field on the booking page when this method is selected">
                <input type="checkbox" data-field="ask_for_tracking" {{ $m->ask_for_tracking ? 'checked' : '' }} class="method-edit-toggle">
                <span>Tracking</span>
              </label>
              <button type="button" class="ia-toggle method-row-toggle {{ $m->is_active ? 'on' : '' }}" data-field="is_active" title="{{ $m->is_active ? 'Click to deactivate' : 'Click to activate' }}">
                <span class="ia-toggle-sr">{{ $m->is_active ? 'Active' : 'Inactive' }}</span>
              </button>
            </div>
          @endforeach
        </div>
      @endif
    </div>
  </div>
</div>

{{-- =====================================================================
     BRANDING — shop identity, logos, colors, typography
     ===================================================================== --}}
<div class="set-pane" id="pane-branding" role="tabpanel">
  <form method="POST" action="{{ route('tenant.settings.update') }}" enctype="multipart/form-data" class="set-section set-section--grid" data-dirty-form>
    @csrf @method('PATCH')
    <input type="hidden" name="tab" value="branding">

    <div class="set-savebar" data-savebar>
      <span class="set-savebar-msg"></span><!-- MARKER-PATCH-165 — populated by JS -->
      <div class="set-savebar-actions">
        <button type="button" class="set-discard-btn" data-discard>Discard</button>
        <button type="submit" class="set-save-btn">Save branding</button>
      </div>
    </div>

    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Shop identity</span></div>
      <div class="ia-form-group">
        <label class="ia-form-label">Shop name <span class="ia-required">*</span></label>
        <input type="text" name="name" class="ia-input" value="{{ old('name', $currentTenant->name) }}" required>
      </div>
      <div class="ia-form-group">
        <label class="ia-form-label">Tagline</label>
        <input type="text" name="tagline" class="ia-input" value="{{ old('tagline', $currentTenant->tagline) }}"
          placeholder="e.g. Expert bike service since 2010">
      </div>
    </div>

    <div class="ia-card set-card--wide" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Logos</span></div>
      <p style="font-size:13px;opacity:.5;margin-bottom:16px">
        Upload two versions of your logo. The system automatically picks the right one based on the background color.
      </p>
      <div class="ia-input-grid-2">
        <div class="ia-form-group">
          <label class="ia-form-label">Default logo <span style="opacity:.4;font-weight:400">(for light backgrounds)</span></label>
          @if($currentTenant->logo_url)
            <img src="{{ $currentTenant->logo_url }}" alt="Logo" class="logo-preview">
          @endif
          <input type="file" name="logo" accept="image/*" class="ia-input" style="padding:6px">
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Light logo <span style="opacity:.4;font-weight:400">(for dark backgrounds)</span></label>
          @if($currentTenant->logo_light_url)
            <div class="logo-preview-dark">
              <img src="{{ $currentTenant->logo_light_url }}" alt="Light logo">
            </div>
          @endif
          <input type="file" name="logo_light" accept="image/*" class="ia-input" style="padding:6px">
          <div style="font-size:11px;opacity:.35;margin-top:4px">White or light-colored version for dark hero sections and dark theme booking forms.</div>
        </div>
      </div>
      <div class="ia-form-group" style="margin-top:12px">
        <label class="ia-form-label">Favicon</label>
        @if($currentTenant->favicon_url)
          <img src="{{ $currentTenant->favicon_url }}" alt="Favicon" style="height:32px;border-radius:4px;margin-bottom:8px;display:block">
        @endif
        <input type="file" name="favicon" accept="image/*" class="ia-input" style="padding:6px;max-width:300px">
      </div>
    </div>

    <div class="ia-card set-card--wide" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Logo display size</span></div>
      <p style="font-size:13px;opacity:.5;margin-bottom:18px">
        Drag the sliders to set how big the uploaded logo renders. The preview shows what it'll look like.
        Doesn't affect the file itself — re-uploading isn't needed.
      </p>

      @php
        // Pulled into PHP vars so JS init values match what's in the DB.
        $adminPx   = (int) ($currentTenant->logo_size_admin   ?? 26);
        $bookingPx = (int) ($currentTenant->logo_size_booking ?? 28);
        // Pick whichever logo will actually render in each surface.
        $adminLogo = \App\Support\ColorHelper::pickLogo($currentTenant, '#0c0c0c'); // dark sidebar
        $bookLogo  = \App\Support\ColorHelper::pickLogo($currentTenant, $currentTenant->bg_color ?? '#ffffff'); // booking bg
      @endphp

      {{-- Admin sidebar --}}
      <div style="margin-bottom:24px">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
          <label class="ia-form-label" style="margin:0">Admin sidebar</label>
          <span style="font-size:12px;color:var(--ia-text-muted);font-variant-numeric:tabular-nums">
            <span id="logo-admin-readout">{{ $adminPx }}</span>px
          </span>
        </div>
        <input type="range" name="logo_size_admin" id="logo-admin-slider"
               min="16" max="80" step="1" value="{{ $adminPx }}"
               style="width:100%;margin:0">
        <div style="font-size:11px;opacity:.45;margin-top:4px;display:flex;justify-content:space-between">
          <span>16px</span><span>80px</span>
        </div>

        {{-- Mini preview chip — mimics the sidebar logo block --}}
        <div style="margin-top:14px;background:#0c0c0c;border-radius:var(--ia-r-md);padding:14px 16px;display:flex;align-items:center;gap:10px;min-height:60px">
          @if($adminLogo)
            <img id="logo-admin-preview" src="{{ $adminLogo }}" alt="Admin logo preview"
                 style="height:{{ $adminPx }}px;width:auto;border-radius:4px;max-width:160px;object-fit:contain;transition:height .05s linear">
          @else
            <span style="color:#999;font-size:12px;font-style:italic">Upload a logo above to preview</span>
          @endif
        </div>
      </div>

      {{-- Booking page --}}
      <div>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
          <label class="ia-form-label" style="margin:0">Booking page</label>
          <span style="font-size:12px;color:var(--ia-text-muted);font-variant-numeric:tabular-nums">
            <span id="logo-booking-readout">{{ $bookingPx }}</span>px
          </span>
        </div>
        <input type="range" name="logo_size_booking" id="logo-booking-slider"
               min="16" max="120" step="1" value="{{ $bookingPx }}"
               style="width:100%;margin:0">
        <div style="font-size:11px;opacity:.45;margin-top:4px;display:flex;justify-content:space-between">
          <span>16px</span><span>120px</span>
        </div>

        {{-- Mini preview chip — mimics the booking page top bar --}}
        @php $previewBg = $currentTenant->bg_color ?? '#ffffff'; @endphp
        <div style="margin-top:14px;background:{{ $previewBg }};border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);padding:14px 16px;display:flex;align-items:center;gap:10px;min-height:80px">
          @if($bookLogo)
            <img id="logo-booking-preview" src="{{ $bookLogo }}" alt="Booking logo preview"
                 style="height:{{ $bookingPx }}px;width:auto;border-radius:4px;max-width:240px;object-fit:contain;transition:height .05s linear">
          @else
            <span style="color:#999;font-size:12px;font-style:italic">Upload a logo above to preview</span>
          @endif
        </div>
      </div>
    </div>

    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Colors</span></div>
      @foreach([
        ['accent_color', 'Accent color', $currentTenant->accent_color ?? '#BEF264', 'Used for buttons, links, and active states'],
        ['text_color',   'Text color',   $currentTenant->text_color   ?? '#111111', 'Main body text on your booking form'],
        ['bg_color',     'Background',   $currentTenant->bg_color     ?? '#ffffff', 'Page background on your booking form'],
      ] as [$name, $label, $value, $hint])
      <div class="ia-form-group">
        <label class="ia-form-label">{{ $label }}</label>
        <div class="color-swatch-row">
          <div class="color-swatch">
            <input type="color" name="{{ $name }}" value="{{ old($name, $value) }}" id="color-{{ $name }}">
          </div>
          <input type="text" class="ia-input" style="width:110px;font-family:var(--ia-font-mono);font-size:13px"
            value="{{ old($name, $value) }}" id="text-{{ $name }}"
            oninput="document.getElementById('color-{{ $name }}').value=this.value"
            pattern="^#[0-9A-Fa-f]{6}$">
          <span style="font-size:12px;opacity:.45">{{ $hint }}</span>
        </div>
      </div>
      @endforeach
    </div>

    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Typography</span></div>
      <div class="ia-input-grid-2">
        <div class="ia-form-group">
          <label class="ia-form-label">Heading font</label>
          <select name="font_heading" class="ia-input">
            @foreach($fonts as $font)
              <option value="{{ $font }}" @selected(old('font_heading', $currentTenant->font_heading) === $font)>{{ $font }}</option>
            @endforeach
          </select>
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Body font</label>
          <select name="font_body" class="ia-input">
            @foreach($fonts as $font)
              <option value="{{ $font }}" @selected(old('font_body', $currentTenant->font_body) === $font)>{{ $font }}</option>
            @endforeach
          </select>
        </div>
      </div>
    </div>
  </form>
</div>

{{-- =====================================================================
     COMMUNICATION — email sender, SMS provider, notifications
     ===================================================================== --}}
<div class="set-pane" id="pane-communication" role="tabpanel">
  <form method="POST" action="{{ route('tenant.settings.update') }}" class="set-section set-section--grid" data-dirty-form>
    @csrf @method('PATCH')
    <input type="hidden" name="tab" value="communication">

    <div class="set-savebar" data-savebar>
      <span class="set-savebar-msg"></span><!-- MARKER-PATCH-165 — populated by JS -->
      <div class="set-savebar-actions">
        <button type="button" class="set-discard-btn" data-discard>Discard</button>
        <button type="submit" class="set-save-btn">Save communication settings</button>
      </div>
    </div>



    {{-- Email sender details --}}
    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Email sender details</span></div>
      <p style="font-size:13px;opacity:.5;margin-bottom:16px">
        All emails to your customers will be sent from these details.
      </p>
      <div class="ia-form-group">
        <label class="ia-form-label">From name</label>
        <input type="text" name="email_from_name" class="ia-input"
          value="{{ old('email_from_name', $currentTenant->email_from_name) }}"
          placeholder="{{ $currentTenant->name }}">
      </div>
      {{-- MARKER-PATCH-143 — From address locked to <subdomain>@intake.works until custom domains land --}}
      <div class="ia-input-grid-2">
        <div class="ia-form-group">
          <label class="ia-form-label">From email address</label>
          <input type="email" class="ia-input" readonly disabled
            value="{{ $currentTenant->subdomain }}@intake.works"
            style="opacity:.7;cursor:not-allowed">
          <div style="font-size:11px;color:var(--ia-text-dim);margin-top:4px">
            All your customer emails come from this address. Custom domains coming soon.
          </div>
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Reply-to (optional)</label>
          <input type="email" name="email_reply_to" class="ia-input"
            value="{{ old('email_reply_to', $currentTenant->email_reply_to) }}"
            placeholder="{{ Auth::guard('tenant')->user()->email ?? '' }}">
          <div style="font-size:11px;color:var(--ia-text-dim);margin-top:4px">
            Where replies go. Usually your shop's main email.
          </div>
        </div>
      </div>

      {{-- MARKER-PATCH-144 — Test send block (no nested form, uses fetch) --}}
      <div style="margin-top:14px;padding:14px;background:rgba(190,242,100,.06);border:1px solid rgba(190,242,100,.18);border-radius:var(--ia-r-md)" id="email-test-block">
        <div style="font-size:13px;font-weight:500;margin-bottom:6px">Test your email setup</div>
        <div style="font-size:12px;color:var(--ia-text-dim);margin-bottom:10px;line-height:1.55">
          Save any changes above first. Then enter a recipient and send a test email to verify the From name and reply-to look right.
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
          <input type="email" id="email-test-recipient" class="ia-input" style="flex:1;min-width:240px"
            placeholder="recipient@example.com"
            value="{{ Auth::guard('tenant')->user()->email ?? '' }}">
          <button type="button" id="email-test-btn" class="ia-btn ia-btn--ghost ia-btn--sm">Send test email</button>
        </div>
        <div id="email-test-result" style="margin-top:10px;font-size:12px;display:none"></div>
      </div>
      <script>
        (function() {
          const btn = document.getElementById('email-test-btn');
          const recipient = document.getElementById('email-test-recipient');
          const result = document.getElementById('email-test-result');
          if (!btn) return;
          btn.addEventListener('click', async function(e) {
            e.preventDefault();
            const r = (recipient.value || '').trim();
            if (!r) {
              result.style.display = 'block';
              result.style.color = 'var(--ia-bad, #F87171)';
              result.textContent = 'Enter a recipient email first.';
              return;
            }
            btn.disabled = true;
            btn.textContent = 'Sending…';
            result.style.display = 'block';
            result.style.color = 'var(--ia-text-dim)';
            result.textContent = 'Sending test email to ' + r + '…';
            try {
              const resp = await fetch('{{ route('tenant.settings.email.test') }}', {
                method: 'POST',
                headers: {
                  'Content-Type': 'application/x-www-form-urlencoded',
                  'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                  'X-Requested-With': 'XMLHttpRequest',
                  'Accept': 'application/json'
                },
                body: 'recipient=' + encodeURIComponent(r)
              });
              if (resp.ok) {
                result.style.color = 'var(--ia-ok, #86EFAC)';
                result.textContent = 'Sent to ' + r + '. Check the inbox (and spam folder) within ~1 minute.';
              } else {
                const body = await resp.text();
                result.style.color = 'var(--ia-bad, #F87171)';
                result.textContent = 'Send failed (HTTP ' + resp.status + '). Check logs for details.';
              }
            } catch (err) {
              result.style.color = 'var(--ia-bad, #F87171)';
              result.textContent = 'Send failed: ' + err.message;
            } finally {
              btn.disabled = false;
              btn.textContent = 'Send test email';
            }
          });
        })();
      </script>
      <div class="ia-form-group">
        <label class="ia-form-label">New booking notification email</label>
        <input type="email" name="notification_email" class="ia-input"
          value="{{ old('notification_email', $currentTenant->notification_email) }}"
          placeholder="Where to send new booking alerts">
      </div>
    </div>

    {{-- MARKER-PATCH-228B — Rentals pointer card --}}
    @if($currentTenant->rentals_enabled)
    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head" style="display:flex;align-items:center;justify-content:space-between">
        <span class="ia-card-title">Rentals &amp; leasing</span>
        <span class="ia-badge {{ $currentTenant->rentals_visible ? 'ia-badge--paid' : 'ia-badge--unpaid' }}">
          {{ $currentTenant->rentals_visible ? 'On' : 'Hidden' }}{{ $currentTenant->leases_enabled ? ' · leasing' : '' }}
        </span>
      </div>
      <p style="font-size:13px;opacity:.5;margin-bottom:12px;line-height:1.55">
        Turn rentals on or off, configure your season window, and enable season-long leasing.
      </p>
      <a href="{{ route('tenant.rentals.settings') }}" class="ia-btn ia-btn--primary">Open Rental settings</a>
    </div>
    @endif

    {{-- MARKER-PATCH-228B — Notifications/Alerts pointer card --}}
    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Notifications</span></div>
      <p style="font-size:13px;opacity:.5;margin-bottom:12px;line-height:1.55">
        Choose how you hear about new bookings, overdue rentals, payments, and more — in-app and by text.
      </p>
      <a href="{{ route('tenant.alerts.prefs') }}" class="ia-btn ia-btn--primary">Open Notification settings</a>
    </div>

    {{-- MARKER-PATCH-224 — SMS config moved to Settings -> Messaging --}}
    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head" style="display:flex;align-items:center;justify-content:space-between">
        <span class="ia-card-title">Text messaging</span>
        <span class="ia-badge {{ $currentTenant->sms_enabled && $currentTenant->sms_from_number ? 'ia-badge--paid' : 'ia-badge--unpaid' }}">
          {{ $currentTenant->sms_enabled && $currentTenant->sms_from_number ? 'Active · ' . $currentTenant->sms_from_number : 'Not set up' }}
        </span>
      </div>
      <p style="font-size:13px;opacity:.5;margin-bottom:12px;line-height:1.55">
        Your business text number, two-way Inbox routing, and SMS sending live on the Messaging page.
      </p>
      <a href="{{ route('tenant.settings.messaging') }}" class="ia-btn ia-btn--primary">Open Messaging settings</a>
    </div>

    {{-- MARKER-PATCH-406 — customer notifications moved to Communication Center --}}
    <div class="ia-card set-card--wide" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Customer notifications</span></div>
      <p style="font-size:13px;opacity:.6;margin:0;line-height:1.55">
        Booking, delivery, reminder, and receipt messages are managed in
        <a href="{{ route('tenant.communication.index') }}" style="color:var(--ia-accent)">Communication</a>.
      </p>
    </div>
  </form>
  {{-- MARKER-PATCH-150-FIX — Web analytics card, outside parent form (HTML disallows nested forms) --}}
  {{-- MARKER-PATCH-150-POLISH-C — wrap in grid section so set-card--wide applies --}}
  <div class="set-section set-section--grid">
  <div class="ia-card set-card--wide" style="margin-bottom: 20px;">
    <div class="ia-card-head">
      <span class="ia-card-title">Web analytics</span>
    </div>
    <p style="font-size:13px;opacity:.5;margin-bottom:14px">
      Connect Google Analytics 4 to your public-facing pages. We'll inject the tracking script automatically.
      Leave blank to disable.
    </p>
    <form method="POST" action="{{ route('tenant.settings.analytics.update') }}">
      @csrf
      <div class="ia-form-group">
        <label class="ia-form-label">GA-4 measurement ID</label>
        <input type="text" name="analytics_ga4_id" class="ia-input"
               value="{{ old('analytics_ga4_id', $currentTenant->settings['analytics_ga4_id'] ?? '') }}"
               placeholder="G-XXXXXXXXXX"
               style="max-width: 320px; font-family: var(--ia-font-mono, 'JetBrains Mono', monospace);">
        <div style="font-size:11px;color:var(--ia-text-dim);margin-top:4px">
          Find this in your GA-4 Admin → Data Streams → Measurement ID. Starts with <code>G-</code>.
        </div>
      </div>
      @error('analytics_ga4_id')
        <div style="color: #F47373; font-size: 12px; margin-top: 6px;">{{ $message }}</div>
      @enderror
      <div style="margin-top: 14px;">
        <button type="submit" class="ia-btn ia-btn--primary">Save analytics</button>
      </div>
    </form>
  </div>
  </div>{{-- MARKER-PATCH-150-POLISH-C close grid wrapper --}}

</div>

{{-- =====================================================================
     ACCOUNT — booking URL, custom domain, subscription
     ===================================================================== --}}
<div class="set-pane" id="pane-account" role="tabpanel">
  <form method="POST" action="{{ route('tenant.settings.update') }}" class="set-section set-section--grid" data-dirty-form>
    @csrf @method('PATCH')
    <input type="hidden" name="tab" value="account">

    <div class="set-savebar" data-savebar>
      <span class="set-savebar-msg"></span><!-- MARKER-PATCH-165 — populated by JS -->
      <div class="set-savebar-actions">
        <button type="button" class="set-discard-btn" data-discard>Discard</button>
        <button type="submit" class="set-save-btn">Save account</button>
      </div>
    </div>

    {{-- Booking URL (read-only) --}}
    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Your booking URL</span></div>
      <div style="font-size:14px;font-weight:500;margin-bottom:6px">
        <a href="{{ $currentTenant->bookingUrl() }}" target="_blank" rel="noopener noreferrer"
           style="color:var(--ia-accent);text-decoration:none;font-family:var(--ia-font-mono);font-size:13px">
          {{ $currentTenant->bookingUrl() }}
        </a>
      </div>
      <div style="font-size:12px;opacity:.5">This is where customers go to book with you.</div>
    </div>

    {{-- MARKER-PATCH-120 - Custom domains live on a dedicated page --}}
    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head">
        <span class="ia-card-title">Custom domains</span>
      </div>
      <p style="font-size:13px;opacity:.6;margin-bottom:14px;line-height:1.55">
        Connect your own domain — like <code style="font-family:var(--ia-font-mono);font-size:12px">{{ $currentTenant->subdomain }}.com</code> — to your Intake site. HTTPS is automatic.
      </p>
      <a href="{{ route('tenant.domains.index', []) }}"
         class="ia-btn ia-btn-secondary"
         style="display:inline-flex;align-items:center;gap:6px">
        Manage domains →
      </a>
    </div>
  </form>

  {{-- Subscription (read-only, separate from form) --}}
  <div class="set-section set-section--grid">
    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Subscription</span></div>

      @if($currentTenant->stripe_customer_id)
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;max-width:480px;font-size:13px;margin-bottom:16px">
          <div>
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--ia-text-muted);margin-bottom:4px;font-weight:500">Current plan</div>
            <div style="font-weight:500">{{ ucfirst($currentTenant->plan_tier ?? 'Starter') }}</div>
          </div>
          <div>
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--ia-text-muted);margin-bottom:4px;font-weight:500">Status</div>
            <div style="font-weight:500">{{ ucfirst($currentTenant->subscription_status ?? 'unknown') }}</div>
          </div>
          @if($currentTenant->trial_ends_at)
          <div>
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--ia-text-muted);margin-bottom:4px;font-weight:500">Trial ends</div>
            <div style="font-weight:500">{{ $currentTenant->trial_ends_at->format('M j, Y') }}</div>
          </div>
          @endif
          <div>
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--ia-text-muted);margin-bottom:4px;font-weight:500">Billing</div>
            <div style="font-weight:500">{{ ucfirst($currentTenant->stripe_subscription_cadence ?? '') ?: '—' }}</div>
          </div>
        </div>

        <a href="{{ route('tenant.billing.portal', []) }}"
           class="ia-btn ia-btn--primary"
           target="_blank" rel="noopener noreferrer">
          Manage billing in Stripe →
        </a>
        <p style="font-size:12px;color:var(--ia-text-muted);margin-top:8px">
          Update your card, download invoices, or cancel your subscription through Stripe's secure portal.
        </p>
      @else
        <p style="margin:0;color:var(--ia-text-muted);font-size:13px;line-height:1.55">
          No billing account is connected to this tenant. Contact support to enable billing.
        </p>
      @endif
    </div>
  </div>
</div>

{{-- =====================================================================
     PAYMENTS — Stripe + PayPal (preserved verbatim)
     ===================================================================== --}}
<div class="set-pane" id="pane-payments" role="tabpanel">
  <form method="POST" action="{{ route('tenant.settings.update') }}" class="set-section set-section--grid" data-dirty-form>
    @csrf @method('PATCH')
    <input type="hidden" name="tab" value="payments">

    <div class="set-savebar" data-savebar>
      <span class="set-savebar-msg"></span><!-- MARKER-PATCH-165 — populated by JS -->
      <div class="set-savebar-actions">
        <button type="button" class="set-discard-btn" data-discard>Discard</button>
        <button type="submit" class="set-save-btn">Save payment settings</button>
      </div>
    </div>

    {{-- MARKER-PATCH-169 — Direct Payments bridge feature.
         Only renders when master admin flipped direct_payments_enabled on for this tenant.
         Tenant pastes their own Stripe keys here for register card-sales. --}}
    @if($currentTenant->direct_payments_enabled ?? false)
    {{-- MARKER-PATCH-618 — toggle-able (default on). Off hides card + payment-link tenders at the register; refunds of past charges still work. --}}
    <div class="provider-card {{ ($s['stripe_register_enabled'] ?? true) ? 'enabled' : '' }}" id="register-payments-card">
      <div class="provider-header">
        <div>
          <div style="font-size:15px;font-weight:500;display:flex;align-items:center;gap:8px">
            Register card payments
          </div>
          <div style="font-size:12px;opacity:.6;margin-top:2px">Hand-key card numbers and send payment links from the register. Paste your own Stripe keys below.</div>
        </div>
        <button type="button" class="prov-toggle-btn {{ ($s['stripe_register_enabled'] ?? true) ? 'on' : '' }}"
          id="register-payments-toggle" onclick="toggleProvider('register-payments')"></button>
        <input type="hidden" name="stripe_register_enabled" id="register-payments-enabled-val" value="{{ ($s['stripe_register_enabled'] ?? true) ? '1' : '0' }}">
      </div>
      <div class="provider-fields" id="register-payments-fields">
        <div class="ia-form-group">
          <label class="ia-form-label">Mode</label>
          <select name="register_payments_mode" class="ia-input" style="width:auto">
            <option value="test" @selected(($s['register_payments_mode'] ?? 'test') === 'test')>Test</option>
            <option value="live" @selected(($s['register_payments_mode'] ?? 'test') === 'live')>Live</option>
          </select>
          <div style="font-size:11px;opacity:.55;margin-top:6px">Start in test mode. Switch to live only after you've verified end-to-end flows with test cards.</div>
        </div>

        <div style="height:1px;background:var(--ia-border);margin:18px 0"></div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
          <div class="ia-form-group">
            <label class="ia-form-label">Test publishable key</label>
            <input type="text" name="register_payments_test_pk" value="{{ $s['register_payments_test_pk'] ?? '' }}" class="ia-input" placeholder="pk_test_…" autocomplete="off" spellcheck="false">
          </div>
          <div class="ia-form-group">
            <label class="ia-form-label">Test secret key</label>
            <input type="password" name="register_payments_test_sk" value="{{ $s['register_payments_test_sk'] ?? '' }}" class="ia-input" placeholder="sk_test_…" autocomplete="off" spellcheck="false">
          </div>
          <div class="ia-form-group">
            <label class="ia-form-label">Live publishable key</label>
            <input type="text" name="register_payments_live_pk" value="{{ $s['register_payments_live_pk'] ?? '' }}" class="ia-input" placeholder="pk_live_…" autocomplete="off" spellcheck="false">
          </div>
          <div class="ia-form-group">
            <label class="ia-form-label">Live secret key</label>
            <input type="password" name="register_payments_live_sk" value="{{ $s['register_payments_live_sk'] ?? '' }}" class="ia-input" placeholder="sk_live_…" autocomplete="off" spellcheck="false">
          </div>
        </div>

        <div style="height:1px;background:var(--ia-border);margin:18px 0"></div>

        <div class="ia-form-group">
          <label class="ia-form-label">Webhook signing secret</label>
          <input type="password" name="register_payments_webhook_secret" value="{{ $s['register_payments_webhook_secret'] ?? '' }}" class="ia-input" placeholder="whsec_…" autocomplete="off" spellcheck="false">
          <div style="font-size:11px;opacity:.55;margin-top:6px">
            From Stripe Dashboard -> Developers -> Webhooks. Point a new endpoint at <code style="background:var(--ia-input-bg);padding:1px 5px;border-radius:3px;font-size:11px">{{ url('/webhooks/stripe-direct/' . $currentTenant->id) }}</code> and subscribe to <code style="background:var(--ia-input-bg);padding:1px 5px;border-radius:3px;font-size:11px">payment_intent.succeeded</code>, <code style="background:var(--ia-input-bg);padding:1px 5px;border-radius:3px;font-size:11px">checkout.session.completed</code>, and <code style="background:var(--ia-input-bg);padding:1px 5px;border-radius:3px;font-size:11px">charge.refunded</code>.
          </div>
        </div>

      </div>
    </div>
    @endif

    {{-- MARKER-PATCH-473 — Square (tenant-connected, paste-token). Same master-admin gate as Stripe. --}}
    @if($currentTenant->direct_payments_enabled ?? false)
    <div class="provider-card {{ ($s['square_enabled'] ?? true) ? 'enabled' : '' }}" id="square-payments-card" style="margin-top:16px">
      <div class="provider-header">
        <div>
          <div style="font-size:15px;font-weight:500;display:flex;align-items:center;gap:8px">Square card payments</div>
          <div style="font-size:12px;opacity:.6;margin-top:2px">Connect your own Square account as an alternative to Stripe. Paste the credentials from your Square app, save, then test the connection.</div>
        </div>
        <button type="button" class="prov-toggle-btn {{ ($s['square_enabled'] ?? true) ? 'on' : '' }}"
          id="square-payments-toggle" onclick="toggleProvider('square-payments')"></button>
        <input type="hidden" name="square_enabled" id="square-payments-enabled-val" value="{{ ($s['square_enabled'] ?? true) ? '1' : '0' }}">
      </div>
      <div class="provider-fields" id="square-payments-fields">
        <div class="ia-form-group">
          <label class="ia-form-label">Mode</label>
          <select name="square_payments_mode" class="ia-input" style="width:auto">
            <option value="sandbox" @selected(($s['square_payments_mode'] ?? 'sandbox') === 'sandbox')>Sandbox</option>
            <option value="production" @selected(($s['square_payments_mode'] ?? 'sandbox') === 'production')>Production</option>
          </select>
          <div style="font-size:11px;opacity:.55;margin-top:6px">Sandbox and production are separate Square apps with their own credentials. Verify in sandbox first.</div>
        </div>

        <div style="height:1px;background:var(--ia-border);margin:18px 0"></div>

        <div style="font-size:11px;font-weight:600;opacity:.7;text-transform:uppercase;letter-spacing:.05em;margin-bottom:10px">Sandbox credentials</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
          <div class="ia-form-group">
            <label class="ia-form-label">Application ID</label>
            <input type="text" name="square_sandbox_app_id" value="{{ $s['square_sandbox_app_id'] ?? '' }}" class="ia-input" placeholder="sandbox-sq0idb-…" autocomplete="off" spellcheck="false">
          </div>
          <div class="ia-form-group">
            <label class="ia-form-label">Location ID</label>
            <input type="text" name="square_sandbox_location_id" value="{{ $s['square_sandbox_location_id'] ?? '' }}" class="ia-input" placeholder="L…" autocomplete="off" spellcheck="false">
          </div>
          <div class="ia-form-group" style="grid-column:1 / -1">
            <label class="ia-form-label">Access token</label>
            <input type="password" name="square_sandbox_access_token" value="{{ $s['square_sandbox_access_token'] ?? '' }}" class="ia-input" placeholder="EAAAl…" autocomplete="off" spellcheck="false">
          </div>
        </div>

        <div style="height:1px;background:var(--ia-border);margin:18px 0"></div>

        <div style="font-size:11px;font-weight:600;opacity:.7;text-transform:uppercase;letter-spacing:.05em;margin-bottom:10px">Production credentials</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
          <div class="ia-form-group">
            <label class="ia-form-label">Application ID</label>
            <input type="text" name="square_production_app_id" value="{{ $s['square_production_app_id'] ?? '' }}" class="ia-input" placeholder="sq0idp-…" autocomplete="off" spellcheck="false">
          </div>
          <div class="ia-form-group">
            <label class="ia-form-label">Location ID</label>
            <input type="text" name="square_production_location_id" value="{{ $s['square_production_location_id'] ?? '' }}" class="ia-input" placeholder="L…" autocomplete="off" spellcheck="false">
          </div>
          <div class="ia-form-group" style="grid-column:1 / -1">
            <label class="ia-form-label">Access token</label>
            <input type="password" name="square_production_access_token" value="{{ $s['square_production_access_token'] ?? '' }}" class="ia-input" placeholder="EAAAl…" autocomplete="off" spellcheck="false">
          </div>
        </div>

        <div style="height:1px;background:var(--ia-border);margin:18px 0"></div>

        <div class="ia-form-group">
          <label class="ia-form-label">Webhook signature key</label>
          <input type="password" name="square_webhook_signature_key" value="{{ $s['square_webhook_signature_key'] ?? '' }}" class="ia-input" placeholder="webhook signature key" autocomplete="off" spellcheck="false">
          <div style="font-size:11px;opacity:.55;margin-top:6px">
            From Square Developer Console -> your app -> Webhooks. Point a subscription at <code style="background:var(--ia-input-bg);padding:1px 5px;border-radius:3px;font-size:11px">{{ url('/webhooks/square/' . $currentTenant->id) }}</code> and subscribe to <code style="background:var(--ia-input-bg);padding:1px 5px;border-radius:3px;font-size:11px">payment.updated</code> and <code style="background:var(--ia-input-bg);padding:1px 5px;border-radius:3px;font-size:11px">refund.updated</code>.
          </div>
        </div>

        <div style="height:1px;background:var(--ia-border);margin:18px 0"></div>

        <div style="display:flex;align-items:center;gap:12px">
          <button type="button" class="ia-btn ia-btn--ghost" onclick="squareTestConnection(this)">Test connection</button>
          <span id="square-test-result" style="font-size:12px;opacity:.85"></span>
        </div>
        <div style="font-size:11px;opacity:.55;margin-top:8px">Save your credentials first, then test. This calls Square with your saved access token to confirm the location is reachable.</div>
      </div>
    </div>
    <script>
      window.squareTestConnection = function (btn) {
        var out = document.getElementById('square-test-result');
        btn.disabled = true; out.textContent = 'Testing…'; out.style.color = '';
        fetch({!! json_encode(route('tenant.settings.square.verify')) !!}, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': {!! json_encode(csrf_token()) !!}, 'Accept': 'application/json' },
          body: '{}'
        }).then(function (r) { return r.json(); }).then(function (d) {
          btn.disabled = false;
          if (d && d.ok) { out.textContent = '\u2713 ' + (d.message || 'Connected'); out.style.color = 'var(--ia-accent)'; }
          else { out.textContent = '\u2715 ' + ((d && d.message) || 'Failed'); out.style.color = '#f87171'; }
        }).catch(function () { btn.disabled = false; out.textContent = '\u2715 Request failed'; out.style.color = '#f87171'; });
      };
    </script>
    @endif

    {{-- PayPal --}}
    <div class="provider-card {{ ($s['paypal_enabled'] ?? false) ? 'enabled' : '' }}" id="paypal-card">
      <div class="provider-header">
        <div>
          <div style="font-size:15px;font-weight:500">PayPal</div>
          <div style="font-size:12px;opacity:.5;margin-top:2px">PayPal, Venmo, Pay Later</div>
        </div>
        <button type="button" class="prov-toggle-btn {{ ($s['paypal_enabled'] ?? false) ? 'on' : '' }}"
          id="paypal-toggle" onclick="toggleProvider('paypal')"></button>
        <input type="hidden" name="paypal_enabled" id="paypal-enabled-val" value="{{ ($s['paypal_enabled'] ?? false) ? '1' : '0' }}">
      </div>
      <div class="provider-fields" id="paypal-fields">
        <div class="ia-form-group">
          <label class="ia-form-label">Mode</label>
          <select name="paypal_mode" class="ia-input" style="width:auto">
            <option value="sandbox" @selected(($s['paypal_mode'] ?? 'sandbox') === 'sandbox')>Sandbox</option>
            <option value="live"    @selected(($s['paypal_mode'] ?? 'sandbox') === 'live')>Live</option>
          </select>
        </div>
        <div class="ia-input-grid-2">
          <div class="ia-form-group">
            <label class="ia-form-label">Sandbox client ID</label>
            <input type="text" name="paypal_test_client_id" class="ia-input ia-mono" value="{{ $s['paypal_test_client_id'] ?? '' }}">
          </div>
          <div class="ia-form-group">
            <label class="ia-form-label">Sandbox secret</label>
            <input type="password" name="paypal_test_secret" class="ia-input ia-mono" value="{{ $s['paypal_test_secret'] ?? '' }}">
          </div>
          <div class="ia-form-group">
            <label class="ia-form-label">Live client ID</label>
            <input type="text" name="paypal_live_client_id" class="ia-input ia-mono" value="{{ $s['paypal_live_client_id'] ?? '' }}">
          </div>
          <div class="ia-form-group">
            <label class="ia-form-label">Live secret</label>
            <input type="password" name="paypal_live_secret" class="ia-input ia-mono" value="{{ $s['paypal_live_secret'] ?? '' }}">
          </div>
        </div>
      </div>
    </div>

  </form>

  {{-- MARKER-PATCH-629 — unified payment methods list (replaces the 618 Venmo/Cash App cards) --}}
  @include('tenant.settings._payment-methods')
</div>
{{-- MARKER-PATCH-315 — Work-order tag settings --}}
{{-- =====================================================================
     ORDERING — how special orders pick a vendor      MARKER-SO-AUTOVENDOR
     ===================================================================== --}}
@php $soAuto = $s['special_orders']['auto_assign_vendor'] ?? 'preferred'; @endphp
<div class="set-pane" id="pane-ordering" role="tabpanel">
  <form method="POST" action="{{ route('tenant.settings.update') }}" class="set-section set-section--grid" data-dirty-form>
    @csrf @method('PATCH')
    <input type="hidden" name="tab" value="ordering">

    <div class="set-savebar" data-savebar>
      <span class="set-savebar-msg"></span>
      <div class="set-savebar-actions">
        <button type="button" class="set-discard-btn" data-discard>Discard</button>
        <button type="submit" class="set-save-btn">Save ordering settings</button>
      </div>
    </div>

    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Special orders — vendor assignment</span></div>
      <p style="font-size:13px;opacity:.55;margin-bottom:16px">
        When a special order is created, Intake can pick the vendor for you from the
        vendors already linked to that item. You can always change it before placing the order.
      </p>

      <label class="set-radio-row" style="display:flex;gap:10px;align-items:flex-start;padding:12px;border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);margin-bottom:8px;cursor:pointer">
        <input type="radio" name="so_auto_assign_vendor" value="preferred" @checked($soAuto === 'preferred')>
        <span>
          <strong style="display:block;font-size:13.5px">Preferred vendor</strong>
          <span style="font-size:12px;opacity:.6">Uses the vendor marked preferred on the item, falling back to whoever you ordered from most recently.</span>
        </span>
      </label>

      <label class="set-radio-row" style="display:flex;gap:10px;align-items:flex-start;padding:12px;border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);margin-bottom:8px;cursor:pointer">
        <input type="radio" name="so_auto_assign_vendor" value="lowest_price" @checked($soAuto === 'lowest_price')>
        <span>
          <strong style="display:block;font-size:13.5px">Lowest price</strong>
          <span style="font-size:12px;opacity:.6">Cheapest cost among vendors that carry it, preferring vendors that actually show stock. Falls back to the preferred vendor when no cost is known.</span>
        </span>
      </label>

      <label class="set-radio-row" style="display:flex;gap:10px;align-items:flex-start;padding:12px;border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);cursor:pointer">
        <input type="radio" name="so_auto_assign_vendor" value="off" @checked($soAuto === 'off')>
        <span>
          <strong style="display:block;font-size:13.5px">Don't assign automatically</strong>
          <span style="font-size:12px;opacity:.6">Leave the vendor blank and choose it yourself on the special orders screen.</span>
        </span>
      </label>
    </div>
  </form>
</div>

<div class="set-pane" id="pane-tags" role="tabpanel">
  @php
    $wot      = $s['work_order_tag'] ?? [];
    $wotOn    = fn($k) => array_key_exists($k, $wot) ? (bool) $wot[$k] : true;
    $wotLead  = $wot['lead_days'] ?? 3;
    $wotPaper = ($wot['paper'] ?? '80mm') === '58mm' ? '58mm' : '80mm';
    $wotLogo  = $wot['logo_path'] ?? null;
    $wotFeed  = (int) ($wot['feed_mm'] ?? 0);
    $wotHeader = (string) ($wot['header_text'] ?? ''); // MARKER-PATCH-330
    $wotFooter = (string) ($wot['footer_text'] ?? ''); // MARKER-PATCH-330
  @endphp
  <style>
    .wot-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:11px 0;border-bottom:0.5px solid var(--ia-border);cursor:pointer}
    .wot-row:last-child{border-bottom:none}
    .wot-row-l .t{font-size:13px;color:var(--ia-text)}
    .wot-row-l .d{font-size:11.5px;color:var(--ia-muted);margin-top:2px}
    .wot-switch{appearance:none;-webkit-appearance:none;width:38px;height:22px;border-radius:99px;background:var(--ia-input-bg);border:0.5px solid var(--ia-border);position:relative;cursor:pointer;flex-shrink:0;transition:background .15s;margin:0}
    .wot-switch::after{content:"";position:absolute;top:2px;left:2px;width:16px;height:16px;border-radius:50%;background:var(--ia-muted);transition:all .15s}
    .wot-switch:checked{background:var(--ia-accent);border-color:var(--ia-accent)}
    .wot-switch:checked::after{left:18px;background:#0a0a0a}
    .wot-seg{display:flex;gap:6px;background:var(--ia-input-bg);border:0.5px solid var(--ia-border);border-radius:8px;padding:4px;max-width:240px}
    .wot-seg label{flex:1;text-align:center;padding:8px;border-radius:5px;font-size:13px;cursor:pointer;color:var(--ia-muted);position:relative}
    .wot-seg input{position:absolute;opacity:0;pointer-events:none}
    .wot-seg label:has(input:checked){background:var(--ia-accent);color:#0a0a0a;font-weight:600}
    .wot-logo-preview{background:#fff;padding:10px 12px;border-radius:8px;display:inline-block;margin-bottom:10px}
    .wot-logo-preview img{max-height:42px;max-width:200px;display:block}
  </style>

  <form method="POST" action="{{ route('tenant.settings.update') }}" enctype="multipart/form-data" class="set-section set-section--grid" data-dirty-form>
    @csrf @method('PATCH') {{-- MARKER-PATCH-316 --}}
    <input type="hidden" name="tab" value="tags">

    <div class="set-savebar" data-savebar>
      <span class="set-savebar-msg"></span>
      <div class="set-savebar-actions">
        <button type="button" class="set-discard-btn" data-discard>Discard</button>
        <button type="submit" class="set-save-btn">Save tag settings</button>
      </div>
    </div>

    <div class="ia-card" style="margin-bottom:20px">
      <label class="wot-row" style="border:none;padding:2px 0">
        <span class="wot-row-l">
          <span class="t">Print service tags</span>
          <span class="d">Hang a tag on each item at drop-off. Prints to your 80mm receipt printer.</span>
        </span>
        <input type="checkbox" name="wot_enabled" value="1" {{ $wotOn('enabled') ? 'checked' : '' }} class="wot-switch">
      </label>
    </div>

    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">What prints on the tag</span></div>
      <label class="wot-row"><span class="wot-row-l"><span class="t">Shop name / logo header</span></span><input type="checkbox" name="wot_show_header" value="1" {{ $wotOn('show_header') ? 'checked' : '' }} class="wot-switch"></label>
      <label class="wot-row"><span class="wot-row-l"><span class="t">Customer phone</span></span><input type="checkbox" name="wot_show_phone" value="1" {{ $wotOn('show_phone') ? 'checked' : '' }} class="wot-switch"></label>
      <label class="wot-row"><span class="wot-row-l"><span class="t">Item / asset description</span></span><input type="checkbox" name="wot_show_bike" value="1" {{ $wotOn('show_bike') ? 'checked' : '' }} class="wot-switch"></label>
      <label class="wot-row"><span class="wot-row-l"><span class="t">Requested services</span></span><input type="checkbox" name="wot_show_services" value="1" {{ $wotOn('show_services') ? 'checked' : '' }} class="wot-switch"></label>
      <label class="wot-row"><span class="wot-row-l"><span class="t">Intake note</span></span><input type="checkbox" name="wot_show_note" value="1" {{ $wotOn('show_note') ? 'checked' : '' }} class="wot-switch"></label>
      <label class="wot-row"><span class="wot-row-l"><span class="t">QR code (links to the job)</span></span><input type="checkbox" name="wot_show_qr" value="1" {{ $wotOn('show_qr') ? 'checked' : '' }} class="wot-switch"></label>
      <label class="wot-row"><span class="wot-row-l"><span class="t">Tear-off customer claim stub</span></span><input type="checkbox" name="wot_show_stub" value="1" {{ $wotOn('show_stub') ? 'checked' : '' }} class="wot-switch"></label>
    </div>

    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Defaults</span></div>
      <div class="ia-input-grid-2">
        <div class="ia-form-group">
          <label class="ia-form-label">Default &ldquo;promised by&rdquo;</label>
          <div style="display:flex;align-items:center;gap:8px">
            <input type="number" name="wot_lead_days" value="{{ $wotLead }}" min="0" max="30" class="ia-input" style="width:84px">
            <span style="font-size:13px;color:var(--ia-muted)">business days after drop-off</span>
          </div>
          <div class="ia-form-hint" style="font-size:11.5px;color:var(--ia-muted);margin-top:6px">Prefilled on new jobs; editable per work order.</div>
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Paper width</label>
          <div class="wot-seg">
            <label><input type="radio" name="wot_paper" value="80mm" {{ $wotPaper === '80mm' ? 'checked' : '' }}><span>80mm</span></label>
            <label><input type="radio" name="wot_paper" value="58mm" {{ $wotPaper === '58mm' ? 'checked' : '' }}><span>58mm</span></label>
          </div>
        </div>
        {{-- MARKER-PATCH-320 --}}
        <div class="ia-form-group">
          <label class="ia-form-label">Extra paper after cut</label>
          <div style="display:flex;align-items:center;gap:8px">
            <input type="number" name="wot_feed_mm" value="{{ $wotFeed }}" min="0" max="40" class="ia-input" style="width:84px">
            <span style="font-size:13px;color:var(--ia-muted)">mm of feed so it clears the cutter</span>
          </div>
          <div class="ia-form-hint" style="font-size:11.5px;color:var(--ia-muted);margin-top:6px">Try 10&ndash;15mm if the last line cuts too close.</div>
        </div>
      </div>
    </div>

    {{-- MARKER-PATCH-330 --}}
    <div class="ia-card">
      <div class="ia-card-head"><span class="ia-card-title">Header &amp; footer</span></div>
      <div class="ia-form-group">
        <label class="ia-form-label">Header lines</label>
        <textarea name="wot_header_text" rows="2" class="ia-input" placeholder="e.g. 509-555-1234&#10;Mon–Fri 9–6" style="resize:vertical">{{ $wotHeader }}</textarea>
        <div class="ia-form-hint" style="font-size:11.5px;color:var(--ia-muted);margin-top:6px">Shown under your logo on tags, receipts &amp; slips. One per line.</div>
      </div>
      <div class="ia-form-group">
        <label class="ia-form-label">Footer message</label>
        <textarea name="wot_footer_text" rows="2" class="ia-input" placeholder="e.g. Thanks for riding with us!" style="resize:vertical">{{ $wotFooter }}</textarea>
        <div class="ia-form-hint" style="font-size:11.5px;color:var(--ia-muted);margin-top:6px">Printed at the bottom. Leave blank for the default.</div>
      </div>
    </div>

    <div class="ia-card">
      <div class="ia-card-head"><span class="ia-card-title">Logo</span></div>
      @if($wotLogo)
        <div class="wot-logo-preview"><img src="{{ asset('storage/' . ltrim($wotLogo, '/')) }}" alt="Tag logo"></div>
        <label style="display:flex;align-items:center;gap:8px;font-size:12px;color:var(--ia-muted);margin-bottom:12px;cursor:pointer">
          <input type="checkbox" name="wot_logo_remove" value="1"> Remove current logo
        </label>
      @endif
      {{-- MARKER-PATCH-317 --}}
      <div class="ia-form-group" style="margin-bottom:12px;max-width:240px">
        <label class="ia-form-label">Logo size on tag</label>
        @php $wls = $wot['logo_size'] ?? 'medium'; @endphp
        <select name="wot_logo_size" class="ia-input">
          <option value="small"  {{ $wls === 'small'  ? 'selected' : '' }}>Small</option>
          <option value="medium" {{ $wls === 'medium' ? 'selected' : '' }}>Medium</option>
          <option value="large"  {{ $wls === 'large'  ? 'selected' : '' }}>Large</option>
          <option value="xl"     {{ $wls === 'xl'     ? 'selected' : '' }}>Extra large</option>
        </select>
      </div>
      <input type="file" name="wot_logo" accept="image/png,image/jpeg,image/webp" class="ia-input">
      <div class="ia-form-hint" style="font-size:11.5px;color:var(--ia-muted);margin-top:6px">High-contrast black-on-white prints best on thermal. Shown at the top of each tag in place of the shop name.</div>
    </div>

  </form>
</div>

@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script>
(function() {
  'use strict';

  var csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

  /* -----------------------------------------------------------------------
   * Tab switching (no URL params)
   * ----------------------------------------------------------------------- */
  function switchTab(name) {
    document.querySelectorAll('.set-tab').forEach(function(t) {
      t.classList.toggle('active', t.dataset.tab === name);
    });
    document.querySelectorAll('.set-pane').forEach(function(p) {
      p.classList.toggle('active', p.id === 'pane-' + name);
    });
    // Reset window scroll so a long pane doesn't start mid-page
    window.scrollTo({ top: 0, behavior: 'instant' });
  }
  document.querySelectorAll('.set-tab').forEach(function(t) {
    t.addEventListener('click', function() { switchTab(t.dataset.tab); });
  });

  /* -----------------------------------------------------------------------
   * Dirty tracking — per form, save bar dims when no changes
   * ----------------------------------------------------------------------- */
  // MARKER-PATCH-166 — savebar shows ONLY the unsaved-changes warning.
  // Save confirmation lives in the top flash banner (one source of truth).
  document.querySelectorAll('[data-dirty-form]').forEach(function(form) {
    var savebar = form.querySelector('[data-savebar]');
    var msg     = savebar ? savebar.querySelector('.set-savebar-msg') : null;
    var initial = serialize(form);

    function serialize(f) {
      // For dirty tracking we build a stable string from the form's editable
      // values. File inputs and password fields with placeholder dots can't
      // be reliably serialized, so we only mark dirty on text/select/hidden
      // changes — any user interaction is enough to flip the bar.
      var parts = [];
      Array.from(f.elements).forEach(function(el) {
        if (!el.name) return;
        if (el.type === 'file') {
          if (el.files && el.files.length) parts.push(el.name + '=FILE');
          return;
        }
        if (el.type === 'checkbox' || el.type === 'radio') {
          parts.push(el.name + '=' + (el.checked ? '1' : '0') + '|' + (el.value || ''));
          return;
        }
        parts.push(el.name + '=' + (el.value || ''));
      });
      return parts.join('&');
    }

    function checkDirty() {
      var nowSerialized = serialize(form);
      var dirty = nowSerialized !== initial;
      if (savebar) {
        savebar.classList.toggle('dirty', dirty);
        // MARKER-PATCH-166 — savebar shows the warning only.
        // Save confirmation is handled by the global flash banner at the top
        // (layouts/tenant/app.blade.php). Dual confirmation was confusing.
        if (msg) {
          msg.textContent = dirty ? 'You have unsaved changes.' : '';
        }
      }
    }

    // Initial paint
    checkDirty();

    form.addEventListener('input', checkDirty);
    form.addEventListener('change', checkDirty);

    // Discard: reload the page (server-rendered, so this resets to saved state)
    var discardBtn = form.querySelector('[data-discard]');
    if (discardBtn) {
      discardBtn.addEventListener('click', function() {
        if (confirm('Discard your unsaved changes?')) {
          window.location.reload();
        }
      });
    }
  });

  /* -----------------------------------------------------------------------
   * Generic "ia-toggle bound to hidden input" pattern. Used on:
   *   - Business: classes_enabled, tax_services_default, tax_supports_exempt
   *   - Communication: sms_enabled, notify_booking_confirmation_email/sms
   *
   * Clicking the toggle flips both the visual class and the hidden input's
   * value, then dispatches a 'change' on the input so dirty tracking runs.
   * ----------------------------------------------------------------------- */
  function bindToggle(btnId, inputId) {
    var btn   = document.getElementById(btnId);
    var input = document.getElementById(inputId);
    if (!btn || !input) return;
    btn.addEventListener('click', function() {
      if (btn.disabled) return;
      var on = !btn.classList.contains('on');
      btn.classList.toggle('on', on);
      input.value = on ? '1' : '0';
      input.dispatchEvent(new Event('change', { bubbles: true }));
    });
  }
  bindToggle('classes-toggle-btn',          'classes_enabled_input');
  // MARKER-PATCH-156
  bindToggle('deliveries-toggle-btn',       'deliveries_enabled_input');
  // MARKER-PATCH-158-B
  bindToggle('multi-asset-toggle-btn',      'multi_asset_enabled_input');
  bindToggle('tax-services-toggle-btn',     'tax_services_default_input');
  bindToggle('tax-exempt-toggle-btn',       'tax_supports_exempt_input');
  // notify toggles removed — patch-406 (moved to Communication Center)

  /* -----------------------------------------------------------------------
   * Branding: color picker text/swatch sync
   * ----------------------------------------------------------------------- */
  document.querySelectorAll('input[type=color]').forEach(function(picker) {
    var textId = picker.id.replace('color-', 'text-');
    var text   = document.getElementById(textId);
    if (text) picker.addEventListener('input', function() { text.value = picker.value; });
  });

  /* -----------------------------------------------------------------------
   * Drop-off methods CRUD (preserved verbatim from the previous settings
   * page — endpoints unchanged, just wrapped in the new tab structure).
   * ----------------------------------------------------------------------- */
  var list = document.getElementById('method-list');

  // Add new method
  var addForm = document.getElementById('add-method-form');
  if (addForm) {
    addForm.addEventListener('submit', function(e) {
      e.preventDefault();
      var fd = new FormData(addForm);
      var body = {
        name:             fd.get('name'),
        description:      fd.get('description'),
        ask_for_time:     fd.get('ask_for_time') ? 1 : 0,
        ask_for_tracking: fd.get('ask_for_tracking') ? 1 : 0,
      };
      fetch("{{ route('tenant.receiving-methods.store') }}", {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
        body: JSON.stringify(body),
      }).then(function(r) {
        if (r.ok) window.location.reload();
        else alert('Could not add method.');
      });
    });
  }

  // Drag-to-reorder
  if (list && window.Sortable) {
    Sortable.create(list, {
      handle: '.drag-handle',
      animation: 150,
      onEnd: function() {
        var ids = Array.from(list.querySelectorAll('.method-row'))
                       .map(function(r) { return r.getAttribute('data-method-id'); });
        fetch("{{ route('tenant.receiving-methods.reorder') }}", {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
          body: JSON.stringify({ order: ids }),
        }).then(function(r) {
          // MARKER-PATCH-248
          if (r.ok) { if (window.IntakeToast) IntakeToast.success('Order saved'); }
          else { if (window.IntakeToast) IntakeToast.error('Could not save the new order'); }
        }).catch(function() { if (window.IntakeToast) IntakeToast.error('Could not save the new order — check your connection'); });
      }
    });
  }

  // Inline edit on blur (text) / change (checkbox)
  document.querySelectorAll('.method-edit, .method-edit-toggle').forEach(function(el) {
    var evt = el.type === 'checkbox' ? 'change' : 'blur';
    el.addEventListener(evt, function() {
      var row = el.closest('.method-row');
      var id  = row.getAttribute('data-method-id');
      var field = el.getAttribute('data-field');
      var value = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value;
      var body = {};
      body[field] = value;
      fetch("{{ url('admin/receiving-methods') }}/" + id, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
        body: JSON.stringify(body),
      }).then(function(r) {
        // MARKER-PATCH-248 — saves speak.
        if (r.ok) { if (window.IntakeToast) IntakeToast.success('Saved'); }
        else {
          row.style.outline = '1px solid #d04444';
          setTimeout(function() { row.style.outline = ''; }, 1500);
          if (window.IntakeToast) IntakeToast.error('Could not save — try again');
        }
      }).catch(function() { if (window.IntakeToast) IntakeToast.error('Could not save — check your connection'); });
    });
  });

  // Active toggle
  document.querySelectorAll('.method-row-toggle').forEach(function(btn) {
    btn.addEventListener('click', function() {
      if (btn.classList.contains('is-busy')) return;
      var row    = btn.closest('.method-row');
      var id     = row.getAttribute('data-method-id');
      var field  = btn.getAttribute('data-field');
      var newVal = !btn.classList.contains('on');
      btn.classList.add('is-busy');
      var body = {};
      body[field] = newVal ? 1 : 0;
      fetch("{{ url('admin/receiving-methods') }}/" + id, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
        body: JSON.stringify(body),
      }).then(function(r) {
        btn.classList.remove('is-busy');
        if (r.ok) {
          btn.classList.toggle('on', newVal);
          row.style.opacity = newVal ? '' : '.45';
          btn.setAttribute('title', newVal ? 'Click to deactivate' : 'Click to activate');
          btn.querySelector('.ia-toggle-sr').textContent = newVal ? 'Active' : 'Inactive';
        } else {
          row.style.outline = '1px solid #d04444';
          setTimeout(function() { row.style.outline = ''; }, 1500);
          if (window.IntakeToast) IntakeToast.error('Could not update — try again'); // MARKER-PATCH-248
        }
      }).catch(function() {
        btn.classList.remove('is-busy');
        if (window.IntakeToast) IntakeToast.error('Could not update — check your connection'); // MARKER-PATCH-248
      });
    });
  });

  /* -----------------------------------------------------------------------
   * SMS test send
   * ----------------------------------------------------------------------- */
  var smsTestBtn    = document.getElementById('sms-test-btn');
  var smsTestTo     = document.getElementById('sms_test_to');
  var smsTestStatus = document.getElementById('sms-test-status');

  if (smsTestBtn && smsTestTo && smsTestStatus) {
    smsTestBtn.addEventListener('click', function() {
      var to = smsTestTo.value.trim();
      if (!to) {
        smsTestStatus.className = 'sms-test-status error';
        smsTestStatus.textContent = 'Enter a phone number first.';
        return;
      }
      smsTestStatus.className = 'sms-test-status';
      smsTestStatus.textContent = '';
      smsTestBtn.disabled = true;
      smsTestBtn.textContent = 'Sending…';

      fetch("{{ route('tenant.settings.test-sms') }}", {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
        body: JSON.stringify({ to: to }),
      }).then(function(r) {
        return r.json().then(function(d) { return { ok: r.ok, body: d }; });
      }).then(function(res) {
        smsTestBtn.disabled = false;
        smsTestBtn.textContent = 'Send test';
        if (res.ok && res.body.ok) {
          smsTestStatus.className = 'sms-test-status success';
          smsTestStatus.textContent = res.body.message || 'Test sent.';
        } else {
          smsTestStatus.className = 'sms-test-status error';
          smsTestStatus.textContent = (res.body && res.body.error) || 'Send failed.';
        }
      }).catch(function() {
        smsTestBtn.disabled = false;
        smsTestBtn.textContent = 'Send test';
        smsTestStatus.className = 'sms-test-status error';
        smsTestStatus.textContent = 'Network error.';
      });
    });
  }

  /* -----------------------------------------------------------------------
   * Logo size sliders — live preview chip resize
   *
   * Slider input dispatches 'input' on every drag tick. We mutate the
   * preview img's height directly. The slider itself is a normal form input
   * so dirty tracking + save bar fire automatically.
   * ----------------------------------------------------------------------- */
  function bindLogoSlider(sliderId, readoutId, previewId) {
    var slider  = document.getElementById(sliderId);
    var readout = document.getElementById(readoutId);
    var preview = document.getElementById(previewId);
    if (!slider) return;
    slider.addEventListener('input', function() {
      var v = parseInt(slider.value, 10) || 16;
      if (readout) readout.textContent = v;
      if (preview) preview.style.height = v + 'px';
    });
  }
  bindLogoSlider('logo-admin-slider',   'logo-admin-readout',   'logo-admin-preview');
  bindLogoSlider('logo-booking-slider', 'logo-booking-readout', 'logo-booking-preview');

})();

/* -----------------------------------------------------------------------
 * Provider toggle (Stripe / PayPal) — needs to be global because the
 * onclick attribute references it from inline. Preserved from old page.
 * ----------------------------------------------------------------------- */
function toggleProvider(name) {
  var card     = document.getElementById(name + '-card');
  var toggle   = document.getElementById(name + '-toggle');
  var valInput = document.getElementById(name + '-enabled-val');
  var enabled  = toggle.classList.toggle('on');
  card.classList.toggle('enabled', enabled);
  valInput.value = enabled ? '1' : '0';
  // Trigger dirty tracking on the parent form
  valInput.dispatchEvent(new Event('change', { bubbles: true }));
}
</script>
@endpush

SOAV_4_EOF

cat > 'resources/views/tenant/register/index.blade.php' <<'SOAV_5_EOF'
@extends('layouts.tenant.app')

@php $pageTitle = 'Register'; @endphp

@push('styles')
<style>
  .reg-page { --reg-danger: #F09595; --reg-danger-bg: rgba(226,75,74,.15); }

  .reg-tabs-bar{
    display:flex;gap:4px;margin:0 0 18px;border-bottom:0.5px solid var(--ia-border);
    flex-wrap:wrap
  }
  /* MARKER-OFFLINE-SYNC stage 3b — mobile: picker on its own full-width row
     instead of floating beside wrapped tabs */
  @media (max-width: 760px) {
    .reg-tabs-bar #registerPicker{
      order:99;flex:1 1 100%;max-width:none;margin:8px 0 2px;width:100%;
    }
    .reg-tabs-bar{row-gap:2px}
  }
  .reg-tab-link{
    padding:10px 18px;font-size:13px;font-weight:500;color:var(--ia-text-dim);
    text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-0.5px;
    transition:color var(--ia-t),border-color var(--ia-t)
  }
  .reg-tab-link:hover{color:var(--ia-text)}
  .reg-tab-link.active{color:var(--ia-text);border-bottom-color:var(--ia-accent)}

  /* patch-96 layout — 50/50 split between item search and cart */
  .reg-grid {
    display:grid;grid-template-columns:1fr 1fr;gap:18px;
  }
  @media(max-width:1200px){ .reg-grid{grid-template-columns:1fr} }

  /* patch-100a oversell-actions — action row below oversold cart lines */
  .reg-oversell-actions {
    display: flex; gap: 8px; align-items: center;
    margin-top: 6px; flex-wrap: wrap;
  }
  .reg-oversell-btn {
    font-size: 11px; padding: 3px 10px;
    background: transparent;
    color: var(--ia-text);
    border: 0.5px solid var(--ia-border);
    border-radius: var(--ia-r-xs);
    cursor: pointer; transition: background 120ms ease;
  }
  .reg-oversell-btn:hover { background: var(--ia-hover); }
  .reg-oversell-pill {
    display: inline-block;
    font-size: 11px; padding: 3px 10px;
    background: rgba(99,153,34,0.12);
    color: #639922;
    border: 0.5px solid rgba(99,153,34,0.35);
    border-radius: var(--ia-r-xs);
    font-weight: 500;
  }
  /* patch-96 oversell-badge — small amber inline marker on cart lines */
  .reg-oversell-badge {
    display:inline-block; margin-left:8px;
    padding:2px 7px;
    background:rgba(245,158,11,0.12);
    color:#F59E0B;
    border:0.5px solid rgba(245,158,11,0.35);
    border-radius:var(--ia-r-xs);
    font-size:10.5px; font-weight:600;
    letter-spacing:0.02em;
    vertical-align:middle;
    white-space:nowrap;
  }
  .reg-panel{
    background:var(--ia-surface);border:0.5px solid var(--ia-border);
    border-radius:var(--ia-r-lg);padding:18px
  }

  .reg-search{
    width:100%;padding:12px 14px;background:var(--ia-input-bg);
    border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);
    color:var(--ia-text);font-size:14px;font-family:inherit
  }
  .reg-search:focus{outline:none;border-color:var(--ia-accent)}

  .reg-tabs{display:flex;gap:6px;margin:12px 0 14px}
  .reg-tab{
    padding:6px 12px;background:transparent;border:0.5px solid var(--ia-border);
    border-radius:99px;color:var(--ia-text-dim);font-size:12px;font-family:inherit;cursor:pointer
  }
  .reg-tab.active{background:var(--ia-accent);color:var(--ia-accent-text);border-color:var(--ia-accent)}

  .reg-results-section{margin-top:14px}
  .reg-results-section h3{
    font-size:11px;font-weight:600;color:var(--ia-text-dim);
    text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px
  }
  .reg-row{
    display:flex;align-items:center;justify-content:space-between;gap:12px;
    padding:10px 12px;border-radius:var(--ia-r-md);cursor:pointer;transition:background var(--ia-t)
  }
  .reg-row:hover{background:var(--ia-hover)}
  .reg-row.highlighted{background:var(--ia-hover)}
  .reg-results-section.mouse-active .reg-row.highlighted:not(:hover){background:transparent}
  .reg-row .name{font-weight:500;font-size:14px}
  .reg-row .meta{font-size:12px;color:var(--ia-text-dim)}
  .reg-row .price{font-size:14px;font-weight:600;color:var(--ia-text);white-space:nowrap}

  .reg-hint{
    display:flex;gap:14px;align-items:center;
    font-size:11px;color:var(--ia-text-dim);
    margin:8px 4px 6px;padding:0 4px
  }
  .reg-hint kbd{
    display:inline-flex;align-items:center;justify-content:center;
    min-width:18px;height:18px;padding:0 5px;
    background:var(--ia-surface-2);
    border:0.5px solid var(--ia-border);
    border-radius:var(--ia-r-sm);
    font-family:var(--ia-font-mono);
    font-size:10px;color:var(--ia-text-muted);
    margin:0 2px
  }

  .reg-open-item{
    width:100%;margin-top:10px;padding:10px 14px;
    background:transparent;border:0.5px dashed var(--ia-border-strong);
    border-radius:var(--ia-r-md);color:var(--ia-text-muted);
    font-size:13px;font-family:inherit;cursor:pointer;transition:all var(--ia-t)
  }
  .reg-open-item:hover{border-color:var(--ia-accent);color:var(--ia-text)}

  .reg-cust{
    display:flex;flex-direction:column;gap:6px;
    padding:12px 14px;background:var(--ia-surface-2);border-radius:var(--ia-r-md);
    margin-bottom:14px;font-size:13px
  }
  .reg-cust .head{display:flex;align-items:center;justify-content:space-between;gap:10px}
  .reg-cust .name{font-weight:500;font-size:14px}
  .reg-cust .meta{display:flex;flex-direction:column;gap:2px;font-size:12px;color:var(--ia-text-dim)}
  .reg-cust .meta a{color:var(--ia-text-dim);text-decoration:none}
  .reg-cust .meta a:hover{color:var(--ia-text);text-decoration:underline}
  .reg-cust .actions{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:4px;padding-top:8px;border-top:0.5px solid var(--ia-border)}
  .reg-cust .profile-link{font-size:12px;color:var(--ia-accent);text-decoration:none}
  .reg-cust .profile-link:hover{text-decoration:underline}
  .reg-cust .clear{color:var(--ia-text-dim);cursor:pointer;font-size:11px}
  .reg-cust .clear:hover{color:var(--reg-danger)}

  .reg-attach{
    width:100%;padding:10px;background:transparent;border:0.5px dashed var(--ia-border-strong);
    border-radius:var(--ia-r-md);color:var(--ia-text-muted);font-size:13px;
    font-family:inherit;cursor:pointer;transition:all var(--ia-t);margin-bottom:14px
  }
  .reg-attach:hover{border-color:var(--ia-accent);color:var(--ia-text)}

  .reg-lines{
    max-height:340px;overflow-y:auto;margin:0 -4px 14px;padding:0 4px;
    border-bottom:0.5px solid var(--ia-border);padding-bottom:14px
  }
  .reg-line{
    display:grid;grid-template-columns:1fr auto auto;gap:10px;align-items:center;padding:10px 4px
  }
  .reg-line .name{font-size:13px;font-weight:500;line-height:1.3}
  .reg-line .meta{font-size:11px;color:var(--ia-text-dim);margin-top:2px}
  .reg-line .qty{
    width:50px;padding:5px 8px;background:var(--ia-input-bg);
    border:0.5px solid var(--ia-border);border-radius:var(--ia-r-sm);
    color:var(--ia-text);font-size:13px;font-family:inherit;text-align:center
  }
  .reg-line .qty:focus{outline:none;border-color:var(--ia-accent)}
  .reg-line .total{font-size:13px;font-weight:600;text-align:right;min-width:62px}
  .reg-line .remove{background:transparent;border:none;color:var(--ia-text-dim);font-size:16px;cursor:pointer;padding:0 4px;line-height:1}
  .reg-line .remove:hover{color:var(--reg-danger)}
  .reg-empty{padding:30px 0;text-align:center;color:var(--ia-text-dim);font-size:13px}

  .reg-totals{font-size:13px}
  .reg-totals-row{display:flex;justify-content:space-between;padding:5px 0;color:var(--ia-text-muted)}
  .reg-totals-row.grand{font-size:18px;font-weight:600;color:var(--ia-text);padding-top:10px;margin-top:6px;border-top:0.5px solid var(--ia-border)}

  .reg-pay-row{display:grid;grid-template-columns:1fr 2fr;gap:8px;margin-top:16px}
  .reg-pay{
    padding:14px;background:var(--ia-accent);color:var(--ia-accent-text);
    border:none;border-radius:var(--ia-r-md);font-size:15px;font-weight:600;
    font-family:inherit;cursor:pointer;transition:filter var(--ia-t)
  }
  .reg-pay:hover:not(:disabled){filter:brightness(.93)}
  .reg-pay:disabled{opacity:.4;cursor:not-allowed}

  .reg-quote-btn{
    padding:14px;background:rgba(var(--ia-accent-rgb,255,255,255),.10);
    border:0.5px solid var(--ia-border);
    border-radius:var(--ia-r-md);color:var(--ia-text);font-size:14px;font-weight:500;
    font-family:inherit;cursor:pointer;transition:all var(--ia-t)
  }
  .reg-quote-btn:hover:not(:disabled){border-color:var(--ia-accent);background:var(--ia-accent-soft)}
  .reg-quote-btn:disabled{opacity:.4;cursor:not-allowed}

  .reg-cust.warning{
    background:var(--reg-danger-bg);
    border:0.5px solid var(--reg-danger);
  }
  /* MARKER-PATCH-161 — receipt indicator */
  .reg-cust-receipt{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
    padding:8px 12px;
    margin-top:8px;
    background:rgba(190,242,100,.06);
    border:0.5px solid rgba(190,242,100,.2);
    border-radius:var(--ia-r-sm);
    font-size:12px;
    flex-wrap:wrap;
  }
  .reg-cust-receipt--none{
    background:var(--ia-surface);
    border-color:var(--ia-border);
    color:var(--ia-text-dim);
  }
  .reg-cust-receipt-status{display:flex;align-items:center;gap:8px;min-width:0;flex:1}
  .reg-cust-receipt-dot{
    width:8px;height:8px;border-radius:50%;background:var(--ia-accent);
    box-shadow:0 0 0 3px rgba(190,242,100,.15);flex-shrink:0;
  }
  .reg-cust-receipt-skip{
    display:flex;align-items:center;gap:6px;cursor:pointer;
    font-size:11.5px;color:var(--ia-text-dim);user-select:none;flex-shrink:0;
  }
  .reg-cust-receipt-skip input{width:14px;height:14px;accent-color:var(--ia-accent)}

  .reg-attach.warning{
    border:0.5px dashed var(--reg-danger);
    color:var(--reg-danger)
  }

  .reg-err{background:var(--reg-danger-bg);color:var(--reg-danger);border-radius:var(--ia-r-sm);padding:10px 12px;font-size:13px;margin-bottom:12px;border:0.5px solid rgba(248,113,113,.30)}
  /* MARKER-PATCH-170C — shake animation for errors. Triggered by toggling .reg-err--shake. */
  @keyframes reg-shake {
    0%,100% { transform: translateX(0); }
    15%     { transform: translateX(-6px); }
    30%     { transform: translateX(5px); }
    45%     { transform: translateX(-4px); }
    60%     { transform: translateX(3px); }
    75%     { transform: translateX(-2px); }
    90%     { transform: translateX(1px); }
  }
  .reg-err--shake { animation: reg-shake 0.55s ease-out; }

  /* Pre-flight blocker modal — uses the same surfaces as other reg-modals
     but with a danger-tinged accent on the title. */
  .reg-preflight-icon {
    width: 44px; height: 44px;
    background: rgba(248,113,113,.10);
    border: 0.5px solid rgba(248,113,113,.25);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    color: #f87171;
    margin: 0 auto 14px;
  }
  .reg-preflight h2 { text-align: center; }
  .reg-preflight .lede { text-align: center; }

  .reg-modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.7);display:none;align-items:center;justify-content:center;z-index:1000;padding:20px}
  .reg-modal-bg.open{display:flex}
  .reg-modal{
    background:var(--ia-surface);border:0.5px solid var(--ia-border);
    border-radius:var(--ia-r-xl);padding:24px;width:100%;max-width:420px
  }
  .reg-modal h2{font-size:18px;font-weight:600;margin-bottom:8px;color:var(--ia-text)}
  .reg-modal .lede{color:var(--ia-text-dim);font-size:13px;margin-bottom:18px}

  .reg-tender-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px}
  .reg-tender-btn{
    padding:14px 12px;background:var(--ia-surface-2);border:0.5px solid var(--ia-border);
    border-radius:var(--ia-r-md);color:var(--ia-text);font-size:13px;font-weight:500;
    font-family:inherit;cursor:pointer;transition:all var(--ia-t);text-align:left
  }
  .reg-tender-btn:hover{border-color:var(--ia-accent)}
  /* MARKER-SPLIT-TENDER */
  .reg-split-remaining{display:flex;justify-content:space-between;font-size:13.5px;padding:8px 2px;border-bottom:1px solid var(--ia-border);margin-bottom:10px}
  .reg-split-remaining b{font-variant-numeric:tabular-nums;color:#F5C56B}
  .reg-split-remaining.zero b{color:var(--ia-accent)}
  .reg-split-row{display:flex;align-items:center;gap:10px;border:1px solid var(--ia-border);border-radius:10px;padding:9px 12px;margin-bottom:7px;font-size:13px;flex-wrap:wrap}
  .reg-split-row .amt{margin-left:auto;font-weight:700;font-variant-numeric:tabular-nums}
  .reg-split-row .x{color:var(--ia-text-dim);cursor:pointer;padding:2px 6px;border-radius:6px}
  .reg-split-row .x:hover{color:#F09595}
  .reg-split-row .chg{flex-basis:100%;font-size:11px;color:var(--ia-accent)}
  .reg-tender-btn.split-disabled{opacity:.35;pointer-events:none}
  .reg-tender-btn.selected{border-color:var(--ia-accent);background:var(--ia-accent-soft)}

  .reg-tip-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin:12px 0}
  .reg-tip-btn{
    padding:12px 10px;background:var(--ia-surface-2);border:0.5px solid var(--ia-border);
    border-radius:var(--ia-r-md);color:var(--ia-text);font-size:13px;font-family:inherit;cursor:pointer;transition:all var(--ia-t)
  }
  .reg-tip-btn:hover{border-color:var(--ia-accent)}
  .reg-tip-btn.selected{border-color:var(--ia-accent);background:var(--ia-accent-soft)}

  .reg-tip-custom{display:flex;gap:8px;align-items:center;margin-top:6px}
  .reg-tip-custom input{flex:1;padding:10px;background:var(--ia-input-bg);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-sm);color:var(--ia-text);font-size:14px;font-family:inherit}
  .reg-tip-custom input:focus{outline:none;border-color:var(--ia-accent)}

  .reg-modal-actions{display:flex;gap:8px;margin-top:18px}
  .reg-btn-secondary{flex:1;padding:11px;background:var(--ia-surface-2);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-sm);color:var(--ia-text);font-size:13px;font-weight:500;font-family:inherit;cursor:pointer;transition:all var(--ia-t)}
  .reg-btn-secondary:hover{border-color:var(--ia-border-strong)}
  .reg-btn-primary{flex:1;padding:11px;background:var(--ia-accent);color:var(--ia-accent-text);border:none;border-radius:var(--ia-r-sm);font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;transition:filter var(--ia-t)}
  .reg-btn-primary:hover:not(:disabled){filter:brightness(.93)}
  .reg-btn-primary:disabled{opacity:.4;cursor:not-allowed}

  .reg-modal input[type=text]{width:100%;padding:10px;background:var(--ia-input-bg);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-sm);color:var(--ia-text);font-size:14px;font-family:inherit}
  .reg-modal input[type=text]:focus{outline:none;border-color:var(--ia-accent)}

  .reg-receipt{text-align:center}
  .reg-receipt h2{font-size:24px;margin-bottom:6px}
  .reg-receipt .num{font-size:13px;color:var(--ia-text-dim);margin-bottom:18px;font-family:var(--ia-font-mono)}
  .reg-receipt .total{font-size:36px;font-weight:700;margin:14px 0}
  /* MARKER-PATCH-187 — auto-reset countdown line */
  .reg-receipt-auto{margin-top:14px;font-size:12px;color:var(--ia-text-dim)}
  .reg-receipt-auto span{font-variant-numeric:tabular-nums;color:var(--ia-text)}

  .reg-cust-results{position:absolute;top:100%;left:0;right:0;background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-sm);margin-top:4px;max-height:240px;overflow-y:auto;z-index:10}

  .reg-refund-result{
    padding:12px;background:rgba(190,242,100,.04);
    border:0.5px solid var(--ia-accent);border-radius:var(--ia-r-md);
    margin-bottom:10px;cursor:pointer;transition:filter var(--ia-t)
  }
  .reg-refund-result:hover{filter:brightness(1.1)}
  .reg-refund-result .label{font-size:11px;color:var(--ia-accent);text-transform:uppercase;letter-spacing:.06em;font-weight:600;margin-bottom:4px}
  .reg-refund-result .name{font-size:14px;font-weight:500}
  .reg-refund-result .meta{font-size:12px;color:var(--ia-text-dim);margin-top:2px}

  .reg-refund-list{max-height:380px;overflow-y:auto;margin:-4px 0 14px;padding:4px 0}
  .reg-refund-row{
    display:grid;grid-template-columns:auto 1fr auto auto;gap:12px;align-items:center;
    padding:10px 12px;border-radius:var(--ia-r-md);border:0.5px solid var(--ia-border);
    margin-bottom:6px
  }
  .reg-refund-row.disabled{opacity:.4}
  .reg-refund-row input[type=checkbox]{width:16px;height:16px;accent-color:var(--ia-accent)}
  .reg-refund-row .name{font-size:13px;font-weight:500}
  .reg-refund-row .meta{font-size:11px;color:var(--ia-text-dim);margin-top:2px}
  .reg-refund-row .qty-input{
    width:60px;padding:5px 8px;background:var(--ia-input-bg);
    border:0.5px solid var(--ia-border);border-radius:var(--ia-r-sm);
    color:var(--ia-text);font-size:13px;font-family:inherit;text-align:center
  }
  .reg-refund-row .qty-input:focus{outline:none;border-color:var(--ia-accent)}
  .reg-refund-row .qty-input:disabled{opacity:.4;cursor:not-allowed}
  .reg-refund-row .total{font-size:13px;font-weight:600;text-align:right;min-width:70px}

  .reg-cart-section-label{
    font-size:10px;color:var(--ia-text-dim);text-transform:uppercase;letter-spacing:.08em;
    font-weight:600;padding:8px 4px 4px;border-top:0.5px solid var(--ia-border);
    margin-top:8px
  }
  .reg-cart-section-label:first-child{border-top:none;margin-top:0}
  .reg-cart-section-label.refund{color:#F09595}

  .reg-line.refund-line{background:rgba(226,75,74,.04)}
  .reg-line.refund-line .total{color:#F09595}
  .reg-line.refund-line .meta{color:#F09595;opacity:.7}
  .reg-cust-results .row{padding:10px 12px;cursor:pointer;border-bottom:0.5px solid var(--ia-border)}
  .reg-cust-results .row:hover{background:var(--ia-hover)}
  .reg-cust-results .row:last-child{border-bottom:none}

  .reg-drafts-banner{
    display:flex;align-items:center;justify-content:space-between;gap:10px;
    padding:11px 14px 11px 13px;
    background:var(--ia-accent-soft);
    border:0.5px solid var(--ia-border);
    border-left:3px solid var(--ia-accent);
    border-radius:var(--ia-r-md);margin-bottom:14px;font-size:13px;cursor:pointer;
    transition:filter var(--ia-t)
  }
  .reg-drafts-banner:hover{filter:brightness(1.08)}
  .reg-drafts-banner .label{color:var(--ia-text);font-weight:500}
  .reg-drafts-banner .cta{font-size:11px;color:var(--ia-text-dim);text-transform:uppercase;letter-spacing:.05em;font-weight:500}

  .reg-save-status{
    font-size:11px;color:var(--ia-text-dim);text-transform:uppercase;letter-spacing:.05em;
    margin-bottom:8px;height:14px;line-height:14px;
    transition:opacity var(--ia-t);opacity:0
  }
  .reg-save-status.visible{opacity:1}

  .reg-drafts-list{max-height:380px;overflow-y:auto;margin:-4px -4px 14px;padding:4px}
  .reg-draft-row{
    display:grid;grid-template-columns:1fr auto auto;gap:12px;align-items:center;
    padding:12px;border-radius:var(--ia-r-md);border:0.5px solid var(--ia-border);margin-bottom:8px
  }
  .reg-draft-row .meta-line{font-size:12px;color:var(--ia-text-dim);margin-top:2px}
  .reg-draft-row .total{font-size:14px;font-weight:600;text-align:right;min-width:62px}
  .reg-draft-row .actions{display:flex;gap:6px}
  .reg-draft-row .btn-resume{padding:6px 12px;background:var(--ia-accent);color:var(--ia-accent-text);border:none;border-radius:var(--ia-r-sm);font-size:12px;font-weight:500;font-family:inherit;cursor:pointer}
  .reg-draft-row .btn-resume:hover{filter:brightness(.93)}
  .reg-draft-row .btn-discard{padding:6px 10px;background:transparent;border:0.5px solid var(--ia-border);border-radius:var(--ia-r-sm);color:var(--ia-text-dim);font-size:12px;font-family:inherit;cursor:pointer}
  .reg-draft-row .btn-discard:hover{color:var(--reg-danger);border-color:var(--reg-danger)}
</style>
@endpush

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Register</h1>
    <p class="ia-page-subtitle">Walk-in sales and retail checkouts.</p>
  </div>
</div>

<div class="reg-tabs-bar">
  <a href="{{ route('tenant.register.index') }}" class="reg-tab-link active">Transaction</a>
  <a href="{{ route('tenant.register.history.index') }}" class="reg-tab-link">Transaction History</a>
  <a href="{{ route('tenant.register.quotes.index') }}" class="reg-tab-link">Quotes</a>
  <a href="{{ route('tenant.register.registers') }}" class="reg-tab-link">Registers</a> {{-- MARKER-REGISTER-RECON-DISPLAY --}}
  {{-- MARKER-REGISTER-RECON-DISPLAY — register picker (only when registers exist) --}}
  @if (($registers ?? collect())->isNotEmpty())
    <select id="registerPicker" class="ia-input" style="margin-left:auto;max-width:220px;font-size:13px"
            title="Pay-station display this device drives">
      <option value="0">No register / display</option>
      @foreach ($registers as $r)
        <option value="{{ $r->id }}" @selected(($currentRegisterId ?? 0) === $r->id)>#{{ $r->number }} — {{ $r->name }}</option>
      @endforeach
    </select>
  @endif
</div>

@if(($appointmentTrayCount ?? 0) > 0)
  {{-- Appointment-sourced sales waiting for payment. Auto-created when staff
       marked an appointment Completed. We surface them prominently so staff
       can't miss a parked sale. --}}
  <div id="appointment-tray-banner" style="background:rgba(21,112,205,.07);border:0.5px solid rgba(21,112,205,.30);border-radius:var(--ia-r-md);padding:14px 18px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;gap:14px">
    <div style="display:flex;align-items:center;gap:12px;flex:1">
      <span style="font-size:20px">💳</span>
      <div>
        <div style="font-weight:500;font-size:14px;color:var(--ia-text)">{{ $appointmentTrayCount }} {{ $appointmentTrayCount === 1 ? 'appointment is' : 'appointments are' }} ready for checkout</div>
        <div style="font-size:12px;color:var(--ia-text-muted);margin-top:2px">From recently completed appointments. Click to take payment.</div>
      </div>
    </div>
    <button type="button" id="appointment-tray-toggle" class="ia-btn ia-btn--primary ia-btn--sm">View list</button>
  </div>
  <div id="appointment-tray-list" style="display:none;background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);padding:8px;margin-bottom:18px"></div>
@endif

<div class="reg-page">

  <div class="reg-grid">

    <div class="reg-panel">
      <input type="text" class="reg-search" id="searchInput" placeholder="Search products and services…" autocomplete="off">

      <div class="reg-tabs">
        <button type="button" class="reg-tab active" data-type="all">All</button>
        <button type="button" class="reg-tab" data-type="product">Products</button>
        <button type="button" class="reg-tab" data-type="service">Services</button>
      </div>

      <div class="reg-hint" id="regHint" style="display:none">
        <span><kbd>↑</kbd><kbd>↓</kbd> navigate</span>
        <span><kbd>↵</kbd> add</span>
        <span><kbd>esc</kbd> clear</span>
      </div>

      <div id="resultsArea">
        <div class="reg-empty">Type to search products and services.</div>
      </div>

      <button type="button" class="reg-open-item" id="addOpenItemBtn">+ Add custom item</button>
    </div>

    <div class="reg-panel">
      <div id="errBanner" class="reg-err" style="display:none"></div>

      <div id="saveStatus" class="reg-save-status"></div>

      <div id="draftsBanner" class="reg-drafts-banner" style="display:none">
        <span class="label" id="draftsBannerLabel"></span>
        <span class="cta">View →</span>
      </div>

      <div id="customerSlot">
        <button type="button" class="reg-attach" id="attachCustBtn">+ Attach customer</button>
      </div>

      <div class="reg-lines" id="cartLines">
        <div class="reg-empty">Cart is empty.</div>
      </div>

      <div class="reg-totals">
        <div class="reg-totals-row"><span>Subtotal</span><span id="subVal">$0.00</span></div>
        <div class="reg-totals-row" id="discountRow" style="display:none"><span>Discount</span><span id="discVal">-$0.00</span></div>
        <div class="reg-totals-row"><span>Tax</span><span id="taxVal">$0.00</span></div>
        <div class="reg-totals-row" id="surchargeRow" style="display:none"><span id="surchLabel">Surcharge</span><span id="surchVal">$0.00</span></div>
        <div class="reg-totals-row" id="tipRow" style="display:none"><span>Tip</span><span id="tipVal">$0.00</span></div>
        <div class="reg-totals-row grand"><span>Total</span><span id="totalVal">$0.00</span></div>
      </div>

      <div class="reg-pay-row">
        <button type="button" class="reg-quote-btn" id="quoteBtn" disabled>Save quote</button>
        <button type="button" class="reg-pay" id="payBtn" disabled>Collect payment</button>
      </div>
    </div>

  </div>

</div>

<div class="reg-modal-bg" id="refundTenderModal">
  <div class="reg-modal">
    <h2>Refund to customer</h2>
    <div class="lede" id="refundTenderLede">How is the refund being given?</div>
    <div class="reg-tender-grid">
      <button type="button" class="reg-tender-btn" data-refund-tender="card">Refund to card</button>
      <button type="button" class="reg-tender-btn" data-refund-tender="cash">Cash from drawer</button>
      <button type="button" class="reg-tender-btn" data-refund-tender="check">Check</button>
      <button type="button" class="reg-tender-btn" data-refund-tender="store_credit">Store credit</button>
    </div>
    <div class="reg-modal-actions">
      <button type="button" class="reg-btn-secondary" data-close-modal="refundTenderModal">Cancel</button>
      <button type="button" class="reg-btn-primary" id="refundTenderConfirmBtn" disabled>Continue</button>
    </div>
  </div>
</div>

<div class="reg-modal-bg" id="tenderModal">
  <div class="reg-modal">
    <h2>Choose tender</h2>
    <div class="lede">How is the customer paying?</div>
    {{-- MARKER-SPLIT-TENDER — running remaining + recorded split payments --}}
    <div class="reg-split-remaining" id="splitRemainRow" style="display:none">
      <span>Remaining</span><b id="splitRemain"></b>
    </div>
    <div id="splitPayList"></div>
    <div class="reg-tender-grid">
      <button type="button" class="reg-tender-btn" data-tender="cash">Cash</button>
      <button type="button" class="reg-tender-btn" data-tender="card">Card</button>
      {{-- MARKER-PATCH-172 — payment-link tender (hidden when direct payments off) --}}
      <button type="button" class="reg-tender-btn" data-tender="payment_link" id="tenderPaymentLinkBtn" style="display:none">
        Send payment link
        <div style="font-size:11px;opacity:.55;font-weight:400;margin-top:2px">Customer pays from their phone</div>
      </button>
      <button type="button" class="reg-tender-btn" data-tender="check">Check</button>
      <button type="button" class="reg-tender-btn" data-tender="store_credit">Store credit</button>
      {{-- MARKER-PATCH-630 — manual tenders from tenant_payment_methods (Venmo, Cash App, custom) --}}
      @foreach(($manualTenders ?? []) as $mt)
        <button type="button" class="reg-tender-btn" data-tender="{{ $mt['key'] }}"
                data-manual="1" data-name="{{ $mt['name'] }}"
                @if($mt['linktpl']) data-linktpl="{{ $mt['linktpl'] }}" @endif
                @if($mt['instructions']) data-instructions="{{ $mt['instructions'] }}" @endif>
          {{ $mt['name'] }}
          @if($mt['hint'])<div style="font-size:11px;opacity:.55;font-weight:400;margin-top:2px">{{ $mt['hint'] }}</div>@endif
        </button>
      @endforeach
      <button type="button" class="reg-tender-btn" data-tender="mark_paid">No tender (already paid)</button>
    </div>
    {{-- MARKER-SPLIT-TENDER — amount entry: prefilled with remaining; Add
         records a partial payment. Untouched prefill + Confirm = the classic
         single-tender path, unchanged. --}}
    <div id="splitAmountRow" style="display:none;gap:8px;margin-bottom:12px">
      <div style="flex:1;position:relative">
        <b style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--ia-text-dim);font-size:15px">$</b>
        <input type="text" id="splitAmountInput" inputmode="decimal"
               style="width:100%;padding:12px 12px 12px 26px;font-size:16px;font-weight:700;font-variant-numeric:tabular-nums">
      </div>
      <button type="button" class="reg-btn" id="splitAddBtn" style="padding:0 18px;font-weight:800">Add payment</button>
    </div>
    <div id="splitHint" style="display:none;font-size:11.5px;color:var(--ia-text-dim);margin:-6px 0 12px">
      Type a partial amount to split tenders — cash above the remainder computes change.
    </div>
    <div id="tenderRefRow" style="display:none;margin-bottom:14px">
      <label style="display:block;font-size:12px;color:var(--ia-text-dim);margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em">Reference (optional)</label>
      <input type="text" id="tenderRefInput" placeholder="Check #, last 4 of card, etc.">
    </div>
    {{-- MARKER-PATCH-630 — manual payment link (Venmo / Cash App) --}}
    <div id="tenderManualRow" style="display:none;margin-bottom:14px">
      <div id="tenderManualInstr" style="font-size:12px;color:var(--ia-text-muted);margin-bottom:8px"></div>
      <div id="tenderManualLinkWrap" style="display:none">
        <div id="tenderManualLink" style="font-size:12px;background:var(--ia-surface-2,#1a1a1a);border:1px solid var(--ia-border);border-radius:8px;padding:9px 11px;color:var(--ia-accent);word-break:break-all;margin-bottom:8px"></div>
        <div style="display:flex;gap:8px">
          <button type="button" class="reg-btn-secondary" id="tenderManualCopy" style="font-size:12px;padding:7px 13px">Copy link</button>
          <a class="reg-btn-secondary" id="tenderManualSms" style="font-size:12px;padding:7px 13px;text-decoration:none" href="#">Text to customer</a>
        </div>
      </div>
      <div style="font-size:11px;color:var(--ia-text-dim,rgba(255,255,255,.4));margin-top:8px">Confirm the payment arrived in your app, then continue — the sale records as paid by this method.</div>
    </div>
    <div class="reg-modal-actions">
      <button type="button" class="reg-btn-secondary" data-close-modal="tenderModal">Cancel</button>
      <button type="button" class="reg-btn-primary" id="tenderConfirmBtn" disabled>Continue</button>
    </div>
  </div>
</div>

{{-- MARKER-PATCH-170C — Pre-flight blocker modal. Shown when the Charge
     button is pressed but the cart isn't commit-able. Replaces hidden inline
     errors that were easy to miss. --}}
<div class="reg-modal-bg" id="preflightModal">
  <div class="reg-modal reg-preflight">
    <div class="reg-preflight-icon">
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="12" r="10"/>
        <line x1="12" y1="8" x2="12" y2="12"/>
        <line x1="12" y1="16" x2="12.01" y2="16"/>
      </svg>
    </div>
    <h2 id="preflightTitle">Add a customer</h2>
    <div class="lede" id="preflightLede">A customer is required when the sale includes a service.</div>
    <div class="reg-modal-actions" style="justify-content:center;gap:10px">
      <button type="button" class="reg-btn-secondary" data-close-modal="preflightModal">Cancel</button>
      <button type="button" class="reg-btn-primary" id="preflightActionBtn">Add customer →</button>
    </div>
  </div>
</div>

{{-- MARKER-PATCH-170 — Direct Payments card-entry modal --}}
<div class="reg-modal-bg" id="cardPaymentModal">
  <div class="reg-modal">
    <h2>Card payment</h2>
    <div class="lede">Enter card details. Powered by Stripe.</div>

    <div id="cardPaymentSummary" style="background:var(--ia-surface-2);border-radius:var(--ia-r-md);padding:14px;margin-bottom:14px;font-size:13px">
      <div style="display:flex;justify-content:space-between;font-weight:600;font-size:15px"><span>Charge</span><span id="cardPaymentAmount">$0.00</span></div>
    </div>

    <div id="card-payment-element" style="background:var(--ia-input-bg);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);padding:16px;margin-bottom:14px;min-height:60px"></div>

    <div id="cardPaymentError" style="display:none;padding:12px 14px;background:rgba(248,113,113,.10);border:0.5px solid rgba(248,113,113,.25);border-radius:var(--ia-r-md);font-size:12.5px;color:#f87171;margin-bottom:14px"></div>

    <div class="reg-modal-actions">
      <button type="button" class="reg-btn-secondary" id="cardPaymentCancelBtn">Cancel</button>
      <button type="button" class="reg-btn-primary" id="cardPaymentChargeBtn" disabled>
        <span id="cardPaymentChargeLabel">Charge</span>
        <span id="cardPaymentSpinner" style="display:none;margin-left:8px">…</span>
      </button>
    </div>
  </div>
</div>

{{-- MARKER-PATCH-172 — Send-payment-link modal --}}
<div class="reg-modal-bg" id="paymentLinkModal">
  <div class="reg-modal" style="max-width:520px">
    <h2>Send payment link</h2>
    <div class="lede">Share this link with your customer. They'll pay from their device.</div>

    <div id="paymentLinkAmount" style="background:var(--ia-surface-2);border-radius:var(--ia-r-md);padding:14px;margin-bottom:14px;font-size:13px">
      <div style="display:flex;justify-content:space-between;font-weight:600;font-size:15px"><span>Charge</span><span id="paymentLinkAmountValue">$0.00</span></div>
    </div>

    <div id="paymentLinkQRContainer" style="background:white;border-radius:var(--ia-r-md);padding:18px;margin-bottom:14px;display:flex;justify-content:center;align-items:center;min-height:240px">
      <div id="paymentLinkQR"></div>
    </div>

    <div style="background:var(--ia-input-bg);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);padding:10px 12px;margin-bottom:12px;display:flex;align-items:center;gap:10px">
      <code id="paymentLinkUrl" style="flex:1;font-size:11px;color:var(--ia-text-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap"></code>
      <button type="button" class="reg-btn-secondary" id="paymentLinkCopyBtn" style="padding:6px 10px;font-size:11.5px">Copy</button>
    </div>

    <div id="paymentLinkStatus" style="display:flex;align-items:center;gap:10px;padding:12px 14px;background:var(--ia-accent-soft);border:0.5px solid rgba(190,242,100,.25);border-radius:var(--ia-r-md);font-size:12.5px;color:var(--ia-text);margin-bottom:14px">
      <span class="stripe-spinner" style="width:14px;height:14px;border:2px solid rgba(190,242,100,.2);border-top-color:var(--ia-accent);border-radius:50%;animation:spin 0.8s linear infinite"></span>
      <span id="paymentLinkStatusText">Waiting for customer to pay…</span>
    </div>

    {{-- MARKER-PATCH-192 — two distinct actions: "Done" keeps the link live
         (sale stays pending, trackable from the appointment); "Cancel link" is
         the explicit destructive action that expires the Stripe session. --}}
    <div class="reg-modal-actions" style="display:flex;gap:10px;justify-content:space-between">
      <button type="button" class="reg-btn-secondary" id="paymentLinkCancelBtn" style="color:var(--ia-red,#F87171)">Cancel link</button>
      <button type="button" class="reg-btn-primary" id="paymentLinkDoneBtn">Done — keep link live</button>
    </div>
  </div>
</div>

{{-- MARKER-PATCH-195 — Payment-link status view. Opened from the appointment
     banner (?status=<sale_id>) to show a live picture of an outstanding link. --}}
<div class="reg-modal-bg" id="linkStatusModal">
  <div class="reg-modal" style="max-width:560px">
    <h2 style="display:flex;align-items:center;gap:10px">Payment link status <span id="lsStatusPill" class="ls-pill"></span></h2>
    <div class="lede" id="lsHeader">Loading…</div>

    <div id="lsBody" style="margin-top:14px">
      <div class="ls-timeline" id="lsTimeline"></div>
    </div>

    <div class="ls-actions" id="lsActions" style="display:none;flex-direction:column;gap:8px;margin-top:16px">
      <div style="display:flex;gap:8px">
        <input type="text" id="lsUrl" readonly style="flex:1;font-size:11px;font-family:var(--ia-font-mono);background:var(--ia-surface-2);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-sm);padding:8px 10px;color:var(--ia-text-muted)">
        <button type="button" class="reg-btn-secondary" id="lsCopyBtn" style="padding:6px 12px;font-size:12px">Copy</button>
      </div>
    </div>

    <div class="reg-modal-actions" style="margin-top:18px;display:flex;justify-content:space-between;gap:10px">
      <button type="button" class="reg-btn-secondary" id="lsCancelLinkBtn" style="color:var(--ia-red,#F87171);display:none">Cancel link</button>
      <button type="button" class="reg-btn-primary" id="lsCloseBtn" style="margin-left:auto">Close</button>
    </div>
  </div>
</div>

<style>
  .ls-pill{font-size:11px;font-weight:600;padding:3px 9px;border-radius:100px}
  .ls-pill.pending{background:rgba(96,165,250,.12);color:#60A5FA}
  .ls-pill.paid{background:rgba(132,204,22,.12);color:#84CC16}
  .ls-pill.expired{background:rgba(251,191,36,.12);color:#FBBF24}
  .ls-timeline{position:relative;padding-left:22px}
  .ls-timeline:before{content:'';position:absolute;left:5px;top:6px;bottom:6px;width:1.5px;background:var(--ia-border)}
  .ls-te{position:relative;padding:7px 0}
  .ls-te:before{content:'';position:absolute;left:-21px;top:11px;width:9px;height:9px;border-radius:50%;background:var(--ia-surface);border:2px solid var(--ia-text-dim)}
  .ls-te.done:before{background:#84CC16;border-color:#84CC16}
  .ls-te.now:before{background:#60A5FA;border-color:#60A5FA}
  .ls-te .tt{font-size:13px;font-weight:500}
  .ls-te .td{font-size:11.5px;color:var(--ia-text-dim);font-family:var(--ia-font-mono);margin-top:1px}
</style>

<div class="reg-modal-bg" id="tipModal">
  <div class="reg-modal">
    <h2>Add tip?</h2>
    <div class="lede">Optional. Choose an amount or skip.</div>
    <div class="reg-tip-grid" id="tipGrid"></div>
    <div class="reg-tip-custom">
      <input type="text" id="tipCustomInput" placeholder="Custom amount">
      <button type="button" class="reg-btn-secondary" id="tipClearBtn" style="padding:10px 14px;flex:0">Clear</button>
    </div>
    <div class="reg-modal-actions">
      <button type="button" class="reg-btn-secondary" id="tipSkipBtn">Skip tip</button>
      <button type="button" class="reg-btn-primary" id="tipConfirmBtn">Add tip & continue</button>
    </div>
  </div>
</div>

<div class="reg-modal-bg" id="openItemModal">
  <div class="reg-modal">
    <h2>Custom item</h2>
    <div class="lede">For one-off items not in inventory.</div>
    <div style="margin-bottom:12px">
      <label style="display:block;font-size:12px;color:var(--ia-text-dim);margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em">Description</label>
      <input type="text" id="openItemName" placeholder="What is it?">
    </div>
    <div style="margin-bottom:6px">
      <label style="display:block;font-size:12px;color:var(--ia-text-dim);margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em">Price</label>
      <input type="text" id="openItemPrice" placeholder="0.00" inputmode="decimal">
    </div>
    <div class="reg-modal-actions">
      <button type="button" class="reg-btn-secondary" data-close-modal="openItemModal">Cancel</button>
      <button type="button" class="reg-btn-primary" id="openItemAddBtn">Add to cart</button>
    </div>
  </div>
</div>

<div class="reg-modal-bg" id="customerModal">
  <div class="reg-modal">
    <h2>Attach customer</h2>
    <div style="margin-bottom:12px;position:relative">
      <input type="text" id="customerSearchInput" placeholder="Name, email, or phone" autocomplete="off">
      <div class="reg-cust-results" id="customerResults" style="display:none"></div>
    </div>
    <div class="reg-modal-actions">
      <button type="button" class="reg-btn-secondary" data-close-modal="customerModal">Cancel</button>
    </div>
  </div>
</div>

<div class="reg-modal-bg" id="confirmModal" style="z-index:1100">
  <div class="reg-modal" style="max-width:380px">
    <h2 id="confirmTitle">Are you sure?</h2>
    <div class="lede" id="confirmMessage"></div>
    <div class="reg-modal-actions">
      <button type="button" class="reg-btn-secondary" id="confirmCancelBtn">Cancel</button>
      <button type="button" class="reg-btn-primary" id="confirmOkBtn">Confirm</button>
    </div>
  </div>
</div>

<div class="reg-modal-bg" id="draftsModal">
  <div class="reg-modal" style="max-width:560px">
    <h2>Open drafts</h2>
    <div class="lede">Carts saved at this location.</div>
    <div class="reg-drafts-list" id="draftsList"></div>
    <div class="reg-modal-actions">
      <button type="button" class="reg-btn-secondary" data-close-modal="draftsModal" style="flex:1">Close</button>
    </div>
  </div>
</div>

<div class="reg-modal-bg" id="quoteModal">
  <div class="reg-modal">
    <h2>Save as quote</h2>
    <div class="lede">The customer can come back later to pick up where they left off.</div>
    <div style="margin-bottom:12px">
      <label style="display:block;font-size:12px;color:var(--ia-text-dim);margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em">Notes (optional)</label>
      <input type="text" id="quoteNotesInput" placeholder="Anything the customer should know">
    </div>
    <div class="reg-modal-actions">
      <button type="button" class="reg-btn-secondary" data-close-modal="quoteModal">Cancel</button>
      <button type="button" class="reg-btn-primary" id="quoteSaveBtn">Save quote</button>
    </div>
  </div>
</div>

<div class="reg-modal-bg" id="refundModal">
  <div class="reg-modal" style="max-width:600px">
    <h2>Add refund items</h2>
    <div class="lede" id="refundModalLede">Select items to refund.</div>
    <div class="reg-refund-list" id="refundList"></div>
    <div class="reg-modal-actions">
      <button type="button" class="reg-btn-secondary" data-close-modal="refundModal">Cancel</button>
      <button type="button" class="reg-btn-primary" id="refundAddBtn" disabled>Add to transaction</button>
    </div>
  </div>
</div>

<div class="reg-modal-bg" id="receiptModal">
  <div class="reg-modal reg-receipt">
    <h2>Sale complete</h2>
    <div class="num" id="receiptNum"></div>
    <div class="total" id="receiptTotal"></div>
    {{-- MARKER-PATCH-322 — print + email the receipt for this sale --}}
    <div class="reg-receipt-actions" style="display:flex;gap:8px;justify-content:center;margin:6px 0 2px">
      <button type="button" class="reg-btn-secondary" id="receiptPrintBtn">Print receipt</button>
      <button type="button" class="reg-btn-secondary" id="receiptEmailBtn">Email receipt</button>
    </div>
    <div id="receiptEmailPrompt" style="display:none;gap:6px;justify-content:center;align-items:center;margin:8px 0 2px">
      <input type="email" id="receiptEmailInput" placeholder="customer@email.com"
        style="background:var(--ia-input-bg,#0a0a0a);border:0.5px solid var(--ia-border,rgba(255,255,255,.13));border-radius:8px;color:var(--ia-text,#f0f0f0);font-size:13px;padding:8px 11px;font-family:inherit;width:210px">
      <button type="button" class="reg-btn-primary" id="receiptEmailSend">Send</button>
    </div>
    <div id="receiptEmailMsg" style="display:none;text-align:center;font-size:12px;margin-top:6px;color:var(--ia-text-dim)"></div>
    <div class="reg-modal-actions">
      {{-- MARKER-PATCH-232B — shown only when the register was opened with a return_to. --}}
      <a id="receiptBackTo" class="reg-btn-primary" style="display:none;text-decoration:none" href="#">Back</a>
      <button type="button" class="reg-btn-primary" id="receiptNewSale">New sale</button>
    </div>
    {{-- MARKER-PATCH-187 — auto-reset countdown --}}
    <div class="reg-receipt-auto" id="receiptAutoReset">Returning to a fresh register in <span id="receiptCountdown">45</span>s</div>
  </div>
</div>


@if(!empty($preAttachCustomer))
<script>
  // Patch 46: pre-attach customer from walk-in flow query param.
  // Runs after the register page's cart JS has initialized.
  document.addEventListener('DOMContentLoaded', function() {
    if (typeof cart !== 'undefined' && cart) {
      cart.customer = @json($preAttachCustomer);
      if (typeof renderCart === 'function') renderCart();
      if (typeof queueDraftSave === 'function') queueDraftSave();
    }
  });
</script>
@endif

{{-- MARKER-PATCH-553 — item detail modal v2 (supersedes the 552 modal):
     gallery, brand header, permissioned cost/margin, badges, specs grid,
     stock table, action footer. --}}
<style>
  .reg-info-btn{flex:none;width:22px;height:22px;border-radius:50%;border:0.5px solid var(--ia-border);background:none;color:var(--ia-text-muted);font:italic 700 11px Georgia,serif;cursor:pointer;margin:0 10px;align-self:center}
  .reg-info-btn:hover{border-color:var(--ia-accent);color:var(--ia-accent)}
  #rim .rim-box{background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:16px;width:min(680px,calc(100vw - 28px));max-height:88vh;overflow-y:auto}
  #rim .rim-head{display:flex;gap:18px;padding:22px 24px 18px;border-bottom:0.5px solid var(--ia-border)}
  #rim .rim-gal{flex:none;width:150px}
  #rim .rim-main{width:150px;height:150px;background:#fff;border-radius:12px;object-fit:contain;display:none}
  #rim .rim-main.ph{display:grid;place-items:center;color:#999;font-size:11px;background:var(--ia-surface-2,#222)}
  #rim .rim-thumbs{display:flex;gap:6px;margin-top:8px}
  #rim .rim-thumbs img{width:33px;height:33px;background:#fff;border-radius:7px;object-fit:contain;opacity:.55;cursor:pointer;border:1.5px solid transparent}
  #rim .rim-thumbs img.on{opacity:1;border-color:var(--ia-accent)}
  #rim .rim-brand{font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:var(--ia-accent);font-weight:700}
  #rim h2{font-size:17px;line-height:1.35;margin:3px 0 4px;font-weight:700}
  #rim .rim-sub{font-size:12.5px;color:var(--ia-text-muted)}
  #rim .rim-price-row{display:flex;align-items:baseline;gap:14px;margin-top:12px;flex-wrap:wrap}
  #rim .rim-price{font:700 22px inherit;color:var(--ia-accent)}
  #rim .rim-cost{font-size:12px;color:var(--ia-text-muted)}
  #rim .rim-cost b{color:#8FD14F;font-weight:600}
  #rim .rim-badges{display:flex;gap:6px;margin-top:10px;flex-wrap:wrap}
  #rim .rim-badge{font-size:10.5px;border:0.5px solid var(--ia-border);border-radius:99px;padding:2px 9px;color:var(--ia-text-muted)}
  #rim .rim-badge.ok{color:#8FD14F;border-color:#8FD14F}
  #rim .rim-body{padding:6px 24px 8px}
  #rim .rim-sec{padding:14px 0;border-bottom:0.5px solid var(--ia-border)}
  #rim .rim-sec:last-child{border-bottom:0}
  #rim .rim-sec h3{font-size:10.5px;letter-spacing:.12em;text-transform:uppercase;color:var(--ia-text-muted);margin-bottom:9px;font-weight:600}
  #rim .rim-attrs{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:4px 22px;font-size:12.5px}
  #rim .rim-attrs .k{color:var(--ia-text-muted)}
  #rim table{width:100%;border-collapse:collapse;font-size:12.5px}
  #rim td{padding:5px 0;vertical-align:top}
  #rim td.k{color:var(--ia-text-muted);width:130px}
  #rim td.n{text-align:right;font-variant-numeric:tabular-nums}
  #rim .rim-foot{display:flex;gap:10px;padding:16px 24px 20px;border-top:0.5px solid var(--ia-border);position:sticky;bottom:0;background:var(--ia-surface)}
  #rim .rim-foot .grow{flex:1}
</style>
<div id="rim" style="display:none;position:fixed;inset:0;z-index:210;align-items:center;justify-content:center;background:rgba(0,0,0,.6)" onclick="if(event.target===this)this.style.display='none'">
  <div class="rim-box">
    <div class="rim-head">
      <div class="rim-gal">
        <img class="rim-main" id="rim-main" alt="">
        <div class="rim-main ph" id="rim-ph">no image</div>
        <div class="rim-thumbs" id="rim-thumbs"></div>
      </div>
      <div style="min-width:0">
        <div class="rim-brand" id="rim-brand"></div>
        <h2 id="rim-name"></h2>
        <div class="rim-sub" id="rim-sub"></div>
        <div class="rim-price-row">
          <span class="rim-price" id="rim-price"></span>
          <span class="rim-cost" id="rim-cost"></span>
        </div>
        <div class="rim-badges" id="rim-badges"></div>
      </div>
    </div>
    <div class="rim-body">
      <div class="rim-sec" id="rim-sec-desc" style="display:none"><h3>Description</h3><div id="rim-desc" style="font-size:12.5px;color:var(--ia-text-muted);line-height:1.55"></div></div>
      <div class="rim-sec" id="rim-sec-attrs" style="display:none"><h3>Specs</h3><div class="rim-attrs" id="rim-attrs"></div></div>
      <div class="rim-sec"><h3>Stock &amp; identifiers</h3><table id="rim-table"></table></div>
    </div>
    <div class="rim-foot">
      <a class="ia-btn ia-btn--ghost" id="rim-edit" href="#" style="text-decoration:none">Edit item</a>
      <button type="button" class="ia-btn ia-btn--ghost" onclick="document.getElementById('rim').style.display='none'">Close</button>
      <button type="button" class="ia-btn ia-btn--primary grow" id="rim-add">Add to sale</button>
    </div>
  </div>
</div>
<script>
// MARKER-PATCH-553
let rimItem = null;
async function openItemInfo(id) {
  const m = document.getElementById('rim');
  m.style.display = 'flex';
  rimItem = null;
  document.getElementById('rim-name').textContent = 'Loading…';
  ['rim-brand','rim-sub','rim-price','rim-cost','rim-desc'].forEach(x => document.getElementById(x).textContent = '');
  document.getElementById('rim-badges').innerHTML = '';
  document.getElementById('rim-attrs').innerHTML = '';
  document.getElementById('rim-table').innerHTML = '';
  document.getElementById('rim-thumbs').innerHTML = '';
  document.getElementById('rim-main').style.display = 'none';
  document.getElementById('rim-ph').style.display = 'grid';
  document.getElementById('rim-sec-desc').style.display = 'none';
  document.getElementById('rim-sec-attrs').style.display = 'none';
  try {
    const r = await fetch('/admin/register/item/' + encodeURIComponent(id) + '/info', { headers: { 'Accept': 'application/json' } });
    const d = await r.json();
    if (!d || !d.ok) throw new Error();
    rimItem = { type: 'product', source_id: id, name: d.name, price_cents: d.price_cents, is_taxable: d.taxable };

    document.getElementById('rim-brand').textContent = d.brand || '';
    document.getElementById('rim-name').textContent  = d.name || '';
    document.getElementById('rim-sub').textContent   = d.subtitle || '';
    document.getElementById('rim-price').textContent = fmt(d.price_cents);
    if (d.cost && d.cost.cost_cents) {
      document.getElementById('rim-cost').innerHTML = 'cost ' + fmt(d.cost.cost_cents)
        + (d.cost.margin_pct !== null ? ' · margin <b>' + d.cost.margin_pct + '%</b>' : '');
    }

    const imgs = d.images || [];
    if (imgs.length) {
      const main = document.getElementById('rim-main');
      main.src = imgs[0]; main.style.display = 'block';
      document.getElementById('rim-ph').style.display = 'none';
      if (imgs.length > 1) {
        document.getElementById('rim-thumbs').innerHTML = imgs.map((u, i) =>
          '<img src="' + u + '" class="' + (i === 0 ? 'on' : '') + '" onclick="rimSwap(this)">').join('');
      }
    }

    const here = (d.stock || []).reduce((a, s2) => a + (s2.count || 0), 0);
    const badges = [];
    badges.push('<span class="rim-badge ' + (here > 0 ? 'ok' : '') + '">' + here + ' in stock</span>');
    badges.push('<span class="rim-badge">' + (d.taxable ? 'taxable' : 'tax exempt') + '</span>');
    if (d.sold_30d > 0) badges.push('<span class="rim-badge">sold ' + (+d.sold_30d).toFixed(0) + ' in 30d</span>');
    document.getElementById('rim-badges').innerHTML = badges.join('');

    if (d.description) { document.getElementById('rim-desc').textContent = d.description; document.getElementById('rim-sec-desc').style.display = ''; }
    if (d.attrs && d.attrs.length) {
      document.getElementById('rim-attrs').innerHTML = d.attrs.map(a =>
        '<span class="k">' + escapeHtml(a.name) + '</span><span>' + escapeHtml(a.value) + '</span>').join('');
      document.getElementById('rim-sec-attrs').style.display = '';
    }

    const rows = [];
    (d.stock || []).forEach(s2 => rows.push(['<td class="k">' + escapeHtml(s2.location) + '</td>', '<td class="n">' + s2.count + '</td>']));
    if (d.sku)      rows.push(['<td class="k">SKU</td>', '<td class="n" style="font-family:ui-monospace,monospace;font-size:12px">' + escapeHtml(d.sku) + '</td>']);
    if (d.upc)      rows.push(['<td class="k">UPC</td>', '<td class="n" style="font-family:ui-monospace,monospace;font-size:12px">' + escapeHtml(d.upc) + '</td>']);
    if (d.category) rows.push(['<td class="k">Category</td>', '<td class="n">' + escapeHtml(d.category) + '</td>']);
    document.getElementById('rim-table').innerHTML = rows.map(r2 =>
      '<tr style="border-top:0.5px solid var(--ia-border)">' + r2.join('') + '</tr>').join('');

    document.getElementById('rim-edit').href = d.edit_url || '#';
    const add = document.getElementById('rim-add');
    add.textContent = 'Add to sale — ' + fmt(d.price_cents);
    add.onclick = () => {
      if (rimItem) addToCart(rimItem);
      document.getElementById('rim').style.display = 'none';
    };
  } catch (e) {
    document.getElementById('rim-name').textContent = 'Could not load item.';
  }
}
function rimSwap(el) {
  document.getElementById('rim-main').src = el.src;
  document.querySelectorAll('#rim-thumbs img').forEach(t => t.classList.remove('on'));
  el.classList.add('on');
}
</script>

@endsection

@push('scripts')
<script>
const ROUTES = {
  search:      @json(route('tenant.register.search')),
  storeSale:   @json(route('tenant.register.sales.store')),
  offlineCatalog: @json(route('tenant.register.offline_catalog')), // MARKER-OFFLINE-SYNC
  offlineSyncEnabled: {{ ($offlineSyncEnabled ?? false) ? 'true' : 'false' }}, // MARKER-OFFLINE-SYNC
  storeDraft:  @json(route('tenant.register.drafts.store')),
  listDrafts:  @json(route('tenant.register.drafts.index')),
  draftBase:   @json(url('/admin/register/drafts')),
  commitDraft: @json(url('/admin/register/drafts')),
  storeQuote:  @json(route('tenant.register.quotes.store')),
  quotesIndex: @json(route('tenant.register.quotes.index')),
  lookupSale:  @json(route('tenant.register.lookup-sale')),
  commitTxn:   @json(route('tenant.register.transactions.store')),
  // MARKER-PATCH-161
  customerBase: @json(url('/admin/customers')),
  // MARKER-PATCH-162
  multiLocationActive: {{ $multiLocationActive ? 'true' : 'false' }},
  // MARKER-PATCH-170 — Direct Payments
  directPaymentsEnabled: {{ (($tenant->direct_payments_enabled ?? false) && ($tenant->settings['stripe_register_enabled'] ?? true)) ? 'true' : 'false' }}, {{-- MARKER-PATCH-618 --}}
  directPaymentsPk: @json((($tenant->direct_payments_enabled ?? false) && ($tenant->settings['stripe_register_enabled'] ?? true)) ? (($tenant->settings['register_payments_mode'] ?? 'test') === 'live' ? ($tenant->settings['register_payments_live_pk'] ?? '') : ($tenant->settings['register_payments_test_pk'] ?? '')) : ''),
  paymentIntentCreate: @json(url('/admin/register/payment-intent')),
  paymentIntentConfirm: @json(url('/admin/register/payment-intent/confirm')),
  // MARKER-PATCH-170B
  paymentIntentAutoRefund: @json(url('/admin/register/payment-intent/auto-refund')),
  // MARKER-PATCH-172
  checkoutSessionCreate: @json(url('/admin/register/checkout-session')),
  checkoutSessionCheck:  @json(url('/admin/register/checkout-session/check')),
  saleShow:              @json(route('tenant.register.sales.show', ['id' => '__ID__'])), {{-- MARKER-PATCH-195 --}}
  saleReceipt:           @json(route('tenant.register.sales.receipt', ['id' => '__ID__'])), {{-- MARKER-PATCH-322 --}}
  resendReceipt:         @json(route('tenant.sales.resend_receipt', ['id' => '__ID__'])), {{-- MARKER-PATCH-322 --}}
  checkoutSessionCancel: @json(url('/admin/register/checkout-session/cancel')),
};
const CSRF = document.querySelector('meta[name=csrf-token]').content;

// MARKER-REGISTER-RECON-DISPLAY — customer display mirroring.
// Debounced snapshots of the cart are pushed to the currently selected
// register; a paired iPad polls that register's snapshot and renders it.
const DisplayMirror = {
  enabled: {{ ($currentRegisterId ?? 0) > 0 ? 'true' : 'false' }},
  payUrl: null,
  timer: null,
  stateUrl: @json(route('tenant.register.display_state')),
  selectUrl: @json(route('tenant.register.select')),
};
function displaySnapshot() {
  const items = [];
  for (const i of cart.items) items.push({ name: i.name, qty: i.qty, line_cents: Math.round(i.price_cents * i.qty) });
  for (const r of cart.refund_lines) items.push({ name: r.name, qty: r.qty, line_cents: Math.round(r.price_cents * r.qty), refund: true });
  const sub = calcSubtotal() - calcRefundSubtotal();
  const tax = calcTax();
  const surch = calcSurcharge();
  const total = (calcSubtotal() - cart.discountCents + tax + surch + cart.tipCents) - (calcRefundSubtotal());
  return {
    state: DisplayMirror.payUrl ? 'pay' : (items.length ? 'cart' : 'idle'),
    items,
    subtotal_cents: sub,
    discount_cents: cart.discountCents,
    tax_cents: tax,
    tax_label: CFG.taxLabel || null,
    surcharge_cents: surch,
    tip_cents: cart.tipCents,
    total_cents: Math.max(0, Math.round(total)),
    pay_url: DisplayMirror.payUrl,
  };
}
function queueDisplayMirror(immediate = false) {
  if (!DisplayMirror.enabled) return;
  clearTimeout(DisplayMirror.timer);
  DisplayMirror.timer = setTimeout(() => {
    fetch(DisplayMirror.stateUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify(displaySnapshot()),
    }).catch(() => {});
  }, immediate ? 0 : 400);
}

// MARKER-OFFLINE-SYNC stage 3 — register-specific offline behavior.
// Core (outbox, snapshot, replay, SW registration, status pill) lives in the
// global /js/offline-sync.js module loaded by the layout on every admin page;
// this block only handles what's unique to the register: queueing a commit,
// snapshot search, and disabling network-only tenders.
function osToggleTenders(online) {
  document.querySelectorAll('.reg-tender-btn').forEach(b => {
    const t = b.dataset.tender || b.dataset.refundTender;
    if (t === 'card' || t === 'payment_link') {
      const block = !online && window.IntakeOffline && IntakeOffline.enabled && !IntakeOffline.paused;
      b.disabled = block;
      b.style.opacity = block ? '.35' : '';
      b.title = block ? 'Unavailable offline' : '';
    }
  });
}
document.addEventListener('intake-offline-status', e => osToggleTenders(e.detail.online));
if (window.IntakeOffline) osToggleTenders(navigator.onLine);

function osBuildSalePayload(){
  return {
    client_uuid: IntakeOffline.uuid(),
    customer_id: cart.customer ? cart.customer.id : null,
    tip_cents: cart.tipCents,
    discount_cents: cart.discountCents,
    payment_method: cart.payment_method,
    payment_reference: cart.payment_reference,
    items: cart.items.map(serializeLine),
    skip_receipt: cart.skipReceipt ? 1 : 0,
  };
}
async function osTryQueueCommit(){
  const io = window.IntakeOffline;
  if (!io || !io.enabled || io.paused || !io.db) return false;
  if (cart.refund_lines.length > 0) return false;
  if (cart.stripe_payment_intent_id) return false;
  if (cart.payment_method === 'card' || cart.payment_method === 'payment_link') return false;
  if (!cart.items.length) return false;
  await io.queueSale(osBuildSalePayload());
  cart.items = []; cart.refund_lines = []; cart.refund_meta = null;
  cart.customer = null; cart.tipCents = 0; cart.discountCents = 0;
  cart.payment_method = null; cart.payments = []; if (typeof renderSplit === 'function') renderSplit(); /* MARKER-SPLIT-TENDER */ cart.payment_reference = null;
  cart.draft_id = null; cart.skipReceipt = false;
  renderCart();
  showError('Saved offline — this sale will sync automatically when the connection returns.');
  return true;
}
function osSearchSnapshot(q){
  return (window.IntakeOffline && IntakeOffline.enabled && !IntakeOffline.paused)
    ? IntakeOffline.snapshotSearch(q) : null;
}

const registerPickerEl = document.getElementById('registerPicker');
if (registerPickerEl) {
  registerPickerEl.addEventListener('change', async () => {
    const id = parseInt(registerPickerEl.value, 10) || 0;
    try {
      await fetch(DisplayMirror.selectUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
        body: JSON.stringify({ register_id: id }),
      });
      DisplayMirror.enabled = id > 0;
      queueDisplayMirror(true);
    } catch (e) {}
  });
}
const CFG = {
  taxRate:        {{ $taxRate ?? 0 }},
  taxLabel:       @json($taxLabel ?? ''),
  tipsEnabled:    {{ $tipsConfig['enabled'] ? 'true' : 'false' }},
  tipMethod:      @json($tipsConfig['method'] ?? null),
  tipOptions:     @json($tipsConfig['options'] ?? []),
  tipAllowCustom: {{ $tipsConfig['allow_custom'] ? 'true' : 'false' }},
  surchargeOn:    {{ $surchargeConfig['enabled'] ? 'true' : 'false' }},
  surchargePct:   {{ $surchargeConfig['percent'] ?? 0 }},
  surchargeLabel: @json($surchargeConfig['label'] ?? 'Surcharge'),
};

// Reusable confirm dialog. Returns a promise that resolves true/false.
// Usage: const ok = await confirmDialog('Replace cart?', 'Replace');
function confirmDialog(message, confirmLabel = 'Confirm', title = 'Are you sure?') {
  return new Promise(resolve => {
    document.getElementById('confirmTitle').textContent = title;
    document.getElementById('confirmMessage').textContent = message;
    const okBtn = document.getElementById('confirmOkBtn');
    const cancelBtn = document.getElementById('confirmCancelBtn');
    okBtn.textContent = confirmLabel;
    const cleanup = (result) => {
      okBtn.removeEventListener('click', onOk);
      cancelBtn.removeEventListener('click', onCancel);
      closeModal('confirmModal');
      resolve(result);
    };
    const onOk = () => cleanup(true);
    const onCancel = () => cleanup(false);
    okBtn.addEventListener('click', onOk);
    cancelBtn.addEventListener('click', onCancel);
    openModal('confirmModal');
  });
}

const cart = {
  draft_id: null,
  customer: null,
  items: [],            // new-sale lines
  refund_lines: [],     // refund lines, each: {key, original_sale_id, original_item_id, name, qty, price_cents, type}
  refund_meta: null,    // {original_sale_id, original_sale_number, refund_method} — set when first refund line added
  tipCents: 0, discountCents: 0,
  payment_method: null, payment_reference: null,
  payments: [], // MARKER-SPLIT-TENDER
  tax_locked: false,    // when true, calcTax sums per-line tax_cents instead of computing from rate
  skipReceipt: false,   // MARKER-PATCH-161 — cashier opted out of receipt for this sale
};
const fmt = (cents) => '$' + (cents / 100).toFixed(2);
const fmtNeg = (cents) => '-$' + (cents / 100).toFixed(2);
let lineKey = 0;

// --- Draft auto-save infrastructure ---
// Cart changes debounce a save to /register/drafts. First save creates the
// draft and stores its id on cart.draft_id. Subsequent saves include the id
// to update in place. Mark Paid awaits any pending save, then commits.
const DRAFT_DEBOUNCE_MS = 1500;
let draftSaveTimer = null;
let draftSaveInFlight = null; // Promise of currently-firing save, or null.

function buildDraftPayload() {
  return {
    id: cart.draft_id,
    customer_id: cart.customer ? cart.customer.id : null,
    tip_cents: cart.tipCents,
    items: cart.items.map(i => {
      const out = { type: i.type, quantity: i.qty, is_taxable: i.is_taxable };
      // Round-trip per-line tax for tax_locked sales so recalc preserves it.
      if (cart.tax_locked) {
        out.tax_cents = i.tax_cents || 0;
        if (i.tax_rate_snapshot != null) out.tax_rate_snapshot = i.tax_rate_snapshot;
      }
      if (i.type === 'product') out.inventory_item_id = i.source_id;
      if (i.type === 'service') out.service_id = i.source_id;
      if (i.type === 'open_item') {
        out.name_snapshot = i.name;
        out.unit_price_cents = i.price_cents;
      }
      return out;
    }),
  };
}

async function fireDraftSave() {
  // If a save is already in flight, wait for it and re-queue this one.
  // Last-write-wins: the next save will include the latest cart state.
  if (draftSaveInFlight) {
    await draftSaveInFlight;
  }
  const payload = buildDraftPayload();
  draftSaveInFlight = (async () => {
    try {
      const res = await fetch(ROUTES.storeDraft, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (data.ok && data.draft_id) {
        cart.draft_id = data.draft_id;
        setSaveStatus('saved');
      }
    } catch (e) {
      // Silent failure on auto-save. Cart still works locally; commit will
      // fall back to the storeSale path if draft_id is still null.
      console.warn('[draft] save failed', e);
      setSaveStatus('idle');
    } finally {
      draftSaveInFlight = null;
    }
  })();
  return draftSaveInFlight;
}

function queueDraftSave() {
  // Empty cart with no existing draft — nothing to save.
  if (!cart.items.length && !cart.draft_id) return;
  clearTimeout(draftSaveTimer);
  draftSaveTimer = setTimeout(fireDraftSave, DRAFT_DEBOUNCE_MS);
  setSaveStatus('pending');
}

let saveStatusTimer = null;
function setSaveStatus(state) {
  const el = document.getElementById('saveStatus');
  if (!el) return;
  clearTimeout(saveStatusTimer);
  if (state === 'pending' || state === 'saving') {
    el.textContent = 'Saving…';
    el.classList.add('visible');
  } else if (state === 'saved') {
    el.textContent = 'Saved';
    el.classList.add('visible');
    saveStatusTimer = setTimeout(() => el.classList.remove('visible'), 1500);
  } else {
    el.classList.remove('visible');
  }
}

async function flushDraftSave() {
  // Cancel any pending debounce, fire immediately, await any in-flight save.
  clearTimeout(draftSaveTimer);
  draftSaveTimer = null;
  if (cart.items.length || cart.draft_id) {
    await fireDraftSave();
  }
  if (draftSaveInFlight) await draftSaveInFlight;
}

const searchInput = document.getElementById('searchInput');
const resultsArea = document.getElementById('resultsArea');
let searchType = 'all';
let searchTimer = null;

document.querySelectorAll('.reg-tab').forEach(tab => {
  tab.addEventListener('click', () => {
    document.querySelectorAll('.reg-tab').forEach(t => t.classList.remove('active'));
    tab.classList.add('active');
    searchType = tab.dataset.type;
    runSearch();
  });
});
searchInput.addEventListener('input', () => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(runSearch, 250);
});

// Detect sale-number pattern: S-YYYYMMDD-NNN (case-insensitive, optional spaces around dashes)
function looksLikeSaleNumber(q) {
  return /^s[\s-]*\d{8}[\s-]*\d{1,4}$/i.test(q.trim());
}
function normalizeSaleNumber(q) {
  return q.trim().toUpperCase().replace(/\s+/g, '').replace(/^S(\d)/, 'S-$1').replace(/(\d{8})(\d)/, '$1-$2');
}

async function runSearch() {
  const q = searchInput.value.trim();
  if (q.length < 2) {
    resultsArea.innerHTML = '<div class="reg-empty">Type to search products and services.</div>';
    return;
  }

  // Sale-number lookup runs in parallel with regular search.
  let refundResult = null;
  if (looksLikeSaleNumber(q)) {
    try {
      const lookupUrl = new URL(ROUTES.lookupSale, window.location.origin);
      lookupUrl.searchParams.set('sale_number', normalizeSaleNumber(q));
      const r = await fetch(lookupUrl, {headers: {'Accept': 'application/json'}});
      const d = await r.json();
      if (d.ok) refundResult = d.sale;
    } catch (e) { /* silent — fall through to regular search */ }
  }

  try {
    const url = new URL(ROUTES.search, window.location.origin);
    url.searchParams.set('q', q);
    url.searchParams.set('type', searchType);
    const res = await fetch(url, {headers: {'Accept': 'application/json'}});
    const data = await res.json();
    renderResults(data, refundResult);
  } catch (e) {
    // MARKER-OFFLINE-SYNC — offline: search the cached catalog snapshot.
    const snap = osSearchSnapshot(q);
    if (snap && (snap.products.length || snap.services.length)) {
      renderResults(snap, null);
      resultsArea.insertAdjacentHTML('afterbegin',
        '<div style="font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#F5C56B;margin-bottom:8px">Offline — cached catalog snapshot</div>');
    } else {
      resultsArea.innerHTML = '<div class="reg-empty">' + (!navigator.onLine ? 'Offline — no cached matches.' : 'Search failed.') + '</div>';
    }
  }
}

// Keyboard nav state
let highlighted = 0;
let visibleResults = [];

function renderResults(data, refundResult) {
  let html = '';
  visibleResults = [];

  // If a refund-eligible sale was matched, render it first as a distinctive card.
  if (refundResult) {
    html += '<div class="reg-refund-result" data-refund-sale="' + refundResult.id + '">';
    html +=   '<div class="label">Refund from sale</div>';
    html +=   '<div class="name">#' + escapeHtml(refundResult.sale_number) + '</div>';
    html +=   '<div class="meta">' + (refundResult.customer ? escapeHtml(refundResult.customer) + ' · ' : '');
    html +=     fmt(refundResult.total_cents) + ' · ' + (refundResult.items.length) + ' items</div>';
    html += '</div>';
  }

  if (data.products && data.products.length) {
    html += '<div class="reg-results-section"><h3>Products</h3>';
    data.products.forEach(p => {
      visibleResults.push({type:'product',source_id:p.id,name:p.name,price_cents:p.price_cents,is_taxable:p.is_taxable,current_location_stock:p.current_location_stock,current_location_name:p.current_location_name});
      const idx = visibleResults.length - 1;
      html += `<div class="reg-row" data-i="${idx}">
        <div><div class="name">${escapeHtml(p.name)}</div><div class="meta">${escapeHtml(p.subtitle || p.sku || '')}</div></div>
        <button type="button" class="reg-info-btn" data-item-id="${p.id}" title="Item details" aria-label="Item details">i</button>
        <div class="price">${fmt(p.price_cents)}</div>
      </div>`;
    });
    html += '</div>';
  }
  if (data.services && data.services.length) {
    html += '<div class="reg-results-section mouse-defer"><h3>Services</h3>';
    data.services.forEach(s => {
      visibleResults.push({type:'service',source_id:s.id,name:s.name,price_cents:s.price_cents,is_taxable:true});
      const idx = visibleResults.length - 1;
      html += `<div class="reg-row" data-i="${idx}">
        <div><div class="name">${escapeHtml(s.name)}</div><div class="meta">${s.duration_minutes || 0} min</div></div>
        <div class="price">${fmt(s.price_cents)}</div>
      </div>`;
    });
    html += '</div>';
  }
  if (!html) html = '<div class="reg-empty">No matches.</div>';
  resultsArea.innerHTML = html;

  // Show/hide keyboard hint based on whether results exist
  const hint = document.getElementById('regHint');
  hint.style.display = visibleResults.length ? '' : 'none';

  // Reset highlight to first row
  if (highlighted >= visibleResults.length) highlighted = 0;
  applyHighlight();

  // MARKER-PATCH-552 — info buttons open the item modal; stop the row's add-to-cart
  resultsArea.querySelectorAll('.reg-info-btn').forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      openItemInfo(btn.dataset.itemId);
    });
  });

  // Click handler — add the row's item, then clear search and refocus (same as Enter)
  resultsArea.querySelectorAll('[data-i]').forEach(row => {
    row.addEventListener('click', () => {
      const i = parseInt(row.dataset.i, 10);
      addToCart(visibleResults[i]);
      searchInput.value = '';
      visibleResults = [];
      highlighted = 0;
      resultsArea.innerHTML = '<div class="reg-empty">Type to search products and services.</div>';
      document.getElementById('regHint').style.display = 'none';
      searchInput.focus();
    });
  });

  // Wire refund-result click → open picker modal.
  const refundEl = resultsArea.querySelector('[data-refund-sale]');
  if (refundEl) {
    // Stash the refund result on the element via dataset for the click handler.
    refundEl.addEventListener('click', () => {
      // Re-fetch the sale to get fresh refundable quantities (in case anything changed).
      const saleId = refundEl.dataset.refundSale;
      openRefundPicker(saleId);
    });
  }

  // Wire mouse-active class to the search panel's results sections
  resultsArea.querySelectorAll('.reg-results-section').forEach(section => {
    section.addEventListener('mouseenter', () => section.classList.add('mouse-active'));
    section.addEventListener('mouseleave', () => section.classList.remove('mouse-active'));
  });
}

function applyHighlight() {
  resultsArea.querySelectorAll('.reg-row').forEach((row, i) => {
    if (parseInt(row.dataset.i, 10) === highlighted) {
      row.classList.add('highlighted');
    } else {
      row.classList.remove('highlighted');
    }
  });
}

// Keyboard navigation on the search input
searchInput.addEventListener('keydown', (e) => {
  if (e.key === 'ArrowDown') {
    e.preventDefault();
    if (highlighted < visibleResults.length - 1) { highlighted++; applyHighlight(); }
  } else if (e.key === 'ArrowUp') {
    e.preventDefault();
    if (highlighted > 0) { highlighted--; applyHighlight(); }
  } else if (e.key === 'Enter') {
    e.preventDefault();
    if (visibleResults[highlighted]) {
      addToCart(visibleResults[highlighted]);
      // Clear search and refocus for next item
      searchInput.value = '';
      visibleResults = [];
      highlighted = 0;
      resultsArea.innerHTML = '<div class="reg-empty">Type to search products and services.</div>';
      document.getElementById('regHint').style.display = 'none';
      searchInput.focus();
    }
  } else if (e.key === 'Escape') {
    searchInput.value = '';
    visibleResults = [];
    highlighted = 0;
    resultsArea.innerHTML = '<div class="reg-empty">Type to search products and services.</div>';
    document.getElementById('regHint').style.display = 'none';
  }
});

function escapeHtml(s) {
  const div = document.createElement('div');
  div.textContent = s || '';
  return div.innerHTML;
}

function addToCart(item) {
  // patch-96 cart-meta + patch-100a oversell-actions — store stock data
  // and any action-state (transfer / SO) on the cart line so it persists
  // through re-renders and draft saves.
  cart.items.push({
    key: ++lineKey, type: item.type, source_id: item.source_id,
    name: item.name, price_cents: item.price_cents, qty: 1,
    is_taxable: item.is_taxable !== false,
    current_location_stock: (typeof item.current_location_stock === 'number')
      ? item.current_location_stock : null,
    current_location_name: item.current_location_name || null,
    transfer_request_id: null,
    transfer_request_from: null,
    special_order_id: null,
    so_number: null,
  });
  renderCart();
  queueDraftSave();
}

// patch-100a oversell-actions — handlers for the two action buttons.
// Both find the cart line, POST to the endpoint, then mutate the line's
// state fields so the next renderCart() swaps button for pill.

function requestTransferForLine(key) {
  const line = cart.items.find(i => i.key === key);
  if (!line || line.transfer_request_id) return;
  fetch('{{ route('tenant.register.oversell.transfer-request') }}', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
      'Accept': 'application/json',
    },
    body: JSON.stringify({
      inventory_item_id: line.source_id,
      quantity: Math.max(1, Math.ceil(line.qty)),
    }),
  })
  .then(r => r.json())
  .then(data => {
    if (data.ok) {
      line.transfer_request_id = data.transfer_request_id;
      line.transfer_request_from = data.from_location_name || null;
      renderCart();
      queueDraftSave();
    } else {
      alert('Transfer request failed: ' + (data.error || 'unknown error'));
    }
  })
  .catch(err => alert('Transfer request error: ' + err.message));
}

function addToOrderForLine(key, retried) {
  const line = cart.items.find(i => i.key === key);
  if (!line || line.special_order_id) return;

  // MARKER-SO-DRAFT-RACE — draft saving is debounced, so a fast click could
  // create the order before cart.draft_id existed, leaving it with no sale
  // link — exactly the orphan class this feature exists to prevent. Flush
  // the draft first and wait for its id.
  if (!cart.draft_id && !retried && typeof fireDraftSave === 'function') {
    Promise.resolve(fireDraftSave())
      .then(function () { addToOrderForLine(key, true); })
      .catch(function () { addToOrderForLine(key, true); }); // proceed unlinked rather than blocking the sale
    return;
  }
  fetch('{{ route('tenant.register.oversell.special-order') }}', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
      'Accept': 'application/json',
    },
    body: JSON.stringify({
      inventory_item_id: line.source_id,
      quantity: Math.max(1, Math.ceil(line.qty)),
      customer_id: cart.customer_id || null,
      sale_id: cart.draft_id || null, // MARKER-SO-SALE-LINK — lets the server clean up later
    }),
  })
  .then(r => r.json())
  .then(data => {
    if (data.ok) {
      line.special_order_id = data.special_order_id;
      line.so_number = data.so_number;
      renderCart();
      queueDraftSave();
    } else {
      alert('Add to order failed: ' + (data.error || 'unknown error'));
    }
  })
  .catch(err => alert('Add to order error: ' + err.message));
}

function removeLine(key) {
  // MARKER-SO-SALE-LINK — a line that requested a special order takes that
  // request with it. Only retracts orders still in "needed"; anything already
  // placed with a vendor is left alone and reported, since goods may be
  // inbound. Same rule as removing a part from an appointment.
  const line = cart.items.find(i => i.key === key);
  if (line && line.special_order_id) {
    const soUrl = @json(route('tenant.special-orders.cancel', ['id' => '__ID__']));
    fetch(soUrl.replace('__ID__', line.special_order_id), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify({ reason: 'Line removed from register sale.' }),
    })
      .then(r => r.json())
      .then(d => {
        if (d && d.ok) {
          if (window.IntakeToast) IntakeToast.success('Special order ' + (line.so_number || '') + ' cancelled');
        } else if (window.IntakeToast) {
          IntakeToast.error('Line removed, but ' + (line.so_number || 'the special order') + ' is already placed — check Special orders');
        }
      })
      .catch(() => {
        if (window.IntakeToast) IntakeToast.error('Line removed, but ' + (line.so_number || 'the special order') + ' may still be open');
      });
  }

  cart.items = cart.items.filter(i => i.key !== key);
  renderCart();
  queueDraftSave();
}
function updateQty(key, qty) {
  const n = parseFloat(qty);
  if (isNaN(n) || n <= 0) { removeLine(key); return; }
  const line = cart.items.find(i => i.key === key);
  if (line) line.qty = n;
  renderCart();
  queueDraftSave();
}

function renderCart() {
  const lines = document.getElementById('cartLines');
  const totalCount = cart.items.length + cart.refund_lines.length;
  if (totalCount === 0) {
    lines.innerHTML = '<div class="reg-empty">Cart is empty.</div>';
    document.getElementById('payBtn').disabled = true;
    document.getElementById('quoteBtn').disabled = true;
  } else {
    let html = '';

    // Refund section — render first (visually on top) when present.
    if (cart.refund_lines.length > 0) {
      html += '<div class="reg-cart-section-label refund">Returning to customer · sale #' +
        escapeHtml(cart.refund_meta?.original_sale_number ?? '') + '</div>';
      html += cart.refund_lines.map(r => `
        <div class="reg-line refund-line">
          <div>
            <div class="name">${escapeHtml(r.name)}</div>
            <div class="meta">refund · ${r.qty} × ${fmt(r.price_cents)}</div>
            ${r.type === 'product' ? `
            <div class="meta" style="margin-top:6px;display:flex;align-items:center;gap:6px">
              <span style="opacity:.65">Goes to</span>
              <select class="reg-dispo" data-dispo="${r.key}"
                      style="background:transparent;border:1px solid var(--ia-border);border-radius:7px;color:inherit;font-family:inherit;font-size:11.5px;padding:3px 6px">
                <option value="restock"${(r.disposition||'restock')==='restock'?' selected':''}>Restock — sellable</option>
                <option value="open_box"${r.disposition==='open_box'?' selected':''}>Open box — sellable</option>
                <option value="damaged"${r.disposition==='damaged'?' selected':''}>Damaged</option>
                <option value="defective"${r.disposition==='defective'?' selected':''}>Defective</option>
                <option value="warranty_hold"${r.disposition==='warranty_hold'?' selected':''}>Warranty hold</option>
                <option value="return_vendor"${r.disposition==='return_vendor'?' selected':''}>Return to vendor</option>
                <option value="scrap"${r.disposition==='scrap'?' selected':''}>Scrap</option>
                <option value="customer_keeps"${r.disposition==='customer_keeps'?' selected':''}>Customer keeps item</option>
              </select>
              ${['restock','open_box'].includes(r.disposition||'restock')
                ? '<span style="color:var(--ia-accent)">back to stock</span>'
                : (r.disposition === 'customer_keeps' ? '<span style="opacity:.6">no stock change</span>' : '<span style="color:#F5C56B">off the shelf</span>')}
            </div>` : ''}
          </div>
          <div></div>
          <div style="display:flex;align-items:center;gap:6px">
            <span class="total">-${fmt(Math.round(r.price_cents * r.qty))}</span>
            <button type="button" class="remove" data-remove-refund="${r.key}">×</button>
          </div>
        </div>
      `).join('');
    }

    // New-sale section
    if (cart.items.length > 0) {
      if (cart.refund_lines.length > 0) {
        html += '<div class="reg-cart-section-label">Adding to cart</div>';
      }
      html += cart.items.map(i => {
        // patch-96 oversell-badge + patch-100a oversell-actions — show badge
        // and an action row below the line when the qty exceeds local stock.
        let badge = '';
        let actionRow = '';
        const isOversold = typeof i.current_location_stock === 'number'
                           && i.qty > i.current_location_stock;
        if (isOversold) {
          const overBy = i.qty - i.current_location_stock;
          const locLabel = i.current_location_name ? ' at ' + escapeHtml(i.current_location_name) : '';
          badge = `<span class="reg-oversell-badge" title="Stock will go to ${i.current_location_stock - i.qty}${locLabel}">⚠ short ${overBy}${locLabel}</span>`;

          // Action row: each button is either active (button) or already-fired (pill).
          // MARKER-PATCH-162 — transfer button only renders when the tenant
          // has 2+ active locations to move stock between. Single-location
          // tenants still see the pill if a transfer was previously created
          // (orphan rows pre-patch), but can't create new ones.
          let transferBtn = '';
          if (i.transfer_request_id) {
            const fromLabel = i.transfer_request_from ? ' from ' + escapeHtml(i.transfer_request_from) : '';
            transferBtn = `<span class="reg-oversell-pill">✓ Transfer requested${fromLabel}</span>`;
          } else if (ROUTES.multiLocationActive && i.type === 'product' && i.source_id) {
            transferBtn = `<button type="button" class="reg-oversell-btn" data-action="transfer" data-key="${i.key}">Request transfer</button>`;
          }

          let soBtn = '';
          if (i.special_order_id) {
            soBtn = `<span class="reg-oversell-pill">✓ ${escapeHtml(i.so_number || 'SO created')}</span>`;
          } else if (i.type === 'product' && i.source_id) {
            soBtn = `<button type="button" class="reg-oversell-btn" data-action="so" data-key="${i.key}">Add to order</button>`;
          }

          if (transferBtn || soBtn) {
            actionRow = `<div class="reg-oversell-actions">${transferBtn}${soBtn}</div>`;
          }
        }

        return `
        <div class="reg-line">
          <div>
            <div class="name">${escapeHtml(i.name)} ${badge}</div>
            <div class="meta">${fmt(i.price_cents)} · ${i.type}</div>
            ${actionRow}
          </div>
          <input type="text" class="qty" value="${i.qty}" data-key="${i.key}" inputmode="decimal">
          <div style="display:flex;align-items:center;gap:6px">
            <span class="total">${fmt(Math.round(i.price_cents * i.qty))}</span>
            <button type="button" class="remove" data-remove="${i.key}">×</button>
          </div>
        </div>
      `;
      }).join('');
    }

    lines.innerHTML = html;
    document.getElementById('payBtn').disabled = false;
    document.getElementById('quoteBtn').disabled = false;
  }
  lines.querySelectorAll('[data-key]').forEach(input => {
    input.addEventListener('change', () => updateQty(parseInt(input.dataset.key, 10), input.value));
  });
  lines.querySelectorAll('[data-remove]').forEach(btn => {
    btn.addEventListener('click', () => removeLine(parseInt(btn.dataset.remove, 10)));
  });
  // patch-100a oversell-actions — wire the action buttons
  lines.querySelectorAll('[data-action="transfer"]').forEach(btn => {
    btn.addEventListener('click', () => requestTransferForLine(parseInt(btn.dataset.key, 10)));
  });
  lines.querySelectorAll('[data-action="so"]').forEach(btn => {
    btn.addEventListener('click', () => addToOrderForLine(parseInt(btn.dataset.key, 10)));
  });
  lines.querySelectorAll('[data-remove-refund]').forEach(btn => {
    btn.addEventListener('click', () => {
      const key = parseInt(btn.dataset.removeRefund, 10);
      cart.refund_lines = cart.refund_lines.filter(r => r.key !== key);
      if (cart.refund_lines.length === 0) cart.refund_meta = null;
      renderCart();
    });
  });
  // MARKER-REFUND-QTY — where the returned goods go, per line.
  lines.querySelectorAll('[data-dispo]').forEach(sel => {
    sel.addEventListener('change', () => {
      const key = parseInt(sel.dataset.dispo, 10);
      const line = cart.refund_lines.find(r => r.key === key);
      if (line) { line.disposition = sel.value; renderCart(); }
    });
  });

  const slot = document.getElementById('customerSlot');
  if (cart.customer) {
    const c = cart.customer;
    const profileUrl = ROUTES.customerBase + '/' + encodeURIComponent(c.id);
    const emailRow = c.email
      ? `<a href="mailto:${escapeHtml(c.email)}">${escapeHtml(c.email)}</a>`
      : '';
    const phoneRow = c.phone
      ? `<a href="tel:${escapeHtml(c.phone)}">${escapeHtml(c.phone)}</a>`
      : '';
    const metaInner = (emailRow || phoneRow)
      ? `<div class="meta">${emailRow}${phoneRow}</div>`
      : '';
    // MARKER-PATCH-161 — receipt indicator
    const hasEmail = !!c.email;
    const skipChecked = cart.skipReceipt ? 'checked' : '';
    const receiptRow = hasEmail
      ? `<div class="reg-cust-receipt">
           <span class="reg-cust-receipt-status">
             <span class="reg-cust-receipt-dot"></span>
             Receipt will email to <b>${escapeHtml(c.email)}</b>
           </span>
           <label class="reg-cust-receipt-skip">
             <input type="checkbox" id="skipReceiptChk" ${skipChecked}>
             Skip receipt
           </label>
         </div>`
      : `<div class="reg-cust-receipt reg-cust-receipt--none">
           <span class="reg-cust-receipt-status">No email on file — no receipt will send</span>
         </div>`;

    slot.innerHTML = `
      <div class="reg-cust">
        <div class="head">
          <span class="name">${escapeHtml(c.name || '(no name)')}</span>
        </div>
        ${metaInner}
        ${receiptRow}
        <div class="actions">
          <a class="profile-link" href="${profileUrl}" target="_blank" rel="noopener">View profile →</a>
          <span class="clear" id="clearCust">Remove</span>
        </div>
      </div>`;
    var skipChk = document.getElementById('skipReceiptChk');
    if (skipChk) {
      skipChk.addEventListener('change', function(){
        cart.skipReceipt = !!skipChk.checked;
      });
    }
    document.getElementById('clearCust').addEventListener('click', () => {
      cart.customer = null;
      cart.skipReceipt = false; // MARKER-PATCH-161
      renderCart();
      queueDraftSave();
    });
    // Customer is now attached — clear any prior warning.
    if (customerWarningActive) applyCustomerWarning(false);
  } else {
    slot.innerHTML = `<button type="button" class="reg-attach" id="attachCustBtn">+ Attach customer</button>`;
    document.getElementById('attachCustBtn').addEventListener('click', openCustomerModal);
    // Re-apply warning class if a prior quote attempt set it.
    if (customerWarningActive) applyCustomerWarning(true);
  }
  renderTotals();
}

function calcSubtotal() { return cart.items.reduce((sum, i) => sum + Math.round(i.price_cents * i.qty), 0); }
function calcRefundSubtotal() {
  return cart.refund_lines.reduce((sum, r) => sum + Math.round(r.price_cents * r.qty), 0);
}
// Refund-line tax: snapshot from the original sale, summed across refund lines.
// Always honor the snapshot — refunds preserve historical tax even if rate changed.
function calcRefundTax() {
  return cart.refund_lines.reduce((s, r) => s + (r.tax_cents || 0), 0);
}
function calcTax() {
  // tax_locked: per-line tax was set externally (e.g. by the appointment bridge).
  if (cart.tax_locked) {
    return cart.items.reduce((s, i) => s + (i.tax_cents || 0), 0);
  }
  if (!CFG.taxRate) return 0;
  let taxable = 0;
  cart.items.forEach(i => { if (i.is_taxable) taxable += Math.round(i.price_cents * i.qty); });
  return Math.round(taxable * (CFG.taxRate / 100));
}
function calcSurcharge() {
  if (!CFG.surchargeOn) return 0;
  if (cart.payment_method !== 'card') return 0;
  return Math.round(calcSubtotal() * (CFG.surchargePct / 100));
}

function renderTotals() {
  const sub = calcSubtotal();
  const refundSub = calcRefundSubtotal();
  const tax = calcTax();
  const refundTax = calcRefundTax();
  const surch = calcSurcharge();
  const tip = cart.tipCents;
  const disc = cart.discountCents;

  // Display values reflect the NET cart (new lines minus refund lines).
  // Total = (subtotal - discount + tax + surcharge + tip) - (refund subtotal + refund tax).
  const netSub   = sub - refundSub;
  const netTax   = tax - refundTax;
  const total    = (sub - disc + tax + surch + tip) - (refundSub + refundTax);

  document.getElementById('subVal').textContent = fmt(netSub);
  document.getElementById('taxVal').textContent = fmt(netTax);
  document.getElementById('totalVal').textContent = fmt(total);

  if (disc > 0) { document.getElementById('discountRow').style.display = ''; document.getElementById('discVal').textContent = fmtNeg(disc); }
  else { document.getElementById('discountRow').style.display = 'none'; }
  if (surch > 0) { document.getElementById('surchargeRow').style.display = ''; document.getElementById('surchLabel').textContent = CFG.surchargeLabel; document.getElementById('surchVal').textContent = fmt(surch); }
  else { document.getElementById('surchargeRow').style.display = 'none'; }
  if (tip > 0) { document.getElementById('tipRow').style.display = ''; document.getElementById('tipVal').textContent = fmt(tip); }
  else { document.getElementById('tipRow').style.display = 'none'; }
  queueDisplayMirror(); // MARKER-REGISTER-RECON-DISPLAY
}

document.getElementById('addOpenItemBtn').addEventListener('click', () => {
  document.getElementById('openItemName').value = '';
  document.getElementById('openItemPrice').value = '';
  openModal('openItemModal');
});
document.getElementById('openItemAddBtn').addEventListener('click', () => {
  const name = document.getElementById('openItemName').value.trim();
  const priceStr = document.getElementById('openItemPrice').value.trim();
  const priceFloat = parseFloat(priceStr);
  if (!name || isNaN(priceFloat) || priceFloat < 0) return;
  const cents = Math.round(priceFloat * 100);
  addToCart({type:'open_item', source_id:null, name, price_cents:cents, is_taxable:true});
  closeModal('openItemModal');
});

function openCustomerModal() {
  document.getElementById('customerSearchInput').value = '';
  document.getElementById('customerResults').style.display = 'none';
  openModal('customerModal');
  setTimeout(() => document.getElementById('customerSearchInput').focus(), 50);
}
let custTimer = null;
document.getElementById('customerSearchInput').addEventListener('input', () => {
  clearTimeout(custTimer);
  custTimer = setTimeout(searchCustomers, 250);
});
async function searchCustomers() {
  const q = document.getElementById('customerSearchInput').value.trim();
  const box = document.getElementById('customerResults');
  if (q.length < 2) { box.style.display = 'none'; return; }
  const url = new URL(ROUTES.search, window.location.origin);
  url.searchParams.set('q', q);
  url.searchParams.set('type', 'customer');
  try {
    const res = await fetch(url, {headers:{'Accept':'application/json'}});
    const data = await res.json();
    if (!data.customers || !data.customers.length) {
      box.innerHTML = '<div class="row" style="color:var(--ia-text-dim)">No matches.</div>';
      box.style.display = '';
      return;
    }
    box.innerHTML = data.customers.map(c => `
      <div class="row" data-cust='${JSON.stringify(c)}'>
        <div style="font-weight:500">${escapeHtml(c.name || '(no name)')}</div>
        <div style="font-size:11px;color:var(--ia-text-dim)">${escapeHtml(c.email || c.phone || '')}</div>
      </div>
    `).join('');
    box.querySelectorAll('[data-cust]').forEach(row => {
      row.addEventListener('click', () => {
        cart.customer = JSON.parse(row.dataset.cust);
        closeModal('customerModal');
        renderCart();
        queueDraftSave();
      });
    });
    box.style.display = '';
  } catch (e) {
    box.innerHTML = '<div class="row" style="color:#F09595">Search failed.</div>';
    box.style.display = '';
  }
}

// --- Save as Quote flow ---
let customerWarningActive = false;

function applyCustomerWarning(on) {
  customerWarningActive = on;
  const slot = document.getElementById('customerSlot');
  const cust = slot.querySelector('.reg-cust');
  const attach = slot.querySelector('.reg-attach');
  if (on) {
    if (cust) cust.classList.add('warning');
    if (attach) attach.classList.add('warning');
  } else {
    if (cust) cust.classList.remove('warning');
    if (attach) attach.classList.remove('warning');
  }
}

document.getElementById('quoteBtn').addEventListener('click', async () => {
  if (cart.refund_lines.length > 0) {
    showError('Quotes can\'t include refund items. Remove the refund lines or commit the transaction.');
    return;
  }
  if (!cart.customer) {
    applyCustomerWarning(true);
    const ok = await confirmDialog(
      'Quotes need a customer attached so you can find and follow up later.',
      'Attach customer',
      'Customer required'
    );
    if (ok) openCustomerModal();
    return;
  }
  // Customer is attached — clear any prior warning state and open quote modal.
  applyCustomerWarning(false);
  document.getElementById('quoteNotesInput').value = '';
  openModal('quoteModal');
  setTimeout(() => document.getElementById('quoteNotesInput').focus(), 50);
});

document.getElementById('quoteSaveBtn').addEventListener('click', async () => {
  const btn = document.getElementById('quoteSaveBtn');
  btn.disabled = true;

  // Make sure any pending draft save lands first — same flush pattern as commit.
  await flushDraftSave();

  const payload = {
    id: cart.draft_id,
    customer_id: cart.customer.id,
    notes: document.getElementById('quoteNotesInput').value.trim() || null,
    tip_cents: cart.tipCents,
    items: cart.items.map(i => {
      const out = { type: i.type, quantity: i.qty, is_taxable: i.is_taxable };
      if (i.type === 'product') out.inventory_item_id = i.source_id;
      if (i.type === 'service') out.service_id = i.source_id;
      if (i.type === 'open_item') {
        out.name_snapshot = i.name;
        out.unit_price_cents = i.price_cents;
      }
      return out;
    }),
  };

  try {
    const res = await fetch(ROUTES.storeQuote, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify(payload),
    });
    const data = await res.json();
    if (!data.ok) {
      showError(data.error || 'Could not save the quote.');
      closeModal('quoteModal');
      return;
    }
    // Success — clear the cart so register is ready for the next sale.
    closeModal('quoteModal');
    cart.draft_id = null;
    cart.customer = null;
    cart.items = [];
    cart.tipCents = 0;
    cart.discountCents = 0;
    cart.payment_method = null; cart.payments = []; if (typeof renderSplit === 'function') renderSplit(); /* MARKER-SPLIT-TENDER */
    cart.payment_reference = null;
    renderCart();
    refreshDraftsBanner(await loadDrafts());
  } catch (e) {
    showError('Network error saving quote.');
    closeModal('quoteModal');
  } finally {
    btn.disabled = false;
  }
});

document.getElementById('payBtn').addEventListener('click', () => {
  // MARKER-PATCH-170C — pre-flight validation FIRST. If the cart can't
  // be committed (e.g. service line without customer), block the tender
  // modal entirely and show a focused dialog explaining what to fix.
  const blocker = preflightCheck();
  if (blocker) {
    openPreflightModal(blocker);
    return;
  }

  // Net total decides which path we take.
  const sub = calcSubtotal();
  const refundSub = calcRefundSubtotal();
  const tax = calcTax();
  const surch = calcSurcharge();
  const tip = cart.tipCents;
  const disc = cart.discountCents;
  const net = (sub - disc + tax + surch + tip) - refundSub;

  if (net === 0 && cart.refund_lines.length > 0) {
    // Even exchange — skip tender, commit immediately.
    // No money changes hands, but the payload still requires a payment method
    // for the validator. 'even_exchange' is a sentinel that the controller treats
    // the same as 'mark_paid' (no actual tender).
    cart.payment_method = 'even_exchange';
    commitTransaction({ even_exchange: true });
    return;
  }

  if (net < 0) {
    // Refund-direction transaction.
    cart.payment_method = null; cart.payments = []; if (typeof renderSplit === 'function') renderSplit(); /* MARKER-SPLIT-TENDER */
    document.getElementById('refundTenderConfirmBtn').disabled = true;
    document.querySelectorAll('#refundTenderModal .reg-tender-btn').forEach(b => b.classList.remove('selected'));
    document.getElementById('refundTenderLede').textContent =
      'Customer is owed ' + fmt(Math.abs(net)) + '. How is the refund being given?';
    openModal('refundTenderModal');
    return;
  }

  // Standard sale-direction tender flow (net > 0).
  cart.payment_method = null; cart.payments = []; if (typeof renderSplit === 'function') renderSplit(); /* MARKER-SPLIT-TENDER */
  cart.payment_reference = null;
  document.getElementById('tenderRefRow').style.display = 'none';
  document.getElementById('tenderManualRow').style.display = 'none'; // MARKER-PATCH-630
  document.getElementById('tenderRefInput').value = '';
  document.getElementById('tenderConfirmBtn').disabled = true;
  document.querySelectorAll('#tenderModal .reg-tender-btn').forEach(b => b.classList.remove('selected'));
  openModal('tenderModal');
});

// Refund-tender modal handlers
document.querySelectorAll('#refundTenderModal .reg-tender-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('#refundTenderModal .reg-tender-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    cart.payment_method = btn.dataset.refundTender;
    document.getElementById('refundTenderConfirmBtn').disabled = false;
  });
});

document.getElementById('refundTenderConfirmBtn').addEventListener('click', () => {
  closeModal('refundTenderModal');
  // Refund-direction commits skip the tip step entirely.
  commitTransaction({});
});

// MARKER-SPLIT-TENDER — split-tender engine. Zero recorded payments = the
// classic single-tender flow, unchanged. Split only activates via "Add
// payment". Stripe card / payment-link / mark_paid stay full-amount-only
// until stage 2 and grey out mid-split.
const SplitCard = { pendingAmountCents: null }; // MARKER-SPLIT-ANYORDER
function splitDueCents() {
  const t = computeTotalsForCommit();
  return Math.max(0, t.total_cents + (cart.tipCents || 0) - (calcRefundSubtotal() + calcRefundTax()));
}
function splitPaidCents() { return cart.payments.reduce((s, p) => s + p.amount_cents, 0); }
function splitRemaining() { return Math.max(0, splitDueCents() - splitPaidCents()); }
function renderSplit() {
  const list = document.getElementById('splitPayList');
  const remRow = document.getElementById('splitRemainRow');
  if (!list || !remRow) return;
  const active = cart.payments.length > 0;
  remRow.style.display = active ? '' : 'none';
  list.innerHTML = '';
  cart.payments.forEach((p, i) => {
    const row = document.createElement('div');
    row.className = 'reg-split-row';
    // MARKER-SPLIT-ANYORDER — charged card rows are locked: removing one
    // refunds real money, so it's an explicit Void, never a quiet ✕.
    const removeCtl = p.locked
      ? '<span class="x" style="font-size:11px;font-weight:700">Void</span>'
      : '<span class="x">✕</span>';
    row.innerHTML = '<span>' + (p.label || p.method) + (p.locked && p.reference ? ' <span style="opacity:.55;font-size:11px">' + p.reference + '</span>' : '') + '</span>'
      + '<span class="amt">' + fmt(p.amount_cents) + '</span>'
      + removeCtl
      + (p.change_cents ? '<span class="chg">Change due ' + fmt(p.change_cents) + '</span>' : '');
    row.querySelector('.x').addEventListener('click', async () => {
      if (!p.locked) { cart.payments.splice(i, 1); renderSplit(); return; }
      if (!confirm('Void this ' + fmt(p.amount_cents) + ' card charge? The customer will be refunded.')) return;
      try {
        const res = await fetch(ROUTES.paymentIntentAutoRefund, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
          body: JSON.stringify({ payment_intent: p.stripe_payment_intent_id, reason: 'split_leg_voided' }),
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Refund failed');
        cart.payments.splice(i, 1); renderSplit();
      } catch (e) {
        alert('Void failed: ' + e.message + ' — check the Stripe dashboard for ' + p.stripe_payment_intent_id);
      }
    });
    list.appendChild(row);
  });
  const rem = splitRemaining();
  document.getElementById('splitRemain').textContent = fmt(rem);
  remRow.classList.toggle('zero', rem === 0);
  document.querySelectorAll('#tenderModal .reg-tender-btn').forEach(b => {
    const t = b.dataset.tender;
    // MARKER-SPLIT-STRIPE — Stripe card now joins a split as the FINAL leg
    // (it charges exactly the remaining balance). Link + mark_paid wait.
    const stage2 = t === 'payment_link' || t === 'mark_paid';
    b.classList.toggle('split-disabled', active && stage2);
  });
  const confirmBtn = document.getElementById('tenderConfirmBtn');
  if (active) {
    confirmBtn.disabled = rem !== 0;
    confirmBtn.textContent = rem === 0 ? 'Complete' : fmt(rem) + ' remaining';
  } else {
    confirmBtn.textContent = 'Confirm';
  }
}
document.getElementById('splitAddBtn').addEventListener('click', () => {
  if (!cart.payment_method) return;
  const raw = document.getElementById('splitAmountInput').value;
  let c = Math.round(parseFloat(String(raw).replace(/[^0-9.]/g, '')) * 100);
  if (isNaN(c) || c <= 0) return;
  const rem = splitRemaining();
  if (rem === 0) return;
  let change = 0;
  if (cart.payment_method === 'cash' && c > rem) { change = c - rem; c = rem; }
  if (c > rem) c = rem;
  // MARKER-SPLIT-ANYORDER — a Stripe card leg charges immediately for the
  // typed amount; on success the row lands locked (real money moved).
  if (cart.payment_method === 'card' && ROUTES.directPaymentsEnabled && ROUTES.directPaymentsPk) {
    SplitCard.pendingAmountCents = c;
    closeModal('tenderModal');
    openCardPaymentModal();
    return;
  }
  const selBtn = document.querySelector('#tenderModal .reg-tender-btn.selected');
  cart.payments.push({
    method: cart.payment_method,
    amount_cents: c,
    change_cents: change,
    reference: (document.getElementById('tenderRefInput').value || '').trim() || null,
    label: selBtn ? selBtn.textContent.trim().split('\n')[0].trim() : cart.payment_method,
  });
  cart.payment_method = null;
  document.querySelectorAll('#tenderModal .reg-tender-btn').forEach(b => b.classList.remove('selected'));
  document.getElementById('splitAmountRow').style.display = 'none';
  document.getElementById('splitHint').style.display = 'none';
  document.getElementById('tenderRefRow').style.display = 'none';
  document.getElementById('tenderRefInput').value = '';
  renderSplit();
});

document.querySelectorAll('#tenderModal .reg-tender-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('#tenderModal .reg-tender-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    cart.payment_method = btn.dataset.tender;
    // MARKER-SPLIT-ANYORDER — no ordering rules: any number of payments,
    // any order, any amount. Card gets the same amount field as everything.
    // MARKER-SPLIT-TENDER — amount entry for splittable tenders
    (function () {
      const t = btn.dataset.tender;
      const splittable = !(t === 'payment_link' || t === 'mark_paid'); // MARKER-SPLIT-ANYORDER
      const rowEl = document.getElementById('splitAmountRow');
      const hintEl = document.getElementById('splitHint');
      if (rowEl && splittable) {
        rowEl.style.display = 'flex'; if (hintEl) hintEl.style.display = '';
        const inp = document.getElementById('splitAmountInput');
        inp.value = (splitRemaining() / 100).toFixed(2);
        setTimeout(() => { inp.focus(); inp.select(); }, 30);
      } else if (rowEl) {
        rowEl.style.display = 'none'; if (hintEl) hintEl.style.display = 'none';
      }
    })();
    document.getElementById('tenderConfirmBtn').disabled = false;
    // MARKER-PATCH-170C — reference field only meaningful for checks now.
    // Card no longer needs a hand-typed reference (with direct payments the
    // brand+last4 becomes the reference automatically; without direct payments
    // the field was always low-value friction).
    const showRef = ['check'].includes(cart.payment_method);
    document.getElementById('tenderRefRow').style.display = showRef ? '' : 'none';

    // MARKER-PATCH-630 — manual tenders: show instructions + amount-prefilled link
    const manualRow = document.getElementById('tenderManualRow');
    if (btn.dataset.manual) {
      const total = ((calcSubtotal() - cart.discountCents + calcTax() + calcSurcharge() + cart.tipCents) - (calcRefundSubtotal() + calcRefundTax())) / 100;
      document.getElementById('tenderManualInstr').textContent = btn.dataset.instructions || '';
      const wrap = document.getElementById('tenderManualLinkWrap');
      if (btn.dataset.linktpl && total > 0) {
        const link = btn.dataset.linktpl.replace('{amount}', total.toFixed(2));
        document.getElementById('tenderManualLink').textContent = link;
        document.getElementById('tenderManualSms').href = 'sms:?&body=' + encodeURIComponent('Pay ' + btn.dataset.name + ': ' + link);
        wrap.style.display = '';
      } else {
        wrap.style.display = 'none';
      }
      manualRow.style.display = '';
    } else {
      manualRow.style.display = 'none';
    }
    renderTotals();
  });
});

// MARKER-PATCH-630 — copy the manual payment link
document.getElementById('tenderManualCopy').addEventListener('click', function () {
  const t = document.getElementById('tenderManualLink').textContent;
  navigator.clipboard.writeText(t).then(() => { this.textContent = 'Copied ✓'; setTimeout(() => { this.textContent = 'Copy link'; }, 1400); });
});

// MARKER-PATCH-170 — Direct Payments hand-keyed card flow
// When the card tender is selected AND the tenant has direct payments
// enabled, intercept to run the Stripe Payment Element BEFORE commit.
// Other tender types (cash, check, etc.) flow unchanged.
let DirectPay = {
  stripe: null,
  elements: null,
  paymentElement: null,
  clientSecret: null,
  paymentIntentId: null,
  inFlight: false,
};

// MARKER-PATCH-172 — Send-payment-link state
let PaymentLink = {
  saleId: null,
  sessionId: null,
  checkoutUrl: null,
  pollHandle: null,
};

// Show the Send-payment-link tender button when direct payments are enabled.
if (ROUTES.directPaymentsEnabled && ROUTES.directPaymentsPk) {
  const btn = document.getElementById('tenderPaymentLinkBtn');
  if (btn) btn.style.display = '';
}

async function openCardPaymentModal() {
  // MARKER-PATCH-170B + 170C — pre-charge validation. The Charge button
  // pre-flight modal already catches this upstream, but defense-in-depth
  // in case openCardPaymentModal is reached via some other path.
  const hasServiceLine = cart.items.some(i => i.type === 'service');
  if (hasServiceLine && !cart.customer) {
    closeModal('tenderModal');
    openPreflightModal({
      title: 'Add a customer',
      message: 'A customer is required when the sale includes a service.',
      actionLabel: 'Add customer →',
      actionFn: () => { closeModal('preflightModal'); openCustomerModal(); },
    });
    return;
  }

  const errBox = document.getElementById('cardPaymentError');
  errBox.style.display = 'none';
  errBox.textContent = '';
  document.getElementById('cardPaymentChargeBtn').disabled = true;
  document.getElementById('cardPaymentSpinner').style.display = 'none';

  const totals = computeTotalsForCommit();
  // MARKER-SPLIT-ANYORDER — a pending split card leg charges the typed
  // amount; otherwise (single-tender card) the full total as always.
  const amountCents = SplitCard.pendingAmountCents != null
    ? SplitCard.pendingAmountCents
    : totals.total_cents + (cart.tipCents || 0);
  DirectPay.chargeAmountCents = amountCents;
  document.getElementById('cardPaymentAmount').textContent = fmt(amountCents);
  document.getElementById('cardPaymentChargeLabel').textContent = 'Charge ' + fmt(amountCents);

  openModal('cardPaymentModal');

  // Reset Stripe.js elements between opens
  if (DirectPay.paymentElement) {
    try { DirectPay.paymentElement.unmount(); } catch (e) {}
  }
  DirectPay.elements = null;
  DirectPay.paymentElement = null;
  DirectPay.clientSecret = null;
  DirectPay.paymentIntentId = null;

  // Create the PaymentIntent
  let intent;
  try {
    const res = await fetch(ROUTES.paymentIntentCreate, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify({
        amount_cents: amountCents,
        // MARKER-PATCH-170B — preflight context
        customer_id: cart.customer ? cart.customer.id : null,
        has_service_line: cart.items.some(i => i.type === 'service'),
      }),
    });
    intent = await res.json();
    if (!intent.ok) throw new Error(intent.error || 'Could not initialize card payment.');
  } catch (e) {
    errBox.textContent = e.message;
    errBox.style.display = '';
    return;
  }

  DirectPay.clientSecret = intent.client_secret;
  DirectPay.paymentIntentId = intent.payment_intent;

  // Lazy-init Stripe.js with the tenant\'s publishable key
  if (!DirectPay.stripe) {
    DirectPay.stripe = Stripe(intent.publishable_key);
  }

  DirectPay.elements = DirectPay.stripe.elements({
    clientSecret: DirectPay.clientSecret,
    appearance: {
      theme: 'night',
      variables: {
        colorPrimary: '#BEF264',
        colorBackground: '#1c1c1c',
        colorText: '#f0f0f0',
        colorDanger: '#f87171',
        fontFamily: '-apple-system, BlinkMacSystemFont, sans-serif',
        borderRadius: '6px',
      },
    },
  });
  DirectPay.paymentElement = DirectPay.elements.create('payment', {
    layout: 'tabs',
  });
  DirectPay.paymentElement.mount('#card-payment-element');
  DirectPay.paymentElement.on('ready', () => {
    document.getElementById('cardPaymentChargeBtn').disabled = false;
  });
  DirectPay.paymentElement.on('change', (ev) => {
    document.getElementById('cardPaymentChargeBtn').disabled = !!ev.empty;
    if (ev.error) {
      errBox.textContent = ev.error.message;
      errBox.style.display = '';
    } else {
      errBox.style.display = 'none';
    }
  });
}

async function confirmCardPayment() {
  if (DirectPay.inFlight) return;
  DirectPay.inFlight = true;

  const errBox = document.getElementById('cardPaymentError');
  errBox.style.display = 'none';
  const chargeBtn = document.getElementById('cardPaymentChargeBtn');
  chargeBtn.disabled = true;
  document.getElementById('cardPaymentSpinner').style.display = '';

  let result;
  try {
    result = await DirectPay.stripe.confirmPayment({
      elements: DirectPay.elements,
      redirect: 'if_required',
    });
  } catch (e) {
    errBox.textContent = e.message || 'Payment failed.';
    errBox.style.display = '';
    chargeBtn.disabled = false;
    document.getElementById('cardPaymentSpinner').style.display = 'none';
    DirectPay.inFlight = false;
    return;
  }

  if (result.error) {
    errBox.textContent = result.error.message;
    errBox.style.display = '';
    chargeBtn.disabled = false;
    document.getElementById('cardPaymentSpinner').style.display = 'none';
    DirectPay.inFlight = false;
    return;
  }

  // Verify with our server (Stripe is source of truth, not the client)
  let conf;
  try {
    const res = await fetch(ROUTES.paymentIntentConfirm, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify({ payment_intent: DirectPay.paymentIntentId }),
    });
    conf = await res.json();
    if (!conf.ok) throw new Error(conf.error || 'Could not verify payment.');
  } catch (e) {
    errBox.textContent = e.message;
    errBox.style.display = '';
    chargeBtn.disabled = false;
    document.getElementById('cardPaymentSpinner').style.display = 'none';
    DirectPay.inFlight = false;
    return;
  }

  // Stash Stripe metadata for the sale commit
  cart.stripe_payment_intent_id = conf.payment_intent;
  cart.stripe_charge_id         = conf.stripe_charge_id;
  cart.card_brand               = conf.card_brand;
  cart.card_last4               = conf.card_last4;
  cart.card_funding             = conf.card_funding;
  cart.payment_reference        = (conf.card_brand && conf.card_last4)
    ? (conf.card_brand + ' ····' + conf.card_last4)
    : null;

  // MARKER-SPLIT-ANYORDER — a split card charge lands as a LOCKED row (the
  // charge is live; removal is an explicit Void). Remainder left → reopen
  // the tender modal and keep taking payments, any order. Covered → commit.
  if (SplitCard.pendingAmountCents != null) {
    cart.payments.push({
      method: 'card',
      amount_cents: DirectPay.chargeAmountCents,
      change_cents: 0,
      reference: cart.payment_reference,
      label: 'Card',
      locked: true,
      stripe_payment_intent_id: conf.payment_intent,
    });
    SplitCard.pendingAmountCents = null;
    cart.payment_method = null;
    cart.payment_reference = null;
    closeModal('cardPaymentModal');
    DirectPay.inFlight = false;
    if (splitRemaining() > 0) {
      renderSplit();
      openModal('tenderModal');
      return;
    }
    cart.payment_method = 'split';
    commitTransaction({});
    return;
  }

  // Close modal and run the existing commit pipeline.
  // MARKER-PATCH-170B — wrap commit in our own try; if commitTransaction
  // shows the failure banner, we still hold the PI in cart.stripe_payment_intent_id.
  // commitTransaction itself calls autoRefundOnCommitFailure() if its commit fails.
  closeModal('cardPaymentModal');
  DirectPay.inFlight = false;
  if (CFG.tipsEnabled) openTipModal(); else commitTransaction({});
}

// MARKER-PATCH-170B — called by commitTransaction's error path when the
// charge has already authorized but the commit step failed. Refunds the
// PaymentIntent server-side and clears the Stripe metadata from the cart
// so the user doesn\'t double-charge.
async function autoRefundOnCommitFailure(reason) {
  if (!cart.stripe_payment_intent_id) return;
  const pi = cart.stripe_payment_intent_id;
  // Optimistically clear from cart so a retry doesn\'t re-send the stale PI
  cart.stripe_payment_intent_id = null;
  cart.stripe_charge_id = null;
  cart.card_brand = null;
  cart.card_last4 = null;
  cart.card_funding = null;
  cart.payment_reference = null;

  try {
    const res = await fetch(ROUTES.paymentIntentAutoRefund, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify({ payment_intent: pi, reason: reason || 'commit_failed' }),
    });
    const data = await res.json();
    const banner = document.getElementById('errBanner');
    if (data.ok) {
      banner.textContent = (banner.textContent || '') + ' The card charge was automatically refunded.';
    } else {
      banner.textContent = (banner.textContent || '') + ' WARNING: card was charged but refund failed — check Stripe dashboard for payment intent ' + pi;
    }
    banner.style.display = '';
  } catch (e) {
    const banner = document.getElementById('errBanner');
    banner.textContent = 'Card was charged but refund attempt failed. Stripe payment intent: ' + pi + '. Please refund manually in Stripe dashboard.';
    banner.style.display = '';
  }
}

document.getElementById('cardPaymentCancelBtn').addEventListener('click', () => {
  if (DirectPay.paymentElement) {
    try { DirectPay.paymentElement.unmount(); } catch (e) {}
  }
  DirectPay.inFlight = false;
  closeModal('cardPaymentModal');
});
document.getElementById('cardPaymentChargeBtn').addEventListener('click', confirmCardPayment);

// Helper used by openCardPaymentModal to compute the current charge total.
// Mirrors the math in commitTransaction\'s totals without firing a save.
function computeTotalsForCommit() {
  const sub = calcSubtotal();
  const tax = Math.round(sub * (CFG.taxRate || 0));
  const total = sub + tax - (cart.discountCents || 0);
  return { subtotal_cents: sub, tax_cents: tax, total_cents: Math.max(0, total) };
}

document.getElementById('tenderConfirmBtn').addEventListener('click', () => {
  cart.payment_reference = document.getElementById('tenderRefInput').value.trim() || null;

  // MARKER-SPLIT-TENDER — split path: tenders recorded row by row; the tip
  // modal is skipped (set tips before splitting so remaining math is stable).
  if (cart.payments.length > 0) {
    if (splitRemaining() !== 0) return;
    cart.payment_method = 'split';
    cart.payment_reference = null;
    closeModal('tenderModal');
    commitTransaction({});
    return;
  }

  // MARKER-PATCH-170 — Direct Payments path
  if (cart.payment_method === 'card' && ROUTES.directPaymentsEnabled && ROUTES.directPaymentsPk) {
    closeModal('tenderModal');
    openCardPaymentModal();
    return;
  }

  // MARKER-PATCH-172 — Send-payment-link path
  if (cart.payment_method === 'payment_link' && ROUTES.directPaymentsEnabled && ROUTES.directPaymentsPk) {
    closeModal('tenderModal');
    openPaymentLinkModal();
    return;
  }

  // Default path (cash, check, store_credit, mark_paid, or card-without-Stripe)
  closeModal('tenderModal');
  if (CFG.tipsEnabled) openTipModal(); else commitTransaction({});
});

// MARKER-PATCH-172 — Send-payment-link modal flow
async function openPaymentLinkModal() {
  const statusText = document.getElementById('paymentLinkStatusText');
  statusText.textContent = 'Creating payment link…';
  document.getElementById('paymentLinkQR').innerHTML = '';
  document.getElementById('paymentLinkUrl').textContent = '';
  openModal('paymentLinkModal');

  const totals = computeTotalsForCommit();
  const amountCents = totals.total_cents + (cart.tipCents || 0);
  document.getElementById('paymentLinkAmountValue').textContent = fmt(amountCents);

  let resp;
  try {
    const res = await fetch(ROUTES.checkoutSessionCreate, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify({
        amount_cents: amountCents,
        customer_id: cart.customer ? cart.customer.id : null,
        has_service_line: cart.items.some(i => i.type === 'service'),
        description: 'Purchase at ' + document.title,
        items: cart.items.map(serializeLine),
        tip_cents: cart.tipCents || 0,
        discount_cents: cart.discountCents || 0,
        sale_id: cart.draft_id || null,
      }),
    });
    resp = await res.json();
    if (!resp.ok) throw new Error(resp.error || 'Could not create payment link.');
  } catch (e) {
    closeModal('paymentLinkModal'); DisplayMirror.payUrl = null; queueDisplayMirror(true); // MARKER-REGISTER-RECON-DISPLAY
    showError(e.message);
    return;
  }

  PaymentLink.saleId = resp.sale_id;
  PaymentLink.sessionId = resp.session_id;
  PaymentLink.checkoutUrl = resp.checkout_url;
  DisplayMirror.payUrl = resp.checkout_url; // MARKER-REGISTER-RECON-DISPLAY
  queueDisplayMirror(true);

  // Render QR code
  const qrEl = document.getElementById('paymentLinkQR');
  qrEl.innerHTML = '';
  if (typeof qrcode === 'function') {
    const qr = qrcode(0, 'L');
    qr.addData(resp.checkout_url);
    qr.make();
    qrEl.innerHTML = qr.createSvgTag({ scalable: true, margin: 2 });
    // Constrain SVG size
    const svg = qrEl.querySelector('svg');
    if (svg) { svg.style.width = '200px'; svg.style.height = '200px'; }
  } else {
    qrEl.textContent = '(QR library failed to load. Use the URL below.)';
  }

  document.getElementById('paymentLinkUrl').textContent = resp.checkout_url;
  document.getElementById('paymentLinkStatusText').textContent = 'Waiting for customer to pay…';

  // Start polling
  startPaymentLinkPolling();
}

function startPaymentLinkPolling() {
  stopPaymentLinkPolling();
  PaymentLink.pollHandle = setInterval(checkPaymentLinkStatus, 3000);
}
function stopPaymentLinkPolling() {
  if (PaymentLink.pollHandle) {
    clearInterval(PaymentLink.pollHandle);
    PaymentLink.pollHandle = null;
  }
}

async function checkPaymentLinkStatus() {
  if (!PaymentLink.saleId) return;
  try {
    const res = await fetch(ROUTES.checkoutSessionCheck, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify({ sale_id: PaymentLink.saleId }),
    });
    const data = await res.json();
    if (!data.ok) return;

    if (data.status === 'succeeded') {
      stopPaymentLinkPolling();
      closeModal('paymentLinkModal'); DisplayMirror.payUrl = null; queueDisplayMirror(true); // MARKER-REGISTER-RECON-DISPLAY
      // Show the receipt screen using the existing flow
      showReceipt({ sale_number: data.sale_number, total_cents: data.total_cents, sale_id: data.sale_id }); // MARKER-PATCH-322
      // Clear cart since the sale completed
      cart.draft_id = null;
      cart.customer = null;
      cart.items = [];
      cart.refund_lines = [];
      cart.tipCents = 0;
      cart.discountCents = 0;
      renderAll();
      PaymentLink.saleId = null;
      PaymentLink.sessionId = null;
      PaymentLink.checkoutUrl = null;
      return;
    }

    if (data.status === 'expired') {
      stopPaymentLinkPolling();
      document.getElementById('paymentLinkStatusText').textContent = 'Link expired. Cancel and try again.';
    }
  } catch (e) {
    // Transient network errors — keep polling.
  }
}

document.getElementById('paymentLinkCopyBtn').addEventListener('click', () => {
  if (!PaymentLink.checkoutUrl) return;
  navigator.clipboard.writeText(PaymentLink.checkoutUrl).then(() => {
    const btn = document.getElementById('paymentLinkCopyBtn');
    const orig = btn.textContent;
    btn.textContent = 'Copied ✓';
    setTimeout(() => { btn.textContent = orig; }, 1500);
  });
});

// MARKER-PATCH-192 — "Cancel link": explicit destructive action. Expires the
// Stripe session and marks the sale cancelled. Only fires on deliberate click.
document.getElementById('paymentLinkCancelBtn').addEventListener('click', async () => {
  if (!confirm('Cancel this payment link? The customer will no longer be able to pay it.')) return;
  stopPaymentLinkPolling();
  if (PaymentLink.saleId) {
    try {
      await fetch(ROUTES.checkoutSessionCancel, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
        body: JSON.stringify({ sale_id: PaymentLink.saleId }),
      });
    } catch (e) {}
  }
  PaymentLink.saleId = null;
  PaymentLink.sessionId = null;
  PaymentLink.checkoutUrl = null;
  closeModal('paymentLinkModal'); DisplayMirror.payUrl = null; queueDisplayMirror(true); // MARKER-REGISTER-RECON-DISPLAY
});

// MARKER-PATCH-192 — "Done — keep link live": the operator steps away while the
// customer pays on their own time. Stops the foreground poll and closes the
// modal, but leaves the sale PENDING and the Stripe session active. The webhook
// will promote it when the customer pays; the appointment surfaces the pending
// state so it's never lost. Does NOT cancel anything.
document.getElementById('paymentLinkDoneBtn').addEventListener('click', () => {
  stopPaymentLinkPolling();
  PaymentLink.saleId = null;
  PaymentLink.sessionId = null;
  PaymentLink.checkoutUrl = null;
  closeModal('paymentLinkModal'); DisplayMirror.payUrl = null; queueDisplayMirror(true); // MARKER-REGISTER-RECON-DISPLAY
});

function openTipModal() {
  cart.tipCents = 0;
  document.getElementById('tipCustomInput').value = '';
  const grid = document.getElementById('tipGrid');
  grid.innerHTML = '';
  const sub = calcSubtotal();
  (CFG.tipOptions || []).forEach(opt => {
    let cents, label;
    if (CFG.tipMethod === 'percent') {
      cents = Math.round(sub * (parseFloat(opt) / 100));
      label = `${opt}% (${fmt(cents)})`;
    } else {
      cents = Math.round(parseFloat(opt) * 100);
      label = fmt(cents);
    }
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'reg-tip-btn';
    btn.textContent = label;
    btn.addEventListener('click', () => {
      document.querySelectorAll('.reg-tip-btn').forEach(b => b.classList.remove('selected'));
      btn.classList.add('selected');
      cart.tipCents = cents;
      document.getElementById('tipCustomInput').value = '';
    });
    grid.appendChild(btn);
  });
  openModal('tipModal');
}

document.getElementById('tipCustomInput').addEventListener('input', () => {
  const v = parseFloat(document.getElementById('tipCustomInput').value);
  if (!isNaN(v) && v >= 0) {
    cart.tipCents = Math.round(v * 100);
    document.querySelectorAll('.reg-tip-btn').forEach(b => b.classList.remove('selected'));
  }
});
document.getElementById('tipClearBtn').addEventListener('click', () => {
  cart.tipCents = 0;
  document.getElementById('tipCustomInput').value = '';
  document.querySelectorAll('.reg-tip-btn').forEach(b => b.classList.remove('selected'));
});
document.getElementById('tipSkipBtn').addEventListener('click', () => {
  cart.tipCents = 0;
  closeModal('tipModal');
  commitTransaction({});
});
document.getElementById('tipConfirmBtn').addEventListener('click', () => {
  closeModal('tipModal');
  commitTransaction({});
});

async function commitTransaction(opts = {}) {
  document.getElementById('payBtn').disabled = true;
  document.getElementById('errBanner').style.display = 'none';

  // Make sure any pending or in-flight draft save lands before commit.
  await flushDraftSave();

  const hasRefund = cart.refund_lines.length > 0;
  const hasNewSale = cart.items.length > 0;

  try {
    let url, payload;

    if (hasRefund) {
      // Mixed or pure-refund transaction — use the new endpoint that handles both.
      url = ROUTES.commitTxn;
      payload = {
        customer_id: cart.customer ? cart.customer.id : null,
        tip_cents: cart.tipCents,
        payment_method: cart.payment_method,
        payment_reference: cart.payment_reference,
        payments: cart.payments.length ? cart.payments.map(p => ({ method: p.method, amount_cents: p.amount_cents, reference: p.stripe_payment_intent_id ? ((p.reference || 'card') + ' · ' + p.stripe_payment_intent_id) : p.reference })) : null, // MARKER-SPLIT-TENDER + ANYORDER
        // MARKER-PATCH-170 — Stripe metadata if Direct Payments fired
        stripe_payment_intent_id: cart.stripe_payment_intent_id || null,
        stripe_charge_id: cart.stripe_charge_id || null,
        card_brand: cart.card_brand || null,
        card_last4: cart.card_last4 || null,
        card_funding: cart.card_funding || null,
        items: hasNewSale ? cart.items.map(serializeLine) : [],
        refund: {
          original_sale_id: cart.refund_meta.original_sale_id,
          // MARKER-REFUND-QTY — the quantity the cashier chose is now sent and
          // is authoritative on the server, along with where the goods went.
          items: cart.refund_lines.map(r => ({
            sale_item_id: r.original_item_id,
            quantity: r.qty,
            disposition: r.disposition || 'restock',
          })),
          item_ids: cart.refund_lines.map(r => r.original_item_id),
          refund_method: cart.payment_method,
        },
      };
    } else if (cart.draft_id) {
      // Draft-backed pure sale — promote draft to paid (existing path).
      url = ROUTES.commitDraft + '/' + cart.draft_id + '/commit';
      payload = {
        payment_method: cart.payment_method,
        payment_reference: cart.payment_reference,
        payments: cart.payments.length ? cart.payments.map(p => ({ method: p.method, amount_cents: p.amount_cents, reference: p.stripe_payment_intent_id ? ((p.reference || 'card') + ' · ' + p.stripe_payment_intent_id) : p.reference })) : null, // MARKER-SPLIT-TENDER + ANYORDER
        tip_cents: cart.tipCents,
        customer_id: cart.customer ? cart.customer.id : null,
        skip_receipt: cart.skipReceipt ? 1 : 0, // MARKER-PATCH-161
        // MARKER-PATCH-170 — Stripe metadata if Direct Payments fired
        stripe_payment_intent_id: cart.stripe_payment_intent_id || null,
        stripe_charge_id: cart.stripe_charge_id || null,
        card_brand: cart.card_brand || null,
        card_last4: cart.card_last4 || null,
        card_funding: cart.card_funding || null,
      };
    } else {
      // Fallback path — pure sale, no draft, send full cart.
      url = ROUTES.storeSale;
      payload = {
        customer_id: cart.customer ? cart.customer.id : null,
        tip_cents: cart.tipCents,
        discount_cents: cart.discountCents,
        payment_method: cart.payment_method,
        payment_reference: cart.payment_reference,
        payments: cart.payments.length ? cart.payments.map(p => ({ method: p.method, amount_cents: p.amount_cents, reference: p.stripe_payment_intent_id ? ((p.reference || 'card') + ' · ' + p.stripe_payment_intent_id) : p.reference })) : null, // MARKER-SPLIT-TENDER + ANYORDER
        items: cart.items.map(serializeLine),
        skip_receipt: cart.skipReceipt ? 1 : 0, // MARKER-PATCH-161
        // MARKER-PATCH-170 — Stripe metadata if Direct Payments fired
        stripe_payment_intent_id: cart.stripe_payment_intent_id || null,
        stripe_charge_id: cart.stripe_charge_id || null,
        card_brand: cart.card_brand || null,
        card_last4: cart.card_last4 || null,
        card_funding: cart.card_funding || null,
      };
    }

    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify(payload),
    });
    const data = await res.json();
    if (!data.ok) {
      // MARKER-PATCH-170B — auto-refund the card if we authorized one
      if (cart.stripe_payment_intent_id) {
        await autoRefundOnCommitFailure(data.error || 'commit_failed');
      }
      showError(data.error || 'Could not complete the transaction.');
      return;
    }
    showReceipt(data);
  } catch (e) {
    // MARKER-OFFLINE-SYNC — network failure: queue the sale on-device when eligible.
    if (await osTryQueueCommit()) return;
    showError('Network error. Please try again.');
  } finally {
    document.getElementById('payBtn').disabled = (cart.items.length === 0 && cart.refund_lines.length === 0);
  }
}

function serializeLine(i) {
  const out = { type: i.type, quantity: i.qty, is_taxable: i.is_taxable };
  if (i.type === 'product') out.inventory_item_id = i.source_id;
  if (i.type === 'service') out.service_id = i.source_id;
  if (i.type === 'open_item') {
    out.name_snapshot = i.name;
    out.unit_price_cents = i.price_cents;
  }
  return out;
}

function showError(msg) {
  const el = document.getElementById('errBanner');
  el.textContent = msg;
  el.style.display = '';
  // MARKER-PATCH-170C — shake to draw attention, even on repeat errors.
  // Re-trigger by removing then re-adding the class on the next frame.
  el.classList.remove('reg-err--shake');
  requestAnimationFrame(() => {
    requestAnimationFrame(() => { el.classList.add('reg-err--shake'); });
  });
}

// MARKER-PATCH-170C — pre-flight cart validation.
// Returns null if the cart is commit-able, or a blocker object
// { title, message, actionLabel, actionFn } describing what's wrong.
// Order matters: surface the most-actionable problem first.
function preflightCheck() {
  // Service-line-without-customer is the only blocker we know about today.
  // More can be added (e.g. price-zero items, missing location) without
  // changing the call site.
  const hasServiceLine = cart.items.some(i => i.type === 'service');
  if (hasServiceLine && !cart.customer) {
    return {
      title: 'Add a customer',
      message: 'A customer is required when the sale includes a service. Attach a customer and we\'ll continue.',
      actionLabel: 'Add customer →',
      actionFn: () => {
        closeModal('preflightModal');
        openCustomerModal();
      },
    };
  }
  return null;
}

function openPreflightModal(blocker) {
  document.getElementById('preflightTitle').textContent = blocker.title;
  document.getElementById('preflightLede').textContent  = blocker.message;
  const btn = document.getElementById('preflightActionBtn');
  btn.textContent = blocker.actionLabel;
  // Replace previous click handler — clone the node to drop bound listeners.
  const fresh = btn.cloneNode(true);
  btn.parentNode.replaceChild(fresh, btn);
  fresh.addEventListener('click', blocker.actionFn);
  openModal('preflightModal');
}
// MARKER-PATCH-187 — after a completed sale the receipt sits briefly, then the
// register auto-resets to a fresh state. A visible countdown shows it coming;
// clicking "New sale" (or any cart interaction) resets immediately and cancels
// the timer.
const RECEIPT_AUTO_RESET_SECONDS = 45;
let receiptResetTimer = null;
let receiptCountdownTimer = null;
let receiptSaleId = null;        // MARKER-PATCH-322
let receiptCustomerEmail = null; // MARKER-PATCH-322

function clearReceiptTimers() {
  if (receiptResetTimer) { clearTimeout(receiptResetTimer); receiptResetTimer = null; }
  if (receiptCountdownTimer) { clearInterval(receiptCountdownTimer); receiptCountdownTimer = null; }
}

async function resetRegisterToFresh() {
  clearReceiptTimers();
  cart.draft_id = null;
  cart.customer = null;
  cart.items = [];
  cart.refund_lines = [];
  cart.refund_meta = null;
  cart.tipCents = 0; cart.discountCents = 0;
  cart.payment_method = null; cart.payments = []; if (typeof renderSplit === 'function') renderSplit(); /* MARKER-SPLIT-TENDER */ cart.payment_reference = null;
  closeModal('receiptModal');
  renderCart();
  searchInput.value = '';
  resultsArea.innerHTML = '<div class="reg-empty">Type to search products and services.</div>';
  refreshDraftsBanner(await loadDrafts());
}

function showReceipt(data) {
  document.getElementById('receiptNum').textContent = data.sale_number;
  document.getElementById('receiptTotal').textContent = fmt(data.total_cents);
  openModal('receiptModal');

  // MARKER-PATCH-322 — capture the sale for print/email before the cart clears.
  receiptSaleId = data.sale_id || null;
  receiptCustomerEmail = (typeof cart !== 'undefined' && cart && cart.customer && cart.customer.email) ? cart.customer.email : null;
  var _rPrint = document.getElementById('receiptPrintBtn');
  var _rEmail = document.getElementById('receiptEmailBtn');
  var _rPrompt = document.getElementById('receiptEmailPrompt');
  var _rMsg = document.getElementById('receiptEmailMsg');
  if (_rPrint) _rPrint.style.display = receiptSaleId ? '' : 'none';
  if (_rEmail) _rEmail.style.display = receiptSaleId ? '' : 'none';
  if (_rPrompt) _rPrompt.style.display = 'none';
  if (_rMsg) { _rMsg.style.display = 'none'; _rMsg.textContent = ''; }

  // MARKER-PATCH-232B — round-trip receipts: when the register was opened
  // with a return_to, the receipt offers (and the countdown takes) the way
  // back instead of resetting to a fresh register.
  const backBtn = document.getElementById('receiptBackTo');
  if (backBtn) {
    if (window.registerReturnTo) {
      backBtn.href = window.registerReturnTo;
      backBtn.style.display = '';
      backBtn.textContent = 'Back to where you were →';
      const autoEl = document.getElementById('receiptAutoReset');
      if (autoEl) autoEl.innerHTML = 'Heading back in <span id="receiptCountdown">45</span>s';
    } else {
      backBtn.style.display = 'none';
    }
  }

  // Start the auto-reset countdown.
  clearReceiptTimers();
  let remaining = RECEIPT_AUTO_RESET_SECONDS;
  const countdownEl = document.getElementById('receiptCountdown');
  if (countdownEl) countdownEl.textContent = remaining;
  receiptCountdownTimer = setInterval(() => {
    remaining -= 1;
    if (countdownEl) countdownEl.textContent = Math.max(0, remaining);
    if (remaining <= 0) clearInterval(receiptCountdownTimer);
  }, 1000);
  receiptResetTimer = setTimeout(() => {
    if (window.registerReturnTo) { window.location.href = window.registerReturnTo; return; }
    resetRegisterToFresh();
  }, RECEIPT_AUTO_RESET_SECONDS * 1000);
}

document.getElementById('receiptNewSale').addEventListener('click', () => { resetRegisterToFresh(); });

// MARKER-PATCH-322 — print + email the just-completed receipt.
(function () {
  var printBtn = document.getElementById('receiptPrintBtn');
  var emailBtn = document.getElementById('receiptEmailBtn');
  var promptEl = document.getElementById('receiptEmailPrompt');
  var inputEl  = document.getElementById('receiptEmailInput');
  var sendEl   = document.getElementById('receiptEmailSend');
  var msgEl    = document.getElementById('receiptEmailMsg');

  // Stop the auto-reset countdown once the cashier interacts here.
  function holdReset() {
    try { clearReceiptTimers(); } catch (e) {}
    var a = document.getElementById('receiptAutoReset');
    if (a) a.style.display = 'none';
  }

  if (printBtn) printBtn.addEventListener('click', function () {
    if (!receiptSaleId) return;
    holdReset();
    if (window.openPrintComposer) { window.openPrintComposer('sale', receiptSaleId, { type: 'receipt', format: 't80' }); return; } // MARKER-PATCH-338
    if (!ROUTES.saleReceipt) return;
    var url = ROUTES.saleReceipt.replace('__ID__', encodeURIComponent(receiptSaleId)) + '?embed=1';
    var f = document.createElement('iframe');
    f.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;';
    f.src = url;
    f.onload = function () {
      try { f.contentWindow.focus(); f.contentWindow.print(); }
      catch (e) { window.open(url.replace('?embed=1', ''), '_blank'); }
      setTimeout(function () { f.remove(); }, 2000);
    };
    document.body.appendChild(f);
  });

  function sendReceipt(email) {
    if (!receiptSaleId || !ROUTES.resendReceipt) return;
    var url = ROUTES.resendReceipt.replace('__ID__', encodeURIComponent(receiptSaleId));
    var token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    if (sendEl) sendEl.disabled = true;
    fetch(url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
      body: email ? ('email=' + encodeURIComponent(email)) : ''
    })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (sendEl) sendEl.disabled = false;
      if (d && d.ok) {
        var to = email || receiptCustomerEmail;
        if (msgEl) { msgEl.style.display = ''; msgEl.textContent = 'Receipt sent' + (to ? ' to ' + to : '') + '.'; }
        if (promptEl) promptEl.style.display = 'none';
      } else if (msgEl) {
        msgEl.style.display = ''; msgEl.textContent = (d && d.error) || 'Could not send receipt.';
      }
    })
    .catch(function () { if (sendEl) sendEl.disabled = false; if (msgEl) { msgEl.style.display = ''; msgEl.textContent = 'Could not send receipt.'; } });
  }

  if (emailBtn) emailBtn.addEventListener('click', function () {
    if (!receiptSaleId) return;
    holdReset();
    if (receiptCustomerEmail) { sendReceipt(null); }
    else { if (promptEl) promptEl.style.display = 'flex'; if (inputEl) inputEl.focus(); }
  });
  if (sendEl) sendEl.addEventListener('click', function () {
    var v = ((inputEl && inputEl.value) || '').trim();
    if (!v || v.indexOf('@') < 0) { if (inputEl) inputEl.focus(); return; }
    sendReceipt(v);
  });
  if (inputEl) inputEl.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); if (sendEl) sendEl.click(); } });
})();

function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('[data-close-modal]').forEach(btn => {
  btn.addEventListener('click', () => closeModal(btn.dataset.closeModal));
});

// --- Drafts banner / resume / discard ---
async function loadDrafts() {
  try {
    const res = await fetch(ROUTES.listDrafts, {headers:{'Accept':'application/json'}});
    const data = await res.json();
    return data.drafts || [];
  } catch (e) {
    return [];
  }
}

function refreshDraftsBanner(drafts) {
  const banner = document.getElementById('draftsBanner');
  // Filter out the current cart's own draft from the count.
  const others = drafts.filter(d => d.id !== cart.draft_id);
  if (!others.length) { banner.style.display = 'none'; return; }
  const word = others.length === 1 ? 'draft' : 'drafts';
  document.getElementById('draftsBannerLabel').textContent =
    others.length + ' open ' + word + ' at this location';
  banner.style.display = '';
}

function fmtAge(iso) {
  if (!iso) return '';
  const then = new Date(iso).getTime();
  const now = Date.now();
  const mins = Math.floor((now - then) / 60000);
  if (mins < 1) return 'just now';
  if (mins < 60) return mins + 'm ago';
  const hrs = Math.floor(mins / 60);
  if (hrs < 24) return hrs + 'h ago';
  return Math.floor(hrs / 24) + 'd ago';
}

function renderDraftsList(drafts) {
  const list = document.getElementById('draftsList');
  const others = drafts.filter(d => d.id !== cart.draft_id);
  if (!others.length) {
    list.innerHTML = '<div class="reg-empty">No other open drafts.</div>';
    return;
  }
  list.innerHTML = others.map(d => {
    const itemWord = d.item_count === 1 ? 'item' : 'items';
    const meta = [
      d.item_count + ' ' + itemWord,
      d.customer || 'no customer',
      d.started_by ? 'by ' + d.started_by : null,
      fmtAge(d.updated_at),
    ].filter(Boolean).join(' · ');
    return '<div class="reg-draft-row" data-id="' + d.id + '">' +
      '<div>' +
        '<div style="font-weight:500">' + escapeHtml(d.customer || 'Walk-in') + '</div>' +
        '<div class="meta-line">' + escapeHtml(meta) + '</div>' +
      '</div>' +
      '<div class="total">' + fmt(d.total_cents) + '</div>' +
      '<div class="actions">' +
        '<button type="button" class="btn-resume" data-resume="' + d.id + '">Resume</button>' +
        '<button type="button" class="btn-discard" data-discard="' + d.id + '">Discard</button>' +
      '</div>' +
    '</div>';
  }).join('');
  list.querySelectorAll('[data-resume]').forEach(btn => {
    btn.addEventListener('click', () => resumeDraft(btn.dataset.resume));
  });
  list.querySelectorAll('[data-discard]').forEach(btn => {
    btn.addEventListener('click', () => discardDraftFromList(btn.dataset.discard));
  });
}

document.getElementById('draftsBanner').addEventListener('click', async () => {
  const drafts = await loadDrafts();
  renderDraftsList(drafts);
  openModal('draftsModal');
});

async function resumeDraft(id) {
  if (cart.items.length > 0) {
    const ok = await confirmDialog(
      'Your current cart will be replaced with this draft.',
      'Replace cart',
      'Replace current cart?'
    );
    if (!ok) return;
  }
  try {
    const res = await fetch(ROUTES.draftBase + '/' + id, {headers:{'Accept':'application/json'}});
    const data = await res.json();
    if (!data.ok) { showError(data.error || 'Could not load draft.'); closeModal('draftsModal'); return; }
    // Cancel any pending save for the OLD cart before we overwrite state.
    clearTimeout(draftSaveTimer);
    draftSaveTimer = null;
    cart.draft_id = data.draft.id;
    cart.customer = data.draft.customer;
    cart.tipCents = data.draft.tip_cents || 0;
    cart.tax_locked = !!data.draft.tax_locked;
    cart.items = (data.draft.items || []).map(i => ({
      key: ++lineKey,
      type: i.type,
      source_id: i.source_id,
      name: i.name,
      price_cents: i.price_cents,
      qty: i.qty,
      is_taxable: i.is_taxable,
      tax_cents: i.tax_cents || 0,
      tax_rate_snapshot: i.tax_rate_snapshot,
    }));
    closeModal('draftsModal');
    renderCart();
    refreshDraftsBanner(await loadDrafts());
  } catch (e) {
    showError('Network error loading draft.');
    closeModal('draftsModal');
  }
}

async function discardDraftFromList(id) {
  const ok = await confirmDialog(
    'This draft will be permanently deleted.',
    'Discard draft',
    'Discard this draft?'
  );
  if (!ok) return;
  try {
    const res = await fetch(ROUTES.draftBase + '/' + id, {
      method: 'DELETE',
      headers: {'Accept':'application/json', 'X-CSRF-TOKEN': CSRF},
    });
    const data = await res.json();
    if (!data.ok) { showError(data.error || 'Could not discard draft.'); return; }
    // If we just discarded the cart's own draft, clear it too.
    if (cart.draft_id === id) {
      cart.draft_id = null;
      cart.items = [];
      cart.customer = null;
      cart.tipCents = 0;
      renderCart();
    }
    const drafts = await loadDrafts();
    renderDraftsList(drafts);
    refreshDraftsBanner(drafts);
  } catch (e) {
    showError('Network error discarding draft.');
  }
}

renderCart();

// Auto-load a draft from ?draft=X in the URL. Used by the cash-pays-for-class
// flow in ClassController::registerViaCash, which prepares a drop-in cart and
// redirects here so the admin can take payment. Removes the param after load
// so a refresh doesn't re-trigger.
(function autoloadDraftFromUrl(){
  const params = new URLSearchParams(window.location.search);
  const draftId = params.get('draft');
  if (!draftId) return;
  // Strip the param so this only fires once.
  params.delete('draft');
  const cleanUrl = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
  window.history.replaceState({}, '', cleanUrl);
  resumeDraft(draftId);
})();

// MARKER-PATCH-195 — Payment-link status view. Opened from the appointment
// "Payment link sent" banner via ?status=<sale_id>. Shows a live timeline of
// the outstanding link, polls for resolution, and offers copy / cancel.
const LinkStatus = { saleId: null, sessionId: null, url: null, poll: null };

function lsRenderTimeline(sale, liveStatus) {
  const paid = (liveStatus === 'succeeded') || sale.payment_status === 'paid' || (sale.paid_cents > 0);
  const expired = (liveStatus === 'expired') || sale.sale_status === 'cancelled';
  const created = sale.created_at ? lsFmtDate(sale.created_at) : '';
  const rows = [];
  rows.push(['done', 'Link created', created]);
  rows.push(['done', 'Link sent to customer', sale.customer && sale.customer.email ? sale.customer.email : '']);
  if (paid) {
    rows.push(['done', 'Payment received', sale.paid_at ? lsFmtDate(sale.paid_at) : '']);
    rows.push(['done', 'Recorded to ledger', sale.payments && sale.payments.length ? (sale.payments[0].method_label || 'card') : '']);
  } else if (expired) {
    rows.push(['', 'Link expired without payment', '']);
  } else {
    rows.push(['now', 'Awaiting payment', 'checking automatically…']);
    rows.push(['', 'Payment received', '— pending —']);
    rows.push(['', 'Recorded to ledger', '— pending —']);
  }
  return rows.map(r =>
    '<div class="ls-te ' + r[0] + '"><div class="tt">' + esc(r[1]) + '</div>' +
    (r[2] ? '<div class="td">' + esc(r[2]) + '</div>' : '') + '</div>'
  ).join('');
}

function lsSetPill(status) {
  const el = document.getElementById('lsStatusPill');
  if (status === 'succeeded' || status === 'paid') { el.className = 'ls-pill paid'; el.textContent = 'Paid'; }
  else if (status === 'expired') { el.className = 'ls-pill expired'; el.textContent = 'Expired'; }
  else { el.className = 'ls-pill pending'; el.textContent = 'Awaiting payment'; }
}

function lsFmtDate(iso){ if(!iso) return ''; const d=new Date(iso); if(isNaN(d.getTime())) return iso; return d.toLocaleString(undefined,{year:'numeric',month:'short',day:'numeric',hour:'numeric',minute:'2-digit'}); }
function esc(s){ if(s==null) return ''; return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

async function openLinkStatus(saleId) {
  if (!saleId) return;
  LinkStatus.saleId = saleId;
  openModal('linkStatusModal');
  document.getElementById('lsHeader').textContent = 'Loading…';
  document.getElementById('lsTimeline').innerHTML = '';
  // Fetch the sale detail (showSaleJson — includes checkout + payments).
  let sale = null;
  try {
    const showUrl = ROUTES.saleShow ? ROUTES.saleShow.replace('__ID__', encodeURIComponent(saleId)) : null;
    if (showUrl) {
      const r = await fetch(showUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
      const d = await r.json();
      if (d.ok) sale = d.sale;
    }
  } catch (e) {}
  if (!sale) { document.getElementById('lsHeader').textContent = 'Could not load this sale.'; return; }

  const status = (sale.payment_status === 'paid' || sale.paid_cents > 0) ? 'paid'
               : (sale.sale_status === 'cancelled' ? 'expired' : 'pending');
  lsSetPill(status);
  document.getElementById('lsHeader').innerHTML =
    fmt(sale.total_cents) + ' · ' + esc(sale.customer ? sale.customer.name : 'No customer') +
    (sale.sale_number ? ' · <span style="font-family:var(--ia-font-mono);font-size:11px">' + esc(sale.sale_number) + '</span>' : '');
  document.getElementById('lsTimeline').innerHTML = lsRenderTimeline(sale, status === 'paid' ? 'succeeded' : (status === 'expired' ? 'expired' : 'pending'));

  // Cancel-link action only while still pending.
  const cancelBtn = document.getElementById('lsCancelLinkBtn');
  cancelBtn.style.display = (status === 'pending') ? '' : 'none';

  // Poll for resolution while pending.
  if (LinkStatus.poll) clearInterval(LinkStatus.poll);
  if (status === 'pending') {
    LinkStatus.poll = setInterval(async () => {
      try {
        const res = await fetch(ROUTES.checkoutSessionCheck, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
          body: JSON.stringify({ sale_id: saleId }),
        });
        const d = await res.json();
        if (!d.ok) return;
        if (d.status === 'succeeded' || d.status === 'expired') {
          clearInterval(LinkStatus.poll); LinkStatus.poll = null;
          openLinkStatus(saleId); // re-render terminal state
        }
      } catch (e) {}
    }, 4000);
  }
}

function lsClose() {
  if (LinkStatus.poll) { clearInterval(LinkStatus.poll); LinkStatus.poll = null; }
  closeModal('linkStatusModal');
}

document.getElementById('lsCloseBtn').addEventListener('click', lsClose);
document.getElementById('lsCancelLinkBtn').addEventListener('click', async () => {
  if (!LinkStatus.saleId) return;
  if (!confirm('Cancel this payment link? The customer will no longer be able to pay it.')) return;
  try {
    await fetch(ROUTES.checkoutSessionCancel, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify({ sale_id: LinkStatus.saleId }),
    });
  } catch (e) {}
  lsClose();
});

// Autoload from ?status=<sale_id> (from the appointment banner).
(function autoloadStatusFromUrl(){
  const params = new URLSearchParams(window.location.search);
  const sid = params.get('status');
  if (!sid) return;
  params.delete('status');
  const cleanUrl = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
  window.history.replaceState({}, '', cleanUrl);
  openLinkStatus(sid);
})();

// --- Refund picker ---
let refundPickerSale = null;  // the full sale object from lookupSale, kept while modal is open

async function openRefundPicker(saleId) {
  // We don't have a per-id endpoint yet; the sale_number-based lookup is what we have.
  // Re-trigger the search to get fresh data (cheap — last query is still in input).
  const q = searchInput.value.trim();
  if (!q || !looksLikeSaleNumber(q)) {
    showError('Could not load sale. Try searching the sale number again.');
    return;
  }
  try {
    const url = new URL(ROUTES.lookupSale, window.location.origin);
    url.searchParams.set('sale_number', normalizeSaleNumber(q));
    const r = await fetch(url, {headers: {'Accept': 'application/json'}});
    const d = await r.json();
    if (!d.ok) { showError(d.error || 'Sale not found.'); return; }
    refundPickerSale = d.sale;
    renderRefundPicker();
    openModal('refundModal');
  } catch (e) {
    showError('Network error loading sale.');
  }
}

// Auto-open the refund picker when arriving from the sale-detail modal's
// "Refund this sale" button (?refund=SALE_NUMBER). Looks the sale up by
// number directly so it doesn't depend on the search input being populated.
(function autoloadRefundFromUrl(){
  const params = new URLSearchParams(window.location.search);
  const saleNumber = params.get('refund');
  if (!saleNumber) return;
  // Strip the param so a refresh doesn't re-trigger.
  params.delete('refund');
  const cleanUrl = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
  window.history.replaceState({}, '', cleanUrl);

  (async () => {
    try {
      const url = new URL(ROUTES.lookupSale, window.location.origin);
      url.searchParams.set('sale_number', saleNumber);
      const r = await fetch(url, {headers: {'Accept': 'application/json'}});
      const d = await r.json();
      if (!d.ok) { showError(d.error || 'Sale not found.'); return; }
      refundPickerSale = d.sale;
      renderRefundPicker();
      openModal('refundModal');
    } catch (e) {
      showError('Network error loading sale.');
    }
  })();
})();

function renderRefundPicker() {
  const sale = refundPickerSale;
  if (!sale) return;
  document.getElementById('refundModalLede').textContent =
    'Sale #' + sale.sale_number + (sale.customer ? ' · ' + sale.customer : '') + ' · ' + fmt(sale.total_cents);

  const list = document.getElementById('refundList');
  if (!sale.items.length) {
    list.innerHTML = '<div class="reg-empty">No items on this sale.</div>';
    return;
  }
  list.innerHTML = sale.items.map((it, idx) => {
    const disabled = it.remaining <= 0;
    const meta = disabled
      ? 'fully refunded'
      : (it.already_refunded > 0 ? it.already_refunded + ' of ' + it.quantity + ' already refunded · ' + it.remaining + ' available' : it.quantity + ' available');
    return '<div class="reg-refund-row ' + (disabled ? 'disabled' : '') + '" data-idx="' + idx + '">' +
      '<input type="checkbox" data-pick="' + idx + '" ' + (disabled ? 'disabled' : '') + '>' +
      '<div>' +
        '<div class="name">' + escapeHtml(it.name) + '</div>' +
        '<div class="meta">' + escapeHtml(meta) + '</div>' +
      '</div>' +
      '<input type="number" class="qty-input" data-qty="' + idx + '" min="0" max="' + it.remaining + '" step="1" value="' + it.remaining + '" ' + (disabled ? 'disabled' : '') + '>' +
      '<div class="total">' + fmt(it.unit_price_cents) + '</div>' +
    '</div>';
  }).join('');

  // Wire checkbox + qty change to update the Add button state.
  list.querySelectorAll('[data-pick]').forEach(cb => cb.addEventListener('change', updateRefundAddBtn));
  list.querySelectorAll('[data-qty]').forEach(inp => inp.addEventListener('input', updateRefundAddBtn));
  updateRefundAddBtn();
}

function updateRefundAddBtn() {
  const list = document.getElementById('refundList');
  let anyChecked = false;
  list.querySelectorAll('[data-pick]:checked').forEach(cb => {
    const idx = cb.dataset.pick;
    const qty = parseFloat(list.querySelector('[data-qty="' + idx + '"]').value);
    if (qty > 0) anyChecked = true;
  });
  document.getElementById('refundAddBtn').disabled = !anyChecked;
}

document.getElementById('refundAddBtn').addEventListener('click', () => {
  const sale = refundPickerSale;
  if (!sale) return;
  const list = document.getElementById('refundList');

  // If cart already has refund lines from a different sale, block.
  if (cart.refund_meta && cart.refund_meta.original_sale_id !== sale.id) {
    showError('Cart already has refund lines from a different sale. Discard or commit those first.');
    return;
  }

  list.querySelectorAll('[data-pick]:checked').forEach(cb => {
    const idx = parseInt(cb.dataset.pick, 10);
    const item = sale.items[idx];
    const qty = parseFloat(list.querySelector('[data-qty="' + idx + '"]').value);
    if (!qty || qty <= 0) return;
    // Tax on a partial refund is a proportional share of original line tax.
    const fullQty = item.quantity || 1;
    const taxShare = item.tax_cents
      ? Math.round((item.tax_cents * qty) / fullQty)
      : 0;
    cart.refund_lines.push({
      key: ++lineKey,
      original_sale_id:  sale.id,
      original_item_id:  item.id,
      type:              item.type,
      name:              item.name,
      qty:               qty,
      price_cents:       item.unit_price_cents,
      tax_cents:         taxShare,
      is_taxable:        !!item.is_taxable,
    });
  });

  if (cart.refund_lines.length > 0 && !cart.refund_meta) {
    cart.refund_meta = {
      original_sale_id:    sale.id,
      original_sale_number: sale.sale_number,
      refund_method:       null,  // resolved at tender time
    };
  }

  closeModal('refundModal');
  refundPickerSale = null;
  searchInput.value = '';
  resultsArea.innerHTML = '<div class="reg-empty">Type to search products and services.</div>';
  renderCart();
  searchInput.focus();
});

// On page load, populate the banner.
loadDrafts().then(refreshDraftsBanner);

// If we were redirected here from the Quotes page with ?resume=<id>,
// load that quote into the cart automatically.
(function () {
  const params = new URLSearchParams(window.location.search);
  // MARKER-PATCH-232B — capture return_to BEFORE replaceState wipes the
  // query string. Local paths only; anything else is ignored.
  const rawReturnTo = params.get('return_to') || '';
  window.registerReturnTo = (rawReturnTo.startsWith('/') && !rawReturnTo.startsWith('//')) ? rawReturnTo : null;
  const resumeId = params.get('resume');
  if (!resumeId) return;
  // Strip the param from the URL so a refresh doesn't re-trigger.
  const cleanUrl = window.location.pathname;
  window.history.replaceState({}, '', cleanUrl);
  // Reuse the existing resumeDraft path — it handles drafts and quotes both.
  resumeDraft(resumeId);
})();

/* ===================================================================
   Appointment tray — lazy-loads on click. Lists every pending sale
   that came from a completed appointment, lets staff jump to one.
   =================================================================== */
(function () {
  var toggle = document.getElementById('appointment-tray-toggle');
  var listEl = document.getElementById('appointment-tray-list');
  if (!toggle || !listEl) return;

  var loaded = false;
  var open = false;

  toggle.addEventListener('click', function () {
    if (!loaded) {
      fetch('{{ route("tenant.register.appointment-tray", ["subdomain" => tenant()->subdomain]) }}', {
        headers: { 'Accept': 'application/json' }
      }).then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data.ok || !data.sales || !data.sales.length) {
            listEl.innerHTML = '<div style="padding:14px;font-size:12px;color:var(--ia-text-dim);text-align:center">No pending appointment sales.</div>';
            return;
          }
          listEl.innerHTML = data.sales.map(function (s) {
            // MARKER-PATCH-180 — row carries data-sale-id; a × dismiss button
            // removes the parked draft from the tray. Resume happens on the
            // row body (not the buttons), wired via delegation below.
            return '<div class="appt-tray-row" data-sale-id="' + escapeHtml(s.id) + '" style="display:grid;grid-template-columns:1fr auto auto auto;gap:14px;align-items:center;padding:10px 12px;background:var(--ia-bg);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);margin:4px 0">'
                 + '<div class="appt-tray-resume" style="cursor:pointer">'
                 + '<div style="font-weight:500;font-size:13px">' + escapeHtml(s.customer_name) + (s.ra_number ? ' — Appt ' + escapeHtml(s.ra_number) : '') + '</div>'
                 + '<div style="font-size:11px;color:var(--ia-text-dim);margin-top:2px">' + escapeHtml(s.sale_number) + ' · ' + s.item_count + ' line' + (s.item_count === 1 ? '' : 's') + '</div>'
                 + '</div>'
                 + '<div style="font-weight:500;font-size:14px">' + escapeHtml(s.total_display) + '</div>'
                 + '<button type="button" class="ia-btn ia-btn--primary ia-btn--sm appt-tray-pay">Take payment →</button>'
                 + '<button type="button" class="appt-tray-dismiss" aria-label="Remove from list" title="Remove from list" style="background:none;border:none;color:var(--ia-text-dim);font-size:18px;line-height:1;cursor:pointer;padding:4px 8px">×</button>'
                 + '</div>';
          }).join('');
          loaded = true;
          wireTrayRowActions();
        });
    }
    open = !open;
    listEl.style.display = open ? 'block' : 'none';
    toggle.textContent = open ? 'Hide list' : 'View list';
  });

  function escapeHtml(s) {
    if (s == null) return '';
    return String(s)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
  }

  // MARKER-PATCH-180 — wire resume/pay/dismiss on tray rows.
  function wireTrayRowActions() {
    listEl.querySelectorAll('.appt-tray-row').forEach(function (row) {
      var saleId = row.getAttribute('data-sale-id');
      var resume = function () { window.location.href = '?resume=' + saleId; };
      var body = row.querySelector('.appt-tray-resume');
      var pay  = row.querySelector('.appt-tray-pay');
      if (body) body.addEventListener('click', resume);
      if (pay)  pay.addEventListener('click', function (e) { e.stopPropagation(); resume(); });
      var dismiss = row.querySelector('.appt-tray-dismiss');
      if (dismiss) dismiss.addEventListener('click', async function (e) {
        e.stopPropagation();
        dismiss.disabled = true;
        try {
          var res = await fetch('{{ route("tenant.register.appointment-tray.dismiss", ["subdomain" => tenant()->subdomain]) }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
            body: JSON.stringify({ sale_id: saleId }),
            credentials: 'same-origin',
          });
          var data = await res.json();
          if (!data.ok) { dismiss.disabled = false; if (window.IntakeToast) IntakeToast.error(data.error || 'Could not remove.'); return; }
          row.style.transition = 'opacity .2s ease';
          row.style.opacity = '0';
          setTimeout(function () {
            row.remove();
            // Decrement the banner count; hide the whole banner if empty.
            var countEl = document.querySelector('#appointment-tray-banner div[style*="font-weight:500"]');
            var banner = document.getElementById('appointment-tray-banner');
            if (!listEl.querySelector('.appt-tray-row')) {
              if (banner) banner.style.display = 'none';
              listEl.style.display = 'none';
            } else if (countEl) {
              var n = (listEl.querySelectorAll('.appt-tray-row').length);
              countEl.textContent = n + (n === 1 ? ' appointment is' : ' appointments are') + ' ready for checkout';
            }
          }, 210);
        } catch (err) {
          dismiss.disabled = false;
          if (window.IntakeToast) IntakeToast.error('Network error.');
        }
      });
    });
  }
})();
</script>

@if(($tenant->direct_payments_enabled ?? false) && ($tenant->settings['stripe_register_enabled'] ?? true))
{{-- MARKER-PATCH-170 — Stripe.js for Direct Payments hand-keyed flow --}}
<script src="https://js.stripe.com/v3/"></script>
{{-- MARKER-PATCH-172 — QR code library for send-payment-link --}}
<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
@endif
@endpush

SOAV_5_EOF

echo "special-orders-auto-vendor applied — server: git pull && php artisan migrate --force && php artisan view:clear"
