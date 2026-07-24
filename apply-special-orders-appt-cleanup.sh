#!/bin/bash
# special-orders-appt-cleanup — clearing a legacy orphan off a work order.
#   Removing a part now cancels its special order correctly (shipped July 16),
#   but orders created BEFORE that fix are still attached to their work orders
#   with no way off, and the screen reported them as live because the
#   appointment still exists.
#   1. ORIGIN: an appointment can be alive while the PART that requested the
#      order is gone. Parts carry special_order_id, so this is exact — such
#      orders now read "Part removed from work order" instead of showing the
#      work-order number as a live source. (Same trap already fixed for sales
#      with removed lines.)
#   2. WORK ORDER: the special-order rows had no action but "open". A
#      still-"needed" order can now be cancelled straight from the row, with
#      a confirm; ordered/arrived rows show "placed" and cannot, since goods
#      may be inbound — the same rule used everywhere else.
#   The handler is registered INSIDE the Blade section on purpose: a script
#   after @endsection is silently discarded, which is exactly how the
#   special-orders Cancel button ended up doing nothing.
# No routes, no migration. Server: view:clear.
set -e
cd "$(git rev-parse --show-toplevel)"
if grep -q "MARKER-SO-PARTGONE" app/Http/Controllers/Tenant/SpecialOrderController.php; then
  echo "already applied — aborting."; exit 1
fi
if ! grep -q "MARKER-SO-LINEGONE" app/Http/Controllers/Tenant/SpecialOrderController.php; then
  echo "special-orders-fixes not applied — wrong base, aborting."; exit 1
fi

