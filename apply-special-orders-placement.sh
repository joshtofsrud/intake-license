#!/bin/bash
# special-orders-placement — step 3 of 3 (Direction B). A board for the job
# itself: placing the orders.
#   · Needed orders grouped by their assigned vendor, unassigned first
#   · Each row carries an inline picker of the vendors that ACTUALLY carry
#     that item, from the item-vendor catalog — name, live availability,
#     cost, lead time, preferred — then Assign drops it into that bucket.
#     No page loads. Items no vendor carries say so instead of offering a
#     fake option.
#   · Freight bar per vendor when a free-freight threshold is set, so moving
#     a line between distributors shows what it does to the minimum. Vendors
#     without a threshold simply show no bar rather than an invented one.
#   · Select and Mark ordered marks the batch with a shared PO number and
#     expected date; partial failures are reported, not swallowed.
#   · Assignment is NOT ordering — it sets vendor_id, is reversible, and has
#     no side effects. Marking ordered is the committing action.
#   · Options are filtered to this tenant's active vendors (the item-vendor
#     pivot deliberately carries no tenant_id).
#   · free_freight_cents added to tenant_vendors (nullable).
# Adds THREE routes, inserted surgically.
# Server: MIGRATION REQUIRED, route:clear + route:cache, view:clear.
set -e
cd "$(git rev-parse --show-toplevel)"
if grep -q "MARKER-SO-PLACEMENT" app/Http/Controllers/Tenant/SpecialOrderController.php; then
  echo "special-orders-placement already applied — aborting."; exit 1
fi
if ! grep -q "MARKER-SO-ORIGIN" app/Http/Controllers/Tenant/SpecialOrderController.php; then
  echo "special-orders-origin not applied — wrong base, aborting."; exit 1
fi

python3 - <<'PYROUTE'
s = open('routes/web.php').read()
if 'placement/board' in s:
    print('routes already present')
else:
    old = "                Route::post('/{id}/confirm-source',                [TenantControllers\\SpecialOrderController::class, 'confirmSource'])->name('confirm-source'); // MARKER-SO-ORIGIN"
    assert s.count(old) == 1, 'confirm-source anchor not found — routes/web.php differs, aborting'
    add = (chr(10) + "                Route::get('/placement/board',                     [TenantControllers\\SpecialOrderController::class, 'placement'])->name('placement'); // MARKER-SO-PLACEMENT"
         + chr(10) + "                Route::post('/{id}/assign-vendor',                 [TenantControllers\\SpecialOrderController::class, 'assignVendor'])->name('assign-vendor'); // MARKER-SO-PLACEMENT"
         + chr(10) + "                Route::post('/placement/mark-ordered-batch',       [TenantControllers\\SpecialOrderController::class, 'markOrderedBatch'])->name('mark-ordered-batch'); // MARKER-SO-PLACEMENT")
    open('routes/web.php', 'w').write(s.replace(old, old + add))
    print('routes inserted')
PYROUTE

cat > 'database/migrations/2026_07_23_000005_add_free_freight_to_tenant_vendors.php' <<'SOPLACE_0_EOF'
<?php

// MARKER-SO-PLACEMENT — the threshold at which a vendor ships free. Optional:
// the placement board only shows a freight bar for vendors that have one set,
// rather than inventing a number.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_vendors', function (Blueprint $t) {
            $t->integer('free_freight_cents')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_vendors', function (Blueprint $t) {
            $t->dropColumn('free_freight_cents');
        });
    }
};
SOPLACE_0_EOF

cat > 'app/Models/Tenant/TenantVendor.php' <<'SOPLACE_1_EOF'
<?php

namespace App\Models\Tenant;

use App\Models\PlatformDistributorCatalog;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-scoped vendor.
 *
 * A vendor is "someone I buy from" — QBP, Hawley, Amazon Business, the
 * local distributor down the street, etc. Distinct from
 * PlatformDistributorCatalog (the global sync source); a vendor can
 * optionally link to one via distributor_catalog_id when there's a
 * platform-level relationship.
 */
