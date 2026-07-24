#!/bin/bash
# special-orders-origin — step 2 of 3 (Direction A). The special-orders list
# gains an Origin column: where each request came from, and whether that
# source still exists.
#   · live      — the sale or work order is still there, shown by number
#   · orphan    — the sale or work order was deleted; the request survived
#   · unknown   — created from the register BEFORE sale linking existed, so
#                 the link was never recorded and cannot be reconstructed.
#                 Said plainly rather than guessed at.
#   · confirmed — someone answered "still needed" (persisted, so the queue
#                 stops asking)
#   Orphaned and unknown requests still in "needed" carry their two honest
#   choices inline — Still needed / Cancel — resolved without leaving the
#   list. Age in days shows on every row, so a March request no longer looks
#   like this morning's.
#   Origin is computed with two lookups for the whole page (no N+1).
# Adds ONE route (special-orders/{id}/confirm-source), inserted surgically.
# Server: MIGRATION REQUIRED, route:clear + route:cache, view:clear.
set -e
cd "$(git rev-parse --show-toplevel)"
if grep -q "MARKER-SO-ORIGIN" app/Http/Controllers/Tenant/SpecialOrderController.php; then
  echo "special-orders-origin already applied — aborting."; exit 1
fi
if ! grep -q "MARKER-SO-SALE-LINK" app/Services/Tenant/SpecialOrderService.php; then
  echo "special-orders-linking not applied — wrong base, aborting."; exit 1
fi

python3 - <<'PYROUTE'
s = open('routes/web.php').read()
if 'confirm-source' in s:
    print('route already present')
else:
    old = "                Route::post('/{id}/cancel',                        [TenantControllers\\SpecialOrderController::class, 'cancel'])->name('cancel');"
    assert s.count(old) == 1, 'cancel route anchor not found — routes/web.php differs, aborting'
    s = s.replace(old, old + chr(10) + "                Route::post('/{id}/confirm-source',                [TenantControllers\\SpecialOrderController::class, 'confirmSource'])->name('confirm-source'); // MARKER-SO-ORIGIN")
    open('routes/web.php', 'w').write(s)
    print('route inserted')
PYROUTE

cat > 'database/migrations/2026_07_23_000004_add_source_confirmed_to_tenant_special_orders.php' <<'SOORIG_0_EOF'
<?php

// MARKER-SO-ORIGIN — "still needed" is a decision, so it has to persist.
// Without it the queue would re-flag the same orphaned order every time
// someone looked at it.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_special_orders', function (Blueprint $t) {
            $t->timestamp('source_confirmed_at')->nullable();
            $t->uuid('source_confirmed_by_user_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_special_orders', function (Blueprint $t) {
            $t->dropColumn(['source_confirmed_at', 'source_confirmed_by_user_id']);
        });
    }
};
SOORIG_0_EOF

cat > 'app/Models/Tenant/TenantSpecialOrder.php' <<'SOORIG_1_EOF'
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
SOORIG_1_EOF

