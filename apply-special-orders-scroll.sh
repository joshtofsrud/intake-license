#!/bin/bash
# special-orders-scroll — the open tab IS the grouped screen, and it scrolls.
#   · The "Group by vendor" toggle is GONE. Open orders are always grouped by
#     vendor, because that is how they get placed. The other tabs (bench,
#     overdue, pulled, cancelled) stay flat and paginated — vendor grouping
#     means nothing once an order has been placed.
#   · One scroll region instead of pages. The footer sentence — "13 open ·
#     $1,847 across 3 vendors · 2 still need a vendor" — describes the WHOLE
#     list, which is only ever true without pagination. Capped at 500 with
#     the cap surfaced in the footer, so a pathological backlog cannot render
#     forever.
#   · Vendor headers STICK while scrolling and carry the batch action:
#     selected count, group total, PO field, expected date, Mark ordered. You
#     never scroll back up to commit, and the PO is typed once per vendor.
#   · "No vendor yet" is pinned first and states that those cannot be ordered
#     until a vendor is chosen.
#   · Rows keep origin badge, age, the rule that auto-assigned the vendor, and
#     inline Still needed / Cancel for orphans.
#   · On phones the sticky headers and height cap are dropped (the whole page
#     scrolls instead), and the vendor picker goes full width.
# No routes, no migration. Server: view:clear.
set -e
cd "$(git rev-parse --show-toplevel)"
if grep -q "MARKER-SO-SCROLL" app/Http/Controllers/Tenant/SpecialOrderController.php; then
  echo "special-orders-scroll already applied — aborting."; exit 1
fi
if ! grep -q "MARKER-SO-ONESCREEN" app/Http/Controllers/Tenant/SpecialOrderController.php; then
  echo "special-orders-one-screen not applied — wrong base, aborting."; exit 1
fi

cat > 'app/Http/Controllers/Tenant/SpecialOrderController.php' <<'SOSCR_0_EOF'
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
SOSCR_0_EOF

cat > 'resources/views/tenant/special-orders/index.blade.php' <<'SOSCR_1_EOF'
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

{{-- MARKER-SO-ONESCREEN — grouped mode replaces both renderers, so the same
     orders are never shown twice, and it works on phones as well as desktop. --}}
@if($grouped) {{-- MARKER-SO-SCROLL — open orders ARE the grouped screen --}}
  @include('tenant.special-orders._vendor_groups')
@else

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
                  @if($so->vendor_assigned_rule && $so->vendor_assigned_rule !== 'manual')
                    · auto: {{ str_replace('_', ' ', $so->vendor_assigned_rule) }}
                  @endif
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
        {{-- MARKER-SO-ORIGIN-MOBILE — the desktop table showed origin and its
             actions; the cards did not, which is where triage actually
             happens. Same data, same actions, thumb-sized. --}}
        @php $og = $origins[$so->id] ?? null; @endphp
        @if($og)
          <div class="so-card-meta" style="margin-top:6px;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
            <span class="so-origin so-origin--{{ $og['state'] }}">{{ $og['label'] }}</span>
            @if($so->created_at)<span class="ia-text-muted" style="font-size:11px">{{ (int) $so->created_at->diffInDays(now()) }}d old</span>@endif
            @if($so->vendor_assigned_rule && $so->vendor_assigned_rule !== 'manual')
              <span class="ia-text-muted" style="font-size:11px">auto: {{ str_replace('_', ' ', $so->vendor_assigned_rule) }}</span>
            @endif
          </div>
          @if(in_array($og['state'], ['orphan', 'unknown'], true) && $so->status === \App\Models\Tenant\TenantSpecialOrder::STATUS_NEEDED)
            <div class="so-origin-acts" data-so="{{ $so->id }}" onclick="event.preventDefault();event.stopPropagation()">
              <button type="button" class="so-oa" data-so-keep>Still needed</button>
              <button type="button" class="so-oa danger" data-so-drop>Cancel</button>
            </div>
          @endif
        @endif
      </a>
    @endforeach
  </div>
@endif{{-- MARKER-SO-ONESCREEN --}}

  @if($totalPages > 1 && !$grouped) {{-- MARKER-SO-SCROLL — the open view scrolls instead of paging --}}
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
SOSCR_1_EOF