cat > 'app/Http/Controllers/Tenant/SpecialOrderController.php' <<'SOAC_0_EOF'
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

        // MARKER-SO-SCROLL — the open view is one scrollable list, not pages:
        // the footer total ("13 open across 3 vendors") is only ever true when
        // the whole set is on screen. Capped so a pathological backlog cannot
        // render forever; the cap is surfaced in the footer when hit.
        $grouped   = ($view === 'open');
        $scrollCap = 500;

        $total = $q->count();
        if ($grouped) {
            $totalPages = 1;
            $page = 1;
            $sos = $q->limit($scrollCap)->get();
        } else {
            $totalPages = max(1, (int) ceil($total / $perPage));
            $sos = $q->offset(($page - 1) * $perPage)->limit($perPage)->get();
        }

        // Drawer prep: vendors list for the picker, plus today's date
        // for the date-picker default. Item search is XHR, customers
        // are XHR — only vendors is small enough to inline.
        $vendors = TenantVendor::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        // MARKER-SO-SCROLL — grouping is not a mode. Open orders are grouped by
        // vendor because that is how they get placed; the other tabs stay flat,
        // since vendor grouping means nothing once an order has been placed.
        $vendorData = ['groups' => [], 'vendors' => collect(), 'options' => [], 'checkedAt' => null];
        if ($grouped) {
            $needed = $sos->where('status', TenantSpecialOrder::STATUS_NEEDED);
            $vendorData = $this->vendorGroups($tenant, $needed);
        }

        // MARKER-SO-ORIGIN — for each listed order: where did it come from,
        // and does that source still exist? Two lookups, no N+1.
        $origins = [];
        if ($sos->isNotEmpty()) {
            $saleIds = $sos->pluck('sale_id')->filter()->unique();
            $liveSales = $saleIds->isEmpty() ? collect() : \App\Models\Tenant\TenantSale::where('tenant_id', $tenant->id)
                ->whereIn('id', $saleIds)->pluck('sale_number', 'id');

            // MARKER-SO-LINEGONE — a live sale does not mean the request is
            // live: the LINE that asked for it may have been removed while the
            // draft survived. sale_item_id is not populated on older rows, so
            // match on the inventory item still being present on that sale.
            $hasLine = [];
            if ($liveSales->isNotEmpty()) {
                foreach (\App\Models\Tenant\TenantSaleItem::whereIn('sale_id', $liveSales->keys())
                            ->get(['sale_id', 'inventory_item_id']) as $si) {
                    if ($si->inventory_item_id) {
                        $hasLine[$si->sale_id][$si->inventory_item_id] = true;
                    }
                }
            }

            $apptIds = $sos->pluck('appointment_id')->filter()->unique();
            $liveAppts = $apptIds->isEmpty() ? collect() : \App\Models\Tenant\TenantAppointment::where('tenant_id', $tenant->id)
                ->whereIn('id', $apptIds)->pluck('ra_number', 'id');

            // MARKER-SO-PARTGONE — same trap as a live sale with a dead line:
            // the work order survives while the PART that requested the order
            // is removed. Parts carry special_order_id, so this is exact.
            $partAlive = [];
            if ($apptIds->isNotEmpty()) {
                $partAlive = \App\Models\Tenant\TenantAppointmentPart::whereIn('appointment_id', $apptIds)
                    ->whereNotNull('special_order_id')
                    ->pluck('special_order_id')
                    ->flip()
                    ->all();
            }

            foreach ($sos as $so) {
                if ($so->appointment_id) {
                    if (! isset($liveAppts[$so->appointment_id])) {
                        $origins[$so->id] = ['state' => 'orphan', 'label' => 'Work order deleted'];
                    } elseif (! isset($partAlive[$so->id])) {
                        // MARKER-SO-PARTGONE
                        $origins[$so->id] = ['state' => 'orphan', 'label' => 'Part removed from work order'];
                    } else {
                        $origins[$so->id] = ['state' => 'live', 'label' => $liveAppts[$so->appointment_id]];
                    }
                } elseif ($so->sale_id) {
                    if (! isset($liveSales[$so->sale_id])) {
                        $origins[$so->id] = ['state' => 'orphan', 'label' => 'Sale removed'];
                    } elseif ($so->inventory_item_id && empty($hasLine[$so->sale_id][$so->inventory_item_id])) {
                        // MARKER-SO-LINEGONE
                        $origins[$so->id] = ['state' => 'orphan', 'label' => 'Line removed from sale'];
                    } else {
                        $origins[$so->id] = ['state' => 'live', 'label' => 'Sale ' . $liveSales[$so->sale_id]];
                    }
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
            'grouped'    => $grouped,                  // MARKER-SO-SCROLL
            'scrollCap'  => $scrollCap,                // MARKER-SO-SCROLL
            'vgroups'    => $vendorData['groups'],     // MARKER-SO-ONESCREEN
            'vvendors'   => $vendorData['vendors'],    // MARKER-SO-ONESCREEN
            'voptions'   => $vendorData['options'],    // MARKER-SO-ONESCREEN
            'vcheckedAt' => $vendorData['checkedAt'],  // MARKER-SO-ONESCREEN
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
    /**
     * MARKER-SO-ONESCREEN — vendor grouping data for the special-orders
     * screen. This used to be a separate placement page; it is now a mode of
     * the one list, so there is no second screen to remember.
     *
     * @param  \Illuminate\Support\Collection  $sos  the orders being shown
     */
    private function vendorGroups($tenant, $sos): array
    {

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

        return [
            'groups'    => $groups,
            'vendors'   => $vendors,
            'options'   => $options,
            'checkedAt' => $freshest,
        ];
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
SOAC_0_EOF

cat > 'resources/views/tenant/appointments/show.blade.php' <<'SOAC_1_EOF'
@extends('layouts.tenant.app')
@php
  $pageTitle = $appointment->ra_number;
  $statusLabels = \App\Support\AppointmentStatus::LABELS; // MARKER-PATCH-287 single source
  $transitionLabels = [
    'confirmed'   => 'Confirm',
    'in_progress' => 'Start work',
    'completed'   => 'Mark completed',
    'shipped'     => 'Mark shipped',
    'closed'      => 'Close job',
    'cancelled'   => 'Cancel appointment',
    'refunded'    => 'Refund',
  ];
  $updateUrl = route('tenant.appointments.update', $appointment->id);
@endphp

<style>
.appt-layout { display: grid; grid-template-columns: 1fr 300px; gap: 20px; align-items: start; }
.appt-section-label { font-size: 11px; text-transform: uppercase; letter-spacing: .07em; font-weight: 500; opacity: .45; margin-bottom: 10px; }
.appt-line-items { width: 100%; border-collapse: collapse; font-size: 13px; }
.appt-line-items th { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; font-weight: 500; opacity: .45; padding: 6px 0; text-align: left; border-bottom: 0.5px solid var(--ia-border); }
.appt-line-items td { padding: 10px 0; border-bottom: 0.5px solid var(--ia-border); vertical-align: middle; }
.appt-line-items tr:last-child td { border-bottom: none; }
.appt-line-items .ia-num { text-align: right; font-variant-numeric: tabular-nums; }
.appt-total-row { display: flex; justify-content: space-between; padding: 10px 0; border-top: 0.5px solid var(--ia-border); font-weight: 500; }
.appt-response { display: flex; flex-direction: column; gap: 2px; padding: 10px 0; border-bottom: 0.5px solid var(--ia-border); }
.appt-response:last-child { border-bottom: none; }
.appt-response-label { font-size: 11px; opacity: .45; }
.appt-response-value { font-size: 13px; }
.appt-charge-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 0.5px solid var(--ia-border); font-size: 13px; }
.appt-charge-row:last-child { border-bottom: none; }
.sidebar-stat { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 0.5px solid var(--ia-border); font-size: 13px; }
.sidebar-stat:last-child { border-bottom: none; }
.sidebar-stat-label { opacity: .5; }
.sidebar-stat-value { font-weight: 500; }
.add-charge-form { display: none; margin-top: 12px; padding-top: 12px; border-top: 0.5px solid var(--ia-border); }
.add-charge-form.open { display: block; }
@media (max-width: 900px) { .appt-layout { grid-template-columns: 1fr; } }

/* Status progress bar */
.appt-progress-card { padding: 18px 24px; margin-bottom: 20px; }
.appt-progress-bar { display: flex; align-items: flex-start; justify-content: space-between; position: relative; gap: 4px; }
.appt-progress-bar::before { content: ''; position: absolute; top: 12px; left: 12px; right: 12px; height: 2px; background: var(--ia-border); z-index: 0; }
.appt-progress-bar::after {
  content: ''; position: absolute; top: 12px; left: 12px; height: 2px; background: var(--ia-accent); z-index: 0;
  width: calc((100% - 24px) * var(--progress, 0));
  transition: width .25s ease;
}
.appt-progress-step {
  display: flex; flex-direction: column; align-items: center; gap: 6px;
  position: relative; z-index: 1; background: transparent; border: none; cursor: pointer; padding: 0;
  flex: 1; min-width: 0; font-family: inherit;
}
.appt-progress-step:disabled { cursor: default; }
.appt-progress-dot {
  width: 24px; height: 24px; border-radius: 50%;
  background: var(--ia-surface); border: 0.5px solid var(--ia-border);
  display: flex; align-items: center; justify-content: center;
  transition: background var(--ia-t), border-color var(--ia-t);
  color: #fff;
}
.appt-progress-step.is-done .appt-progress-dot { background: var(--ia-accent); border-color: var(--ia-accent); color: var(--ia-accent-text); }
.appt-progress-step.is-current .appt-progress-dot { border: 2px solid var(--ia-accent); background: var(--ia-surface); }
.appt-progress-dot-inner { width: 8px; height: 8px; border-radius: 50%; background: var(--ia-accent); }
.appt-progress-label { font-size: 11px; color: var(--ia-text-muted); transition: color var(--ia-t); }
.appt-progress-step.is-current .appt-progress-label { font-weight: 500; color: var(--ia-text); }
.appt-progress-step:not(:disabled):hover .appt-progress-dot { border-color: var(--ia-accent); }
.appt-progress-step.is-saving .appt-progress-dot { opacity: .5; }

/* Terminal state card (cancelled / refunded) */
.appt-terminal-card { display: flex; align-items: center; gap: 14px; padding: 18px 24px; margin-bottom: 20px; }
.appt-terminal-icon { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; color: #fff; }
.appt-terminal-icon--cancelled { background: #A32D2D; }
.appt-terminal-icon--refunded { background: #BA7517; }
.appt-terminal-title { font-size: 15px; font-weight: 500; }
.appt-terminal-sub { font-size: 13px; color: var(--ia-text-muted); margin-top: 2px; }
.appt-terminal-card .appt-reopen-btn { margin-left: auto; }

.appt-cancel-btn { margin-top: 4px; }

/* LAYOUT-B-CSS v1 */
.appt-b-shell { display: grid; grid-template-columns: 280px 1fr; gap: 20px; align-items: start; }
.appt-b-rail { display: flex; flex-direction: column; gap: 14px; position: sticky; top: 16px; }
.appt-b-main { display: flex; flex-direction: column; gap: 18px; }
@media (max-width: 900px) { .appt-b-shell { grid-template-columns: 1fr; } .appt-b-rail { position: static; } }

/* Time/date hero band — left rail, accent border-left */
.appt-b-when {
  background: var(--ia-surface);
  border: 0.5px solid var(--ia-border);
  border-left: 3px solid var(--ia-accent);
  border-radius: var(--ia-r-md);
  padding: 14px 16px;
}
.appt-b-when-time {
  font-size: 22px; font-weight: 500; letter-spacing: -.01em; line-height: 1.15;
  color: var(--ia-text);
}
.appt-b-when-date { font-size: 12px; color: var(--ia-text-muted); margin-top: 4px; }
.appt-b-when-dur  { font-size: 11px; color: var(--ia-text-muted); margin-top: 6px; opacity: .7; }
.appt-b-when-resource {
  margin-top: 12px; padding-top: 10px;
  border-top: 0.5px solid var(--ia-border);
  font-size: 12px; color: var(--ia-text-muted);
}
.appt-b-when-resource .who { color: var(--ia-text); font-weight: 500; }
.appt-b-when-resource .swatch {
  display: inline-block; width: 8px; height: 8px;
  border-radius: 50%; margin-right: 6px; vertical-align: 1px;
}

/* Vertical status pipeline — overrides the horizontal one when wrapped in .appt-b-rail */
.appt-b-rail .appt-progress-card { padding: 14px 16px; }
.appt-b-rail .appt-progress-bar {
  flex-direction: column;
  align-items: stretch;
  gap: 0;
}
.appt-b-rail .appt-progress-bar::before,
.appt-b-rail .appt-progress-bar::after { display: none; }
.appt-b-rail .appt-progress-step {
  flex-direction: row;
  justify-content: flex-start;
  gap: 12px;
  padding: 8px 0;
  text-align: left;
  position: relative;
}
.appt-b-rail .appt-progress-step:not(:last-child)::after {
  content: ''; position: absolute;
  left: 11.25px; top: calc(50% + 12px); bottom: -8px;
  width: 1.5px; background: var(--ia-border);
  z-index: 0;
}
.appt-b-rail .appt-progress-step.is-done:not(:last-child)::after { background: var(--ia-accent); }
.appt-b-rail .appt-progress-step.is-done + .appt-progress-step.is-current::after,
.appt-b-rail .appt-progress-step.is-done + .appt-progress-step.is-done::after { background: var(--ia-accent); }
.appt-b-rail .appt-progress-dot { flex-shrink: 0; }
.appt-b-rail .appt-progress-label {
  font-size: 13px; line-height: 1.3;
}

/* Action button stack */
.appt-b-actions {
  background: var(--ia-surface);
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-md);
  padding: 8px;
  display: flex; flex-direction: column; gap: 4px;
}
.appt-b-actions .ia-btn { width: 100%; justify-content: flex-start; padding: 8px 12px; font-size: 13px; }
.appt-b-actions-divider { height: 0.5px; background: var(--ia-border); margin: 4px 4px; }
.appt-b-action-coming-soon {
  font-size: 11px; color: var(--ia-text-muted);
  padding: 0 12px 6px; opacity: .55;
}

/* Rail customer card — slightly tighter than the original */
.appt-b-rail .ia-card { padding: 14px 16px; }
.appt-b-rail .appt-section-label { margin-bottom: 8px; }

/* Time/date inline meta on hero (re-shows at bottom of band) */
.appt-b-meta-grid {
  display: grid; grid-template-columns: auto 1fr; gap: 4px 10px;
  font-size: 12px; color: var(--ia-text-muted);
  margin-top: 10px;
}
.appt-b-meta-grid .lbl { opacity: .65; }

/* Inline capacity-in-work-order grid (3-up) */
.appt-b-wo-grid {
  display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px;
  margin-top: 8px;
}
.appt-b-wo-cell .lbl { font-size: 11px; color: var(--ia-text-muted); margin-bottom: 4px; }
.appt-b-wo-cell .val { font-size: 13px; }

/* Hide the original right-rail cancel button when in B layout — JS still finds it,
   but visually the new rail action handles it. */
.appt-b-shell .appt-cancel-btn-original { display: none !important; }


/* LAYOUT-B-CUST-MODAL-CSS v1 */
.appt-b-cust-modal {
  position: fixed; inset: 0; z-index: 1000;
  display: flex; align-items: center; justify-content: center;
  padding: 20px;
}
.appt-b-cust-modal[hidden] { display: none; }
.appt-b-cust-modal-backdrop {
  position: absolute; inset: 0;
  background: rgba(0,0,0,.55);
  backdrop-filter: blur(4px);
}
.appt-b-cust-modal-card {
  position: relative;
  background: var(--ia-surface);
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-lg, 12px);
  width: 100%; max-width: 560px;
  max-height: 80vh;
  display: flex; flex-direction: column;
  box-shadow: 0 20px 60px rgba(0,0,0,.4);
}
.appt-b-cust-modal-head {
  display: flex; align-items: center; justify-content: space-between;
  padding: 16px 20px;
  border-bottom: 0.5px solid var(--ia-border);
}
.appt-b-cust-modal-title {
  margin: 0;
  font-size: 15px; font-weight: 500; letter-spacing: -.01em;
}
.appt-b-cust-modal-close {
  background: none; border: none; color: inherit;
  font-size: 22px; line-height: 1; cursor: pointer;
  padding: 4px 8px; border-radius: 4px;
  opacity: .6;
}
.appt-b-cust-modal-close:hover { opacity: 1; background: rgba(255,255,255,.06); }
.appt-b-cust-modal-body {
  padding: 18px 20px;
  overflow-y: auto;
}

/* Capacity collapsible — hide default disclosure triangle */
.appt-b-cap-override summary { list-style: none; }
.appt-b-cap-override summary::-webkit-details-marker { display: none; }
.appt-b-cap-override[open] summary { color: var(--ia-text-muted); }

/* RESCHEDULE-MODAL-CSS v1 */
.resch-modal { position: fixed; inset: 0; z-index: 1000; display: flex; align-items: center; justify-content: center; padding: 20px; }
.resch-modal[hidden] { display: none; }
.resch-modal-backdrop { position: absolute; inset: 0; background: rgba(0,0,0,.55); backdrop-filter: blur(4px); }
.resch-modal-card {
  position: relative;
  background: var(--ia-surface);
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-lg, 12px);
  width: 100%; max-width: 520px;
  max-height: 86vh;
  display: flex; flex-direction: column;
  box-shadow: 0 20px 60px rgba(0,0,0,.4);
}
.resch-modal-head {
  display: flex; align-items: center; justify-content: space-between;
  padding: 16px 20px;
  border-bottom: 0.5px solid var(--ia-border);
}
.resch-modal-title { margin: 0; font-size: 15px; font-weight: 500; letter-spacing: -.01em; }
.resch-modal-close {
  background: none; border: none; color: inherit;
  font-size: 22px; line-height: 1; cursor: pointer;
  padding: 4px 8px; border-radius: 4px; opacity: .6;
}
.resch-modal-close:hover { opacity: 1; background: rgba(255,255,255,.06); }
.resch-modal-body { padding: 18px 20px; overflow-y: auto; }
.resch-modal-foot {
  display: flex; justify-content: flex-end; gap: 8px;
  padding: 14px 20px;
  border-top: 0.5px solid var(--ia-border);
}

.resch-from {
  background: var(--ia-surface-2, rgba(255,255,255,.03));
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-md, 8px);
  padding: 12px 14px;
  margin-bottom: 16px;
}
.resch-from-label, .resch-to-label {
  font-size: 11px; text-transform: uppercase; letter-spacing: .07em;
  font-weight: 500; opacity: .5; margin-bottom: 6px;
}
.resch-from-when, .resch-to-when { font-size: 14px; font-weight: 500; }
.resch-from-resource, .resch-to-resource {
  font-size: 12px; opacity: .7; margin-top: 4px;
  display: flex; align-items: center; gap: 6px;
}
.resch-swatch { display: inline-block; width: 8px; height: 8px; border-radius: 50%; }

.resch-to {
  background: rgba(190,242,100,.07);
  border: 1px solid rgba(190,242,100,.25);
  border-radius: var(--ia-r-md, 8px);
  padding: 12px 14px;
  margin-top: 12px;
}
.resch-to-label { color: var(--ia-accent); opacity: 1; }

.resch-field { margin-bottom: 14px; }
.resch-label {
  display: block; font-size: 12px; opacity: .7;
  margin-bottom: 6px;
}
.resch-input {
  width: 100%; padding: 9px 12px;
  background: var(--ia-surface-2, rgba(255,255,255,.03));
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-md, 6px);
  color: inherit; font-family: inherit; font-size: 13px;
}

.resch-times-head {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 8px;
}
.resch-week-nav { display: flex; align-items: center; gap: 4px; }
.resch-week-btn {
  background: transparent; border: 0.5px solid var(--ia-border);
  color: inherit; cursor: pointer;
  width: 24px; height: 24px; border-radius: 4px;
  font-size: 14px; line-height: 1; padding: 0;
}
.resch-week-btn:hover:not(:disabled) { background: rgba(255,255,255,.04); }
.resch-week-btn:disabled { opacity: .3; cursor: not-allowed; }
.resch-week-label { font-size: 12px; opacity: .7; min-width: 110px; text-align: center; }

.resch-times-list {
  max-height: 240px; overflow-y: auto;
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-md, 6px);
}
.resch-times-empty { padding: 24px 16px; text-align: center; font-size: 12px; opacity: .5; }
.resch-times-empty.error { color: #f59999; }
.resch-time-row {
  display: flex; justify-content: space-between; align-items: center;
  padding: 10px 14px;
  border-bottom: 0.5px solid var(--ia-border);
  cursor: pointer;
  font-size: 13px;
  transition: background .1s;
}
.resch-time-row:last-child { border-bottom: none; }
.resch-time-row:hover { background: rgba(255,255,255,.04); }
.resch-time-row.selected {
  background: rgba(190,242,100,.1);
  color: var(--ia-accent);
  font-weight: 500;
}
.resch-time-date { opacity: .7; }
.resch-time-time { font-variant-numeric: tabular-nums; }

/* CANCEL-RED-DARK v1 — rail Cancel needs visible red on dark surface */
.appt-b-cancel-btn.ia-btn--danger {
  background: #6B1F1F;
  color: #FFD0D0;
  border: 1px solid #8C2C2C;
}
.appt-b-cancel-btn.ia-btn--danger:hover {
  background: #8C2C2C;
  color: #FFE5E5;
}

/* APPT-MOBILE-OVERFLOW-FIX v1 — keep the page from exceeding viewport width.
   The status pipeline's `min-width: max-content` rule in the block below
   makes the bar take its content width. Without these constraints, that
   width propagates up through .appt-progress-card → .appt-b-rail →
   .appt-b-shell → page, causing horizontal scroll. We constrain the chain
   so the bar can scroll horizontally inside its card while the card stays
   inside the rail stays inside the page. */
@media (max-width: 900px) {
  .appt-b-shell, .appt-b-rail, .appt-b-main { max-width: 100%; min-width: 0; }
  .appt-b-rail > * { max-width: 100%; min-width: 0; }
  .appt-b-rail .appt-progress-card {
    max-width: 100%;
    min-width: 0;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
  }
  /* Body block too — services/work order/payment cards shouldn't push width */
  .appt-b-main > * { max-width: 100%; min-width: 0; overflow-x: hidden; }
}

/* APPT-DETAIL-MOBILE v1 — phone polish at ≤700px */
@media (max-width: 700px) {

  /* Tighten the hero band on phones */
  .appt-b-when {
    padding: 12px 14px;
  }
  .appt-b-when-time {
    font-size: 20px;
  }

  /* ── Status pipeline: vertical → horizontal pill chain ── */
  .appt-b-rail .appt-progress-card {
    padding: 10px 12px;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
  }
  .appt-b-rail .appt-progress-bar {
    flex-direction: row !important;
    gap: 6px;
    align-items: center;
    min-width: max-content;
  }
  .appt-b-rail .appt-progress-step {
    flex-direction: row !important;
    padding: 4px 10px !important;
    border-radius: 99px;
    background: var(--ia-surface-2, rgba(255,255,255,.04));
    border: 0.5px solid var(--ia-border);
    flex-shrink: 0;
    gap: 6px;
  }
  .appt-b-rail .appt-progress-step::after {
    display: none !important;  /* no connecting line in horizontal mode */
  }
  .appt-b-rail .appt-progress-step.is-done {
    background: var(--ia-accent-soft);
    border-color: rgba(190,242,100,.3);
    color: var(--ia-accent);
  }
  .appt-b-rail .appt-progress-step.is-current {
    background: var(--ia-accent);
    border-color: var(--ia-accent);
    color: var(--ia-accent-text);
    font-weight: 600;
  }
  .appt-b-rail .appt-progress-dot {
    width: 12px; height: 12px;
  }
  .appt-b-rail .appt-progress-label {
    font-size: 12px;
    white-space: nowrap;
  }

  /* ── Action stack: vertical → 2-col grid ── */
  .appt-b-actions {
    display: grid !important;
    grid-template-columns: 1fr 1fr;
    gap: 6px;
    padding: 6px;
  }
  .appt-b-actions .ia-btn {
    width: 100%;
    justify-content: center !important;
    padding: 10px 8px !important;
    font-size: 13px;
  }
  /* The "Reschedule shipping tomorrow" hint is no longer present, but keep
     the rule defensive for any future inline hint row. */
  .appt-b-action-coming-soon { display: none; }
  /* Divider spans full row */
  .appt-b-actions-divider { grid-column: 1 / -1; }
  /* Cancel button spans full row */
  .appt-b-cancel-btn { grid-column: 1 / -1; }

  /* ── Reschedule modal → bottom sheet ── */
  .resch-modal {
    align-items: flex-end !important;
    padding: 0 !important;
  }
  .resch-modal-card {
    max-width: 100% !important;
    width: 100%;
    border-top-left-radius: 18px;
    border-top-right-radius: 18px;
    border-bottom-left-radius: 0;
    border-bottom-right-radius: 0;
    max-height: 88vh;
    padding-bottom: env(safe-area-inset-bottom, 0);
    animation: appt-sheet-up 280ms cubic-bezier(.2, .8, .2, 1);
  }
  /* Drag handle */
  .resch-modal-card::before {
    content: '';
    display: block;
    width: 36px;
    height: 4px;
    background: var(--ia-text-dim, rgba(255,255,255,.18));
    border-radius: 2px;
    margin: 10px auto 0;
  }
  .resch-modal-head { padding-top: 10px; }
  .resch-modal-foot {
    flex-wrap: wrap;
    gap: 6px;
  }
  .resch-modal-foot .ia-btn { flex: 1; min-width: 0; }

  /* ── Booking-notes modal → bottom sheet ── */
  .appt-b-cust-modal {
    align-items: flex-end !important;
    padding: 0 !important;
  }
  .appt-b-cust-modal-card {
    max-width: 100% !important;
    width: 100%;
    border-top-left-radius: 18px;
    border-top-right-radius: 18px;
    border-bottom-left-radius: 0;
    border-bottom-right-radius: 0;
    max-height: 88vh;
    padding-bottom: env(safe-area-inset-bottom, 0);
    animation: appt-sheet-up 280ms cubic-bezier(.2, .8, .2, 1);
  }
  .appt-b-cust-modal-card::before {
    content: '';
    display: block;
    width: 36px;
    height: 4px;
    background: var(--ia-text-dim, rgba(255,255,255,.18));
    border-radius: 2px;
    margin: 10px auto 0;
  }
  .appt-b-cust-modal-head { padding-top: 10px; }
}

/* APPT-MOBILE-FIX v1 — duplicate back button + FAB padding */
@media (max-width: 700px) {
  /* The mobile top-bar already shows "‹ Schedule"; hide the page-head Back. */
  .ia-page-actions .ia-btn--ghost { display: none; }
  /* So .ia-page-actions doesn't render as an empty box, hide it when empty. */
  .ia-page-actions:empty { display: none; }
}
@media (max-width: 1023px) {
  /* Push content above the FAB so the last card isn't covered. */
  .ia-content { padding-bottom: calc(160px + env(safe-area-inset-bottom, 0px)) !important; }
}

@keyframes appt-sheet-up {
  from { transform: translateY(100%); }
  to   { transform: translateY(0); }
}
</style>

@section('mobile-back', 'Schedule|' . route('tenant.calendar.index'))
@section('mobile-fab', 'walk-in')

@section('content')

@php
  // Banner state — drives the top-of-page status banner.
  // Three cases: open draft sale (amber, take payment), paid (green),
  // overage (amber warning, refund customer). Anything else = no banner.
  $bannerPendingLink = $appointment->pendingPaymentLinkSale(); // MARKER-PATCH-194
  $bannerSale     = $appointment->openRegisterSale();
  $bannerBalance  = max(0, (int)$appointment->total_cents - (int)$appointment->paid_cents);
  $bannerOverage  = max(0, (int)$appointment->paid_cents - (int)$appointment->total_cents);
  $bannerPaidFull = ($appointment->payment_status === 'paid');
@endphp

@if($bannerPendingLink)
  {{-- MARKER-PATCH-194 — a payment link is out and awaiting the customer. --}}
  <div style="background:rgba(96,165,250,.10);border:0.5px solid rgba(96,165,250,.35);border-radius:var(--ia-r-md);padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:14px">
    <span style="font-size:20px;line-height:1">🔗</span>
    <div style="flex:1">
      <div style="font-weight:500;font-size:13px;color:var(--ia-text)">Payment link sent — awaiting customer · {{ format_money($bannerPendingLink->total_cents) }}</div>
      <div style="font-size:12px;color:var(--ia-text-muted);margin-top:2px">
        Sale {{ $bannerPendingLink->sale_number ?? 'pending' }} · the customer can pay on their own time; this updates automatically when they do.
      </div>
    </div>
    <a href="{{ route('tenant.register.index', []) }}?status={{ $bannerPendingLink->id }}"
       class="ia-btn ia-btn--ghost ia-btn--sm">View status →</a>
  </div>
@elseif($bannerSale)
  <div style="background:rgba(251,191,36,.10);border:0.5px solid rgba(251,191,36,.35);border-radius:var(--ia-r-md);padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:14px">
    <span style="font-size:20px;line-height:1">💳</span>
    <div style="flex:1">
      <div style="font-weight:500;font-size:13px;color:var(--ia-text)">Ready for checkout — {{ format_money($bannerBalance) }}</div>
      <div style="font-size:12px;color:var(--ia-text-muted);margin-top:2px">
        Sale {{ $bannerSale->sale_number }} parked in the register for {{ $appointment->customerName() }}.
      </div>
    </div>
    <a href="{{ route('tenant.register.index', []) }}?resume={{ $bannerSale->id }}"
       class="ia-btn ia-btn--primary ia-btn--sm">Open in register →</a>
  </div>
@elseif($bannerPaidFull)
  <div style="background:rgba(132,204,22,.08);border:0.5px solid rgba(132,204,22,.30);border-radius:var(--ia-r-md);padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:14px">
    <span style="font-size:20px;line-height:1">✅</span>
    <div style="flex:1">
      <div style="font-weight:500;font-size:13px;color:var(--ia-text)">Paid in full — {{ format_money($appointment->paid_cents) }}</div>
      <div style="font-size:12px;color:var(--ia-text-muted);margin-top:2px">
        @if($appointment->payments()->count() === 1 && $appointment->payments()->first()->kind === 'deposit')
          Customer prepaid before service. No checkout needed.
        @else
          {{ $appointment->payments()->count() }} {{ $appointment->payments()->count() === 1 ? 'payment' : 'payments' }} on file.
        @endif
      </div>
    </div>
  </div>
@elseif($bannerOverage > 0)
  <div style="background:rgba(251,191,36,.10);border:0.5px solid rgba(251,191,36,.45);border-radius:var(--ia-r-md);padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:14px">
    <span style="font-size:20px;line-height:1">⚠️</span>
    <div style="flex:1">
      <div style="font-weight:500;font-size:13px;color:var(--ia-text)">Customer owed {{ format_money($bannerOverage) }}</div>
      <div style="font-size:12px;color:var(--ia-text-muted);margin-top:2px">
        Customer paid more than the final total. Refund the difference through the register.
      </div>
    </div>
  </div>
@endif

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <div style="font-size:11px;text-transform:uppercase;letter-spacing:.08em;opacity:.4;margin-bottom:4px">
      Work order
    </div>
    <h1 class="ia-page-title">{{ $appointment->ra_number }}</h1>
    <p class="ia-page-subtitle">
      {{ $appointment->customerName() }} ·
      {{ $appointment->appointment_date->format('M j, Y') }}
    </p>
  </div>
  <div class="ia-page-actions">
    <a href="{{ route('tenant.appointments.index') }}" class="ia-btn ia-btn--ghost">← Back</a>
  </div>
</div>

@php
  // Status progress bar — terminal states (cancelled/refunded) replace the bar with a card.
  $isTerminal = \App\Support\AppointmentStatus::isTerminal($appointment->status);
  $pipelineSteps = \App\Support\AppointmentStatus::pipeline();
  // TODO: per-tenant extensions for 'shipped' and 'closed' once Workflow settings ship.
  $currentIndex = array_search($appointment->status, $pipelineSteps);
  if ($currentIndex === false) $currentIndex = 0;
@endphp

{{-- LAYOUT-B-PIPELINE-RELOCATED v1: original full-width status pipeline removed.
     The rail (above) renders the same markup with the same JS hooks. --}}

<div class="appt-b-shell">

  {{-- LAYOUT-B-RAIL v1 — left rail: time/date, status, actions, customer --}}
  <aside class="appt-b-rail">

    {{-- Time/date hero band --}}
    @php
      try {
        $apptStartC = $appointment->appointment_time
          ? \Carbon\Carbon::parse($appointment->appointment_date->toDateString() . ' ' . $appointment->appointment_time)
          : null;
        $durationMin = (int) ($appointment->total_duration_minutes ?? 0);
        $apptEndC   = ($apptStartC && $durationMin > 0) ? $apptStartC->copy()->addMinutes($durationMin) : null;
      } catch (\Throwable $e) {
        $apptStartC = null; $apptEndC = null; $durationMin = 0;
      }
    @endphp
    @if($apptStartC)
      <div class="appt-b-when">
        <div class="appt-b-when-time">
          {{ $apptStartC->format('g:i A') }}@if($apptEndC) – {{ $apptEndC->format('g:i A') }}@endif
        </div>
        <div class="appt-b-when-date">
          {{ $appointment->appointment_date->format('l, M j, Y') }}
        </div>
        @if($durationMin > 0)
          <div class="appt-b-when-dur">{{ $durationMin }} min</div>
        @endif
        @if($currentResource ?? null)
          <div class="appt-b-when-resource">
            <span class="swatch" style="background: {{ ($availableResources->firstWhere('id', $appointment->resource_id))->color_hex ?? '#888' }}"></span>
            <span class="who">{{ ($availableResources->firstWhere('id', $appointment->resource_id))->name ?? 'Unassigned' }}</span>
          </div>
        @else
          @php $rr = $availableResources->firstWhere('id', $appointment->resource_id); @endphp
          @if($rr)
            <div class="appt-b-when-resource">
              <span class="swatch" style="background: {{ $rr->color_hex ?? '#888' }}"></span>
              <span class="who">{{ $rr->name }}</span>
            </div>
          @endif
        @endif
      </div>
    @else
      <div class="appt-b-when">
        <div class="appt-b-when-time" style="font-size:15px;font-weight:500">
          {{ $appointment->appointment_date->format('l, M j, Y') }}
        </div>
        <div class="appt-b-when-dur">No time set</div>
      </div>
    @endif
    {{-- MARKER-PATCH-311 --}}
    <div style="margin-top:10px">@include('tenant.appointments._promised_editor')</div>
    @include('tenant.appointments._delivery_propose_modal'){{-- MARKER-PATCH-527 --}}
    {{-- MARKER-PATCH-514 --}}
    @include('tenant.appointments._route_trip')

    {{-- Status pipeline (markup is fed into vertical CSS by .appt-b-rail wrapper) --}}
    @if($isTerminal)
      {{-- Terminal state — show compact card --}}
      <div class="ia-card appt-terminal-card" style="padding:12px 14px">
        <div class="appt-terminal-icon appt-terminal-icon--{{ $appointment->status }}">
          @if($appointment->status === 'cancelled')
            <svg width="14" height="14" viewBox="0 0 10 10" fill="none"><path d="M2.5 2.5l5 5M7.5 2.5l-5 5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
          @else
            <svg width="14" height="14" viewBox="0 0 10 10" fill="none"><path d="M2 5h6M5 2v6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
          @endif
        </div>
        <div>
          <div class="appt-terminal-title" style="font-size:13px">{{ $statusLabels[$appointment->status] }}</div>
        </div>
        <button type="button" class="ia-btn ia-btn--secondary ia-btn--sm appt-reopen-btn" data-status="pending" style="margin-left:auto">
          Reopen
        </button>
      </div>
    @else
      <div class="ia-card appt-progress-card">
        <div class="appt-progress-bar" data-current-index="{{ $currentIndex }}" data-update-url="{{ $updateUrl }}">
          @foreach($pipelineSteps as $idx => $step)
            @php
              $stepLabel = $statusLabels[$step];
              $isDone    = $idx < $currentIndex;
              $isCurrent = $idx === $currentIndex;
            @endphp
            <button type="button"
                    class="appt-progress-step {{ $isDone ? 'is-done' : '' }} {{ $isCurrent ? 'is-current' : '' }}"
                    data-status="{{ $step }}"
                    data-step-index="{{ $idx }}"
                    data-label="{{ $stepLabel }}">
              <span class="appt-progress-dot">
                @if($isDone)
                  <svg width="12" height="12" viewBox="0 0 10 10" fill="none"><path d="M2 5l2 2 4-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                @elseif($isCurrent)
                  <span class="appt-progress-dot-inner"></span>
                @endif
              </span>
              <span class="appt-progress-label">{{ $stepLabel }}</span>
            </button>
          @endforeach
        </div>
      </div>
    @endif

    {{-- Action stack --}}
    @unless($isTerminal)
    <div class="appt-b-actions">
      {{-- MARKER-PATCH-313 --}}
      {{-- MARKER-PATCH-315 — gated on the tag enable toggle --}}
      @if(data_get(tenant()->settings, 'work_order_tag.enabled', true))
      <button type="button" class="ia-btn ia-btn--secondary" onclick="openTagModal()">&#9113; Print tag</button>
      @endif
      <div class="appt-b-actions-divider"></div>
      {{-- MARKER-PATCH-285 — removed stray "Reschedule shipping tomorrow" cruft --}}
      <button type="button" class="ia-btn ia-btn--secondary appt-b-reschedule-btn">↻ Reschedule</button>
      <div class="appt-b-actions-divider"></div>
      <button type="button" class="ia-btn ia-btn--danger appt-b-cancel-btn">Cancel appointment</button>
    </div>
    @endunless

    {{-- Customer card --}}
    <div class="ia-card ia-card--tight">
      <div class="appt-section-label">Customer</div>
      <div style="font-weight:500;margin-bottom:4px">
        {{ $appointment->customerName() }}
      </div>
      <div style="font-size:13px;opacity:.6;margin-bottom:2px">
        {{ $appointment->customer_email }}
      </div>
      @if($appointment->customer_phone)
        <div style="font-size:13px;opacity:.6;margin-bottom:10px">
          {{ $appointment->customer_phone }}
        </div>
      @else
        <div style="margin-bottom:10px"></div>
      @endif
      @if($appointment->customer_id)
        <a href="{{ route('tenant.customers.show', $appointment->customer_id) }}"
           class="ia-btn ia-btn--secondary ia-btn--sm" style="width:100%;justify-content:center">
          View customer profile →
        </a>
        @if($appointment->responses->isNotEmpty())
          <button type="button"
                  class="ia-btn ia-btn--primary ia-btn--sm appt-b-cust-details-btn"
                  style="width:100%;justify-content:center;margin-top:6px">
            View booking notes →
          </button>
        @endif
      @endif
    </div>

    {{-- Resource — change which staff member or station owns this appointment.
         Soft-warns on conflicts with an override path. Auto-notes on change. --}}
    {{-- LAYOUT-B-PROMOTE-ORDER 20 --}}
    <div class="ia-card ia-card--tight" data-appt-resource-card data-appt-id="{{ $appointment->id }}">
      <div style="font-size:11px;text-transform:uppercase;letter-spacing:.07em;font-weight:500;opacity:.4;margin-bottom:12px">
        Resource
      </div>

      @php
        $currentResourceId = $appointment->resource_id;
        $currentResource   = $availableResources->firstWhere('id', $currentResourceId);
      @endphp

      <div class="sidebar-stat" style="border-bottom:none;padding-bottom:4px">
        <span class="sidebar-stat-label">Currently assigned</span>
        <span class="sidebar-stat-value" style="display:flex;align-items:center;gap:6px">
          @if($currentResource)
            <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:{{ $currentResource->color_hex ?: '#888' }}"></span>
            {{ $currentResource->name }}
          @else
            <span style="opacity:.5">Unassigned</span>
          @endif
        </span>
      </div>

      <label class="ia-form-label" style="margin-top:12px">Change to</label>
      <select class="ia-input" data-appt-resource-select style="margin-bottom:8px">
        @foreach($availableResources as $r)
          <option value="{{ $r->id }}" @selected($r->id === $currentResourceId)>
            {{ $r->name }}@if($r->subtitle) · {{ $r->subtitle }}@endif
          </option>
        @endforeach
      </select>
      <button type="button"
              class="ia-btn ia-btn--ghost"
              data-appt-resource-save
              style="width:100%">Save resource</button>
      <p style="font-size:11px;opacity:.4;margin-top:8px;line-height:1.4">
        If the new resource is busy at this time, you'll get a warning before the change is saved.
      </p>
    </div>

    {{-- Capacity slots · LAYOUT-B-RAIL v1 (collapsible override) --}}
    <div class="ia-card ia-card--tight">
      <div style="font-size:11px;text-transform:uppercase;letter-spacing:.07em;font-weight:500;opacity:.4;margin-bottom:10px">
        Capacity slots
      </div>
      <div class="sidebar-stat" style="margin-bottom:8px">
        <span class="sidebar-stat-label">Auto-calculated</span>
        <span class="sidebar-stat-value">{{ $appointment->slot_weight_auto ?? 1 }}</span>
      </div>
      @if($appointment->slot_weight_overridden)
      <div class="sidebar-stat" style="margin-bottom:4px">
        <span class="sidebar-stat-label" style="color:#EF9F27">Overridden</span>
        <span class="sidebar-stat-value" style="color:#EF9F27">{{ $appointment->slot_weight }}</span>
      </div>
      @endif
      <details class="appt-b-cap-override" style="margin-top:8px">
        <summary style="cursor:pointer;font-size:12px;color:var(--ia-accent);padding:4px 0;list-style:none">
          Override slot weight ▾
        </summary>
        <div data-appt-slot-weight-card style="margin-top:10px">
          <input type="hidden" data-appt-slot-weight-current value="{{ (int) ($appointment->slot_weight ?? 1) }}">
          <select class="ia-input" data-appt-slot-weight-select style="margin-bottom:8px">
            @foreach([1,2,3,4] as $w)
              <option value="{{ $w }}" @selected($appointment->slot_weight == $w)>
                {{ $w }} slot{{ $w > 1 ? 's' : '' }}
                @if($w == 1) — normal job
                @elseif($w == 2) — bigger job
                @elseif($w == 3) — large job
                @elseif($w == 4) — full day job
                @endif
              </option>
            @endforeach
          </select>
          <button type="button"
                  class="ia-btn ia-btn--ghost"
                  data-appt-slot-weight-save
                  data-appt-id="{{ $appointment->id }}"
                  style="width:100%">Save slot weight</button>
          <p style="font-size:11px;opacity:.4;margin-top:8px;line-height:1.4">
            Override how many capacity slots this appointment occupies.
          </p>
        </div>
      </details>
    </div>

  </aside>

  {{-- Main column starts here (existing content unchanged) --}}
  <div class="appt-b-main" style="display:flex;flex-direction:column;gap:20px">

    {{-- Line items · LAYOUT-B-PROMOTE-ORDER 10 --}}
    <div class="ia-card" style="order:10">
      <div class="appt-section-label" style="display:flex;align-items:center;justify-content:space-between">
        <span>Services</span>
        @if($bannerSale)
          <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm void-sale-btn" style="font-size:11px;padding:4px 10px" data-context="services">
            <span style="opacity:.6">🔒</span> Edit (voids draft)
          </button>
        @endif
      </div>

      <table class="appt-line-items" id="line-items-table">
        <thead>
          <tr>
            <th>Item</th>
            <th class="ia-num">Duration</th>
            <th class="ia-num">Price</th>
            <th style="width:32px"></th>
          </tr>
        </thead>
        <tbody id="line-items-body">
          @foreach($appointment->items as $item)
            <tr class="line-row" data-kind="service" data-item-id="{{ $item->id }}">
              <td style="font-weight:500">{{ $item->item_name_snapshot }}</td>
              <td class="ia-num">
                <input type="number" min="0" class="line-edit ia-input ia-input--sm"
                  data-field="duration_minutes"
                  value="{{ $item->duration_minutes_override ?? $item->duration_minutes_snapshot ?? 0 }}"
                  style="width:70px;text-align:right"> <span style="opacity:.5">min</span>
              </td>
              <td class="ia-num">
                <input type="number" min="0" step="0.01" class="line-edit ia-input ia-input--sm"
                  data-field="price_dollars"
                  value="{{ number_format(($item->price_cents_override ?? $item->price_cents) / 100, 2, '.', '') }}"
                  style="width:80px;text-align:right">
              </td>
              <td>
                <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm line-remove" title="Remove">&#x2715;</button>
              </td>
            </tr>
          @endforeach
          @foreach($appointment->addons as $addon)
            <tr class="line-row" data-kind="addon" data-item-id="{{ $addon->id }}">
              <td style="opacity:.7">+ {{ $addon->addon_name_snapshot }}</td>
              <td class="ia-num">
                <input type="number" min="0" class="line-edit ia-input ia-input--sm"
                  data-field="duration_minutes"
                  value="{{ $addon->duration_minutes_override ?? $addon->duration_minutes_snapshot ?? 0 }}"
                  style="width:70px;text-align:right"> <span style="opacity:.5">min</span>
              </td>
              <td class="ia-num">
                <input type="number" min="0" step="0.01" class="line-edit ia-input ia-input--sm"
                  data-field="price_dollars"
                  value="{{ number_format(($addon->price_cents_override ?? $addon->price_cents) / 100, 2, '.', '') }}"
                  style="width:80px;text-align:right">
              </td>
              <td>
                <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm line-remove" title="Remove">&#x2715;</button>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>

      @if($appointment->items->isEmpty() && $appointment->addons->isEmpty())
        <p style="font-size:13px;opacity:.4;margin:8px 0 12px">No items yet — add one below.</p>
      @endif

      <div style="display:flex;gap:8px;margin-top:12px;padding-top:12px;border-top:0.5px solid var(--ia-border);align-items:center">
        <select id="add-line-select" class="ia-input ia-input--sm" style="flex:1">
          <option value="">+ Add service or add-on…</option>
          <optgroup label="Services">
            @foreach($availableServices as $svc)
              <option value="service:{{ $svc->id }}">{{ $svc->name }} · {{ format_money($svc->price_cents) }}</option>
            @endforeach
          </optgroup>
          <optgroup label="Add-ons">
            @foreach($availableAddons as $ad)
              <option value="addon:{{ $ad->id }}">+ {{ $ad->name }} · {{ format_money($ad->price_cents) }}</option>
            @endforeach
          </optgroup>
        </select>
        <button type="button" id="add-line-btn" class="ia-btn ia-btn--secondary ia-btn--sm">Add</button>
      </div>

      <div class="appt-total-row" style="margin-top:14px">
        <span>Subtotal</span>
        <span>{{ format_money($appointment->subtotal_cents) }}</span>
      </div>
    </div>

    {{-- ===================================================================
         Products & Add-ons (physical inventory and custom items consumed
         during the appointment)
         =================================================================== --}}
    @php
      $isCommittedStatus = \App\Support\AppointmentStatus::isDone($appointment->status);
    @endphp
    {{-- LAYOUT-B-PROMOTE-ORDER 40 --}}
    <div class="ia-card" id="parts-card" style="order:40">
      <div class="appt-section-label" style="display:flex;align-items:center;justify-content:space-between;gap:10px">
        <span>Products & Add-ons</span>
        <div style="display:flex;align-items:center;gap:8px">
          @if($isCommittedStatus)
            <span style="font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-dim);font-weight:600;padding:2px 8px;border-radius:99px;background:var(--ia-surface-2)">Committed</span>
          @endif
          @if($bannerSale)
            <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm void-sale-btn" style="font-size:11px;padding:4px 10px" data-context="parts">
              <span style="opacity:.6">🔒</span> Edit (voids draft)
            </button>
          @endif
        </div>
      </div>

      <table class="appt-line-items" id="parts-table">
        <thead>
          <tr>
            <th>Item</th>
            <th class="ia-num" style="width:90px">Qty</th>
            <th class="ia-num" style="width:90px">Price</th>
            <th class="ia-num" style="width:90px">Total</th>
            <th style="width:32px"></th>
          </tr>
        </thead>
        <tbody id="parts-body">
          @foreach($appointment->parts as $part)
            @php
              $invItem = $part->inventoryItem;
              $stockNow = $invItem ? (int) ($invItem->computed_stock_count ?? 0) : null;
              $stockProjected = ($stockNow !== null && !$part->isCommitted())
                ? $stockNow - (int) $part->quantity
                : null;
            @endphp
            <tr class="part-row" data-part-id="{{ $part->id }}" data-committed="{{ $part->isCommitted() ? '1' : '0' }}">
              <td>
                <div style="font-weight:500;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                  <span>{{ $part->item_name_snapshot }}</span>
                  @if(!$part->inventory_item_id)
                    <span style="font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-dim);font-weight:600;padding:1px 7px;border-radius:99px;background:var(--ia-surface-2);border:0.5px solid var(--ia-border)">Custom</span>
                  @endif
                </div>
                @if($part->item_sku_snapshot)
                  <div style="font-size:11px;opacity:.45;font-family:var(--ia-font-mono);margin-top:2px">{{ $part->item_sku_snapshot }}</div>
                @endif
                @if($stockNow !== null)
                  <div style="font-size:11px;opacity:.55;margin-top:3px">
                    @if($part->isCommitted())
                      Stock decremented · current: {{ $stockNow }}
                    @else
                      Stock: {{ $stockNow }} → {{ $stockProjected }} on completion
                    @endif
                  </div>
                @endif
              </td>
              <td class="ia-num">
                <input type="number" min="1" max="999"
                  class="part-qty-edit ia-input ia-input--sm"
                  value="{{ $part->quantity }}"
                  data-part-id="{{ $part->id }}"
                  {{ ($part->isCommitted() && $part->inventory_item_id) ? 'disabled' : '' }}
                  style="width:60px;text-align:right">
              </td>
              <td class="ia-num">{{ format_money($part->effectiveUnitPriceCents()) }}</td>
              <td class="ia-num" data-line-total>{{ format_money($part->lineTotalCents()) }}</td>
              <td>
                <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm part-remove" data-part-id="{{ $part->id }}" title="Remove">&#x2715;</button>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>

      @if($appointment->parts->isEmpty())
        <p style="font-size:13px;opacity:.4;margin:8px 0 12px">No products added yet.</p>
      @endif

      <div style="display:flex;gap:8px;margin-top:12px;padding-top:12px;border-top:0.5px solid var(--ia-border);align-items:center;position:relative">
        <input type="text" id="part-picker-input" class="ia-input ia-input--sm" placeholder="+ Add product from inventory or custom item…" style="flex:1" autocomplete="off">
        <div id="part-picker-results" style="display:none;position:absolute;top:100%;left:0;right:64px;margin-top:4px;background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);max-height:280px;overflow-y:auto;z-index:20"></div>
      </div>

      {{-- Custom item inline form (shown when user clicks "+ Custom item" in the picker) --}}
      <div id="custom-item-form" style="display:none;margin-top:10px;padding:12px;border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);background:var(--ia-surface-2)">
        <div style="font-size:12px;font-weight:500;margin-bottom:10px;display:flex;align-items:center;justify-content:space-between">
          <span>Custom item</span>
          <button type="button" id="custom-item-cancel" class="ia-btn ia-btn--ghost ia-btn--sm" style="padding:2px 8px;font-size:11px">Cancel</button>
        </div>
        <div style="display:grid;grid-template-columns:1.6fr 0.7fr 0.5fr auto;gap:8px;align-items:end">
          <div>
            <label class="ia-form-label" style="font-size:11px;margin-bottom:4px">Name</label>
            <input type="text" id="custom-item-name" class="ia-input ia-input--sm" maxlength="255" placeholder="e.g. Scratched paint touch-up" style="width:100%">
          </div>
          <div>
            <label class="ia-form-label" style="font-size:11px;margin-bottom:4px">Price</label>
            <input type="number" id="custom-item-price" class="ia-input ia-input--sm" min="0" step="0.01" placeholder="0.00" style="width:100%;text-align:right">
          </div>
          <div>
            <label class="ia-form-label" style="font-size:11px;margin-bottom:4px">Qty</label>
            <input type="number" id="custom-item-qty" class="ia-input ia-input--sm" min="1" max="999" value="1" style="width:100%;text-align:right">
          </div>
          <div>
            <button type="button" id="custom-item-add" class="ia-btn ia-btn--primary ia-btn--sm">Add</button>
          </div>
        </div>
        <label style="display:flex;align-items:center;gap:6px;margin-top:10px;font-size:11px;cursor:pointer;opacity:.75">
          <input type="checkbox" id="custom-item-taxable" checked> Taxable
        </label>
      </div>
    </div>
    {{-- ================================================================== --}}

    {{-- Work order (staff-filled equipment details) --}}
    @if($appointment->workOrderFields && $appointment->workOrderFields->isNotEmpty())
    @php
      $responsesByFieldId = $appointment->workOrderResponses->keyBy('field_id');
      $identifierField = $appointment->workOrderFields->firstWhere('is_identifier', true);
      $identifierValue = $identifierField ? ($responsesByFieldId[$identifierField->id]->response_value ?? null) : null;
      $nonIdentifierFields = $appointment->workOrderFields->filter(fn($f) => !$f->is_identifier);
    @endphp
    {{-- LAYOUT-B-PROMOTE-ORDER 50 --}}
    {{-- ════════════════════════════════════════════════════════════
         Special Orders integration (added by patch 88, Stage 5)
         Parts on order via SO. Distinct from in-stock parts above.
         Includes soft completion-block warning when appointment is
         in_progress and SOs aren't yet pulled.
         ════════════════════════════════════════════════════════════ --}}
    @isset($specialOrdersForAppt)
      @php
        $openAppointmentSos = $specialOrdersForAppt->whereIn('status', ['needed', 'ordered', 'arrived']);
        $unArrivedSos = $specialOrdersForAppt->whereIn('status', ['needed', 'ordered']);
        $showBlockWarning = $appointment->status === 'in_progress' && $unArrivedSos->isNotEmpty();
      @endphp

      <div class="ia-card" id="so-parts-card" style="order:45;{{ $showBlockWarning ? 'border-left:3px solid #F59E0B;' : '' }}">
        <div class="appt-section-label" style="display:flex;align-items:center;justify-content:space-between;gap:10px">
          <span>Special-order parts</span>
          <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm"
                  onclick='SoDrawer.open({customer_id: @json($appointment->customer_id), customer_label: @json(trim(($appointment->customer->first_name ?? "") . " " . ($appointment->customer->last_name ?? ""))), appointment_id: @json($appointment->id), alloc_mode: "customer_appt"})'>
            + SO for this appointment
          </button>
        </div>

        @if($showBlockWarning)
          <div style="background:rgba(245,158,11,0.08);border-radius:6px;padding:10px 12px;margin-bottom:12px;font-size:12.5px">
            <strong style="color:#F59E0B">⚠ {{ $unArrivedSos->count() }} part{{ $unArrivedSos->count() === 1 ? '' : 's' }} not yet arrived.</strong>
            <span style="color:var(--ia-text-muted)">
              Completing this appointment will leave the customer waiting on parts. Consider waiting until parts arrive, or proceed if customer is OK with split pickup.
            </span>
          </div>
        @endif

        @if($specialOrdersForAppt->isEmpty())
          <p style="font-size:13px;color:var(--ia-text-muted);padding:6px 0;margin:0">No special-order parts on this appointment.</p>
        @else
          <table class="appt-line-items">
            <thead>
              <tr>
                <th>Part</th>
                <th class="ia-num" style="width:60px">Qty</th>
                <th style="width:120px">Status</th>
                <th style="width:90px">ETA</th>
                <th>Vendor</th>
                <th style="width:80px">SO #</th>
                <th style="width:70px"></th>{{-- MARKER-SO-APPT-CANCEL --}}
              </tr>
            </thead>
            <tbody>
              @foreach($specialOrdersForAppt as $so)
                @php
                  $isOverdue = $so->status === 'ordered' && $so->expected_arrival_date && $so->expected_arrival_date->isPast();
                  $rowOpacity = in_array($so->status, ['pulled', 'cancelled']) ? '0.55' : '1';
                @endphp
                <tr style="cursor:pointer;opacity:{{ $rowOpacity }}" onclick="window.location.href='{{ route('tenant.special-orders.show', ['id' => $so->id]) }}'">
                  <td>
                    <strong>{{ $so->item_name_snapshot }}</strong>
                  </td>
                  <td class="ia-num">{{ $so->quantity }}</td>
                  <td>
                    <span class="so-status so-status--{{ $isOverdue ? 'overdue' : $so->status }}">{{ $isOverdue ? 'Overdue' : ucfirst($so->status) }}</span>
                  </td>
                  <td style="color:var(--ia-text-muted);font-size:12px">
                    @if($so->expected_arrival_date){{ $so->expected_arrival_date->format('M j') }}@else — @endif
                  </td>
                  <td style="color:var(--ia-text-muted);font-size:12px">{{ $so->vendor?->name ?? 'TBD' }}</td>
                  <td style="font-size:11px;color:var(--ia-text-muted)">{{ $so->so_number }}</td>
                  {{-- MARKER-SO-APPT-CANCEL — a special order attached here had
                       no way off the work order: the only action was opening
                       it. A still-"needed" order can be retracted from the
                       row; placed ones cannot, since goods may be inbound. --}}
                  <td onclick="event.stopPropagation()" style="cursor:default;text-align:right">
                    @if($so->status === 'needed')
                      <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm"
                              data-appt-so-cancel="{{ $so->id }}" data-so-number="{{ $so->so_number }}">Cancel</button>
                    @elseif(in_array($so->status, ['ordered', 'arrived']))
                      <span style="font-size:11px;color:var(--ia-text-muted)">placed</span>
                    @endif
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        @endif
      </div>

      @include('tenant.special-orders._drawer', ['vendors' => $soVendors ?? collect()])

      {{-- MARKER-SO-APPT-CANCEL — inside the section on purpose: a script
           placed after @endsection is silently discarded by Blade. --}}
      <script>
      (function () {
        var url  = @json(route('tenant.special-orders.cancel', ['id' => '__ID__']));
        var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content;

        document.addEventListener('click', function (e) {
          var btn = e.target.closest('[data-appt-so-cancel]');
          if (!btn) return;
          e.preventDefault();
          e.stopPropagation();

          var num = btn.getAttribute('data-so-number') || 'this special order';
          if (!confirm('Cancel ' + num + '? The part is no longer needed for this work order.')) return;

          btn.disabled = true;
          fetch(url.replace('__ID__', btn.getAttribute('data-appt-so-cancel')), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({ reason: 'Cancelled from the work order.' }),
          })
            .then(function (r) { return r.json(); })
            .then(function (j) {
              if (j && j.ok) {
                if (window.IntakeToast) IntakeToast.success(num + ' cancelled');
                window.location.reload();
              } else {
                btn.disabled = false;
                if (window.IntakeToast) IntakeToast.error((j && j.error) || 'Could not cancel.');
              }
            })
            .catch(function () {
              btn.disabled = false;
              if (window.IntakeToast) IntakeToast.error('Network error.');
            });
        });
      })();
      </script>

      @push('styles')
      <style>
      .so-status {
        display: inline-block; padding: 2px 8px; border-radius: 99px;
        font-size: 10.5px; font-weight: 600;
        text-transform: uppercase; letter-spacing: 0.05em;
      }
      .so-status--needed   { background: rgba(167,139,250,0.10); color: #A78BFA; }
      .so-status--ordered  { background: rgba(96,165,250,0.10);  color: #60A5FA; }
      .so-status--arrived  { background: rgba(190,242,100,0.10); color: var(--ia-accent); }
      .so-status--pulled   { background: rgba(200,200,200,0.06); color: var(--ia-text-muted); }
      .so-status--cancelled{ background: rgba(248,113,113,0.10); color: #F87171; text-decoration: line-through; }
      .so-status--overdue  { background: rgba(248,113,113,0.15); color: #F87171; }
      </style>
      @endpush
    @endisset

        <div class="ia-card" id="work-order-card" style="order:50">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;padding-bottom:12px;border-bottom:0.5px solid var(--ia-border)">
        <div class="appt-section-label" style="margin-bottom:0">Work order</div>
        <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" id="wo-edit-toggle">Edit</button>
      </div>

      {{-- Display mode --}}
      <div id="wo-display">
        @if($identifierField && $identifierValue)
          <div style="margin-bottom:18px;padding-bottom:16px;border-bottom:0.5px solid var(--ia-border)">
            <div style="font-size:10px;text-transform:uppercase;letter-spacing:.07em;color:var(--ia-text-muted);font-weight:500;margin-bottom:6px">
              {{ $identifierField->label }}
            </div>
            <div class="ia-mono" style="font-size:18px;font-weight:500;letter-spacing:.02em">
              {{ $identifierValue }}
            </div>
          </div>
        @endif

        @php
          $filledNonIdentifier = $nonIdentifierFields->filter(fn($f) => !empty($responsesByFieldId[$f->id]->response_value ?? null));
        @endphp

        @if($filledNonIdentifier->isEmpty() && (!$identifierField || !$identifierValue))
          <p style="font-size:13px;opacity:.4">No work order details recorded yet.</p>
        @elseif($filledNonIdentifier->isNotEmpty())
          <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:16px 32px">
            @foreach($filledNonIdentifier as $field)
              <div>
                <div style="font-size:10px;text-transform:uppercase;letter-spacing:.07em;color:var(--ia-text-muted);font-weight:500;margin-bottom:3px">
                  {{ $field->label }}
                </div>
                <div style="font-size:14px">{{ $responsesByFieldId[$field->id]->response_value }}</div>
              </div>
            @endforeach
          </div>
        @endif
      </div>

      {{-- Edit mode --}}
      <form id="wo-edit-form" style="display:none" method="POST" action="{{ $updateUrl }}">
        @csrf @method('PATCH')
        <input type="hidden" name="op" value="save_work_order">

        @foreach($appointment->workOrderFields as $field)
          @php $currentValue = $responsesByFieldId[$field->id]->response_value ?? ''; @endphp
          <div class="ia-form-group" style="margin-bottom:14px">
            <label class="ia-form-label">
              {{ $field->label }}
              @if($field->is_identifier)
                <span style="background:var(--ia-accent);color:var(--ia-accent-text);font-size:9px;font-weight:500;padding:1px 6px;border-radius:20px;text-transform:uppercase;letter-spacing:.05em;margin-left:6px">ID</span>
              @endif
              @if($field->is_required)
                <span class="ia-required">*</span>
              @endif
            </label>
            @if($field->field_type === 'textarea')
              <textarea name="values[{{ $field->id }}]" class="ia-input" rows="3" @if($field->is_required) required @endif>{{ $currentValue }}</textarea>
            @elseif($field->field_type === 'number')
              <input type="number" name="values[{{ $field->id }}]" value="{{ $currentValue }}" class="ia-input" @if($field->is_required) required @endif>
            @elseif($field->field_type === 'select')
              <select name="values[{{ $field->id }}]" class="ia-input" @if($field->is_required) required @endif>
                <option value="">—</option>
                @foreach(($field->options ?? []) as $opt)
                  <option value="{{ $opt }}" @selected($currentValue === $opt)>{{ $opt }}</option>
                @endforeach
              </select>
            @else
              <input type="text" name="values[{{ $field->id }}]" value="{{ $currentValue }}" class="ia-input" @if($field->is_required) required @endif>
            @endif
            @if($field->help_text)
              <div style="font-size:11px;opacity:.5;margin-top:3px">{{ $field->help_text }}</div>
            @endif
          </div>
        @endforeach

        <div style="display:flex;gap:8px;margin-top:16px">
          <button type="submit" class="ia-btn ia-btn--primary ia-btn--sm">Save</button>
          <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" id="wo-edit-cancel">Cancel</button>
        </div>
      </form>
    </div>
    @endif

    {{-- LAYOUT-B-CUSTDETAIL-MOVED v1: Booking notes (intake responses) now render in the modal at end of page --}}

    {{-- Charges --}}
    {{-- LAYOUT-B-PROMOTE-ORDER 70 --}}
    <div class="ia-card" style="order:70">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
        <div class="appt-section-label" style="margin-bottom:0">Additional charges</div>
        <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" id="add-charge-toggle">
          + Add charge
        </button>
      </div>

      <form method="POST" action="{{ $updateUrl }}" class="add-charge-form" id="add-charge-form">
        @csrf @method('PATCH')
        <input type="hidden" name="op" value="add_charge">
        <div class="ia-input-grid-2" style="margin-bottom:10px">
          <div class="ia-form-group" style="margin-bottom:0">
            <label class="ia-form-label">Description <span class="ia-required">*</span></label>
            <input type="text" name="description" class="ia-input" placeholder="e.g. New brake cable" required>
          </div>
          <div class="ia-form-group" style="margin-bottom:0">
            <label class="ia-form-label">Amount ($) <span class="ia-required">*</span></label>
            <input type="number" name="amount_display" class="ia-input" placeholder="25.00"
              step="0.01" min="0.01" id="charge-amount-display">
            <input type="hidden" name="amount_cents" id="charge-amount-cents">
          </div>
        </div>
        <div style="display:flex;gap:8px">
          <button type="submit" class="ia-btn ia-btn--primary ia-btn--sm">Save charge</button>
          <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" id="add-charge-cancel">Cancel</button>
        </div>
      </form>

      @if($appointment->charges->isEmpty())
        <p style="font-size:13px;opacity:.4">No additional charges.</p>
      @else
        @foreach($appointment->charges as $charge)
          <div class="appt-charge-row">
            <div>
              <div style="font-size:13px">{{ $charge->description }}</div>
              <div style="font-size:11px;opacity:.4">
                {{ \Carbon\Carbon::parse($charge->created_at)->format('M j') }} ·
                {{ $charge->is_paid ? 'Paid' : 'Unpaid' }}
              </div>
            </div>
            <div style="font-weight:500">{{ format_money($charge->amount_cents) }}</div>
          </div>
        @endforeach

        <div class="appt-total-row">
          <span>Charges total</span>
          <span>{{ format_money($appointment->charges->sum('amount_cents')) }}</span>
        </div>
      @endif
    </div>

    {{-- Notes --}}
    {{-- LAYOUT-B-PROMOTE-ORDER 90 --}}
    <div class="ia-card" style="order:90">
      <div class="appt-section-label">Notes</div>

      <div class="ia-note-add">
        <textarea id="note-input" rows="3" maxlength="500"
          data-maxlength="500" data-counter="note-chars"
          placeholder="Add a note…" style="width:100%;border-radius:var(--ia-r-md);border:0.5px solid var(--ia-border);background:var(--ia-input-bg);color:var(--ia-text);padding:8px 10px;font-size:13px;resize:none;font-family:var(--ia-font)"></textarea>
        <div class="ia-note-add-footer">
          <span class="ia-char-count" id="note-chars">500</span>
          <button type="button" class="ia-btn ia-btn--primary ia-btn--sm" id="note-submit"
            data-url="{{ $updateUrl }}">
            Add note
          </button>
        </div>
        <p id="note-error" style="font-size:12px;color:#E24B4A;margin-top:4px;display:none"></p>
      </div>

      <div class="ia-notes" id="notes-list">
        @forelse($appointment->notes->sortByDesc('created_at') as $note)
          <div class="ia-note" data-note-id="{{ $note->id }}">
            <div class="ia-note-head">
              <span class="ia-note-author">
                {{ $note->user?->name ?? ($note->note_type === 'system' ? 'System' : 'Staff') }}
              </span>
              <span class="ia-note-time">
                {{ tlocal($note->created_at, 'M j, g:i a') }}{{-- MARKER-PATCH-532 --}}
              </span>
              @if($note->note_type !== 'system')
                <button type="button" class="ia-note-delete"
                  data-note-id="{{ $note->id }}"
                  title="Delete">&#x2715;</button>
              @endif
            </div>
            <div class="ia-note-body">{{ $note->note_content }}</div>
          </div>
        @empty
          <p class="ia-notes-empty" style="font-size:13px;opacity:.4">No notes yet.</p>
        @endforelse
      </div>
    </div>

    {{-- LAYOUT-B-PROMOTE v1 — moved-block unwrapped; cards are direct children of .appt-b-main and ordered via CSS order:N --}}

    {{-- Customer card · LAYOUT-B-PROMOTE-ORDER 9999 (hidden) --}}
    <div class="ia-card ia-card--tight" style="display:none;order:9999" aria-hidden="true">
      <div class="appt-section-label">Customer</div>
      <div style="font-weight:500;margin-bottom:4px">
        {{ $appointment->customerName() }}
      </div>
      <div style="font-size:13px;opacity:.6;margin-bottom:2px">
        {{ $appointment->customer_email }}
      </div>
      @if($appointment->customer_phone)
        <div style="font-size:13px;opacity:.6;margin-bottom:10px">
          {{ $appointment->customer_phone }}
        </div>
      @else
        <div style="margin-bottom:10px"></div>
      @endif
      @if($appointment->customer_id)
        <a href="{{ route('tenant.customers.show', $appointment->customer_id) }}"
           class="ia-btn ia-btn--secondary ia-btn--sm" style="width:100%;justify-content:center">
          View customer profile →
        </a>
      @endif
    </div>

    {{-- LAYOUT-B-RAIL-MOVE v1: Resource card moved to rail --}}

    {{-- LAYOUT-B-RAIL-MOVE v1: Capacity card moved to rail --}}

    {{-- Payment ledger · LAYOUT-B-PROMOTE-ORDER 80 (order applies to the wrapping ia-card div below) --}}
    @php
      $payments      = $appointment->payments;
      $balanceDue    = max(0, (int)$appointment->total_cents - (int)$appointment->paid_cents);
      $overage       = max(0, (int)$appointment->paid_cents - (int)$appointment->total_cents);
      $openSale      = $appointment->openRegisterSale();
      $hasOpenSale   = $openSale !== null;
    @endphp
    <div style="order:80" class="ia-card ia-card--tight">
      <div class="appt-section-label">Payment</div>
      <div class="sidebar-stat">
        <span class="sidebar-stat-label">Status</span>
        <span>
          <span class="ia-badge ia-badge--{{ $appointment->payment_status }}">
            {{ ucwords(str_replace('_', ' ', $appointment->payment_status)) }}
          </span>
        </span>
      </div>
      <div class="sidebar-stat">
        <span class="sidebar-stat-label">Subtotal</span>
        <span class="sidebar-stat-value">{{ format_money($appointment->subtotal_cents) }}</span>
      </div>
      @if($appointment->tax_cents > 0)
      <div class="sidebar-stat">
        <span class="sidebar-stat-label">Tax</span>
        <span class="sidebar-stat-value">{{ format_money($appointment->tax_cents) }}</span>
      </div>
      @endif
      <div class="sidebar-stat">
        <span class="sidebar-stat-label" style="font-weight:500">Total</span>
        <span class="sidebar-stat-value" style="font-size:16px">{{ format_money($appointment->total_cents) }}</span>
      </div>

      @if($payments->isNotEmpty())
        <div style="margin-top:14px;padding-top:12px;border-top:0.5px solid var(--ia-border)">
          <div style="font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-dim);font-weight:600;margin-bottom:8px">Payment ledger</div>
          @foreach($payments as $p)
            <div style="display:flex;justify-content:space-between;align-items:flex-start;font-size:12px;padding:6px 0;border-bottom:0.5px solid var(--ia-border)">
              <div style="flex:1;min-width:0">
                <div style="font-weight:500;color:var(--ia-text)">
                  {{ $p->kind === 'refund' || $p->kind === 'overage_refund' ? 'Refund' : ucfirst($p->kind) }}
                  · {{ $p->methodLabel() }}
                </div>
                <div style="font-size:10px;color:var(--ia-text-dim);margin-top:2px">
                  {{ $p->recorded_at ? tlocal($p->recorded_at, 'M j · g:i A') : '' }}
                  @if($p->source === 'register_sale' && $p->register_sale_id)
                    · sale {{ optional($p->registerSale)->sale_number ?? '#' }}
                  @endif
                </div>
              </div>
              <div style="font-weight:500;color:{{ $p->amount_cents < 0 ? '#F09595' : '#A8D670' }}">
                {{ $p->amount_cents < 0 ? '−' : '+' }}{{ format_money(abs($p->amount_cents)) }}
              </div>
            </div>
          @endforeach
        </div>
      @endif

      <div class="sidebar-stat" style="margin-top:8px">
        <span class="sidebar-stat-label">Paid so far</span>
        <span class="sidebar-stat-value" style="color:#A8D670">{{ format_money($appointment->paid_cents) }}</span>
      </div>

      @if($balanceDue > 0)
        <div class="sidebar-stat">
          <span class="sidebar-stat-label" style="font-weight:500">Balance owed</span>
          <span class="sidebar-stat-value" style="font-size:15px;font-weight:500">{{ format_money($balanceDue) }}</span>
        </div>
      @elseif($overage > 0)
        <div class="sidebar-stat">
          <span class="sidebar-stat-label" style="font-weight:500;color:#FBBF24">Customer is owed</span>
          <span class="sidebar-stat-value" style="font-size:15px;font-weight:500;color:#FBBF24">{{ format_money($overage) }}</span>
        </div>
      @else
        <div class="sidebar-stat">
          <span class="sidebar-stat-label" style="font-weight:500">Balance owed</span>
          <span class="sidebar-stat-value" style="font-size:15px;font-weight:500;color:#A8D670">$0.00</span>
        </div>
      @endif

      @if($hasOpenSale)
        <a href="{{ route('tenant.register.index', []) }}?resume={{ $openSale->id }}"
           class="ia-btn ia-btn--primary ia-btn--sm" style="width:100%;margin-top:14px;text-align:center;display:block">
          Take payment in register
        </a>
      @elseif($balanceDue > 0 && !\App\Support\AppointmentStatus::isTerminal($appointment->status))
        <button type="button" id="record-deposit-toggle" class="ia-btn ia-btn--secondary ia-btn--sm" style="width:100%;margin-top:14px">
          + Record deposit
        </button>
        <div id="record-deposit-form" style="display:none;margin-top:10px;padding:12px;background:var(--ia-surface-2);border-radius:var(--ia-r-md);border:0.5px solid var(--ia-border)">
          <label style="font-size:11px;color:var(--ia-text-dim);display:block;margin-bottom:4px">Amount</label>
          <input type="number" id="record-deposit-amount" min="0.01" step="0.01" placeholder="0.00" style="width:100%;padding:6px 10px;background:var(--ia-bg);border:0.5px solid var(--ia-border);color:var(--ia-text);border-radius:6px;font-size:13px;margin-bottom:8px">
          <div style="display:flex;gap:6px">
            <button type="button" id="record-deposit-cancel" class="ia-btn ia-btn--ghost ia-btn--sm" style="flex:1">Cancel</button>
            <button type="button" id="record-deposit-go" class="ia-btn ia-btn--primary ia-btn--sm" style="flex:1">Send to register</button>
          </div>
          <p style="font-size:10px;color:var(--ia-text-dim);margin:8px 0 0">Creates a draft sale in the register where you take the actual payment.</p>
        </div>
      @endif
    </div>

    {{-- Cancel appointment — DOM kept, hidden in B layout; rail Cancel proxies to this --}}
    @unless(\App\Support\AppointmentStatus::isTerminal($appointment->status))
      <button type="button" class="ia-btn ia-btn--danger ia-btn--sm appt-cancel-btn appt-cancel-btn-original" style="width:100%;order:9999">
        Cancel appointment
      </button>
    @endunless

  </div>{{-- /.appt-b-main --}}

</div>{{-- /.appt-b-shell --}}

{{-- RESCHEDULE-MODAL v1 --}}
@php
  // Build the eligible resources list. For now, all active resources on this tenant
  // are eligible. (A future improvement is per-service eligibility via
  // service_resource_eligibility, matching the create flow's logic.)
  $reschedFirstService = $appointment->items->first();
  $reschedFirstServiceId = $reschedFirstService?->service_item_id;
  $reschedCurrentResource = $availableResources->firstWhere('id', $appointment->resource_id);

  try {
    $reschedFromTimeC = $appointment->appointment_time
      ? \Carbon\Carbon::parse($appointment->appointment_date->toDateString() . ' ' . $appointment->appointment_time)
      : null;
    $reschedFromDur = (int) ($appointment->total_duration_minutes ?? 0);
    $reschedFromEndC = ($reschedFromTimeC && $reschedFromDur > 0)
      ? $reschedFromTimeC->copy()->addMinutes($reschedFromDur)
      : null;
  } catch (\Throwable $e) {
    $reschedFromTimeC = null; $reschedFromEndC = null;
  }
@endphp
@unless($isTerminal)
<div class="resch-modal" id="resch-modal" hidden role="dialog" aria-modal="true" aria-labelledby="resch-modal-title">
  <div class="resch-modal-backdrop" data-resch-close></div>
  <div class="resch-modal-card">
    <div class="resch-modal-head">
      <h2 class="resch-modal-title" id="resch-modal-title">Reschedule appointment</h2>
      <button type="button" class="resch-modal-close" data-resch-close aria-label="Close">×</button>
    </div>

    <div class="resch-modal-body">

      {{-- "From" current state --}}
      <div class="resch-from">
        <div class="resch-from-label">Current</div>
        <div class="resch-from-when">
          @if($reschedFromTimeC)
            {{ $reschedFromTimeC->format('D, M j · g:i A') }}@if($reschedFromEndC) – {{ $reschedFromEndC->format('g:i A') }}@endif
          @else
            No time set
          @endif
        </div>
        <div class="resch-from-resource">
          @if($reschedCurrentResource)
            <span class="resch-swatch" style="background: {{ $reschedCurrentResource->color_hex ?: '#888' }}"></span>
            {{ $reschedCurrentResource->name }}
          @else
            Unassigned
          @endif
        </div>
      </div>

      {{-- Resource picker --}}
      <div class="resch-field">
        <label class="resch-label" for="resch-resource">Resource</label>
        <select class="resch-input" id="resch-resource" data-resch-resource>
          @foreach($availableResources as $r)
            <option value="{{ $r->id }}" data-color="{{ $r->color_hex ?: '#888' }}" @selected($r->id === $appointment->resource_id)>
              {{ $r->name }}@if($r->subtitle) · {{ $r->subtitle }}@endif
            </option>
          @endforeach
        </select>
      </div>

      {{-- Times picker — week strip --}}
      <div class="resch-field">
        <div class="resch-times-head">
          <label class="resch-label" style="margin:0">Available times</label>
          <div class="resch-week-nav">
            <button type="button" class="resch-week-btn" id="resch-prev-week" aria-label="Previous week">‹</button>
            <span class="resch-week-label" id="resch-week-label">—</span>
            <button type="button" class="resch-week-btn" id="resch-next-week" aria-label="Next week">›</button>
          </div>
        </div>
        <div class="resch-times-list" id="resch-times-list">
          <div class="resch-times-empty">Pick a resource and click Show times.</div>
        </div>
        <button type="button" class="ia-btn ia-btn--secondary ia-btn--sm" id="resch-show-times"
                style="width:100%;margin-top:8px">
          Show available times
        </button>
      </div>

      {{-- "To" preview (appears after selection) --}}
      <div class="resch-to" id="resch-to" hidden>
        <div class="resch-to-label">New</div>
        <div class="resch-to-when" id="resch-to-when">—</div>
        <div class="resch-to-resource" id="resch-to-resource">—</div>
      </div>

    </div>

    <div class="resch-modal-foot">
      <button type="button" class="ia-btn ia-btn--ghost" data-resch-close>Cancel</button>
      <button type="button" class="ia-btn ia-btn--primary" id="resch-submit" disabled
              data-appt-id="{{ $appointment->id }}"
              data-update-url="{{ $updateUrl }}"
              data-first-service-id="{{ $reschedFirstServiceId ?? '' }}">
        Reschedule
      </button>
    </div>
  </div>
</div>
@endunless

{{-- LAYOUT-B-CUSTDETAIL-MODAL v1 --}}
@if($appointment->responses->isNotEmpty())
<div class="appt-b-cust-modal" id="appt-b-cust-modal" hidden role="dialog" aria-modal="true" aria-labelledby="appt-b-cust-modal-title">
  <div class="appt-b-cust-modal-backdrop" data-cust-modal-close></div>
  <div class="appt-b-cust-modal-card">
    <div class="appt-b-cust-modal-head">
      <h2 class="appt-b-cust-modal-title" id="appt-b-cust-modal-title">Booking notes</h2>
      <button type="button" class="appt-b-cust-modal-close" data-cust-modal-close aria-label="Close">×</button>
    </div>
    <div class="appt-b-cust-modal-body">
      @foreach($appointment->responses as $r)
        <div class="appt-response">
          <div class="appt-response-label">{{ $r->field_label_snapshot }}</div>
          <div class="appt-response-value">{{ $r->response_value ?: '—' }}</div>
        </div>
      @endforeach
    </div>
  </div>
</div>
@endif

{{-- MARKER-PATCH-314 --}}
@include('tenant.appointments._tag_modal')

@endsection

@push('scripts')
<script>
(function () {
  var updateUrl = '{{ $updateUrl }}';
  var csrf      = window.IntakeAdmin.csrfToken;

  var toggle  = document.getElementById('add-charge-toggle');
  var form    = document.getElementById('add-charge-form');
  var cancel  = document.getElementById('add-charge-cancel');
  var amtDisp = document.getElementById('charge-amount-display');
  var amtCents= document.getElementById('charge-amount-cents');

  if (toggle) toggle.addEventListener('click', function () {
    form.classList.add('open');
    toggle.style.display = 'none';
  });
  if (cancel) cancel.addEventListener('click', function () {
    form.classList.remove('open');
    toggle.style.display = '';
  });
  if (amtDisp) amtDisp.addEventListener('input', function () {
    amtCents.value = Math.round(parseFloat(amtDisp.value || 0) * 100);
  });

  var noteInput  = document.getElementById('note-input');
  var noteSubmit = document.getElementById('note-submit');
  var noteError  = document.getElementById('note-error');
  var notesList  = document.getElementById('notes-list');
  var noteChars  = document.getElementById('note-chars');

  if (noteInput && noteChars) {
    noteInput.addEventListener('input', function () {
      var rem = 500 - noteInput.value.length;
      noteChars.textContent = rem;
      noteChars.classList.toggle('warn', rem <= 30);
    });
  }

  if (noteSubmit) {
    noteSubmit.addEventListener('click', function () {
      var note = noteInput.value.trim();
      if (!note) { showErr('Please enter a note.'); return; }
      noteSubmit.disabled = true;
      noteSubmit.textContent = 'Saving…';

      var fd = new FormData();
      fd.append('_token', csrf);
      fd.append('_method', 'PATCH');
      fd.append('op', 'add_note');
      fd.append('note', note);

      fetch(updateUrl, { method: 'POST', body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json(); })
        .then(function (resp) {
          noteSubmit.disabled = false;
          noteSubmit.textContent = 'Add note';
          if (!resp.ok) { showErr(resp.message || 'Error.'); return; }

          var empty = notesList.querySelector('.ia-notes-empty');
          if (empty) empty.remove();

          var el = document.createElement('div');
          el.className = 'ia-note';
          el.setAttribute('data-note-id', resp.id);
          el.innerHTML =
            '<div class="ia-note-head">' +
              '<span class="ia-note-author">' + esc(resp.author) + '</span>' +
              '<span class="ia-note-time">' + esc(resp.created_at) + '</span>' +
              '<button type="button" class="ia-note-delete" data-note-id="' + resp.id + '" title="Delete">&#x2715;</button>' +
            '</div>' +
            '<div class="ia-note-body">' + esc(resp.note) + '</div>';
          notesList.insertBefore(el, notesList.firstChild);
          bindDeleteOnEl(el.querySelector('.ia-note-delete'));

          noteInput.value = '';
          if (noteChars) { noteChars.textContent = '500'; noteChars.classList.remove('warn'); }
          hideErr();
        })
        .catch(function () {
          noteSubmit.disabled = false;
          noteSubmit.textContent = 'Add note';
          showErr('Network error. Try again.');
        });
    });
  }

  document.querySelectorAll('.ia-note-delete').forEach(bindDeleteOnEl);

  function bindDeleteOnEl(btn) {
    if (!btn) return;
    btn.addEventListener('click', function () {
      if (!confirm('Delete this note?')) return;
      var noteId = btn.getAttribute('data-note-id');
      var fd = new FormData();
      fd.append('_token', csrf);
      fd.append('_method', 'PATCH');
      fd.append('op', 'delete_note');
      fd.append('note_id', noteId);
      fetch(updateUrl, { method: 'POST', body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json(); })
        .then(function (resp) {
          if (resp.ok) {
            var li = document.querySelector('[data-note-id="' + noteId + '"]');
            if (li) li.remove();
            if (!notesList.querySelector('.ia-note')) {
              var p = document.createElement('p');
              p.className = 'ia-notes-empty';
              p.style.cssText = 'font-size:13px;opacity:.4';
              p.textContent = 'No notes yet.';
              notesList.appendChild(p);
            }
          }
        });
    });
  }

  function showErr(msg) {
    if (noteError) { noteError.textContent = msg; noteError.style.display = ''; }
  }
  function hideErr() {
    if (noteError) noteError.style.display = 'none';
  }
  function esc(str) {
    return String(str)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;')
      .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }


  // ================================================================
  // Line-item editor — services and add-ons
  // ================================================================
  function postOp(payload) {
    var fd = new FormData();
    fd.append('_token', csrf);
    fd.append('_method', 'PATCH');
    Object.keys(payload).forEach(function (k) { fd.append(k, payload[k]); });
    return fetch(updateUrl, { method: 'POST', body: fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); });
  }

  // Add a new service or addon
  var addBtn = document.getElementById('add-line-btn');
  var addSel = document.getElementById('add-line-select');
  if (addBtn && addSel) {
    addBtn.addEventListener('click', function () {
      var val = addSel.value;
      if (!val) return;
      var parts = val.split(':');
      var kind = parts[0]; // 'service' or 'addon'
      var id   = parts[1];
      addBtn.disabled = true;
      addBtn.textContent = '…';
      var op = kind === 'addon' ? 'add_addon' : 'add_service';
      var payload = { op: op };
      payload[kind === 'addon' ? 'addon_id' : 'service_item_id'] = id;
      postOp(payload).then(function (res) {
        addBtn.disabled = false;
        addBtn.textContent = 'Add';
        if (res.ok && res.body && res.body.ok) {
          window.IntakeToast.success(kind === 'addon' ? 'Add-on added' : 'Service added');
          setTimeout(function () { window.location.reload(); }, 500);
        } else {
          window.IntakeToast.error((res.body && res.body.message) || 'Could not add.');
        }
      });
    });
  }

  // Remove a service or addon
  document.querySelectorAll('.line-remove').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var row  = btn.closest('.line-row');
      var kind = row.getAttribute('data-kind');
      var id   = row.getAttribute('data-item-id');

      window.IntakeConfirm.show({
        title:       'Remove this ' + (kind === 'addon' ? 'add-on' : 'service') + '?',
        message:     'The line will be removed and totals recalculated.',
        confirmText: 'Remove',
        danger:      true
      }).then(function (ok) {
        if (!ok) return;
        var op = kind === 'addon' ? 'remove_addon' : 'remove_service';
        var payload = { op: op };
        payload[kind === 'addon' ? 'addon_id' : 'item_id'] = id;
        postOp(payload).then(function (res) {
          if (res.ok && res.body && res.body.ok) {
            window.IntakeToast.success('Removed');
            setTimeout(function () { window.location.reload(); }, 500);
          } else {
            window.IntakeToast.error((res.body && res.body.message) || 'Could not remove.');
          }
        });
      });
    });
  });

  // Update price/duration override on blur
  document.querySelectorAll('.line-edit').forEach(function (input) {
    input.addEventListener('blur', function () {
      var row  = input.closest('.line-row');
      var kind = row.getAttribute('data-kind');
      var id   = row.getAttribute('data-item-id');
      var field = input.getAttribute('data-field');

      // Read both fields' current values so we send a complete update.
      var durInput = row.querySelector('.line-edit[data-field="duration_minutes"]');
      var priInput = row.querySelector('.line-edit[data-field="price_dollars"]');
      var duration = durInput ? parseInt(durInput.value, 10) : null;
      var dollars  = priInput ? parseFloat(priInput.value) : null;
      var cents    = (dollars === null || isNaN(dollars)) ? null : Math.round(dollars * 100);

      postOp({
        op: 'update_line_item',
        kind: kind,
        item_id: id,
        price_cents: cents === null ? '' : cents,
        duration_minutes: (duration === null || isNaN(duration)) ? '' : duration,
      }).then(function (res) {
        if (res.ok && res.body && res.body.ok) {
          window.IntakeToast.success('Updated');
        } else {
          window.IntakeToast.error((res.body && res.body.message) || 'Could not save.');
        }
      });
    });
  });

  // Status updates — used by progress bar steps, reopen button, and cancel button.
  function updateStatus(targetStatus, targetLabel, opts) {
    opts = opts || {};
    var fd = new FormData();
    fd.append('_token', csrf);
    fd.append('_method', 'PATCH');
    fd.append('op', 'status');
    fd.append('status', targetStatus);

    return fetch(updateUrl, { method: 'POST', body: fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
      .then(function (res) {
        if (res.ok && res.body && res.body.ok) {
          window.IntakeToast.success(targetLabel || 'Saved');
          // MARKER-PATCH-527 — completed + P&D: offer to text delivery windows
          if (res.body.propose_delivery && window.IntakeDeliveryPropose
              && IntakeDeliveryPropose.show(res.body.propose_delivery, { updateUrl: updateUrl, csrf: csrf })) {
            return true; // modal handles the reload
          }
          setTimeout(function () {
            if (opts.redirectToCalendar) {
              window.location.href = '{{ route("tenant.calendar.index") }}';
            } else {
              window.location.reload();
            }
          }, 600);
          return true;
        }
        var msg = (res.body && res.body.message) || 'Could not update status.';
        window.IntakeToast.error(msg);
        return false;
      })
      .catch(function () {
        window.IntakeToast.error('Network error. Try again.');
        return false;
      });
  }

  // Progress bar — click a step to move there.
  // Forward moves go silently. Backward moves trigger a confirm modal.
  var bar = document.querySelector('.appt-progress-bar');
  if (bar) {
    var currentIndex = parseInt(bar.getAttribute('data-current-index'), 10);
    // Set the green-fill width via CSS variable.
    bar.style.setProperty('--progress', currentIndex / 3);

    bar.querySelectorAll('.appt-progress-step').forEach(function (step) {
      step.addEventListener('click', function () {
        var stepIndex = parseInt(step.getAttribute('data-step-index'), 10);
        var status    = step.getAttribute('data-status');
        var label     = step.getAttribute('data-label');
        if (stepIndex === currentIndex) return;  // clicking current is a no-op

        var go = function () {
          step.classList.add('is-saving');
          updateStatus(status, label);
        };

        if (stepIndex < currentIndex) {
          window.IntakeConfirm.show({
            title:       'Move back to ' + label + '?',
            message:     'This appointment is currently further along. Going back may surprise the customer.',
            confirmText: 'Move back',
            cancelText:  'Keep where it is'
          }).then(function (ok) { if (ok) go(); });
        } else {
          go();
        }
      });
    });
  }

  // Reopen button on terminal cards (cancelled / refunded)
  var reopenBtn = document.querySelector('.appt-reopen-btn');
  if (reopenBtn) {
    reopenBtn.addEventListener('click', function () {
      window.IntakeConfirm.show({
        title:       'Reopen this appointment?',
        message:     'This will return it to Pending status.',
        confirmText: 'Reopen',
        cancelText:  'Keep closed'
      }).then(function (ok) {
        if (ok) updateStatus('pending', 'Reopened');
      });
    });
  }

  // Cancel button (sidebar)
  var cancelBtn = document.querySelector('.appt-cancel-btn');
  if (cancelBtn) {
    cancelBtn.addEventListener('click', function () {
      window.IntakeConfirm.show({
        title:       'Cancel this appointment?',
        message:     "The appointment will be removed from the calendar and the customer's slot released. This stays in your records but won't show on the active schedule.",
        confirmText: 'Cancel appointment',
        cancelText:  'Keep it',
        danger:      true
      }).then(function (ok) {
        if (ok) updateStatus('cancelled', 'Cancelled', { redirectToCalendar: true });
      });
    });
  }

  // Work order edit mode toggle — wo-edit-toggle-bound
  var woDisplay = document.getElementById('wo-display');
  var woForm = document.getElementById('wo-edit-form');
  var woToggle = document.getElementById('wo-edit-toggle');
  var woCancel = document.getElementById('wo-edit-cancel');
  if (woToggle && woForm && woDisplay) {
    woToggle.addEventListener('click', function() {
      woDisplay.style.display = 'none';
      woForm.style.display = 'block';
      woToggle.style.display = 'none';
    });
  }
  if (woCancel && woForm && woDisplay) {
    woCancel.addEventListener('click', function() {
      woForm.style.display = 'none';
      woDisplay.style.display = 'block';
      if (woToggle) woToggle.style.display = '';
    });
  }

  /* ===================================================================
     Products & Add-ons — picker, add, remove, quantity edit, custom item
     =================================================================== */
  var partPickerInput   = document.getElementById('part-picker-input');
  var partPickerResults = document.getElementById('part-picker-results');
  var partsBody         = document.getElementById('parts-body');

  function searchInventory(q) {
    var url = '{{ route("tenant.appointments.inventory-search", ["subdomain" => $currentTenant->subdomain]) }}?q=' + encodeURIComponent(q);
    return fetch(url, { headers: { 'Accept': 'application/json' } })
      .then(function(r) { return r.json(); });
  }

  // The custom-item pin row is always shown at the top of the picker so
  // users have an obvious escape hatch when no inventory match exists.
  var customItemPinHtml =
      '<div class="part-pick-row part-pick-custom" style="padding:9px 12px;cursor:pointer;border-bottom:0.5px solid var(--ia-border);background:var(--ia-surface-2)">'
    + '<div style="font-weight:500;font-size:13px;color:var(--ia-accent)">+ Custom item</div>'
    + '<div style="font-size:11px;opacity:.55;margin-top:2px">One-off item not in inventory</div>'
    + '</div>';

  function renderPickerResults(items) {
    var html = customItemPinHtml;
    if (!items.length) {
      html += '<div style="padding:10px;font-size:12px;opacity:.5">No matching products in inventory.</div>';
    } else {
      html += items.map(function(it) {
        var stockClass = it.stock <= 0 && !it.allow_oversell ? 'opacity:.4' : '';
        var stockNote  = it.stock <= 0 && !it.allow_oversell
          ? '<span style="color:#F09595">Out of stock</span>'
          : 'In stock: ' + it.stock;
        return '<div class="part-pick-row" data-id="' + it.id + '" style="padding:9px 12px;cursor:pointer;border-bottom:0.5px solid var(--ia-border);' + stockClass + '">'
             + '<div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px">'
             + '<div style="font-weight:500;font-size:13px">' + escapeHtml(it.name) + '</div>'
             + '<div style="font-size:12px;opacity:.7">' + it.price_display + '</div>'
             + '</div>'
             + '<div style="font-size:11px;opacity:.5;margin-top:2px;display:flex;justify-content:space-between">'
             + '<span>' + (it.sku ? escapeHtml(it.sku) : '') + '</span>'
             + '<span>' + stockNote + '</span>'
             + '</div>'
             + '</div>';
      }).join('');
    }
    partPickerResults.innerHTML = html;
    partPickerResults.style.display = 'block';
  }

  function escapeHtml(s) {
    if (s == null) return '';
    return String(s)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
  }

  if (partPickerInput) {
    var debounceTimer = null;
    partPickerInput.addEventListener('input', function() {
      var q = partPickerInput.value.trim();
      clearTimeout(debounceTimer);
      if (q.length < 1) {
        // Still show the pin so "+ Custom item" is reachable from an empty box.
        renderPickerResults([]);
        return;
      }
      debounceTimer = setTimeout(function() {
        searchInventory(q).then(function(d) {
          if (d.ok) renderPickerResults(d.items);
        });
      }, 180);
    });

    partPickerInput.addEventListener('focus', function() {
      // Always show on focus — the pin is reachable here even with no matches.
      var q = partPickerInput.value.trim();
      if (!q) {
        searchInventory('').then(function(d) {
          renderPickerResults(d.ok ? d.items : []);
        });
      }
    });

    document.addEventListener('click', function(e) {
      if (!partPickerInput.contains(e.target) && !partPickerResults.contains(e.target)) {
        partPickerResults.style.display = 'none';
      }
    });

    // Click a result → either open custom-item form or add the inventory part.
    partPickerResults.addEventListener('click', function(e) {
      var row = e.target.closest('.part-pick-row');
      if (!row) return;
      partPickerResults.style.display = 'none';
      if (row.classList.contains('part-pick-custom')) {
        openCustomItemForm();
        return;
      }
      var id = row.getAttribute('data-id');
      addPart(id);
    });
  }

  function addPart(inventoryItemId) {
    fetch(updateUrl, {
      method: 'PATCH',
      headers: { 'Content-Type':'application/json', 'X-CSRF-TOKEN': csrf, 'Accept':'application/json' },
      body: JSON.stringify({
        op: 'add_part',
        inventory_item_id: inventoryItemId,
        quantity: 1,
      }),
    }).then(function(r) {
      return r.json().then(function(d) { return { ok: r.ok, body: d }; });
    }).then(function(res) {
      if (!res.ok || !res.body.ok) {
        alert(res.body && res.body.message || 'Could not add item.');
        return;
      }
      // Reload — easiest way to get fresh totals + stock displays everywhere.
      window.location.reload();
    });
  }

  // Custom item form
  var customForm    = document.getElementById('custom-item-form');
  var customCancel  = document.getElementById('custom-item-cancel');
  var customAdd     = document.getElementById('custom-item-add');
  var customName    = document.getElementById('custom-item-name');
  var customPrice   = document.getElementById('custom-item-price');
  var customQty     = document.getElementById('custom-item-qty');
  var customTaxable = document.getElementById('custom-item-taxable');

  function openCustomItemForm() {
    if (!customForm) return;
    customForm.style.display = 'block';
    customName.value = '';
    customPrice.value = '';
    customQty.value = '1';
    if (customTaxable) customTaxable.checked = true;
    customName.focus();
  }
  function closeCustomItemForm() {
    if (!customForm) return;
    customForm.style.display = 'none';
  }

  if (customCancel) customCancel.addEventListener('click', closeCustomItemForm);

  if (customAdd) {
    customAdd.addEventListener('click', function() {
      var name  = (customName.value || '').trim();
      var price = parseFloat(customPrice.value || '0');
      var qty   = parseInt(customQty.value, 10) || 1;
      if (!name) { customName.focus(); return; }
      if (isNaN(price) || price < 0) { customPrice.focus(); return; }
      if (qty < 1) qty = 1;

      customAdd.disabled = true;
      customAdd.textContent = 'Adding…';
      fetch(updateUrl, {
        method: 'PATCH',
        headers: { 'Content-Type':'application/json', 'X-CSRF-TOKEN': csrf, 'Accept':'application/json' },
        body: JSON.stringify({
          op: 'add_custom_item',
          name: name,
          unit_price_cents: Math.round(price * 100),
          quantity: qty,
          is_taxable: customTaxable && customTaxable.checked ? 1 : 0,
        }),
      }).then(function(r) {
        return r.json().then(function(d) { return { ok: r.ok, body: d }; });
      }).then(function(res) {
        if (!res.ok || !res.body.ok) {
          alert(res.body && res.body.message || 'Could not add custom item.');
          customAdd.disabled = false;
          customAdd.textContent = 'Add';
          return;
        }
        window.location.reload();
      }).catch(function() {
        alert('Network error.');
        customAdd.disabled = false;
        customAdd.textContent = 'Add';
      });
    });

    // Enter in the name or price field submits.
    [customName, customPrice, customQty].forEach(function(inp) {
      if (!inp) return;
      inp.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); customAdd.click(); }
      });
    });
  }

  // Remove
  if (partsBody) {
    partsBody.addEventListener('click', function(e) {
      var btn = e.target.closest('.part-remove');
      if (!btn) return;
      var partId = btn.getAttribute('data-part-id');
      if (!confirm('Remove this item?')) return;
      fetch(updateUrl, {
        method: 'PATCH',
        headers: { 'Content-Type':'application/json', 'X-CSRF-TOKEN': csrf, 'Accept':'application/json' },
        body: JSON.stringify({ op: 'remove_part', part_id: partId }),
      }).then(function(r) {
        return r.json().then(function(d) { return { ok: r.ok, body: d }; });
      }).then(function(res) {
        if (!res.ok || !res.body.ok) {
          alert(res.body && res.body.message || 'Could not remove item.');
          return;
        }
        window.location.reload();
      });
    });

    // Quantity edit (debounced blur)
    partsBody.addEventListener('change', function(e) {
      var inp = e.target.closest('.part-qty-edit');
      if (!inp) return;
      var partId = inp.getAttribute('data-part-id');
      var qty    = parseInt(inp.value, 10);
      if (!qty || qty < 1) {
        inp.value = 1;
        qty = 1;
      }
      fetch(updateUrl, {
        method: 'PATCH',
        headers: { 'Content-Type':'application/json', 'X-CSRF-TOKEN': csrf, 'Accept':'application/json' },
        body: JSON.stringify({ op: 'update_part_quantity', part_id: partId, quantity: qty }),
      }).then(function(r) {
        return r.json().then(function(d) { return { ok: r.ok, body: d }; });
      }).then(function(res) {
        if (!res.ok || !res.body.ok) {
          alert(res.body && res.body.message || 'Could not update quantity.');
          window.location.reload();
          return;
        }
        // Update the line total cell inline for snappier feel.
        var row  = inp.closest('.part-row');
        var cell = row && row.querySelector('[data-line-total]');
        if (cell) cell.textContent = res.body.line_total_display;
      });
    });
  }

  /* ===================================================================
     Record deposit — opens form, sends to register, redirects.
     =================================================================== */
  var depositToggle = document.getElementById('record-deposit-toggle');
  var depositForm   = document.getElementById('record-deposit-form');
  var depositCancel = document.getElementById('record-deposit-cancel');
  var depositGo     = document.getElementById('record-deposit-go');
  var depositAmt    = document.getElementById('record-deposit-amount');

  if (depositToggle) {
    depositToggle.addEventListener('click', function () {
      depositToggle.style.display = 'none';
      depositForm.style.display = 'block';
      if (depositAmt) depositAmt.focus();
    });
  }
  if (depositCancel) {
    depositCancel.addEventListener('click', function () {
      depositForm.style.display = 'none';
      depositToggle.style.display = '';
      depositAmt.value = '';
    });
  }
  if (depositGo) {
    depositGo.addEventListener('click', function () {
      var dollars = parseFloat(depositAmt.value || '0');
      if (isNaN(dollars) || dollars <= 0) {
        depositAmt.focus();
        return;
      }
      depositGo.disabled = true;
      depositGo.textContent = 'Sending…';
      fetch(updateUrl, {
        method: 'PATCH',
        headers: { 'Content-Type':'application/json', 'X-CSRF-TOKEN': csrf, 'Accept':'application/json' },
        body: JSON.stringify({
          op: 'record_deposit',
          amount_cents: Math.round(dollars * 100),
        }),
      }).then(function (r) {
        return r.json().then(function (d) { return { ok: r.ok, body: d }; });
      }).then(function (res) {
        if (!res.ok || !res.body.ok) {
          alert(res.body && res.body.message || 'Could not create deposit sale.');
          depositGo.disabled = false;
          depositGo.textContent = 'Send to register';
          return;
        }
        // Redirect to the register draft
        window.location.href = res.body.redirect_url;
      }).catch(function () {
        alert('Network error.');
        depositGo.disabled = false;
        depositGo.textContent = 'Send to register';
      });
    });

    if (depositAmt) {
      depositAmt.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); depositGo.click(); }
      });
    }
  }

  /* ===================================================================
     Void register sale — staff clicked "Edit (voids draft)" on a locked
     section. Confirms, voids the open draft sale, reloads the page so
     the now-unlocked editing UI renders cleanly.
     =================================================================== */
  document.querySelectorAll('.void-sale-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (!confirm('Void the draft register sale to edit this appointment?\n\nThe sale will be cancelled. After your edits, completing the appointment again creates a fresh draft.')) {
        return;
      }
      btn.disabled = true;
      btn.textContent = 'Voiding…';
      fetch(updateUrl, {
        method: 'PATCH',
        headers: { 'Content-Type':'application/json', 'X-CSRF-TOKEN': csrf, 'Accept':'application/json' },
        body: JSON.stringify({ op: 'void_register_sale' }),
      }).then(function (r) {
        return r.json().then(function (d) { return { ok: r.ok, body: d }; });
      }).then(function (res) {
        if (!res.ok || !res.body.ok) {
          alert(res.body && res.body.message || 'Could not void sale.');
          btn.disabled = false;
          btn.textContent = '🔒 Edit (voids draft)';
          return;
        }
        window.location.reload();
      }).catch(function () {
        alert('Network error.');
        btn.disabled = false;
        btn.textContent = '🔒 Edit (voids draft)';
      });
    });
  });

}());
</script>

  <script src="{{ asset('js/tenant/appointment-resource.js') }}?v={{ filemtime(public_path('js/tenant/appointment-resource.js')) }}" defer></script>

  <script src="{{ asset('js/tenant/appointment-slot-weight.js') }}?v={{ filemtime(public_path('js/tenant/appointment-slot-weight.js')) }}" defer></script>