cat > 'app/Http/Controllers/Tenant/SpecialOrderController.php' <<'SOORIG_2_EOF'
<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Tenant\Concerns\GuardsRetailAccess;
use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantInventoryItem;
use App\Models\Tenant\TenantSpecialOrder;
use App\Models\Tenant\TenantVendor;
use App\Services\Tenant\SpecialOrderService;
use App\Services\Tenant\SpecialOrderValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class SpecialOrderController extends Controller
{
    use GuardsRetailAccess;

    public function __construct(private SpecialOrderService $service)
    {
    }

    /**
     * SO list with tab filters. The active tab is derived from the
     * `view` query param. All open is the default. Each tab is just
     * a different scope chain on the underlying TenantSpecialOrder
     * query — counts are cheap to compute alongside.
     */
    public function index(Request $request): View
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        $view = $request->input('view', 'open');
        $page = max(1, (int) $request->input('page', 1));
        $perPage = 25;

        // Compute counts for all tabs — small queries, all indexed
        $counts = [
            'open'          => TenantSpecialOrder::where('tenant_id', $tenant->id)->open()->count(),
            'arrived_bench' => TenantSpecialOrder::where('tenant_id', $tenant->id)->arrivedBench()->count(),
            'overdue'       => TenantSpecialOrder::where('tenant_id', $tenant->id)->overdue()->count(),
            'pulled'        => TenantSpecialOrder::where('tenant_id', $tenant->id)
                                ->where('status', TenantSpecialOrder::STATUS_PULLED)->count(),
            'cancelled'     => TenantSpecialOrder::where('tenant_id', $tenant->id)
                                ->where('status', TenantSpecialOrder::STATUS_CANCELLED)->count(),
        ];

        $q = TenantSpecialOrder::where('tenant_id', $tenant->id)
            ->with(['vendor', 'customer', 'appointment', 'item']);

        switch ($view) {
            case 'arrived_bench':
                $q->arrivedBench()->orderBy('arrived_at');
                break;
            case 'overdue':
                $q->overdue()->orderBy('expected_arrival_date');
                break;
            case 'pulled':
                $q->where('status', TenantSpecialOrder::STATUS_PULLED)
                  ->orderByDesc('pulled_at');
                break;
            case 'cancelled':
                $q->where('status', TenantSpecialOrder::STATUS_CANCELLED)
                  ->orderByDesc('cancelled_at');
                break;
            default: // 'open'
                $q->open()->orderByRaw("
                    CASE status
                        WHEN 'arrived' THEN 0
                        WHEN 'ordered' THEN 1
                        WHEN 'needed' THEN 2
                        ELSE 3
                    END
                ")->orderBy('expected_arrival_date');
                break;
        }

        $total = $q->count();
        $totalPages = max(1, (int) ceil($total / $perPage));
        $sos = $q->offset(($page - 1) * $perPage)->limit($perPage)->get();

        // Drawer prep: vendors list for the picker, plus today's date
        // for the date-picker default. Item search is XHR, customers
        // are XHR — only vendors is small enough to inline.
        $vendors = TenantVendor::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        // MARKER-SO-ORIGIN — for each listed order: where did it come from,
        // and does that source still exist? Two lookups, no N+1.
        $origins = [];
        if ($sos->isNotEmpty()) {
            $saleIds = $sos->pluck('sale_id')->filter()->unique();
            $liveSales = $saleIds->isEmpty() ? collect() : \App\Models\Tenant\TenantSale::where('tenant_id', $tenant->id)
                ->whereIn('id', $saleIds)->pluck('sale_number', 'id');

            $apptIds = $sos->pluck('appointment_id')->filter()->unique();
            $liveAppts = $apptIds->isEmpty() ? collect() : \App\Models\Tenant\TenantAppointment::where('tenant_id', $tenant->id)
                ->whereIn('id', $apptIds)->pluck('ra_number', 'id');

            foreach ($sos as $so) {
                if ($so->appointment_id) {
                    $origins[$so->id] = isset($liveAppts[$so->appointment_id])
                        ? ['state' => 'live', 'label' => $liveAppts[$so->appointment_id]]
                        : ['state' => 'orphan', 'label' => 'Work order deleted'];
                } elseif ($so->sale_id) {
                    $origins[$so->id] = isset($liveSales[$so->sale_id])
                        ? ['state' => 'live', 'label' => 'Sale ' . $liveSales[$so->sale_id]]
                        : ['state' => 'orphan', 'label' => 'Sale removed'];
                } elseif ($so->created_from === 'register') {
                    // Created before sale linking existed — the link was never
                    // recorded, so it cannot be reconstructed. Say so plainly.
                    $origins[$so->id] = ['state' => 'unknown', 'label' => 'Origin not recorded'];
                } else {
                    $origins[$so->id] = ['state' => 'manual', 'label' => ucfirst((string) $so->created_from)];
                }

                if ($so->source_confirmed_at && $origins[$so->id]['state'] !== 'live') {
                    $origins[$so->id] = ['state' => 'confirmed', 'label' => 'Confirmed still needed'];
                }
            }
        }

        return view('tenant.special-orders.index', [
            'origins'    => $origins, // MARKER-SO-ORIGIN
            'sos'        => $sos,
            'view'       => $view,
            'counts'     => $counts,
            'total'      => $total,
            'page'       => $page,
            'totalPages' => $totalPages,
            'vendors'    => $vendors,
        ]);
    }

    /**
     * SO detail page. Eager-loads everything the view needs.
     */
    public function show(Request $request, string $id): View
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        $so = TenantSpecialOrder::where('tenant_id', $tenant->id)
            ->with(['vendor', 'customer', 'appointment', 'item', 'notes.user', 'parent', 'children'])
            ->findOrFail($id);

        // If this SO is part of a batch, fetch siblings for the "linked rows" panel
        $batchSiblings = collect();
        if ($so->batch_id) {
            $batchSiblings = TenantSpecialOrder::where('tenant_id', $tenant->id)
                ->where('batch_id', $so->batch_id)
                ->where('id', '!=', $so->id)
                ->with(['customer', 'appointment'])
                ->get();
        }

        return view('tenant.special-orders.show', [
            'so'            => $so,
            'batchSiblings' => $batchSiblings,
        ]);
    }

    /**
     * Create new SO(s) from the drawer. The drawer can submit
     * multiple allocation rows; this method creates one SO row
     * per allocation, sharing a batch_id when there's >1 row.
     *
     * Validation is permissive — the service layer enforces the
     * real rules (status, required fields per status). This method
     * just validates shape.
     */
    public function store(Request $request): RedirectResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        $data = $request->validate([
            'inventory_item_id'         => ['nullable', 'string'],
            'item_name'                 => ['required', 'string', 'max:255'],
            'vendor_id'                 => ['nullable', 'string'],
            'po_number'                 => ['nullable', 'string', 'max:64'],
            'expected_arrival_date'     => ['nullable', 'date'],
            'unit_cost_cents_estimated' => ['nullable', 'integer', 'min:0'],
            'allocations'               => ['required', 'array', 'min:1'],
            'allocations.*.mode'        => ['required', 'in:customer,customer_appt,stock'],
            'allocations.*.customer_id' => ['nullable', 'string'],
            'allocations.*.appointment_id' => ['nullable', 'string'],
            'allocations.*.quantity'    => ['required', 'integer', 'min:1'],
            'notes'                     => ['nullable', 'string'],
            'deposit_cents'             => ['nullable', 'integer', 'min:0'],
        ]);

        // Determine initial status: if vendor + PO + ETA provided → 'ordered'.
        // Otherwise → 'needed'.
        $hasFullOrderInfo = !empty($data['vendor_id'])
            && !empty($data['po_number'])
            && !empty($data['expected_arrival_date']);

        $initialStatus = $hasFullOrderInfo
            ? TenantSpecialOrder::STATUS_ORDERED
            : TenantSpecialOrder::STATUS_NEEDED;

        // Generate a batch_id if >1 allocation row
        $batchId = count($data['allocations']) > 1 ? \Illuminate\Support\Str::uuid()->toString() : null;

        try {
            $created = [];
            foreach ($data['allocations'] as $alloc) {
                $row = [
                    'tenant_id'                 => $tenant->id,
                    'inventory_item_id'         => $data['inventory_item_id'] ?? null,
                    'item_name_snapshot'        => $data['item_name'],
                    'quantity'                  => (int) $alloc['quantity'],
                    'customer_id'               => $alloc['mode'] === 'stock' ? null : ($alloc['customer_id'] ?? null),
                    'appointment_id'            => $alloc['mode'] === 'customer_appt' ? ($alloc['appointment_id'] ?? null) : null,
                    'vendor_id'                 => $data['vendor_id'] ?? null,
                    'po_number'                 => $data['po_number'] ?? null,
                    'expected_arrival_date'     => $data['expected_arrival_date'] ?? null,
                    'unit_cost_cents_estimated' => $data['unit_cost_cents_estimated'] ?? null,
                    'status'                    => $initialStatus,
                    'created_from'              => 'manual',
                    'batch_id'                  => $batchId,
                    'created_by_user_id'        => Auth::guard('tenant')->id(),
                    'deposit_cents'             => $data['deposit_cents'] ?? 0,
                    'notes'                     => $data['notes'] ?? null,
                ];
                $created[] = $this->service->create($row);
            }
        } catch (SpecialOrderValidationException $e) {
            return back()
                ->withInput()
                ->with('flash', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        $count = count($created);
        $msg = $count === 1
            ? "Special order {$created[0]->so_number} created."
            : "{$count} special orders created (batch).";

        return redirect()->route('tenant.special-orders.index')
            ->with('flash', ['type' => 'success', 'message' => $msg]);
    }

    /**
     * Mark an SO as ordered (needed → ordered).
     * If the SO already has vendor + PO + ETA from creation, this is
     * a no-op of sorts — the service layer rejects illegal transitions.
     * Required when the SO was created with status=needed and now
     * staff has placed the order with a vendor.
     */
    public function markOrdered(Request $request, string $id): RedirectResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        $this->ensureBelongsToTenant($id, $tenant->id);

        $data = $request->validate([
            'vendor_id'             => ['required', 'string'],
            'po_number'             => ['required', 'string', 'max:64'],
            'expected_arrival_date' => ['required', 'date'],
            'vendor_reference'      => ['nullable', 'string', 'max:64'],
            'unit_cost_cents_estimated' => ['nullable', 'integer', 'min:0'],
        ]);

        try {
            $this->service->markOrdered($id, $data);
        } catch (SpecialOrderValidationException $e) {
            return back()->with('flash', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        return redirect()->route('tenant.special-orders.show', ['id' => $id])
            ->with('flash', ['type' => 'success', 'message' => 'Marked ordered.']);
    }

    /**
     * Mark an SO as arrived (ordered → arrived).
     * Stage 4b does FULL arrival only (received_qty = quantity).
     * Partial receipts ship in Stage 6 with the receiving integration.
     */
    public function markArrived(Request $request, string $id): RedirectResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        $this->ensureBelongsToTenant($id, $tenant->id);

        $data = $request->validate([
            'unit_cost_cents_actual' => ['nullable', 'integer', 'min:0'],
            'vendor_invoice_number'  => ['nullable', 'string', 'max:64'],
            'vendor_invoice_date'    => ['nullable', 'date'],
        ]);

        try {
            $this->service->markArrived(
                $id,
                null, // null = full receipt
                $data['unit_cost_cents_actual'] ?? null,
                $data['vendor_invoice_number'] ?? null,
                $data['vendor_invoice_date'] ?? null,
            );
        } catch (SpecialOrderValidationException $e) {
            return back()->with('flash', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        return redirect()->route('tenant.special-orders.show', ['id' => $id])
            ->with('flash', ['type' => 'success', 'message' => 'Marked arrived.']);
    }

    public function markPulled(Request $request, string $id): RedirectResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        $this->ensureBelongsToTenant($id, $tenant->id);

        try {
            $this->service->markPulled($id);
        } catch (SpecialOrderValidationException $e) {
            return back()->with('flash', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        return redirect()->route('tenant.special-orders.show', ['id' => $id])
            ->with('flash', ['type' => 'success', 'message' => 'Marked pulled.']);
    }

    // MARKER-SO-SALE-LINK — returns JSON for the register's inline cleanup,
    // and keeps redirecting for the normal admin form post.
    public function cancel(Request $request, string $id): RedirectResponse|\Illuminate\Http\JsonResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        $this->ensureBelongsToTenant($id, $tenant->id);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->service->cancel($id, $data['reason'] ?? null);
        } catch (SpecialOrderValidationException $e) {
            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
            }
            return back()->with('flash', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return redirect()->route('tenant.special-orders.show', ['id' => $id])
            ->with('flash', ['type' => 'success', 'message' => 'Special order cancelled.']);
    }

    /**
     * MARKER-SO-ORIGIN — "yes, this is still needed" for an order whose
     * source is gone. Persisted so the queue stops asking.
     */
    public function confirmSource(Request $request, string $id): \Illuminate\Http\JsonResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);
        $this->ensureBelongsToTenant($id, $tenant->id);

        TenantSpecialOrder::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->update([
                'source_confirmed_at'         => now(),
                'source_confirmed_by_user_id' => auth('tenant')->id(),
            ]);

        return response()->json(['ok' => true]);
    }

    public function addNote(Request $request, string $id): RedirectResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        $this->ensureBelongsToTenant($id, $tenant->id);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        try {
            $this->service->addNote(
                $id,
                Auth::guard('tenant')->id(),
                $data['body'],
                false
            );
        } catch (SpecialOrderValidationException $e) {
            return back()->with('flash', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        return redirect()->route('tenant.special-orders.show', ['id' => $id])
            ->with('flash', ['type' => 'success', 'message' => 'Note added.']);
    }

    /**
     * XHR endpoint for the drawer's allocation picker. Returns
     * upcoming (next 60 days) appointments for a given customer.
     * Scoped to the current tenant.
     */
    public function appointmentsForCustomer(Request $request): JsonResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        $customerId = $request->query('customer_id');
        if (empty($customerId)) {
            return response()->json(['ok' => false, 'error' => 'customer_id required'], 422);
        }

        $appts = TenantAppointment::where('tenant_id', $tenant->id)
            ->where('customer_id', $customerId)
            ->whereDate('appointment_date', '>=', tnow()->toDateString()) // MARKER-TZ-WAVE1 — appointment_date is a naive tenant-local DATE
            ->whereDate('appointment_date', '<=', tnow()->addDays(60)->toDateString())
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->orderBy('appointment_date')
            ->limit(10)
            ->get(['id', 'ra_number', 'appointment_date', 'status']);

        return response()->json([
            'ok' => true,
            'appointments' => $appts->map(fn($a) => [
                'id'     => $a->id,
                'label'  => $a->ra_number . ' · ' . $a->appointment_date->format('M j, Y'),
                'date'   => $a->appointment_date->format('Y-m-d'),
                'status' => $a->status,
            ]),
        ]);
    }

    /**
     * Helper — abort 404 if the SO doesn't belong to this tenant.
     * The service layer's findOrFail is bare; we scope here.
     */
    private function ensureBelongsToTenant(string $id, string $tenantId): void
    {
        $exists = TenantSpecialOrder::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->exists();
        if (!$exists) {
            abort(404);
        }
    }
}
SOORIG_2_EOF

