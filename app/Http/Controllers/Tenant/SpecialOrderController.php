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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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
    /** how long orphans are kept before the sweep. */
    public function saveCleanup(Request $request)
    {
        $tenant = tenant();
        $v = $request->validate(['days' => 'required|integer|min:0|max:365']);

        \App\Support\SpecialOrderCleanup::setDays($tenant, (int) $v['days']);

        return back()->with('flash', ['type' => 'success', 'message' =>
            \App\Support\SpecialOrderCleanup::describe($tenant) . ' — orphaned special orders.']);
    }

    /**
     * clear them now, rather than waiting for the sweep.
     *
     * CANCELS, does not delete. One that reached a vendor may have money
     * against it, and its history is the only record of that. Cancelled is
     * reversible and auditable.
     */
    public function clearOrphans(Request $request)
    {
        $tenant = tenant();
        $ids = (array) $request->input('ids', []);

        if (! $ids) {
            return back();
        }

        $n = \App\Models\Tenant\TenantSpecialOrder::where('tenant_id', $tenant->id)
            ->whereIn('id', $ids)
            ->whereIn('status', \App\Models\Tenant\TenantSpecialOrder::STATUSES_OPEN)
            ->update([
                'status'     => \App\Models\Tenant\TenantSpecialOrder::STATUS_CANCELLED,
                'updated_at' => now(),
            ]);

        return back()->with('flash', ['type' => 'success', 'message' =>
            $n . ' orphaned special order' . ($n === 1 ? '' : 's') . ' cancelled.']);
    }

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
            ->with(['vendor', 'customer', 'appointment', 'item', 'location', 'poLine.purchaseOrder']);

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

        // the open view is one scrollable list, not pages:
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

        // grouping is not a mode. Open orders are grouped by
        // vendor because that is how they get placed; the other tabs stay flat,
        // since vendor grouping means nothing once an order has been placed.
        $vendorData = ['groups' => [], 'vendors' => collect(), 'options' => [], 'checkedAt' => null];
        if ($grouped) {
            $needed = $sos->where('status', TenantSpecialOrder::STATUS_NEEDED);
            $vendorData = $this->vendorGroups($tenant, $needed);
        }

        // for each listed order: where did it come from,
        // and does that source still exist? Two lookups, no N+1.
        $origins = [];
        if ($sos->isNotEmpty()) {
            $saleIds = $sos->pluck('sale_id')->filter()->unique();
            // a draft has no sale_number until it is paid, and
            // isset() on a null value is false, so a HELD sale read as deleted
            // and its order sat under No longer needed waiting for the sweep to
            // cancel it. Key by id, label by number, and test presence with
            // has(), never isset().
            $liveSales = $saleIds->isEmpty() ? collect() : \App\Models\Tenant\TenantSale::where('tenant_id', $tenant->id)
                ->whereIn('id', $saleIds)
                ->get(['id', 'sale_number', 'payment_status'])
                ->mapWithKeys(fn ($sale) => [$sale->id => $sale->sale_number
                    ? ('Sale ' . $sale->sale_number)
                    : ($sale->payment_status === 'draft' ? 'Held sale' : 'Sale (unnumbered)')]);

            // a live sale does not mean the request is
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

            // same trap as a live sale with a dead line:
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

            // collected as we resolve origins, so the
            // board can show them apart from work that still needs a decision.
            $orphanIds = [];

            foreach ($sos as $so) {
                if ($so->appointment_id) {
                    if (! isset($liveAppts[$so->appointment_id])) {
                        $origins[$so->id] = ['state' => 'orphan', 'label' => 'Work order deleted'];
                    } elseif (! isset($partAlive[$so->id])) {
                        $origins[$so->id] = ['state' => 'orphan', 'label' => 'Part removed from work order'];
                    } else {
                        $origins[$so->id] = ['state' => 'live', 'label' => $liveAppts[$so->appointment_id]];
                    }
                } elseif ($so->sale_id) {
                    if (! $liveSales->has($so->sale_id)) { // has(), not isset()
                        $origins[$so->id] = ['state' => 'orphan', 'label' => 'Sale removed'];
                    } elseif ($so->inventory_item_id && empty($hasLine[$so->sale_id][$so->inventory_item_id])) {
                        $origins[$so->id] = ['state' => 'orphan', 'label' => 'Line removed from sale'];
                    } else {
                        $origins[$so->id] = ['state' => 'live', 'label' => $liveSales[$so->sale_id]];
                    }
                }

                if (($origins[$so->id]['state'] ?? null) === 'orphan') {
                    $orphanIds[] = $so->id;
                }

                // this used to run for EVERY order and overwrite
                // the live/orphan result above, so a register order linked to a
                // perfectly good sale still wore "Origin not recorded". Only an
                // order with no sale and no appointment has nothing to say.
                if (! $so->sale_id && ! $so->appointment_id) {
                    if ($so->created_from === 'register') {
                        // Created before sale linking existed — the link was never
                        // recorded, so it cannot be reconstructed. Say so plainly.
                        $origins[$so->id] = ['state' => 'unknown', 'label' => 'Origin not recorded'];
                    } else {
                        $origins[$so->id] = ['state' => 'manual', 'label' => ucfirst((string) $so->created_from)];
                    }
                }

                if ($so->source_confirmed_at && $origins[$so->id]['state'] !== 'live') {
                    $origins[$so->id] = ['state' => 'confirmed', 'label' => 'Confirmed still needed'];
                }
            }
        }

        // MARKER-SO-HANDOVER: the Arrived bench shows each order's hold and
        // how it gets to its person.
        $bench = [];
        if ($view === 'arrived_bench') {
            foreach ($sos as $so) {
                $bench[$so->id] = [
                    'hold' => SpecialOrderService::holdState($so),
                    'ctx'  => SpecialOrderService::handoverContext($so),
                ];
            }
        }

        return view('tenant.special-orders.index', [
            'bench'         => $bench,
            'orphanIds'     => $orphanIds ?? [],
            'cleanupDays'   => \App\Support\SpecialOrderCleanup::days($tenant),
            'cleanupLabel'  => \App\Support\SpecialOrderCleanup::describe($tenant),
            'origins'    => $origins,
            'grouped'    => $grouped,
            'scrollCap'  => $scrollCap,
            'vgroups'    => $vendorData['groups'],
            'vvendors'   => $vendorData['vendors'],
            'voptions'   => $vendorData['options'],
            'vcheckedAt' => $vendorData['checkedAt'],
            // MARKER-PO: each vendor's open drafts, so "Add to PO" can say where they go
            'vdrafts'    => \App\Models\Tenant\TenantPurchaseOrder::where('tenant_id', $tenant->id)->where('status', 'draft')->orderByDesc('created_at')
                ->get(['id', 'po_number', 'vendor_id', 'location_id'])->groupBy('vendor_id')
                ->map(fn ($g) => $g->map(fn ($d) => ['id' => $d->id, 'po' => $d->po_number, 'l' => $d->location_id])->values()->all())->all(),
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
            ->with(['vendor', 'customer', 'appointment', 'item', 'notes.user', 'parent', 'children', 'location', 'poLine.purchaseOrder'])
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

        // MARKER-SO-REPAIRS: only vendors that carry this item can be picked.
        // A not-yet-cataloged order has no source rows, so any active vendor.
        $vendorOptions = $so->inventory_item_id
            ? self::vendorOptionsForItem($tenant->id, $so->inventory_item_id)
            : TenantVendor::where('tenant_id', $tenant->id)->where('is_active', true)->orderBy('name')
                ->get(['id', 'name'])->map(fn ($v) => ['id' => $v->id, 'name' => $v->name, 'sku' => null, 'cost_cents' => null, 'avail' => null, 'preferred' => false, 'lead' => null])->all();

        return view('tenant.special-orders.show', [
            // MARKER-SO-HANDOVER
            'hold'          => SpecialOrderService::holdState($so),
            'ctx'           => SpecialOrderService::handoverContext($so),
            'handedOverBy'  => $so->handed_over_by_user_id
                ? \App\Models\Tenant\TenantUser::where('id', $so->handed_over_by_user_id)->value('name') : null,
            'so'            => $so,
            'batchSiblings' => $batchSiblings,
            'vendorOptions' => $vendorOptions,
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
        $tid = $tenant->id;

        // MARKER-SO-REPAIRS: every id is checked against this shop. These
        // were plain strings, so another shop's customer, job, vendor or item
        // could be attached to an order here.
        $own = fn (string $table) => Rule::exists($table, 'id')->where(fn ($q) => $q->where('tenant_id', $tid));

        $data = $request->validate([
            'inventory_item_id'         => ['nullable', 'uuid', $own('tenant_inventory_items')],
            'item_name'                 => ['required', 'string', 'max:255'],
            'vendor_id'                 => ['nullable', 'uuid', $own('tenant_vendors')],
            'po_number'                 => ['nullable', 'string', 'max:64'],
            'expected_arrival_date'     => ['nullable', 'date'],
            // the drawer now takes dollars; cents is kept for any older caller.
            'unit_cost_estimated'       => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'unit_cost_cents_estimated' => ['nullable', 'integer', 'min:0'],
            'origin'                    => ['nullable', 'in:item,appointment,manual'],
            'allocations'               => ['required', 'array', 'min:1', 'max:50'],
            'allocations.*.mode'        => ['required', 'in:customer,customer_appt,stock'],
            'allocations.*.customer_id' => ['nullable', 'uuid', $own('tenant_customers')],
            'allocations.*.appointment_id' => ['nullable', 'uuid', $own('tenant_appointments')],
            'allocations.*.quantity'    => ['required', 'integer', 'min:1', 'max:9999'],
            'notes'                     => ['nullable', 'string', 'max:2000'],
        ]);

        // a customer row needs a customer, a job row needs a job.
        foreach ($data['allocations'] as $i => $alloc) {
            $n = $i + 1;
            if ($alloc['mode'] !== 'stock' && empty($alloc['customer_id'])) {
                return back()->withInput()->with('flash', ['type' => 'error', 'message' => "Row {$n}: pick a customer, or switch the row to Stock."]);
            }
            if ($alloc['mode'] === 'customer_appt' && empty($alloc['appointment_id'])) {
                return back()->withInput()->with('flash', ['type' => 'error', 'message' => "Row {$n}: pick the work order, or switch the row to Customer."]);
            }
        }

        $costCents = isset($data['unit_cost_estimated']) && $data['unit_cost_estimated'] !== null && $data['unit_cost_estimated'] !== ''
            ? (int) round(((float) $data['unit_cost_estimated']) * 100)
            : ($data['unit_cost_cents_estimated'] ?? null);

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

        // where the order was started from, so its history says so.
        $createdFrom = in_array($data['origin'] ?? null, ['item', 'appointment'], true) ? $data['origin'] : 'manual';

        // pickup location: the register/admin's current one, when this shop has it.
        $locationId = $request->session()->get('current_location_id');
        if ($locationId && ! \App\Models\Tenant\TenantLocation::where('tenant_id', $tid)->where('id', $locationId)->exists()) {
            $locationId = null;
        }

        try {
            // all rows or none: a failure part-way used to leave some created.
            $created = DB::transaction(function () use ($data, $tid, $initialStatus, $batchId, $createdFrom, $costCents, $locationId) {
                $rows = [];
                foreach ($data['allocations'] as $alloc) {
                    $rows[] = $this->service->create([
                        'tenant_id'                 => $tid,
                        'inventory_item_id'         => $data['inventory_item_id'] ?? null,
                        'item_name_snapshot'        => $data['item_name'],
                        'quantity'                  => (int) $alloc['quantity'],
                        'customer_id'               => $alloc['mode'] === 'stock' ? null : ($alloc['customer_id'] ?? null),
                        'appointment_id'            => $alloc['mode'] === 'customer_appt' ? ($alloc['appointment_id'] ?? null) : null,
                        // a work-order row picks up at the job's location.
                        'location_id'               => $alloc['mode'] === 'customer_appt' ? null : $locationId,
                        'vendor_id'                 => $data['vendor_id'] ?? null,
                        'po_number'                 => $data['po_number'] ?? null,
                        'expected_arrival_date'     => $data['expected_arrival_date'] ?? null,
                        'unit_cost_cents_estimated' => $costCents,
                        'status'                    => $initialStatus,
                        'created_from'              => $createdFrom,
                        'batch_id'                  => $batchId,
                        'created_by_user_id'        => Auth::guard('tenant')->id(),
                        'notes'                     => $data['notes'] ?? null,
                    ]);
                }
                return $rows;
            });
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
     * MARKER-SO-REPAIRS: the vendors that carry an item, for the drawer and
     * the order page. Only this shop's active vendors with a source row for
     * the item; in-stock first, then preferred, then cheapest.
     */
    public function vendorsForItem(Request $request): JsonResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        $itemId = (string) $request->query('item_id', '');
        $item = $itemId !== '' ? TenantInventoryItem::where('tenant_id', $tenant->id)->find($itemId) : null;
        if (! $item) {
            return response()->json(['ok' => false, 'error' => 'Item not found.'], 404);
        }

        return response()->json(['ok' => true, 'vendors' => self::vendorOptionsForItem($tenant->id, $item->id)]);
    }

    /** @return array<int, array{id:string,name:string,sku:?string,cost_cents:?int,avail:?int,preferred:bool,lead:?int}> */
    public static function vendorOptionsForItem(string $tenantId, string $itemId): array
    {
        $vendors = TenantVendor::where('tenant_id', $tenantId)->where('is_active', true)->get()->keyBy('id');
        $rows = \App\Models\Tenant\TenantInventoryItemVendor::where('inventory_item_id', $itemId)
            ->whereIn('vendor_id', $vendors->keys())
            ->get();

        return $rows->map(function ($r) use ($vendors) {
            $v = $vendors->get($r->vendor_id);
            $list = $r->live_cost_cents ?? $r->unit_cost_cents;
            return [
                'id'         => $r->vendor_id,
                'name'       => $v->name,
                'sku'        => $r->vendor_sku,
                'cost_cents' => $list !== null ? ($v->netCostCents($list) ?? $list) : null,
                'avail'      => $r->live_avail !== null ? (int) $r->live_avail : null,
                'preferred'  => (bool) $r->is_preferred,
                'lead'       => $r->lead_time_days,
            ];
        })->sortBy([
            fn ($a, $b) => (($b['avail'] ?? 0) > 0) <=> (($a['avail'] ?? 0) > 0),
            fn ($a, $b) => $b['preferred'] <=> $a['preferred'],
            fn ($a, $b) => ($a['cost_cents'] ?? PHP_INT_MAX) <=> ($b['cost_cents'] ?? PHP_INT_MAX),
        ])->values()->all();
    }

    /**
     * MARKER-PO: ordering happens on a purchase order. This puts the order on
     * its vendor's draft PO for its pickup location (starting one if needed)
     * and opens the PO, where it is placed. The order stays Needed until then.
     */
    public function markOrdered(Request $request, string $id): RedirectResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);
        $this->ensureBelongsToTenant($id, $tenant->id);

        $data = $request->validate([
            'vendor_id' => ['required', 'uuid', Rule::exists('tenant_vendors', 'id')->where(fn ($q) => $q->where('tenant_id', $tenant->id)->where('is_active', true))],
        ]);
        $so = TenantSpecialOrder::where('tenant_id', $tenant->id)->findOrFail($id);
        if ($so->status !== TenantSpecialOrder::STATUS_NEEDED) {
            return back()->with('flash', ['type' => 'error', 'message' => "{$so->so_number} is {$so->statusLabel()}, so it can't go on a PO."]);
        }
        if ($so->inventory_item_id && ! SpecialOrderService::vendorCarriesItem($tenant->id, $data['vendor_id'], $so->inventory_item_id)) {
            return back()->with('flash', ['type' => 'error', 'message' => "That vendor doesn't carry {$so->item_name_snapshot}. Pick one listed for it."]);
        }
        if ($so->vendor_id !== $data['vendor_id']) {
            $so->forceFill(['vendor_id' => $data['vendor_id'], 'vendor_assigned_rule' => 'manual'])->save();
        }
        $r = app(\App\Services\Tenant\PurchaseOrderService::class)->addSpecialOrders($tenant, [$so->id]);
        $po = collect($r['pos'])->first()['po'] ?? null;
        if (! $po) {
            return back()->with('flash', ['type' => 'error', 'message' => implode('; ', $r['skipped']) ?: 'It couldn\'t be added to a PO.']);
        }

        return redirect()->route('tenant.purchase-orders.show', ['id' => $po->id])
            ->with('flash', ['type' => 'success', 'message' => "{$so->so_number} added to {$po->po_number}. Place the PO to order it."]);
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
            'received_quantity'      => ['nullable', 'integer', 'min:1', 'max:9999'],
            'unit_cost_actual'       => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'unit_cost_cents_actual' => ['nullable', 'integer', 'min:0'],
            'vendor_invoice_number'  => ['nullable', 'string', 'max:64'],
            'vendor_invoice_date'    => ['nullable', 'date'],
        ]);
        $actualCents = (isset($data['unit_cost_actual']) && $data['unit_cost_actual'] !== null)
            ? (int) round(((float) $data['unit_cost_actual']) * 100)
            : ($data['unit_cost_cents_actual'] ?? null);

        try {
            // MARKER-SO-REPAIRS: a hand arrival puts the units in stock.
            $arrived = $this->service->markArrived(
                $id,
                $data['received_quantity'] ?? null, // null = full receipt
                $actualCents,
                $data['vendor_invoice_number'] ?? null,
                $data['vendor_invoice_date'] ?? null,
                true,
                Auth::guard('tenant')->user(),
            );
        } catch (SpecialOrderValidationException $e) {
            return back()->with('flash', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        // MARKER-SO-HANDOVER: someone is waiting → offer to tell them
        $tell = ($arrived->customer_id || $arrived->appointment_id) ? [$arrived->id] : [];

        return redirect()->route('tenant.special-orders.show', ['id' => $id])
            ->with('flash', ['type' => 'success', 'message' => 'Marked arrived.'])
            ->with('so_notify', $tell);
    }

    /** Old route name kept: "mark pulled" is now a real handover. */
    public function markPulled(Request $request, string $id): RedirectResponse|JsonResponse
    {
        return $this->handOver($request, $id);
    }

    // ────────────────────────────────────────────────────────
    //  MARKER-SO-HANDOVER endpoints. Each answers JSON to fetch callers
    //  (the bench, the order page, the register) and redirects a plain form.
    //  A SpecialOrderChoiceRequired thrown inside renders itself (409).
    // ────────────────────────────────────────────────────────

    private function done(Request $request, string $id, string $message): RedirectResponse|JsonResponse
    {
        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'message' => $message]);
        }

        return redirect()->route('tenant.special-orders.show', ['id' => $id])
            ->with('flash', ['type' => 'success', 'message' => $message]);
    }

    private function failed(Request $request, string $message): RedirectResponse|JsonResponse
    {
        if ($request->wantsJson()) {
            return response()->json(['ok' => false, 'error' => $message, 'message' => $message], 422);
        }

        return back()->with('flash', ['type' => 'error', 'message' => $message]);
    }

    /** Hand an arrived order over (paid, a finished job, or with a reason). */
    public function handOver(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);
        $this->ensureBelongsToTenant($id, $tenant->id);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        try {
            $so = $this->service->handOver($id, $data['reason'] ?? null);
        } catch (SpecialOrderValidationException|\App\Services\Tenant\InventoryStockException $e) {
            return $this->failed($request, $e->getMessage());
        }

        return $this->done($request, $id, "{$so->so_number} handed over.");
    }

    // returns JSON for the register's inline cleanup and the bench,
    // and keeps redirecting for a plain form post.
    public function cancel(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);
        $this->ensureBelongsToTenant($id, $tenant->id);

        $data = $request->validate([
            'reason'    => ['nullable', 'string', 'max:500'],
            'so_reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $so = $this->service->cancelWithChoice($id, ($data['reason'] ?? null) ?: ($data['so_reason'] ?? null));
        } catch (SpecialOrderValidationException $e) {
            return $this->failed($request, $e->getMessage());
        }

        return $this->done($request, $id, $so->status === TenantSpecialOrder::STATUS_CANCELLED
            ? "{$so->so_number} cancelled."
            : "{$so->so_number} is now shop stock on the way.");
    }

    /** The register took the order's line off the sale. */
    public function removeFromSale(Request $request, string $id): JsonResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);
        $this->ensureBelongsToTenant($id, $tenant->id);

        try {
            $so = $this->service->removeFromSale($id, 'Line removed from register sale.');
        } catch (SpecialOrderValidationException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        $msg = match (true) {
            $so->status === TenantSpecialOrder::STATUS_CANCELLED => "{$so->so_number} cancelled.",
            $so->customer_id && ! $so->sale_id                    => "{$so->so_number} kept for {$this->service->whoFor($so)}.",
            ! $so->customer_id && ! $so->appointment_id           => "{$so->so_number} is now shop stock on the way.",
            default                                               => "{$so->so_number} updated.",
        };

        return response()->json(['ok' => true, 'message' => $msg]);
    }

    /** Hold an arrived order after checking the shelf. */
    public function holdNow(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);
        $this->ensureBelongsToTenant($id, $tenant->id);

        try {
            $so = $this->service->holdNow($id);
        } catch (SpecialOrderValidationException $e) {
            return $this->failed($request, $e->getMessage());
        }

        return $this->done($request, $id, "{$so->so_number} is held.");
    }

    /** Not on the shelf: back to Needed so it can be ordered again. */
    public function markMissing(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);
        $this->ensureBelongsToTenant($id, $tenant->id);

        try {
            $so = $this->service->markMissing($id);
        } catch (SpecialOrderValidationException $e) {
            return $this->failed($request, $e->getMessage());
        }

        return $this->done($request, $id, "{$so->so_number} is back in Needed. Order it again.");
    }

    /** Register "Ready for pickup" → Add to sale. The register adds the item line itself. */
    public function attachToSale(Request $request, string $id): JsonResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);
        $this->ensureBelongsToTenant($id, $tenant->id);
        $data = $request->validate([
            'sale_id' => ['required', 'uuid', Rule::exists('tenant_sales', 'id')->where(fn ($q) => $q->where('tenant_id', $tenant->id))],
        ]);

        try {
            $so = $this->service->attachToSale($id, $data['sale_id']);
        } catch (SpecialOrderValidationException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok'   => true,
            'line' => [
                'inventory_item_id' => $so->inventory_item_id,
                'name'              => $so->item_name_snapshot,
                'quantity'          => (int) $so->quantity,
                'special_order_id'  => $so->id,
                'so_number'         => $so->so_number,
            ],
        ]);
    }

    /** MARKER-PO: which orders a notice is for, and its template. */
    private function notifyKind(?string $kind): array
    {
        return $kind === 'new_date'
            ? [TenantSpecialOrder::STATUS_ORDERED, \App\Services\Tenant\SpecialOrderNotifier::TEMPLATE_NEW_DATE]
            : [TenantSpecialOrder::STATUS_ARRIVED, \App\Services\Tenant\SpecialOrderNotifier::TEMPLATE];
    }

    /** Who would be told, and how — for the "Tell customer" dialog. */
    public function notifyRows(Request $request): JsonResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:200'], 'ids.*' => ['uuid'], 'kind' => ['nullable', 'in:arrived,new_date']]);

        [$status, $template] = $this->notifyKind($data['kind'] ?? null);
        $orders = TenantSpecialOrder::where('tenant_id', $tenant->id)->whereIn('id', $data['ids'])
            ->where('status', $status)->get();
        $n = \App\Services\Tenant\SpecialOrderNotifier::forTenant($tenant, $template);

        return response()->json(['ok' => true, 'rows' => $n->rows($orders), 'message' => $n->defaultMessage()]);
    }

    /** Send "your part is here". */
    public function notify(Request $request): RedirectResponse|JsonResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);
        $data = $request->validate([
            'ids'     => ['required', 'array', 'min:1', 'max:200'],
            'ids.*'   => ['uuid'],
            'picks'   => ['nullable', 'array'],
            'message' => ['nullable', 'string', 'max:1000'],
            'kind'    => ['nullable', 'in:arrived,new_date'],
        ]);

        [$status, $template] = $this->notifyKind($data['kind'] ?? null);
        $orders = TenantSpecialOrder::where('tenant_id', $tenant->id)->whereIn('id', $data['ids'])
            ->where('status', $status)->get();
        $picks = [];
        foreach ((array) ($data['picks'] ?? []) as $soId => $ch) {
            $ch = (array) $ch;
            $picks[$soId] = [
                'email' => filter_var($ch['email'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'sms'   => filter_var($ch['sms'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'staff' => filter_var($ch['staff'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
        }
        $c = \App\Services\Tenant\SpecialOrderNotifier::forTenant($tenant, $template)->send($orders, $picks, $data['message'] ?? null);

        $parts = [];
        foreach (['email' => 'email', 'sms' => 'text', 'staff' => 'staff alert'] as $k => $w) {
            if ($c[$k]) {
                $parts[] = $c[$k] . ' ' . $w . ($c[$k] === 1 ? '' : 's');
            }
        }
        $msg = $parts ? 'Sent ' . implode(', ', $parts) . '.' : 'Nothing was sent.';
        if ($c['none'] && $parts) {
            $msg .= " {$c['none']} not told.";
        }

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'message' => $msg, 'counts' => $c]);
        }

        return back()->with('flash', ['type' => $parts ? 'success' : 'error', 'message' => $msg]);
    }

    /**
     * the vendor placement board. Every needed order
     * with the vendors that actually carry it, grouped by where it is
     * currently assigned, so a whole day's ordering is one screen.
     */
    /**
     * vendor grouping data for the special-orders
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
                    // THEIR part number. The export is
                    // per vendor because this number is too.
                    'sku'       => $p->vendor_sku,
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
     * move an order to a vendor. Assignment is not
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
        // MARKER-SO-REPAIRS
        if ($so->inventory_item_id && ! SpecialOrderService::vendorCarriesItem($tenant->id, $data['vendor_id'], $so->inventory_item_id)) {
            return response()->json(['ok' => false, 'error' => 'That vendor does not carry this item.'], 422);
        }

        $so->forceFill(['vendor_id' => $data['vendor_id']])->save();

        return response()->json(['ok' => true]);
    }

    /**
     * "yes, this is still needed" for an order whose
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
            ->whereDate('appointment_date', '>=', tnow()->toDateString()) // appointment_date is a naive tenant-local DATE
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