<script>
// LAYOUT-B-WIRING v1
// Rail "Cancel" button proxies to the original cancel handler.
// Rail "Reschedule" shows a coming-soon toast.
document.addEventListener('DOMContentLoaded', function () {
  var railCancel = document.querySelector('.appt-b-cancel-btn');
  if (railCancel) {
    railCancel.addEventListener('click', function () {
      var orig = document.querySelector('.appt-cancel-btn-original');
      if (orig) orig.click();
    });
  }

  var railResch = document.querySelector('.appt-b-reschedule-btn');
  if (railResch) {
    railResch.addEventListener('click', function () {
      if (window.IntakeToast) {
        window.IntakeToast.info('Reschedule from this page ships tomorrow. For now, drag the appointment block on the calendar to move it.');
      }
    });
  }
});
</script>

<script>
// LAYOUT-B-CUST-MODAL-JS v1
document.addEventListener('DOMContentLoaded', function () {
  var modal     = document.getElementById('appt-b-cust-modal');
  var openBtn   = document.querySelector('.appt-b-cust-details-btn');
  if (!modal || !openBtn) return;

  function open()  { modal.hidden = false;  document.body.style.overflow = 'hidden'; }
  function close() { modal.hidden = true;   document.body.style.overflow = ''; }

  openBtn.addEventListener('click', open);
  modal.querySelectorAll('[data-cust-modal-close]').forEach(function (el) {
    el.addEventListener('click', close);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !modal.hidden) close();
  });
});
</script>