cat > 'resources/views/tenant/special-orders/index.blade.php' <<'SOORIG_3_EOF'
@extends('layouts.tenant.app')
@php $pageTitle = 'Special Orders'; @endphp

@section('content')

{{-- SO-LIST · tab-filtered list with desktop table + mobile cards.
     Parallel render pattern matches customers/vendors. --}}

{{-- ========== DESKTOP HEAD ========== --}}
<div class="ia-page-head so-desktop-only">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Special orders</h1>
    <p class="ia-page-subtitle">
      {{ $counts['open'] }} open
      @if($counts['arrived_bench'] > 0) · {{ $counts['arrived_bench'] }} on bench @endif
      @if($counts['overdue'] > 0) · <span style="color:#F87171">{{ $counts['overdue'] }} overdue</span> @endif
    </p>
  </div>
  <div class="ia-page-actions">
    <button type="button" class="ia-btn ia-btn--primary" onclick="SoDrawer.open()">
      + New special order
    </button>
  </div>
</div>

{{-- ========== MOBILE HEAD ========== --}}
<div class="so-mobile-only so-mobile-head">
  <h1 class="so-mobile-title">Special orders</h1>
  <p class="so-mobile-sub">{{ $counts['open'] }} open · {{ $counts['arrived_bench'] }} on bench</p>