class TenantVendor extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'tenant_vendors';

    protected $fillable = [
        'tenant_id',
        'name',
        'contact_email',
        'contact_phone',
        'website',
        'account_number',
        'distributor_catalog_id',
        'notes',
        'is_active',
        'free_freight_cents', // MARKER-SO-PLACEMENT
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'free_freight_cents' => 'integer', // MARKER-SO-PLACEMENT
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function distributorCatalog(): BelongsTo
    {
        return $this->belongsTo(PlatformDistributorCatalog::class, 'distributor_catalog_id');
    }

    /**
     * Items sourced from this vendor, through the pivot.
     */
    public function items(): BelongsToMany
    {
        return $this->belongsToMany(
            TenantInventoryItem::class,
            'tenant_inventory_item_vendors',
            'vendor_id',
            'inventory_item_id'
        )
            ->using(TenantInventoryItemVendor::class)
            ->withPivot(['vendor_sku', 'unit_cost_cents', 'lead_time_days', 'is_preferred', 'last_ordered_at'])
            ->withTimestamps();
    }

    /**
     * Special orders placed with this vendor.
     */
    public function specialOrders(): HasMany
    {
        return $this->hasMany(TenantSpecialOrder::class, 'vendor_id');
    }

    /**
     * Receive shipments from this vendor. Only finds shipments that
     * were linked to this vendor by FK — legacy receive shipments
     * with vendor_name string but no vendor_id will not appear here.
     */
    public function receiveShipments(): HasMany
    {
        return $this->hasMany(TenantInventoryReceiveShipment::class, 'vendor_id');
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }
}
SOPLACE_1_EOF