<script>
// RESCHEDULE-MODAL-JS v1
(function () {
  var modal = document.getElementById('resch-modal');
  if (!modal) return;  // terminal-state appointments don't render the modal

  var openBtn  = document.querySelector('.appt-b-reschedule-btn');
  if (!openBtn) return;

  // Strip the old "ships tomorrow" toast handler by cloning the button.
  // (clone+replace removes all event listeners.)
  var newOpenBtn = openBtn.cloneNode(true);
  openBtn.parentNode.replaceChild(newOpenBtn, openBtn);
  openBtn = newOpenBtn;

  var resourceSel = document.getElementById('resch-resource');
  var showBtn     = document.getElementById('resch-show-times');
  var prevWeekBtn = document.getElementById('resch-prev-week');
  var nextWeekBtn = document.getElementById('resch-next-week');
  var weekLabel   = document.getElementById('resch-week-label');
  var listEl      = document.getElementById('resch-times-list');
  var toBlock     = document.getElementById('resch-to');
  var toWhenEl    = document.getElementById('resch-to-when');
  var toResEl     = document.getElementById('resch-to-resource');
  var submitBtn   = document.getElementById('resch-submit');

  var weekTimesUrl = "{{ route('tenant.appointments.week-times') }}";

  var state = {
    weekStartDate: null,
    selectedSlot:  null,   // {date, time, time_label, date_label}
    slots:         [],
  };

  function todayStr() {
    var d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
  }

  function open() {
    modal.hidden = false;
    document.body.style.overflow = 'hidden';
    state.selectedSlot = null;
    state.weekStartDate = todayStr();
    weekLabel.textContent = formatWeekLabel(state.weekStartDate);
    listEl.innerHTML = '<div class="resch-times-empty">Click Show available times to see open slots.</div>';
    toBlock.hidden = true;
    submitBtn.disabled = true;
  }

  function close() {
    modal.hidden = true;
    document.body.style.overflow = '';
  }

  function fetchTimes() {
    var firstSvc = submitBtn.getAttribute('data-first-service-id');
    if (!firstSvc) {
      listEl.innerHTML = '<div class="resch-times-empty error">This appointment has no service item; cannot look up available times.</div>';
      return;
    }
    var resourceId = resourceSel.value;
    if (!resourceId) {
      listEl.innerHTML = '<div class="resch-times-empty error">Pick a resource first.</div>';
      return;
    }
    listEl.innerHTML = '<div class="resch-times-empty">Loading…</div>';
    weekLabel.textContent = formatWeekLabel(state.weekStartDate);
    prevWeekBtn.disabled = (state.weekStartDate <= todayStr());

    var url = weekTimesUrl
      + '?service_id='  + encodeURIComponent(firstSvc)
      + '&resource_id=' + encodeURIComponent(resourceId)
      + '&start_date='  + encodeURIComponent(state.weekStartDate);

    fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        state.slots = data.slots || [];
        renderTimes();
      })
      .catch(function () {
        listEl.innerHTML = '<div class="resch-times-empty error">Could not load available times.</div>';
      });
  }

  function renderTimes() {
    if (!state.slots.length) {
      listEl.innerHTML = '<div class="resch-times-empty">No available times this week. Try Next week →</div>';
      return;
    }
    var html = '';
    state.slots.forEach(function (slot, idx) {
      var isSel = state.selectedSlot
        && state.selectedSlot.date === slot.date
        && state.selectedSlot.time === slot.time;
      html += '<div class="resch-time-row' + (isSel ? ' selected' : '') + '" data-idx="' + idx + '">'
        +   '<span class="resch-time-date">' + escapeHtml(slot.date_label) + '</span>'
        +   '<span class="resch-time-time">' + escapeHtml(slot.time_label) + '</span>'
        + '</div>';
    });
    listEl.innerHTML = html;
    listEl.querySelectorAll('.resch-time-row').forEach(function (row) {
      row.addEventListener('click', function () {
        var slot = state.slots[parseInt(row.getAttribute('data-idx'), 10)];
        state.selectedSlot = slot;
        renderTimes();
        updateToPreview();
      });
    });
  }

  function updateToPreview() {
    if (!state.selectedSlot) {
      toBlock.hidden = true;
      submitBtn.disabled = true;
      return;
    }
    var resOpt = resourceSel.options[resourceSel.selectedIndex];
    var resColor = resOpt ? resOpt.getAttribute('data-color') : '#888';
    var resName  = resOpt ? resOpt.textContent.trim() : '';
    toWhenEl.textContent = state.selectedSlot.date_label + ' · ' + state.selectedSlot.time_label;
    toResEl.innerHTML = '<span class="resch-swatch" style="background:' + escapeHtml(resColor) + '"></span>' + escapeHtml(resName);
    toBlock.hidden = false;
    submitBtn.disabled = false;
  }

  function formatWeekLabel(startDate) {
    if (!startDate) return '—';
    var s = new Date(startDate + 'T00:00:00');
    var e = new Date(s); e.setDate(e.getDate() + 6);
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return months[s.getMonth()] + ' ' + s.getDate() + ' – ' + months[e.getMonth()] + ' ' + e.getDate();
  }

  function shiftWeek(days) {
    if (!state.weekStartDate) state.weekStartDate = todayStr();
    var d = new Date(state.weekStartDate + 'T00:00:00');
    d.setDate(d.getDate() + days);
    var ymd = d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
    if (ymd < todayStr()) ymd = todayStr();
    state.weekStartDate = ymd;
    fetchTimes();
  }

  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
    });
  }

  function submit() {
    if (!state.selectedSlot) return;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Rescheduling…';

    var url = submitBtn.getAttribute('data-update-url');
    var token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    fetch(url, {
      method: 'PATCH',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': token,
      },
      credentials: 'same-origin',
      body: JSON.stringify({
        op: 'reschedule',
        appointment_date: state.selectedSlot.date,
        appointment_time: state.selectedSlot.time,
        resource_id:      resourceSel.value,
      }),
    }).then(function (r) {
      return r.json().then(function (data) { return { status: r.status, data: data }; });
    }).then(function (resp) {
      if (resp.status === 200 && resp.data.ok) {
        if (window.IntakeToast) window.IntakeToast.success('Appointment rescheduled.');
        // Reload to reflect the new state across rail + main + system note.
        setTimeout(function () { window.location.reload(); }, 600);
      } else if (resp.status === 409) {
        // Slot taken — refresh times.
        if (window.IntakeToast) window.IntakeToast.error(resp.data.message || 'That time was just taken.');
        state.selectedSlot = null;
        toBlock.hidden = true;
        submitBtn.disabled = true;
        submitBtn.textContent = 'Reschedule';
        fetchTimes();
      } else {
        if (window.IntakeToast) window.IntakeToast.error(resp.data.message || 'Reschedule failed.');
        submitBtn.disabled = false;
        submitBtn.textContent = 'Reschedule';
      }
    }).catch(function () {
      if (window.IntakeToast) window.IntakeToast.error('Network error. Try again.');
      submitBtn.disabled = false;
      submitBtn.textContent = 'Reschedule';
    });
  }

  // Wire up.
  openBtn.addEventListener('click', open);
  modal.querySelectorAll('[data-resch-close]').forEach(function (el) {
    el.addEventListener('click', close);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !modal.hidden) close();
  });
  resourceSel.addEventListener('change', function () {
    state.selectedSlot = null;
    toBlock.hidden = true;
    submitBtn.disabled = true;
    if (state.slots.length) fetchTimes();
  });
  showBtn.addEventListener('click', fetchTimes);
  prevWeekBtn.addEventListener('click', function () { shiftWeek(-7); });
  nextWeekBtn.addEventListener('click', function () { shiftWeek(7); });
  submitBtn.addEventListener('click', submit);
})();
</script>
@endpush
SOAC_1_EOF

echo "special-orders-appt-cleanup applied — server: git pull && php artisan view:clear"