</div>

@if(session('flash'))
  <div class="ia-flash ia-flash--{{ session('flash')['type'] }}">{{ session('flash')['message'] }}</div>
@endif

{{-- ========== TAB FILTERS ========== --}}
<div class="so-tabs">
  @php
    $tabs = [
      'open'          => ['label' => 'All open',      'count' => $counts['open']],
      'arrived_bench' => ['label' => 'Arrived bench', 'count' => $counts['arrived_bench']],
      'overdue'       => ['label' => 'Overdue',       'count' => $counts['overdue']],
      'pulled'        => ['label' => 'Pulled',        'count' => $counts['pulled']],
      'cancelled'     => ['label' => 'Cancelled',     'count' => $counts['cancelled']],
    ];
  @endphp
  @foreach($tabs as $key => $tab)
    <a href="{{ route('tenant.special-orders.index', ['view' => $key]) }}"
       class="so-tab {{ $view === $key ? 'active' : '' }}">
      {{ $tab['label'] }}
      <span class="so-tab-count">{{ $tab['count'] }}</span>
    </a>
  @endforeach
</div>

{{-- ========== LIST ========== --}}
@if($sos->isEmpty())
  <div class="ia-empty">
    <p>No special orders in this view.</p>
    <p class="ia-empty-sub">
      @if($view === 'open')
        Create one with "+ New special order" above. Or wait — SOs will appear here as they're created from the register, appointments, or item detail pages (those flows ship in upcoming stages).
      @else
        Try a different tab.
      @endif
    </p>
  </div>