cat > 'app/Http/Controllers/Tenant/SpecialOrderController.php' <<'SOPLACE_2_EOF'
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
     * MARKER-SO-PLACEMENT — the vendor placement board. Every needed order
     * with the vendors that actually carry it, grouped by where it is
     * currently assigned, so a whole day's ordering is one screen.
     */
    public function placement(Request $request): View
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        $sos = TenantSpecialOrder::where('tenant_id', $tenant->id)
            ->where('status', TenantSpecialOrder::STATUS_NEEDED)
            ->with(['item', 'customer'])
            ->orderBy('created_at')
            ->get();

        $vendors = \App\Models\Tenant\TenantVendor::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'free_freight_cents'])
            ->keyBy('id');

        // Vendor options per item. The item-vendor pivot carries no tenant_id
        // by design, so options are filtered to this tenant's vendors.
        $itemIds = $sos->pluck('inventory_item_id')->filter()->unique();
        $options = [];
        $freshest = null;
        if ($itemIds->isNotEmpty()) {
            $pivots = \App\Models\Tenant\TenantInventoryItemVendor::whereIn('inventory_item_id', $itemIds)
                ->orderByDesc('is_preferred')
                ->get();
            foreach ($pivots as $p) {
                if (! $vendors->has($p->vendor_id)) {
                    continue; // another tenant's vendor, or inactive
                }
                $options[$p->inventory_item_id][] = [
                    'vendor_id' => $p->vendor_id,
                    'name'      => $vendors[$p->vendor_id]->name,
                    'cost'      => $p->live_cost_cents ?? $p->unit_cost_cents,
                    'avail'     => $p->live_avail,
                    'lead'      => $p->lead_time_days,
                    'preferred' => (bool) $p->is_preferred,
                ];
                if ($p->live_checked_at && (! $freshest || $p->live_checked_at->lt($freshest))) {
                    $freshest = $p->live_checked_at;
                }
            }
        }

        // Group by current assignment; unassigned first — it is the nag.
        $groups = ['' => []];
        foreach ($sos as $so) {
            $key = $so->vendor_id && $vendors->has($so->vendor_id) ? $so->vendor_id : '';
            $groups[$key][] = $so;
        }
        if (empty($groups[''])) {
            unset($groups['']);
        }

        return view('tenant.special-orders.placement', [
            'groups'   => $groups,
            'vendors'  => $vendors,
            'options'  => $options,
            'checkedAt'=> $freshest,
        ]);
    }

    /**
     * MARKER-SO-PLACEMENT — move an order to a vendor. Assignment is not
     * ordering: it sets vendor_id and nothing else, and is reversible.
     */
    public function assignVendor(Request $request, string $id): \Illuminate\Http\JsonResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);
        $this->ensureBelongsToTenant($id, $tenant->id);

        $data = $request->validate([
            'vendor_id' => ['required', 'uuid', \Illuminate\Validation\Rule::exists('tenant_vendors', 'id')
                ->where(fn ($q) => $q->where('tenant_id', $tenant->id))],
        ]);

        $so = TenantSpecialOrder::where('tenant_id', $tenant->id)->findOrFail($id);
        if ($so->status !== TenantSpecialOrder::STATUS_NEEDED) {
            return response()->json(['ok' => false, 'error' => 'Only needed orders can be reassigned.'], 422);
        }

        $so->forceFill(['vendor_id' => $data['vendor_id']])->save();

        return response()->json(['ok' => true]);
    }

    /**
     * MARKER-SO-PLACEMENT — mark a whole vendor batch ordered in one action,
     * sharing a PO number and expected date. Partial failures are reported
     * rather than silently swallowed.
     */
    public function markOrderedBatch(Request $request): \Illuminate\Http\JsonResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        $data = $request->validate([
            'ids'                   => ['required', 'array', 'min:1', 'max:200'],
            'ids.*'                 => ['uuid'],
            'vendor_id'             => ['required', 'uuid', \Illuminate\Validation\Rule::exists('tenant_vendors', 'id')
                ->where(fn ($q) => $q->where('tenant_id', $tenant->id))],
            'po_number'             => ['required', 'string', 'max:64'],
            'expected_arrival_date' => ['required', 'date'],
        ]);

        $ok = 0;
        $failed = [];
        foreach ($data['ids'] as $soId) {
            try {
                $this->ensureBelongsToTenant($soId, $tenant->id);
                $this->service->markOrdered($soId, [
                    'vendor_id'             => $data['vendor_id'],
                    'po_number'             => $data['po_number'],
                    'expected_arrival_date' => $data['expected_arrival_date'],
                ]);
                $ok++;
            } catch (\Throwable $e) {
                $failed[] = $e->getMessage();
            }
        }

        return response()->json([
            'ok'      => $ok > 0,
            'ordered' => $ok,
            'failed'  => $failed,
        ]);
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
SOPLACE_2_EOF

cat > 'resources/views/tenant/special-orders/index.blade.php' <<'SOPLACE_3_EOF'
@extends('layouts.tenant.app')
@php $pageTitle = 'Special Orders'; @endphp

@section('content')

{{-- SO-LIST · tab-filtered list with desktop table + mobile cards.
     Parallel render pattern matches customers/vendors. --}}

{{-- ========== DESKTOP HEAD ========== --}}
<div class="ia-page-head so-desktop-only">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Special orders</h1>
    {{-- MARKER-SO-PLACEMENT --}}
    <a href="{{ route('tenant.special-orders.placement') }}" class="ia-btn ia-btn--ghost" style="margin-left:12px">Place orders →</a>
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
SOPLACE_3_EOF

cat > 'resources/views/tenant/special-orders/placement.blade.php' <<'SOPLACE_4_EOF'
@extends('layouts.tenant.app')
@php $pageTitle = 'Place special orders'; @endphp

{{-- MARKER-SO-PLACEMENT — the vendor placement board. Every needed order with
     the vendors that actually carry it, grouped by current assignment. Assign
     moves buckets (reversible, no side effects); Mark ordered is the
     committing action. --}}