cat > 'resources/views/tenant/special-orders/_vendor_groups.blade.php' <<'SOSCR_2_EOF'
{{-- MARKER-SO-SCROLL — the open special-orders screen: always grouped by
     vendor (that is how orders get placed), one scroll region instead of
     pages, and the batch action riding in a sticky group header so it stays
     in reach while scrolling a long vendor. --}}

<style>
  .sog-scroll{max-height:66vh;overflow-y:auto;border:0.5px solid var(--ia-border);border-radius:var(--ia-r-lg);background:var(--ia-surface)}
  .sog-scroll::-webkit-scrollbar{width:9px}
  .sog-scroll::-webkit-scrollbar-thumb{background:rgba(255,255,255,.14);border-radius:100px}

  .sog-head{position:sticky;top:0;z-index:3;background:var(--ia-surface-2,#171717);border-bottom:0.5px solid var(--ia-border);padding:11px 14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
  .sog-head.none{background:rgba(240,149,149,.07)}
  .sog-name{font-weight:800;font-size:13.5px}
  .sog-name .warn{color:#F09595}
  .sog-count{font-size:11.5px;color:var(--ia-text-muted)}
  .sog-act{margin-left:auto;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
  .sog-tot{font-size:12.5px;font-weight:700;font-variant-numeric:tabular-nums}
  .sog-in{background:rgba(0,0,0,.22);border:0.5px solid var(--ia-border);border-radius:8px;color:var(--ia-text);font-family:inherit;font-size:11.5px;padding:6px 8px}

  .sog-freight{display:flex;align-items:center;gap:9px;padding:7px 14px;background:rgba(0,0,0,.16);border-bottom:0.5px solid rgba(255,255,255,.05);font-size:11px}
  .sog-bar{flex:1;min-width:80px;height:4px;border-radius:100px;background:rgba(255,255,255,.09);overflow:hidden}
  .sog-bar span{display:block;height:100%;background:var(--ia-accent)}
  .sog-bar.met span{background:#7FD98F}
  .sog-fnote{color:var(--ia-text-muted)}
  .sog-fnote b{color:var(--ia-accent)}
  .sog-fnote.met b{color:#7FD98F}

  .sog-row{display:flex;align-items:center;gap:11px;padding:11px 14px;border-bottom:0.5px solid rgba(255,255,255,.05);flex-wrap:wrap}
  .sog-row:hover{background:rgba(255,255,255,.02)}
  .sog-cb{width:16px;height:16px;border-radius:4px;border:1.5px solid rgba(255,255,255,.25);flex:none;display:inline-flex;align-items:center;justify-content:center;font-size:10px;color:#0B0B0B;font-weight:900;cursor:pointer}
  .sog-cb.on{background:var(--ia-accent);border-color:var(--ia-accent)}
  .sog-cb.on:after{content:"\2713"}
  .sog-ident{flex:1;min-width:165px}
  .sog-nm{font-weight:600;font-size:13px}
  .sog-mt{font-size:11.5px;color:var(--ia-text-muted);margin-top:3px;display:flex;gap:7px;flex-wrap:wrap;align-items:center}
  .sog-sel{background:rgba(0,0,0,.22);border:0.5px solid var(--ia-border);border-radius:8px;color:var(--ia-text);font-family:inherit;font-size:12px;padding:6px 8px;min-width:200px}
  .sog-sel:focus{outline:none;border-color:var(--ia-accent)}
  .sog-assign{font-family:inherit;font-size:11px;font-weight:700;border-radius:8px;padding:6px 10px;cursor:pointer;border:0.5px solid var(--ia-border);background:transparent;color:var(--ia-text-muted)}
  .sog-assign:hover{border-color:var(--ia-accent);color:var(--ia-accent)}
  .sog-noopt{font-size:11px;color:#F09595}
  .sog-foot{display:flex;align-items:center;gap:10px;padding:11px 3px;font-size:11.5px;color:var(--ia-text-muted);flex-wrap:wrap}
  .sog-empty{padding:34px;text-align:center;color:var(--ia-text-muted);font-size:13px}
  @media(max-width:640px){
    .sog-scroll{max-height:none}
    .sog-head{position:static}
    .sog-act{width:100%;margin-left:0}
    .sog-sel{min-width:0;flex:1}
  }
</style>

@php
  // Footer totals describe the WHOLE list — the sentence is only ever true
  // without pagination, which is half the reason this scrolls.
  $sogAllTotal = 0; $sogNoVendor = 0; $sogVendorsUsed = [];
  foreach ($vgroups as $vid => $rows) {
    foreach ($rows as $r) {
      $opt  = collect($voptions[$r->inventory_item_id] ?? [])->firstWhere('vendor_id', $vid);
      $unit = $opt['cost'] ?? $r->unit_cost_cents_estimated ?? 0;
      if ($vid === '') { $sogNoVendor++; }
      else { $sogAllTotal += (int) $unit * (int) $r->quantity; $sogVendorsUsed[$vid] = true; }
    }
  }
@endphp

<div class="sog-scroll" id="sog">
  @forelse($vgroups as $vendorId => $rows)
    @php
      $vendor = $vendorId !== '' ? ($vvendors[$vendorId] ?? null) : null;
      $groupTotal = 0;
      foreach ($rows as $r) {
        $opt  = collect($voptions[$r->inventory_item_id] ?? [])->firstWhere('vendor_id', $vendorId);
        $unit = $opt['cost'] ?? $r->unit_cost_cents_estimated ?? 0;
        $groupTotal += (int) $unit * (int) $r->quantity;
      }
      $min = $vendor->free_freight_cents ?? null;
    @endphp

    <div class="sog-head {{ $vendorId === '' ? 'none' : '' }}" data-vendor="{{ $vendorId }}">
      <span class="sog-name">
        @if($vendorId === '')<span class="warn">No vendor yet</span>@else{{ $vendor->name ?? 'Vendor' }}@endif
      </span>
      <span class="sog-count" data-sog-count>
        {{ count($rows) }} {{ \Illuminate\Support\Str::plural('item', count($rows)) }}@if($vendorId === '') — choose a vendor before ordering @endif
      </span>
      @if($vendorId !== '')
        <span class="sog-act">
          <span class="sog-tot">${{ number_format($groupTotal / 100, 2) }}</span>
          <input type="text" class="sog-in" placeholder="PO #" data-sog-po style="width:92px">
          <input type="date" class="sog-in" data-sog-eta value="{{ now()->addDays(7)->toDateString() }}">
          <button type="button" class="ia-btn ia-btn--primary" style="padding:6px 12px;font-size:11.5px" data-sog-order>
            Mark ordered
          </button>
        </span>
      @endif
    </div>

    @if($vendorId !== '' && $min)
      @php $pct = min(100, (int) round($groupTotal / max(1, $min) * 100)); $met = $groupTotal >= $min; @endphp
      <div class="sog-freight">
        <span class="sog-bar {{ $met ? 'met' : '' }}"><span style="width:{{ $pct }}%"></span></span>
        <span class="sog-fnote {{ $met ? 'met' : '' }}">
          @if($met)
            <b>free freight met</b> · ${{ number_format($groupTotal / 100, 2) }}
          @else
            <b>${{ number_format(($min - $groupTotal) / 100, 2) }}</b> more for free freight
          @endif
        </span>
      </div>
    @endif

    @foreach($rows as $so)
      @php
        $opts = $voptions[$so->inventory_item_id] ?? [];
        $og   = $origins[$so->id] ?? null;
      @endphp
      <div class="sog-row" data-so="{{ $so->id }}">
        @if($vendorId !== '')
          <span class="sog-cb on" data-sog-cb></span>
        @else
          <span class="sog-cb" style="visibility:hidden"></span>
        @endif

        <div class="sog-ident">
          <div class="sog-nm">{{ $so->item_name_snapshot }}</div>
          <div class="sog-mt">
            <span>{{ $so->so_number }} · qty {{ $so->quantity }} ·
              {{ $so->customer ? trim($so->customer->first_name . ' ' . $so->customer->last_name) : 'stock' }}</span>
            @if($og)
              <span class="so-origin so-origin--{{ $og['state'] }}">{{ $og['label'] }}</span>
            @endif
            <span style="opacity:.6">{{ (int) $so->created_at->diffInDays(now()) }}d old</span>
            @if($so->vendor_assigned_rule && $so->vendor_assigned_rule !== 'manual')
              <span style="opacity:.6">auto: {{ str_replace('_', ' ', $so->vendor_assigned_rule) }}</span>
            @endif
            @if($og && in_array($og['state'], ['orphan', 'unknown'], true))
              <span class="so-origin-acts" data-so="{{ $so->id }}">
                <button type="button" class="so-oa" data-so-keep>Still needed</button>
                <button type="button" class="so-oa danger" data-so-drop>Cancel</button>
              </span>
            @endif
          </div>
        </div>

        @if(empty($opts))
          <span class="sog-noopt">No vendor carries this yet — add one on the item</span>
        @else
          <select class="sog-sel" data-sog-select>
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
          <button type="button" class="sog-assign" data-sog-assign>{{ $vendorId === '' ? 'Assign' : 'Move' }}</button>
        @endif
      </div>
    @endforeach
  @empty
    <div class="sog-empty">Nothing waiting to be placed.</div>
  @endforelse
</div>

<div class="sog-foot">
  <span>
    {{ $total }} open · ${{ number_format($sogAllTotal / 100, 2) }} across
    {{ count($sogVendorsUsed) }} {{ \Illuminate\Support\Str::plural('vendor', count($sogVendorsUsed)) }}
    @if($sogNoVendor) · <span style="color:#F09595">{{ $sogNoVendor }} still need a vendor</span> @endif
  </span>
  @if($total > $scrollCap)
    <span style="color:#F5C56B">showing the first {{ $scrollCap }} — clear some to see the rest</span>
  @endif
  <span style="margin-left:auto">
    @if($vcheckedAt) live cost/stock checked {{ $vcheckedAt->diffForHumans() }} @else costs from your catalog; live stock not checked yet @endif
  </span>
</div>

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

  // Rows between one sticky header and the next belong to that group.
  function rowsFor(head) {
    var out = [], n = head.nextElementSibling;
    while (n && !n.classList.contains('sog-head')) {
      if (n.classList.contains('sog-row')) out.push(n);
      n = n.nextElementSibling;
    }
    return out;
  }

  function headFor(row) {
    var h = row.previousElementSibling;
    while (h && !h.classList.contains('sog-head')) h = h.previousElementSibling;
    return h;
  }

  function refresh(head) {
    if (!head) return;
    var rows = rowsFor(head);
    var sel  = rows.filter(function (r) {
      var c = r.querySelector('[data-sog-cb]');
      return c && c.classList.contains('on');
    });
    var c = head.querySelector('[data-sog-count]');
    if (c) c.textContent = rows.length + ' items · ' + sel.length + ' selected';
    var btn = head.querySelector('[data-sog-order]');
    if (btn) btn.disabled = sel.length === 0;
  }

  document.addEventListener('click', function (e) {
    var cb = e.target.closest('[data-sog-cb]');
    if (cb) {
      cb.classList.toggle('on');
      refresh(headFor(cb.closest('.sog-row')));
      return;
    }

    var as = e.target.closest('[data-sog-assign]');
    if (as) {
      var row = as.closest('.sog-row');
      as.disabled = true;
      post(assignUrl.replace('__ID__', row.dataset.so), { vendor_id: row.querySelector('[data-sog-select]').value })
        .then(function (j) {
          if (j && j.ok) { window.location.reload(); }
          else { as.disabled = false; if (window.IntakeToast) IntakeToast.error((j && j.error) || 'Could not assign.'); }
        })
        .catch(function () { as.disabled = false; if (window.IntakeToast) IntakeToast.error('Network error.'); });
      return;
    }

    var ob = e.target.closest('[data-sog-order]');
    if (ob) {
      var head = ob.closest('.sog-head');
      var ids = rowsFor(head)
        .filter(function (r) { var c = r.querySelector('[data-sog-cb]'); return c && c.classList.contains('on'); })
        .map(function (r) { return r.dataset.so; });
      if (!ids.length) { if (window.IntakeToast) IntakeToast.error('Nothing selected.'); return; }

      var po  = head.querySelector('[data-sog-po]').value.trim();
      var eta = head.querySelector('[data-sog-eta]').value;
      if (!po)  { if (window.IntakeToast) IntakeToast.error('PO number is required.'); return; }
      if (!eta) { if (window.IntakeToast) IntakeToast.error('Expected date is required.'); return; }

      ob.disabled = true;
      post(batchUrl, { ids: ids, vendor_id: head.dataset.vendor, po_number: po, expected_arrival_date: eta })
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

  // Initial state for each group
  document.querySelectorAll('.sog-head').forEach(refresh);
})();
</script>
SOSCR_2_EOF

echo "special-orders-scroll applied — server: git pull && php artisan view:clear"