@else

{{-- MARKER-SO-ORIGIN --}}
<style>
  .so-origin{font-size:10.5px;font-weight:700;border-radius:100px;padding:3px 9px;white-space:nowrap;border:0.5px solid var(--ia-border);color:var(--ia-text-muted)}
  .so-origin--live{border-color:rgba(143,184,240,.35);color:#8FB8F0;background:rgba(143,184,240,.08)}
  .so-origin--orphan{border-color:rgba(240,149,149,.35);color:#F09595;background:rgba(240,149,149,.08)}
  .so-origin--unknown{border-color:rgba(232,163,61,.35);color:#E8A33D;background:rgba(232,163,61,.08)}
  .so-origin--confirmed{border-color:rgba(127,217,143,.35);color:#7FD98F;background:rgba(127,217,143,.08)}
  .so-origin-acts{display:flex;gap:5px;margin-top:6px}
  .so-oa{font-family:inherit;font-size:10.5px;font-weight:700;border-radius:7px;padding:4px 8px;cursor:pointer;border:0.5px solid var(--ia-border);background:transparent;color:var(--ia-text)}
  .so-oa:hover{border-color:var(--ia-accent)}
  .so-oa.danger{color:#F09595;border-color:rgba(240,149,149,.35)}
  .so-oa[disabled]{opacity:.5;cursor:default}
</style>

  {{-- Desktop table --}}
  <div class="ia-card so-desktop-only">
    <table class="ia-table">
      <thead>
        <tr>
          <th>SO</th>
          <th>Item</th>
          <th>Qty</th>
          <th>For</th>
          <th>Vendor</th>
          <th>Origin</th>{{-- MARKER-SO-ORIGIN --}}
          <th>Status</th>
          <th>ETA</th>
        </tr>
      </thead>
      <tbody>
        @foreach($sos as $so)
          <tr style="cursor:pointer" onclick="window.location.href='{{ route('tenant.special-orders.show', ['id' => $so->id]) }}'">
            <td>
              <strong>{{ $so->so_number }}</strong>
              @if($so->batch_id)
                <div class="ia-text-muted" style="font-size:10.5px">batch</div>
              @endif
            </td>
            <td>
              <strong>{{ $so->item_name_snapshot }}</strong>
              @if($so->item && $so->item->sku)
                <div class="ia-text-muted" style="font-size:11.5px">{{ $so->item->sku }}</div>
              @endif
            </td>
            <td>{{ $so->quantity }}</td>
            <td>
              @if($so->customer)
                <strong>{{ $so->customer->first_name }} {{ $so->customer->last_name }}</strong>
                @if($so->appointment)
                  <div class="ia-text-muted" style="font-size:11.5px">
                    {{ $so->appointment->ra_number }} · {{ $so->appointment->appointment_date?->format('M j') }}
                  </div>
                @endif
              @else
                <span class="ia-text-muted">Shop stock</span>
              @endif
            </td>
            <td>
              @if($so->vendor)
                {{ $so->vendor->name }}
              @else
                <span class="ia-text-muted" style="font-size:12px">TBD</span>
              @endif
            </td>
            {{-- MARKER-SO-ORIGIN — where it came from, and whether that source
                 still exists. Orphans carry their two honest choices inline. --}}
            @php $og = $origins[$so->id] ?? ['state' => 'manual', 'label' => '—']; @endphp
            <td onclick="event.stopPropagation()" style="cursor:default">
              <span class="so-origin so-origin--{{ $og['state'] }}">{{ $og['label'] }}</span>
              @if($so->created_at)
                <div class="ia-text-muted" style="font-size:10.5px;margin-top:3px">
                  {{ (int) $so->created_at->diffInDays(now()) }}d old
                </div>
              @endif
              @if(in_array($og['state'], ['orphan', 'unknown'], true) && $so->status === \App\Models\Tenant\TenantSpecialOrder::STATUS_NEEDED)
                <div class="so-origin-acts" data-so="{{ $so->id }}">
                  <button type="button" class="so-oa" data-so-keep>Still needed</button>
                  <button type="button" class="so-oa danger" data-so-drop>Cancel</button>
                </div>
              @endif
            </td>
            <td>
              @php
                $isOverdue = $so->status === 'ordered' && $so->expected_arrival_date && $so->expected_arrival_date->isPast();
              @endphp
              @if($isOverdue)
                <span class="so-status so-status--overdue">Overdue</span>
              @else
                <span class="so-status so-status--{{ $so->status }}">{{ ucfirst($so->status) }}</span>
              @endif
            </td>
            <td class="ia-text-muted" style="font-size:12px">
              @if($so->expected_arrival_date)
                {{ $so->expected_arrival_date->format('M j') }}
              @else
                —
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>

  {{-- Mobile cards --}}
  <div class="so-mobile-only so-cards">
    @foreach($sos as $so)
      @php
        $isOverdue = $so->status === 'ordered' && $so->expected_arrival_date && $so->expected_arrival_date->isPast();
      @endphp
      <a href="{{ route('tenant.special-orders.show', ['id' => $so->id]) }}"
         class="so-card">
        <div class="so-card-top">
          <span class="so-card-num">{{ $so->so_number }}</span>
          @if($isOverdue)
            <span class="so-status so-status--overdue">Overdue</span>
          @else
            <span class="so-status so-status--{{ $so->status }}">{{ ucfirst($so->status) }}</span>
          @endif
        </div>
        <div class="so-card-item">{{ $so->item_name_snapshot }} <span class="ia-text-muted">×{{ $so->quantity }}</span></div>
        <div class="so-card-meta">
          @if($so->customer)
            {{ $so->customer->first_name }} {{ $so->customer->last_name }}
          @else
            Shop stock
          @endif
          @if($so->vendor) · {{ $so->vendor->name }} @endif
          @if($so->expected_arrival_date) · ETA {{ $so->expected_arrival_date->format('M j') }} @endif
        </div>
      </a>
    @endforeach
  </div>

  @if($totalPages > 1)
    <div class="ia-pagination">
      @for($p = 1; $p <= $totalPages; $p++)
        <a href="{{ route('tenant.special-orders.index', array_merge(request()->query(), ['page' => $p])) }}"
           class="ia-page-btn {{ $p === $page ? 'active' : '' }}">{{ $p }}</a>
      @endfor
    </div>
  @endif
@endif

{{-- ========== DRAWER (universal create surface) ========== --}}
@include('tenant.special-orders._drawer', ['vendors' => $vendors])

{{-- Mobile FAB (alternate entry to the drawer) --}}
<button type="button" class="so-fab so-mobile-only" onclick="SoDrawer.open()" aria-label="New special order">+</button>

@push('styles')
<style>
/* SO-LIST styles — parallels customers/vendors patterns */

.so-mobile-only { display: none; }

@media (max-width: 700px) {
  .so-desktop-only { display: none; }
  .so-mobile-only  { display: block; }
  .so-cards        { display: flex; }
  .so-mobile-head { padding: 16px 0 12px; }
  .so-mobile-title { font-size: 22px; font-weight: 600; margin: 0; color: var(--ia-text); }
  .so-mobile-sub { font-size: 12px; color: var(--ia-text-muted); margin: 2px 0 0; }
}

/* Tabs */
.so-tabs {
  display: flex;
  gap: 4px;
  border-bottom: 1px solid var(--ia-border);
  margin-bottom: 18px;
  overflow-x: auto;
  scrollbar-width: none;
}
.so-tabs::-webkit-scrollbar { display: none; }
.so-tab {
  padding: 10px 14px;
  font-size: 13px;
  font-weight: 500;
  color: var(--ia-text-muted);
  border-bottom: 2px solid transparent;
  text-decoration: none;
  white-space: nowrap;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.so-tab:hover { color: var(--ia-text); }
.so-tab.active {
  color: var(--ia-text);
  border-bottom-color: var(--ia-accent);
}
.so-tab-count {
  font-size: 11px;
  font-weight: 600;
  padding: 1px 6px;
  border-radius: 999px;
  background: var(--ia-surface);
  color: var(--ia-text-muted);
}
.so-tab.active .so-tab-count {
  background: var(--ia-accent);
  color: #000;
}

/* Status pills */
.so-status {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 99px;
  font-size: 10.5px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
.so-status--needed   { background: rgba(167,139,250,0.10); color: #A78BFA; }
.so-status--ordered  { background: rgba(96,165,250,0.10);  color: #60A5FA; }
.so-status--arrived  { background: rgba(190,242,100,0.10); color: var(--ia-accent); }
.so-status--pulled   { background: rgba(200,200,200,0.06); color: var(--ia-text-muted); }
.so-status--cancelled{ background: rgba(248,113,113,0.10); color: #F87171; text-decoration: line-through; }
.so-status--overdue  { background: rgba(248,113,113,0.15); color: #F87171; }

/* Mobile cards */
.so-cards { flex-direction: column; gap: 8px; }
.so-card {
  background: var(--ia-surface);
  border: 0.5px solid var(--ia-border);
  border-radius: 10px;
  padding: 14px 16px;
  text-decoration: none;
  color: inherit;
  display: block;
}
.so-card-top {
  display: flex; align-items: center; justify-content: space-between;
  gap: 10px; margin-bottom: 6px;
}
.so-card-num { font-size: 13px; font-weight: 700; color: var(--ia-text); font-variant-numeric: tabular-nums; }
.so-card-item { font-size: 14.5px; color: var(--ia-text); margin-bottom: 3px; }
.so-card-meta { font-size: 11.5px; color: var(--ia-text-muted); }

/* Mobile FAB */
.so-fab {
  position: fixed;
  bottom: calc(76px + env(safe-area-inset-bottom, 0));
  right: 18px;
  width: 56px; height: 56px;
  border-radius: 50%;
  background: var(--ia-accent);
  color: #000;
  font-size: 28px; font-weight: 400;
  border: none;
  box-shadow: 0 4px 16px rgba(0,0,0,0.3);
  cursor: pointer;
  z-index: 30;
}
@media (min-width: 701px) {
  .so-fab { display: none; }
}
</style>
@endpush

@endsection

{{-- MARKER-SO-ORIGIN — resolve an orphaned request without leaving the list --}}
<script>
(function () {
  var confirmUrl = @json(route('tenant.special-orders.confirm-source', ['id' => '__ID__']));
  var cancelUrl  = @json(route('tenant.special-orders.cancel', ['id' => '__ID__']));
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content;

  function post(url, body, wrap, okText) {
    wrap.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
    fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
      body: JSON.stringify(body || {}),
    })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j && j.ok) {
          wrap.innerHTML = '<span class="ia-text-muted" style="font-size:10.5px">' + okText + '</span>';
          if (window.IntakeToast) IntakeToast.success(okText);
        } else {
          wrap.querySelectorAll('button').forEach(function (b) { b.disabled = false; });
          if (window.IntakeToast) IntakeToast.error((j && j.error) || 'Could not save.');
        }
      })
      .catch(function () {
        wrap.querySelectorAll('button').forEach(function (b) { b.disabled = false; });
        if (window.IntakeToast) IntakeToast.error('Network error.');
      });
  }

  document.addEventListener('click', function (e) {
    var wrap = e.target.closest('.so-origin-acts');
    if (!wrap) return;
    var id = wrap.getAttribute('data-so');
    if (e.target.hasAttribute('data-so-keep')) {
      post(confirmUrl.replace('__ID__', id), {}, wrap, 'Confirmed still needed');
    } else if (e.target.hasAttribute('data-so-drop')) {
      post(cancelUrl.replace('__ID__', id), { reason: 'Source removed — abandoned request.' }, wrap, 'Cancelled');
    }
  });
})();
</script>
SOORIG_3_EOF

echo "special-orders-origin applied — server: git pull && php artisan migrate --force && php artisan route:clear && php artisan route:cache && php artisan view:clear"