@push('styles')
<style>
  .pb-head{display:flex;align-items:baseline;gap:12px;flex-wrap:wrap;margin-bottom:6px}
  .pb-fresh{font-size:11.5px;color:var(--ia-text-muted);margin-bottom:18px}
  .pb-vend{border:0.5px solid var(--ia-border);border-radius:var(--ia-r-lg);margin-bottom:12px;overflow:hidden;background:var(--ia-surface)}
  .pb-vend.none{border-color:rgba(240,149,149,.35)}
  .pb-hd{display:flex;align-items:center;gap:11px;padding:13px 15px;background:rgba(0,0,0,.18);flex-wrap:wrap}
  .pb-vn{font-weight:800;font-size:14px}
  .pb-vn .warn{color:#F09595}
  .pb-vc{font-size:11.5px;color:var(--ia-text-muted)}
  .pb-vtot{margin-left:auto;font-size:13.5px;font-weight:800;font-variant-numeric:tabular-nums}
  .pb-freight{display:flex;align-items:center;gap:10px;padding:9px 15px;border-bottom:0.5px solid var(--ia-border);font-size:11.5px;flex-wrap:wrap}
  .pb-bar{flex:1;min-width:110px;height:5px;border-radius:100px;background:rgba(255,255,255,.08);overflow:hidden}
  .pb-bar span{display:block;height:100%;background:var(--ia-accent);border-radius:100px}
  .pb-bar.met span{background:#7FD98F}
  .pb-fnote{color:var(--ia-text-muted)}
  .pb-fnote b{color:var(--ia-accent)}
  .pb-fnote.met b{color:#7FD98F}
  .pb-row{display:flex;align-items:center;gap:11px;padding:11px 15px;border-bottom:0.5px solid var(--ia-border);flex-wrap:wrap}
  .pb-row:last-of-type{border-bottom:none}
  .pb-cb{width:16px;height:16px;border-radius:4px;border:1.5px solid rgba(255,255,255,.25);flex:none;display:inline-flex;align-items:center;justify-content:center;font-size:10px;color:#0B0B0B;font-weight:900;cursor:pointer}
  .pb-cb.on{background:var(--ia-accent);border-color:var(--ia-accent)}
  .pb-cb.on:after{content:"\2713"}
  .pb-ident{flex:1;min-width:170px}
  .pb-nm{font-weight:600;font-size:13px}
  .pb-mt{font-size:11.5px;color:var(--ia-text-muted);margin-top:2px}
  .pb-sel{background:rgba(0,0,0,.2);border:0.5px solid var(--ia-border);border-radius:8px;color:var(--ia-text);font-family:inherit;font-size:12px;padding:7px 9px;min-width:230px}
  .pb-sel:focus{outline:none;border-color:var(--ia-accent)}
  .pb-assign{font-family:inherit;font-size:11.5px;font-weight:700;border-radius:8px;padding:7px 12px;cursor:pointer;border:0.5px solid var(--ia-border);background:transparent;color:var(--ia-text)}
  .pb-assign:hover{border-color:var(--ia-accent);color:var(--ia-accent)}
  .pb-noopt{font-size:11.5px;color:#F09595}
  .pb-bar-row{display:flex;align-items:center;gap:9px;padding:11px 15px;border-top:0.5px solid var(--ia-border);flex-wrap:wrap;background:rgba(0,0,0,.18)}
  .pb-sum{font-size:12px;color:var(--ia-text-muted)}
  .pb-sum b{color:var(--ia-text)}
  .pb-in{background:rgba(0,0,0,.2);border:0.5px solid var(--ia-border);border-radius:8px;color:var(--ia-text);font-family:inherit;font-size:12px;padding:7px 9px}
  .pb-empty{padding:30px;text-align:center;color:var(--ia-text-muted);font-size:13px}
</style>
@endpush

@section('content')
<div class="pb-head">
  <h1 class="ia-page-title">Place special orders</h1>
  <a href="{{ route('tenant.special-orders.index') }}" class="ia-btn ia-btn--ghost" style="margin-left:auto">Back to list</a>
</div>
<div class="pb-fresh">
  @if($checkedAt)
    Live cost and availability last checked {{ $checkedAt->diffForHumans() }} — from your item-vendor catalog.
  @else
    Costs shown are your catalog costs; live availability has not been checked yet.
  @endif
</div>

@if(empty($groups))
  <div class="ia-card"><div class="pb-empty">Nothing waiting to be placed.</div></div>
@endif

@foreach($groups as $vendorId => $rows)
  @php
    $vendor = $vendorId !== '' ? ($vendors[$vendorId] ?? null) : null;
    $groupTotal = 0;
    foreach ($rows as $r) {
      $opt = collect($options[$r->inventory_item_id] ?? [])->firstWhere('vendor_id', $vendorId);
      $unit = $opt['cost'] ?? $r->unit_cost_cents_estimated ?? 0;
      $groupTotal += (int) $unit * (int) $r->quantity;
    }
    $min = $vendor->free_freight_cents ?? null;
  @endphp

  <div class="pb-vend {{ $vendorId === '' ? 'none' : '' }}" data-vendor="{{ $vendorId }}">
    <div class="pb-hd">
      <span class="pb-vn">
        @if($vendorId === '')<span class="warn">No vendor yet</span>@else{{ $vendor->name ?? 'Vendor' }}@endif
      </span>
      <span class="pb-vc">{{ count($rows) }} {{ \Illuminate\Support\Str::plural('item', count($rows)) }}</span>
      @if($vendorId !== '')
        <span class="pb-vtot">${{ number_format($groupTotal / 100, 2) }}</span>
      @endif
    </div>

    @if($vendorId !== '' && $min)
      @php $pct = min(100, (int) round($groupTotal / max(1, $min) * 100)); $met = $groupTotal >= $min; @endphp
      <div class="pb-freight">
        <span class="pb-bar {{ $met ? 'met' : '' }}"><span style="width:{{ $pct }}%"></span></span>
        <span class="pb-fnote {{ $met ? 'met' : '' }}">
          @if($met)
            <b>Free freight met</b> — ${{ number_format($groupTotal / 100, 2) }} of ${{ number_format($min / 100, 2) }}
          @else
            <b>${{ number_format(($min - $groupTotal) / 100, 2) }}</b> more for free freight
            (${{ number_format($groupTotal / 100, 2) }} of ${{ number_format($min / 100, 2) }})
          @endif
        </span>
      </div>
    @endif

    @foreach($rows as $so)
      @php $opts = $options[$so->inventory_item_id] ?? []; @endphp
      <div class="pb-row" data-so="{{ $so->id }}">
        @if($vendorId !== '')
          <span class="pb-cb on" data-pb-cb></span>
        @else
          <span class="pb-cb" style="visibility:hidden"></span>
        @endif

        <div class="pb-ident">
          <div class="pb-nm">{{ $so->item_name_snapshot }}</div>
          <div class="pb-mt">
            {{ $so->so_number }} · qty {{ $so->quantity }} ·
            {{ $so->customer ? trim($so->customer->first_name . ' ' . $so->customer->last_name) : 'stock' }}
          </div>
        </div>

        @if(empty($opts))
          <span class="pb-noopt">No vendor carries this yet — add one on the item</span>
        @else
          <select class="pb-sel" data-pb-select>
            @foreach($opts as $o)
              <option value="{{ $o['vendor_id'] }}" @selected($o['vendor_id'] === $vendorId)>
                {{ $o['name'] }}
                · {{ $o['avail'] === null ? 'stock unknown' : ($o['avail'] > 0 ? $o['avail'] . ' avail' : 'none in stock') }}
                @if($o['cost']) · ${{ number_format($o['cost'] / 100, 2) }} @endif
                @if($o['lead']) · {{ $o['lead'] }}d @endif
                @if($o['preferred']) · preferred @endif
              </option>
            @endforeach
          </select>
          <button type="button" class="pb-assign" data-pb-assign>{{ $vendorId === '' ? 'Assign' : 'Reassign' }}</button>
        @endif
      </div>
    @endforeach

    @if($vendorId !== '')
      <div class="pb-bar-row">
        <span class="pb-sum" data-pb-sum><b>{{ count($rows) }}</b> selected</span>
        <input type="text" class="pb-in" placeholder="PO number" data-pb-po style="width:130px">
        <input type="date" class="pb-in" data-pb-eta value="{{ now()->addDays(7)->toDateString() }}">
        <button type="button" class="ia-btn ia-btn--primary" data-pb-order>
          Mark ordered from {{ $vendor->name ?? 'vendor' }}
        </button>
      </div>
    @endif
  </div>
@endforeach

<script>
(function () {
  var assignUrl = @json(route('tenant.special-orders.assign-vendor', ['id' => '__ID__']));
  var batchUrl  = @json(route('tenant.special-orders.mark-ordered-batch'));
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content;

  function post(url, body) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
      body: JSON.stringify(body),
    }).then(function (r) { return r.json(); });
  }

  function refreshSum(box) {
    var sel = box.querySelectorAll('[data-pb-cb].on').length;
    var el  = box.querySelector('[data-pb-sum]');
    if (el) el.innerHTML = '<b>' + sel + '</b> selected';
  }

  document.addEventListener('click', function (e) {
    // toggle selection
    var cb = e.target.closest('[data-pb-cb]');
    if (cb) {
      cb.classList.toggle('on');
      refreshSum(cb.closest('.pb-vend'));
      return;
    }

    // assign / reassign
    var btn = e.target.closest('[data-pb-assign]');
    if (btn) {
      var row = btn.closest('.pb-row');
      var vid = row.querySelector('[data-pb-select]').value;
      btn.disabled = true;
      post(assignUrl.replace('__ID__', row.dataset.so), { vendor_id: vid })
        .then(function (j) {
          if (j && j.ok) { window.location.reload(); }
          else { btn.disabled = false; if (window.IntakeToast) IntakeToast.error((j && j.error) || 'Could not assign.'); }
        })
        .catch(function () { btn.disabled = false; if (window.IntakeToast) IntakeToast.error('Network error.'); });
      return;
    }

    // batch order
    var ob = e.target.closest('[data-pb-order]');
    if (ob) {
      var box = ob.closest('.pb-vend');
      var ids = Array.prototype.map.call(box.querySelectorAll('[data-pb-cb].on'), function (c) {
        return c.closest('.pb-row').dataset.so;
      });
      if (!ids.length) { if (window.IntakeToast) IntakeToast.error('Nothing selected.'); return; }
      var po  = box.querySelector('[data-pb-po]').value.trim();
      var eta = box.querySelector('[data-pb-eta]').value;
      if (!po)  { if (window.IntakeToast) IntakeToast.error('PO number is required.'); return; }
      if (!eta) { if (window.IntakeToast) IntakeToast.error('Expected date is required.'); return; }

      ob.disabled = true;
      post(batchUrl, { ids: ids, vendor_id: box.dataset.vendor, po_number: po, expected_arrival_date: eta })
        .then(function (j) {
          if (j && j.ordered) {
            if (window.IntakeToast) {
              IntakeToast.success(j.ordered + ' marked ordered' + (j.failed && j.failed.length ? ' — ' + j.failed.length + ' failed' : ''));
            }
            window.location.reload();
          } else {
            ob.disabled = false;
            if (window.IntakeToast) IntakeToast.error((j.failed && j.failed[0]) || 'Could not mark ordered.');
          }
        })
        .catch(function () { ob.disabled = false; if (window.IntakeToast) IntakeToast.error('Network error.'); });
    }
  });
})();
</script>
@endsection
SOPLACE_4_EOF

echo "special-orders-placement applied — server: git pull && php artisan migrate --force && php artisan route:clear && php artisan route:cache && php artisan view:clear"
