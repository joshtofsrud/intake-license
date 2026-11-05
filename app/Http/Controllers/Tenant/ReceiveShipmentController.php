<?php

namespace App\Http\Controllers\Tenant;

use App\Exceptions\Pos\InsufficientStockException;
use App\Exceptions\Pos\InvalidQuantityException;
use App\Exceptions\Pos\TenantMismatchException;
use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantInventoryItem;
use App\Models\Tenant\TenantInventoryReceiveShipment;
use App\Models\Tenant\TenantInventoryReceiveShipmentItem;
use App\Models\Tenant\TenantLocation;
use App\Services\Pos\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class ReceiveShipmentController extends Controller
{
    public function __construct(private readonly InventoryService $inventory)
    {
    }

    public function index(Request $request): View
    {
        $tenant = tenant();
        abort_unless($tenant->retail_enabled, 403);

        $tab = in_array($request->query('tab'), ['draft', 'committed', 'voided'], true)
            ? $request->query('tab')
            : 'draft';

        $locationId = $request->query('location');
        $search     = trim((string) $request->query('s', ''));

        $query = TenantInventoryReceiveShipment::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', $tab)
            ->with('location:id,name')
            ->orderByDesc('updated_at');

        if ($locationId) {
            $query->where('location_id', $locationId);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('shipment_number', 'like', "%{$search}%")
                  ->orWhere('distributor_name', 'like', "%{$search}%");
            });
        }

        $perPage = 25;
        $page    = max(1, (int) $request->query('page', 1));
        $total   = (clone $query)->count();
        $shipments = $query->forPage($page, $perPage)->get();

        $locations = TenantLocation::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $counts = TenantInventoryReceiveShipment::query()
            ->where('tenant_id', $tenant->id)
            ->selectRaw('status, COUNT(*) AS c')
            ->groupBy('status')
            ->pluck('c', 'status');

        return view('tenant.inventory.receiving.index', [
            'shipments' => $shipments,
            'locations' => $locations,
            'tab'       => $tab,
            'location'  => $locationId,
            'search'    => $search,
            'page'      => $page,
            'perPage'   => $perPage,
            'total'     => $total,
            'counts'    => $counts,
            'pageTitle' => 'Receiving',
        ]);
    }

    public function create(Request $request): RedirectResponse
    {
        $tenant = tenant();
        abort_unless($tenant->retail_enabled, 403);

        $locations = TenantLocation::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        if ($locations->isEmpty()) {
            return redirect()
                ->route('tenant.inventory.index')
                ->with('flash', ['type' => 'error', 'message' => 'Add a location first before receiving.']);
        }

        $location = $tenant->defaultLocation ?? $locations->first();

        $shipment = new TenantInventoryReceiveShipment();
        $shipment->tenant_id                 = $tenant->id;
        $shipment->location_id               = $location->id;
        $shipment->shipment_number           = $this->generateShipmentNumber($tenant);
        $shipment->received_date             = now($tenant->timezone ?? 'UTC')->toDateString();
        $shipment->status                    = 'draft';
        $shipment->shipping_cost_cents       = 0;
        $shipment->created_by_tenant_user_id = auth('tenant')->id();
        $shipment->save();

        return redirect()->route('tenant.inventory.receiving.edit', ['id' => $shipment->id]);
    }

    /** MARKER-PO: start a shipment, from a vendor's open POs or blank. */
    public function newForm(Request $request): View|RedirectResponse
    {
        $tenant = tenant();
        abort_unless($tenant->retail_enabled, 403);
        $locations = TenantLocation::where('tenant_id', $tenant->id)->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        if ($locations->isEmpty()) {
            return redirect()->route('tenant.inventory.index')->with('flash', ['type' => 'error', 'message' => 'Add a location first before receiving.']);
        }
        $loc = $request->query('location') ?: (session('current_location_id') ?: ($tenant->defaultLocation?->id ?? $locations->first()->id));

        return view('tenant.inventory.receiving.create', [
            'locations'         => $locations,
            'vendors'           => \App\Models\Tenant\TenantVendor::where('tenant_id', $tenant->id)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'defaultNumber'     => $this->generateShipmentNumber($tenant),
            'defaultLocationId' => $locations->firstWhere('id', $loc)?->id ?? $locations->first()->id,
            'defaultVendorId'   => $request->query('vendor'),
            'today'             => now($tenant->timezone ?? 'UTC')->toDateString(),
        ]);
    }

    /** MARKER-PO: open POs for a vendor + location, for the start form. */
    public function openPos(Request $request): JsonResponse
    {
        $tenant = tenant();
        abort_unless($tenant->retail_enabled, 403);
        $vendorId = (string) $request->query('vendor', '');
        $locId = (string) $request->query('location', '');
        $rows = \App\Services\Tenant\PurchaseOrderService::openFor($tenant->id, $vendorId ?: null, $locId);
        $elsewhere = $vendorId ? \App\Models\Tenant\TenantPurchaseOrder::where('tenant_id', $tenant->id)->where('vendor_id', $vendorId)
            ->where('location_id', '!=', $locId)->whereIn('status', \App\Models\Tenant\TenantPurchaseOrder::OPEN)->with('location:id,name', 'lines')->get()
            ->filter(fn ($po) => $po->owedUnits() > 0)->groupBy(fn ($po) => $po->location?->name ?? 'another location')
            ->map(fn ($g, $name) => count($g) . ' ship' . (count($g) === 1 ? 's' : '') . " to {$name}")->values() : collect();

        return response()->json(['ok' => true, 'pos' => $rows->map(fn ($po) => [
            'id' => $po->id, 'po_number' => $po->po_number, 'placed' => $po->placed_at?->format('M j'),
            'lines' => $po->lines->filter(fn ($l) => $l->owed() > 0)->count(), 'units' => $po->owedUnits(),
            'expected' => $po->lines->filter(fn ($l) => $l->owed() > 0)->map(fn ($l) => ($l->expected_date ?? $po->expected_date)?->format('M j'))->filter()->unique()->implode(' · '),
        ])->values(), 'elsewhere' => $elsewhere]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = tenant();
        abort_unless($tenant->retail_enabled, 403);

        $data = $request->validate([
            'vendor_id'          => ['nullable', 'uuid'], // MARKER-PO
            'po_ids'             => ['nullable', 'array'],
            'po_ids.*'           => ['uuid'],
            'shipment_number'    => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9\-\/_.]+$/'],
            'location_id'        => ['required', 'uuid', \Illuminate\Validation\Rule::exists('tenant_locations', 'id')
                ->where(fn ($q) => $q->where('tenant_id', $tenant->id))],
            'received_date'      => ['required', 'date'],
            'distributor_name'   => ['nullable', 'string', 'max:128'],
            'distributor_code'   => ['nullable', 'string', 'max:32'],
            'shipping_cost_cents' => ['nullable', 'integer', 'min:0'],
            'notes'              => ['nullable', 'string', 'max:2000'],
        ]);

        $location = TenantLocation::where('id', $data['location_id'])
            ->where('tenant_id', $tenant->id)
            ->firstOrFail();

        if (TenantInventoryReceiveShipment::where('tenant_id', $tenant->id)
            ->where('shipment_number', $data['shipment_number'])
            ->exists()) {
            return back()->withInput()
                ->withErrors(['shipment_number' => 'A shipment with this number already exists.']);
        }

        // MARKER-PO: from the vendor's open POs (only ones for this location)
        $vendor = ! empty($data['vendor_id']) ? \App\Models\Tenant\TenantVendor::where('tenant_id', $tenant->id)->find($data['vendor_id']) : null;
        if ($vendor) {
            $pos = \App\Models\Tenant\TenantPurchaseOrder::where('tenant_id', $tenant->id)->where('vendor_id', $vendor->id)
                ->where('location_id', $location->id)->whereIn('status', \App\Models\Tenant\TenantPurchaseOrder::OPEN)
                ->whereIn('id', (array) ($data['po_ids'] ?? []))->orderBy('placed_at')->with('lines')->get();
            $shipment = \App\Services\Tenant\ReceivingFromPo::start($tenant, $location->id, $vendor->id, $pos, $data['shipment_number'], $data['received_date']);
            if (! empty($data['notes'])) {
                $shipment->forceFill(['notes' => $data['notes']])->save();
            }

            return redirect()->route('tenant.inventory.receiving.edit', ['id' => $shipment->id])
                ->with('flash', ['type' => 'success', 'message' => $pos->isNotEmpty()
                    ? 'Shipment started with every line still owed on ' . $pos->pluck('po_number')->implode(', ') . '.'
                    : 'Shipment started.']);
        }

        $shipment = new TenantInventoryReceiveShipment();
        $shipment->tenant_id                  = $tenant->id;
        $shipment->location_id                = $location->id;
        $shipment->shipment_number            = $data['shipment_number'];
        $shipment->received_date              = $data['received_date'];
        $shipment->distributor_name           = $data['distributor_name'] ?? null;
        $shipment->distributor_code           = $data['distributor_code'] ?? null;
        $shipment->shipping_cost_cents        = $data['shipping_cost_cents'] ?? 0;
        $shipment->notes                      = $data['notes'] ?? null;
        $shipment->status                     = 'draft';
        $shipment->created_by_tenant_user_id  = auth('tenant')->id();
        $shipment->save();

        return redirect()
            ->route('tenant.inventory.receiving.edit', ['id' => $shipment->id])
            ->with('flash', ['type' => 'success', 'message' => 'Shipment started.']);
    }

    public function show(string $id): View|RedirectResponse
    {
        $tenant = tenant();
        abort_unless($tenant->retail_enabled, 403);

        $shipment = $this->findOrFail($tenant, $id);

        if ($shipment->isDraft()) {
            return redirect()->route('tenant.inventory.receiving.edit', ['id' => $shipment->id]);
        }

        $shipment->load([
            'location:id,name',
            'items' => fn ($q) => $q->orderBy('created_at'),
            'items.item:id,sku,name,category_id',
            'items.item.category:id,name',
            'createdBy:id,name',
            'committedBy:id,name',
        ]);

        return view('tenant.inventory.receiving.show', [
            'shipment'  => $shipment,
            'pageTitle' => 'Shipment ' . $shipment->shipment_number,
        ]);
    }

    public function edit(string $id): View|RedirectResponse
    {
        $tenant = tenant();
        abort_unless($tenant->retail_enabled, 403);

        $shipment = $this->findOrFail($tenant, $id);

        if (! $shipment->isDraft()) {
            return redirect()
                ->route('tenant.inventory.receiving.show', ['id' => $shipment->id])
                ->with('flash', ['type' => 'info', 'message' => 'This shipment is already committed.']);
        }

        $shipment->load([
            'location:id,name',
            'items' => fn ($q) => $q->orderBy('created_at'),
            'items.item:id,sku,name,category_id',
            'items.item.category:id,name',
        ]);

        $locations = TenantLocation::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        // patch-90 SO auto-link — find open 'ordered' SOs for received items
        $receivedItemIds = $shipment->items
            ->filter(fn ($l) => $l->status === 'received'
                && $l->inventory_item_id !== null
                && $l->received_quantity > 0)
            ->pluck('inventory_item_id')
            ->unique()
            ->values()
            ->all();

        $matchedSos = collect();
        $neededHintCount = 0;
        if (!empty($receivedItemIds)) {
            $matchedSos = \App\Models\Tenant\TenantSpecialOrder::where('tenant_id', $tenant->id)
                ->whereIn('inventory_item_id', $receivedItemIds)
                ->where('status', \App\Models\Tenant\TenantSpecialOrder::STATUS_ORDERED)
                ->whereNull('purchase_order_line_id') // MARKER-PO: those on a PO fill from their line
                ->with(['customer', 'appointment', 'vendor', 'item'])
                ->orderBy('created_at')
                ->get();

            // Surface count of 'needed' SOs that exist for these items —
            // a hint that staff might want to promote them before commit.
            $neededHintCount = \App\Models\Tenant\TenantSpecialOrder::where('tenant_id', $tenant->id)
                ->whereIn('inventory_item_id', $receivedItemIds)
                ->where('status', \App\Models\Tenant\TenantSpecialOrder::STATUS_NEEDED)
                ->count();
        }

        return view('tenant.inventory.receiving.edit', array_merge($this->poContext($shipment), [
            'shipment'        => $shipment,
            'locations'       => $locations,
            'pageTitle'       => 'Editing ' . $shipment->shipment_number,
            'matchedSos'      => $matchedSos,
            'neededHintCount' => $neededHintCount,
        ]));
    }

    // ────────────────────────────────────────────────────────
    //  MARKER-PO: receiving against purchase orders
    // ────────────────────────────────────────────────────────

    /** Everything the lines table and its summary need. */
    private function poContext(TenantInventoryReceiveShipment $shipment): array
    {
        $tenant = tenant();
        $lines = TenantInventoryReceiveShipmentItem::where('shipment_id', $shipment->id)->orderBy('created_at')
            ->with(['item.category', 'poLine.purchaseOrder', 'poLine.specialOrders.customer', 'poLine.specialOrders.appointment'])->get();
        $shares = \App\Services\Tenant\ReceivingFromPo::freightShares($shipment, $lines);
        $gap = \App\Services\Tenant\ReceivingFromPo::invoiceGap($shipment, $lines);
        $tol = \App\Services\Tenant\PurchaseOrderService::settings($tenant)['invoice_tolerance'];
        $poLines = $lines->filter(fn ($l) => $l->poLine);
        $pos = $poLines->map(fn ($l) => $l->poLine->purchaseOrder)->filter()->unique('id')->values();
        $pullable = $shipment->vendor_id
            ? \App\Services\Tenant\PurchaseOrderService::openFor($tenant->id, $shipment->vendor_id, $shipment->location_id)
                ->reject(fn ($po) => $pos->contains('id', $po->id))->values()
            : collect();

        // what commit would do, for the in-app confirm
        $fill = [];
        $still = [];
        foreach ($poLines as $l) {
            $acc = \App\Services\Tenant\ReceivingFromPo::accepted($l);
            if ($l->status !== 'received') {
                $acc = 0;
            }
            $waiting = $l->poLine->specialOrders->where('status', \App\Models\Tenant\TenantSpecialOrder::STATUS_ORDERED)
                ->sortBy(fn ($so) => [($so->customer_id || $so->appointment_id) ? 0 : 1, $so->created_at?->timestamp ?? 0])->values();
            $left = $acc;
            foreach ($waiting as $so) {
                if ($left < 1) { break; }
                if ($so->customer_id || $so->appointment_id) {
                    $fill[] = $so->customer?->fullName() ?? ($so->appointment ? 'work order ' . $so->appointment->ra_number : $so->so_number);
                }
                $left -= (int) $so->quantity;
            }
            $owedAfter = max(0, $l->poLine->owed() - $acc);
            if ($owedAfter > 0) {
                $still[] = ['name' => $l->name, 'po' => $l->poLine->purchaseOrder?->po_number, 'qty' => $owedAfter,
                    'cancel' => $l->rest_action === 'cancel', 'date' => $l->rest_expected_date?->format('M j') ?? ($l->poLine->expected_date ?? $l->poLine->purchaseOrder?->expected_date)?->format('M j')];
            }
        }
        $commitLines = $lines->filter(fn ($l) => \App\Services\Tenant\ReceivingFromPo::committable($l));
        $blockers = $lines->filter(fn ($l) => $l->poLine && ! $l->inventory_item_id && $l->received_quantity > 0)->pluck('name')->values();

        return [
            'lines'    => $lines,
            'shares'   => $shares,
            'invoiceGap' => $gap,
            'invoiceFlag' => $gap !== null && abs($gap) > $tol,
            'pos'      => $pos,
            'pullable' => $pullable,
            'vendor'   => $shipment->vendor_id ? \App\Models\Tenant\TenantVendor::withTrashed()->find($shipment->vendor_id) : null,
            'stats'    => [
                'owed'     => (int) $poLines->sum(fn ($l) => $l->poLine->owed()),
                'received' => (int) $lines->sum(fn ($l) => $l->status === 'received' ? \App\Services\Tenant\ReceivingFromPo::accepted($l) : 0),
                'still'    => (int) collect($still)->where('cancel', false)->sum('qty'),
                'filled'   => count($fill),
            ],
            'plan' => [
                'units'    => (int) $commitLines->sum(fn ($l) => \App\Services\Tenant\ReceivingFromPo::accepted($l)),
                'items'    => $commitLines->count(),
                'freight'  => (int) $shipment->shipping_cost_cents,
                'freightMethod' => ['value' => 'by line value', 'units' => 'by units', 'off' => 'not spread'][$shipment->freight_method ?: 'value'],
                'fill'     => $fill,
                'still'    => $still,
                'rejected' => $lines->filter(fn ($l) => $l->rejected_quantity > 0)->map(fn ($l) => ['name' => $l->name, 'qty' => $l->rejected_quantity, 'replace' => (bool) $l->rejected_replace])->values(),
                'blockers' => $blockers,
                'invoiceFlag' => $gap !== null && abs($gap) > $tol,
                'location' => $shipment->location?->name,
            ],
        ];
    }

    /** The lines table and summary, re-rendered after every change. */
    private function payload(TenantInventoryReceiveShipment $shipment, array $extra = []): JsonResponse
    {
        $shipment->refresh();
        $ctx = $this->poContext($shipment);

        return response()->json(array_merge([
            'ok'      => true,
            'tbody'   => view('tenant.inventory.receiving._partials.lines', array_merge($ctx, ['shipment' => $shipment]))->render(),
            'summary' => view('tenant.inventory.receiving._partials.summary', array_merge($ctx, ['shipment' => $shipment]))->render(),
            'totals'  => $this->serializeTotals($shipment->load('items')),
        ], $extra));
    }

    /** Pull every line still owed on another open PO for this vendor + location. */
    public function pullPo(Request $request, string $id): JsonResponse
    {
        $tenant = tenant();
        abort_unless($tenant->retail_enabled, 403);
        $shipment = $this->findOrFail($tenant, $id);
        $this->assertDraftJson($shipment);
        $data = $request->validate(['po_id' => ['required', 'uuid']]);
        $po = \App\Models\Tenant\TenantPurchaseOrder::where('tenant_id', $tenant->id)->whereIn('status', \App\Models\Tenant\TenantPurchaseOrder::OPEN)->find($data['po_id']);
        if (! $po || $po->vendor_id !== $shipment->vendor_id) {
            return response()->json(['ok' => false, 'message' => 'That PO isn\'t open for this vendor.'], 422);
        }
        try {
            $n = \App\Services\Tenant\ReceivingFromPo::addPo($shipment, $po);
        } catch (\App\Services\Tenant\PurchaseOrderException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return $this->payload($shipment, ['message' => "{$n} line" . ($n === 1 ? '' : 's') . " added from {$po->po_number}."]);
    }

    /** Units in the box that weren't accepted (damaged, wrong item). */
    public function rejectItem(Request $request, string $id, string $itemId): JsonResponse
    {
        $tenant = tenant();
        abort_unless($tenant->retail_enabled, 403);
        $shipment = $this->findOrFail($tenant, $id);
        $this->assertDraftJson($shipment);
        $line = TenantInventoryReceiveShipmentItem::where('shipment_id', $shipment->id)->where('tenant_id', $tenant->id)->findOrFail($itemId);
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:0', 'max:99999'],
            'reason'   => ['nullable', 'string', 'max:64'],
            'replace'  => ['nullable', 'boolean'],
            'note'     => ['nullable', 'string', 'max:500'],
        ]);
        $q = min((int) $data['quantity'], (int) $line->received_quantity);
        $line->forceFill([
            'rejected_quantity' => $q,
            'rejected_reason'   => $q ? ($data['reason'] ?: 'Not accepted') : null,
            'rejected_replace'  => $q ? (bool) ($data['replace'] ?? true) : true,
            'notes'             => $q && ! empty($data['note']) ? $data['note'] : $line->notes,
        ])->save();

        return $this->payload($shipment, ['message' => $q ? "{$q} × {$line->name} not accepted." : 'Undone.']);
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $tenant = tenant();
        abort_unless($tenant->retail_enabled, 403);

        $shipment = $this->findOrFail($tenant, $id);
        $this->assertDraft($shipment);

        $data = $request->validate([
            'shipment_number'    => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9\-\/_.]+$/'],
            'received_date'      => ['required', 'date'],
            'distributor_name'   => ['nullable', 'string', 'max:128'],
            'distributor_code'   => ['nullable', 'string', 'max:32'],
            'shipping_cost_cents' => ['nullable', 'integer', 'min:0'],
            'shipping_cost_dollars' => ['nullable', 'string', 'max:20'],
            'notes'              => ['nullable', 'string', 'max:2000'],
            // MARKER-PO: the vendor's invoice, and how freight is spread
            'vendor_invoice_number' => ['nullable', 'string', 'max:64'],
            'vendor_invoice_date'   => ['nullable', 'date'],
            'invoice_total_dollars' => ['nullable', 'string', 'max:20'],
            'freight_method'        => ['nullable', 'in:value,units,off'],
        ]);

        $shipment->vendor_invoice_number = $data['vendor_invoice_number'] ?? null;
        $shipment->vendor_invoice_date   = $data['vendor_invoice_date'] ?? null;
        $inv = trim((string) ($data['invoice_total_dollars'] ?? ''));
        $shipment->invoice_total_cents   = $inv === '' ? null : (int) round(((float) preg_replace('/[^0-9.]/', '', $inv)) * 100);
        if (! empty($data['freight_method'])) {
            $shipment->freight_method = $data['freight_method'];
        }

        if (array_key_exists('shipping_cost_dollars', $data) && $data['shipping_cost_dollars'] !== null && $data['shipping_cost_dollars'] !== '') {
            $cleaned = preg_replace('/[^0-9.\-]/', '', $data['shipping_cost_dollars']);
            $data['shipping_cost_cents'] = (int) round(((float) $cleaned) * 100);
        }

        if ($data['shipment_number'] !== $shipment->shipment_number
            && TenantInventoryReceiveShipment::where('tenant_id', $tenant->id)
                ->where('shipment_number', $data['shipment_number'])
                ->where('id', '!=', $shipment->id)
                ->exists()) {
            return back()->withErrors(['shipment_number' => 'A shipment with this number already exists.']);
        }

        $shipment->shipment_number     = $data['shipment_number'];
        $shipment->received_date       = $data['received_date'];
        if (! $shipment->vendor_id) { // a vendor's shipment keeps the vendor's name
            $shipment->distributor_name    = $data['distributor_name'] ?? null;
            $shipment->distributor_code    = $data['distributor_code'] ?? null;
        }
        $shipment->shipping_cost_cents = $data['shipping_cost_cents'] ?? 0;
        $shipment->notes               = $data['notes'] ?? null;
        $shipment->save();

        return back()->with('flash', ['type' => 'success', 'message' => 'Shipment saved.']);
    }

    public function destroy(string $id): RedirectResponse
    {
        $tenant = tenant();
        abort_unless($tenant->retail_enabled, 403);

        $shipment = $this->findOrFail($tenant, $id);
        $this->assertDraft($shipment);

        $shipment->delete();

        return redirect()
            ->route('tenant.inventory.receiving.index')
            ->with('flash', ['type' => 'success', 'message' => 'Draft shipment deleted.']);
    }

    public function addItem(Request $request, string $id): JsonResponse
    {
        $tenant = tenant();
        abort_unless($tenant->retail_enabled, 403, 'Retail not enabled.');

        $shipment = $this->findOrFail($tenant, $id);
        $this->assertDraftJson($shipment);

        $data = $request->validate([
            'mode'              => ['required', 'in:expected,unexpected'],
            'inventory_item_id' => ['nullable', 'uuid'],
            'name'              => ['required_without:inventory_item_id', 'nullable', 'string', 'max:255'],
            'sku'               => ['nullable', 'string', 'max:64'],
            'upc'               => ['nullable', 'string', 'max:20'],
            'expected_quantity' => ['nullable', 'integer', 'min:0', 'max:99999'],
            'received_quantity' => ['nullable', 'integer', 'min:0', 'max:99999'],
            'unit_cost_cents'   => ['nullable', 'integer', 'min:0'],
            'unit_cost_dollars' => ['nullable', 'string', 'max:20'],
        ]);

        if (array_key_exists('unit_cost_dollars', $data) && $data['unit_cost_dollars'] !== null && $data['unit_cost_dollars'] !== '') {
            $cleaned = preg_replace('/[^0-9.\-]/', '', $data['unit_cost_dollars']);
            $data['unit_cost_cents'] = (int) round(((float) $cleaned) * 100);
        }

        $item = null;
        if (! empty($data['inventory_item_id'])) {
            $item = TenantInventoryItem::where('id', $data['inventory_item_id'])
                ->where('tenant_id', $tenant->id)
                ->first();
            if (! $item) {
                return response()->json(['ok' => false, 'message' => 'Item not found.'], 404);
            }
        }

        $expectedQty = (int) ($data['expected_quantity'] ?? 0);
        $receivedQty = (int) ($data['received_quantity'] ?? 0);

        // MARKER-PO: scanning an item already on the shipment counts it again;
        // one that sits on an open PO for this vendor links to that PO line.
        if ($item) {
            $same = TenantInventoryReceiveShipmentItem::where('shipment_id', $shipment->id)->where('inventory_item_id', $item->id)
                ->orderBy('created_at')->first();
            if ($same) {
                $same->received_quantity = (int) $same->received_quantity + max(1, $receivedQty);
                if ($same->purchase_order_line_id || $same->status === 'backorder' || $same->status === 'expected') {
                    $same->status = 'received';
                }
                $same->total_cost_cents = $same->unit_cost_cents !== null ? $same->unit_cost_cents * $same->received_quantity : null;
                $same->save();

                return $this->payload($shipment, ['message' => "{$same->name}: {$same->received_quantity} received."]);
            }
            $open = \App\Services\Tenant\ReceivingFromPo::openLineFor($shipment, $item->id);
            if ($open) {
                $sl = \App\Services\Tenant\ReceivingFromPo::lineFromPo($shipment, $open, $open->owed(), max(1, $receivedQty));

                return $this->payload($shipment, ['message' => "{$sl->name} is on {$open->purchaseOrder->po_number}; linked."]);
            }
        }

        $status = match (true) {
            $data['mode'] === 'unexpected' => 'unexpected_pending',
            $receivedQty > 0               => 'received',
            $expectedQty > 0               => 'expected',
            default                        => 'expected',
        };

        $line = new TenantInventoryReceiveShipmentItem();
        $line->tenant_id         = $tenant->id;
        $line->shipment_id       = $shipment->id;
        $line->inventory_item_id = $item?->id;
        $line->name              = $item?->name ?? ($data['name'] ?? 'Untitled');
        $line->sku               = $item?->sku ?? ($data['sku'] ?? null);
        $line->upc               = $data['upc'] ?? null;
        $line->expected_quantity = $expectedQty;
        $line->received_quantity = $receivedQty;
        $line->status            = $status;
        $line->unit_cost_cents   = $data['unit_cost_cents'] ?? $item?->effectiveCostCents();
        $line->total_cost_cents  = $line->unit_cost_cents
            ? $line->unit_cost_cents * $receivedQty
            : null;
        $line->save();

        return $this->payload($shipment, ['message' => $item ? ($shipment->vendor_id ? "{$line->name} isn't on an open PO from this vendor; added as unexpected stock." : "{$line->name} added.") : 'Not in your catalog. Create the item before committing.']);
    }

    public function updateItem(Request $request, string $id, string $itemId): JsonResponse
    {
        $tenant = tenant();
        abort_unless($tenant->retail_enabled, 403, 'Retail not enabled.');

        $shipment = $this->findOrFail($tenant, $id);
        $this->assertDraftJson($shipment);

        $line = TenantInventoryReceiveShipmentItem::where('id', $itemId)
            ->where('shipment_id', $shipment->id)
            ->where('tenant_id', $tenant->id)
            ->first();

        if (! $line) {
            return response()->json(['ok' => false, 'message' => 'Line not found.'], 404);
        }

        $data = $request->validate([
            'expected_quantity' => ['nullable', 'integer', 'min:0', 'max:99999'],
            'received_quantity' => ['nullable', 'integer', 'min:0', 'max:99999'],
            'status'            => ['nullable', 'in:expected,received,backorder,unexpected_pending,unexpected_added,unexpected_hold'],
            'unit_cost_cents'   => ['nullable', 'integer', 'min:0'],
            'unit_cost_dollars' => ['nullable', 'string', 'max:20'],
            'notes'             => ['nullable', 'string', 'max:500'],
            'rest_action'       => ['nullable', 'in:owed,cancel'], // MARKER-PO
            'rest_expected_date'=> ['nullable', 'date'],
        ]);

        if (array_key_exists('unit_cost_dollars', $data) && $data['unit_cost_dollars'] !== null && $data['unit_cost_dollars'] !== '') {
            $cleaned = preg_replace('/[^0-9.\-]/', '', $data['unit_cost_dollars']);
            $data['unit_cost_cents'] = (int) round(((float) $cleaned) * 100);
        }
        if (! empty($data['rest_action'])) {
            $line->rest_action = $data['rest_action'];
        }
        if (array_key_exists('rest_expected_date', $data)) {
            $line->rest_expected_date = $data['rest_expected_date'];
        }

        if (array_key_exists('expected_quantity', $data) && $data['expected_quantity'] !== null && ! $line->purchase_order_line_id) {
            $line->expected_quantity = (int) $data['expected_quantity'];
        }
        if (array_key_exists('received_quantity', $data) && $data['received_quantity'] !== null) {
            $line->received_quantity = (int) $data['received_quantity'];
        }
        if (array_key_exists('status', $data) && $data['status'] !== null) {
            $line->status = $data['status'];
        }
        if (array_key_exists('unit_cost_cents', $data) && $data['unit_cost_cents'] !== null) {
            $line->unit_cost_cents = (int) $data['unit_cost_cents'];
        }
        if (array_key_exists('notes', $data)) {
            $line->notes = $data['notes'];
        }

        // MARKER-PO: a PO line is received when anything came, otherwise still owed
        if ($line->purchase_order_line_id) {
            $line->status = $line->received_quantity > 0 ? 'received' : 'backorder';
            if ($line->rejected_quantity > $line->received_quantity) {
                $line->rejected_quantity = $line->received_quantity;
            }
        }
        $line->total_cost_cents = $line->unit_cost_cents
            ? $line->unit_cost_cents * $line->received_quantity
            : null;
        $line->save();

        return $this->payload($shipment);
    }

    public function removeItem(string $id, string $itemId): JsonResponse
    {
        $tenant = tenant();
        abort_unless($tenant->retail_enabled, 403, 'Retail not enabled.');

        $shipment = $this->findOrFail($tenant, $id);
        $this->assertDraftJson($shipment);

        $line = TenantInventoryReceiveShipmentItem::where('id', $itemId)
            ->where('shipment_id', $shipment->id)
            ->where('tenant_id', $tenant->id)
            ->first();

        if (! $line) {
            return response()->json(['ok' => false, 'message' => 'Line not found.'], 404);
        }

        $line->delete();

        return $this->payload($shipment, ['message' => $line->purchase_order_line_id ? 'Line removed. It stays owed on its PO.' : 'Line removed.']);
    }

    public function searchItems(Request $request): JsonResponse
    {
        $tenant = tenant();
        abort_unless($tenant->retail_enabled, 403, 'Retail not enabled.');

        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['ok' => true, 'results' => []]);
        }

        // the shared search, best match first.
        $itemQuery = TenantInventoryItem::where('tenant_id', $tenant->id)
            ->where('is_active', true);
        $searchHit = \App\Support\InventorySearch::apply($itemQuery, $tenant->id, $q);
        \App\Support\InventorySearch::rank($itemQuery, $searchHit['used']);
        $results = $itemQuery
            ->with('category:id,name')
            ->limit(15)
            ->get(['id', 'sku', 'name', 'category_id', 'catalog_upc', 'shop_cost_cents', 'catalog_cost_cents']);

        return response()->json([
            'ok'      => true,
            'results' => $results->map(fn ($i) => [
                'id'              => $i->id,
                'sku'             => $i->sku,
                'name'            => $i->name,
                'category'        => $i->category?->name,
                'upc'             => $i->catalog_upc,
                'unit_cost_cents' => $i->effectiveCostCents(),
            ]),
        ]);
    }

    public function commit(Request $request, string $id): RedirectResponse
    {
        $tenant = tenant();
        abort_unless($tenant->retail_enabled, 403);

        $shipment = $this->findOrFail($tenant, $id);
        $this->assertDraft($shipment);

        $shipment->load(['items', 'location']);

        // MARKER-PO: a PO line can be left at 0 (nothing came), so a commit
        // that only cancels the rest or records not-accepted units is valid too
        $poWork = $shipment->items->contains(fn ($l) => $l->purchase_order_line_id && ($l->rest_action === 'cancel' || $l->rejected_quantity > 0));
        if ($shipment->received_count === 0 && ! $poWork) {
            return back()->with('flash', [
                'type' => 'error',
                'message' => 'Cannot commit a shipment with no received lines.',
            ]);
        }
        $uncataloged = $shipment->items->filter(fn ($l) => $l->purchase_order_line_id && ! $l->inventory_item_id && $l->received_quantity > 0);
        if ($uncataloged->isNotEmpty()) {
            return back()->with('flash', ['type' => 'error', 'message' => $uncataloged->pluck('name')->implode(', ') . ' isn\'t in your catalog yet. Create the item on its line, then commit.']);
        }

        // serials are a tool, never a gate: a shipment
        // commits whatever was scanned. Units without a serial show as "needs a
        // serial" on the item, to be filled in whenever it's handy.

        $tenantUser = auth('tenant')->user();
        $tenantUserId = $tenantUser?->id;

        try {
            $arrivedForPeople = []; // MARKER-SO-HANDOVER: orders to tell people about
            DB::transaction(function () use ($tenant, $shipment, $tenantUser, $tenantUserId, &$arrivedForPeople) {
                $locked = TenantInventoryReceiveShipment::where('id', $shipment->id)
                    ->where('tenant_id', $tenant->id)
                    ->lockForUpdate()
                    ->first();

                if (! $locked || $locked->status !== 'draft') {
                    throw new \RuntimeException('Shipment was already committed from another session.');
                }

                $writableLines = $shipment->items
                    ->filter(fn ($l) => \App\Services\Tenant\ReceivingFromPo::committable($l)); // MARKER-PO: accepted units only

                // MARKER-PO: freight spread onto each line's cost
                $shares = \App\Services\Tenant\ReceivingFromPo::freightShares($shipment, $shipment->items);

                foreach ($writableLines as $line) {
                    $item = TenantInventoryItem::where('id', $line->inventory_item_id)
                        ->where('tenant_id', $tenant->id)
                        ->firstOrFail();
                    $accepted = \App\Services\Tenant\ReceivingFromPo::accepted($line);
                    [$share, $landed] = $shares[$line->id] ?? [0, $line->unit_cost_cents];
                    if ($line->unit_cost_cents === null) {
                        $landed = null;
                    }
                    $line->forceFill(['freight_cents' => $share])->saveQuietly();

                    $this->inventory->incrementStock(
                        tenant: $tenant,
                        item: $item,
                        location: $shipment->location,
                        quantity: $accepted,
                        referenceType: 'receive_shipment',
                        referenceId: $shipment->id,
                        tenantUser: $tenantUser,
                        costCentsAtTime: $landed,
                        movementType: 'receive',
                        notes: "Shipment {$shipment->shipment_number}",
                    );

                    // this is the number you paid,
                    // rolled up onto the item. Catalog prefilled the line;
                    // whatever the receiver left on it is what committed
                    // (plus its share of freight).
                    if ($landed !== null) {
                        $this->inventory->recordReceivedCost(
                            $tenant, $item, $accepted, (int) $landed, 'receive'
                        );
                    }

                    // the line's serials become units in stock here.
                    if (\App\Support\SerialTracking::isTracked($item)) {
                        foreach ((array) ($line->serials ?? []) as $sn) {
                            if ($there = \App\Support\SerialTracking::inStock($tenant->id, (string) $sn)) {
                                throw new \RuntimeException("{$sn} is already in stock (" . ($there->item->name ?? 'another item') . ').');
                            }
                            \App\Http\Controllers\Tenant\InventoryUnitController::saveUnit($tenant->id, $item, $shipment->location_id, (string) $sn, [
                                'cost_cents'                => $line->unit_cost_cents,
                                'received_shipment_id'      => $shipment->id,
                                'received_shipment_item_id' => $line->id,
                            ]);
                        }
                    }
                }

                // patch-90 commit() SO arrival pass — auto-link arrived SOs.
                // so_arrivals payload from edit-page form: { '<so_id>': '<qty>' }
                // For each entry, re-fetch the SO with lock and confirm it's
                // still 'ordered' before transitioning. Partial-receipt split
                // is handled internally by SpecialOrderService::markArrived
                // when receivedQty < SO.quantity.
                $soArrivals = (array) request()->input('so_arrivals', []);
                if (!empty($soArrivals)) {
                    $soService = app(\App\Services\Tenant\SpecialOrderService::class);
                    foreach ($soArrivals as $soId => $receivedQty) {
                        $receivedQty = (int) $receivedQty;
                        if ($receivedQty < 1) {
                            continue;
                        }

                        $lockedSo = \App\Models\Tenant\TenantSpecialOrder::where('id', $soId)
                            ->where('tenant_id', $tenant->id)
                            ->where('status', \App\Models\Tenant\TenantSpecialOrder::STATUS_ORDERED)
                            ->lockForUpdate()
                            ->first();

                        if (!$lockedSo) {
                            // Drifted state (cancelled / arrived / closed between
                            // page load and commit). Skip silently.
                            continue;
                        }

                        // Clamp receivedQty to SO quantity. Excess on the shipment
                        // line goes to general stock (already incremented above).
                        $clamped = min($receivedQty, (int) $lockedSo->quantity);

                        // MARKER-SO-REPAIRS: the cost is what this shipment's
                        // line says was paid, not the estimate typed when the
                        // order was raised. Estimate only when the line has none.
                        $lineCost = $shipment->items
                            ->first(fn ($l) => $l->inventory_item_id && $l->inventory_item_id === $lockedSo->inventory_item_id && $l->unit_cost_cents !== null)
                            ?->unit_cost_cents;

                        try {
                            $soService->markArrived(
                                $lockedSo->id,
                                $clamped,
                                $lineCost !== null ? (int) $lineCost : $lockedSo->unit_cost_cents_estimated,
                                null,  // invoice number — not captured at receiving v1
                                null,  // invoice date — same
                                false, // stock already moved by this commit
                                $tenantUser,
                                $shipment->location_id, // MARKER-SO-HANDOVER: held where it landed
                            );
                            if ($lockedSo->customer_id || $lockedSo->appointment_id) {
                                $arrivedForPeople[] = $lockedSo->id;
                            }
                        } catch (\App\Services\Tenant\SpecialOrderValidationException $e) {
                            // Log + continue. Don't blow up the entire commit
                            // because one SO transition failed.
                            \Illuminate\Support\Facades\Log::warning(
                                'SO auto-link transition failed on commit',
                                ['shipment_id' => $shipment->id, 'so_id' => $lockedSo->id, 'error' => $e->getMessage()]
                            );
                        }
                    }
                }

                // MARKER-PO: each PO line takes what was accepted; its special
                // orders fill (customers first); the rest stays owed with its
                // date, or is cancelled; units not accepted stay owed when the
                // vendor is replacing them, otherwise they're cancelled too.
                $pos = app(\App\Services\Tenant\PurchaseOrderService::class);
                $touched = [];
                foreach ($shipment->items->filter(fn ($l) => $l->purchase_order_line_id) as $line) {
                    $pl = \App\Models\Tenant\TenantPurchaseOrderLine::where('id', $line->purchase_order_line_id)->lockForUpdate()->first();
                    if (! $pl) {
                        continue;
                    }
                    $po = $pl->purchaseOrder;
                    $touched[$po->id] = $po;
                    $accepted = $line->status === 'received' && $line->inventory_item_id ? \App\Services\Tenant\ReceivingFromPo::accepted($line) : 0;
                    $owedBefore = $pl->owed();
                    if ($accepted > 0) {
                        $landedUnit = ($shares[$line->id][1] ?? null) ?? $line->unit_cost_cents;
                        $arrivedForPeople = array_merge($arrivedForPeople, $pos->receive(
                            $pl, $accepted, $landedUnit, $shipment->location_id, $tenantUser,
                            $shipment->vendor_invoice_number, $shipment->vendor_invoice_date?->toDateString()
                        ));
                        $pos->event($po, "{$accepted} × {$pl->name_snapshot} received on {$shipment->shipment_number}"
                            . ($accepted > $owedBefore ? ' (' . ($accepted - $owedBefore) . ' over what was owed; extra went to stock)' : '') . '.');
                    }
                    $pl->refresh();
                    if ($line->rejected_quantity > 0) {
                        $pos->event($po, "{$line->rejected_quantity} × {$pl->name_snapshot} not accepted on {$shipment->shipment_number} ({$line->rejected_reason})"
                            . ($line->rejected_replace ? '; the vendor is replacing them.' : '.'));
                        if (! $line->rejected_replace && $pl->owed() > 0) {
                            $cut = min($pl->owed(), (int) $line->rejected_quantity);
                            $pl->forceFill(['qty_cancelled' => (int) $pl->qty_cancelled + $cut, 'cancel_reason' => $line->rejected_reason])->save();
                            if ($pl->owed() === 0) {
                                $pos->sendWaitingBack($pl, "the vendor won't replace {$pl->name_snapshot} that arrived {$line->rejected_reason} on {$po->po_number}.");
                            }
                        }
                    }
                    $pl->refresh();
                    if ($pl->owed() > 0) {
                        if ($line->rest_action === 'cancel') {
                            $pos->cancelRemaining($pl, 'cancelled at receiving', false);
                        } elseif ($line->rest_expected_date && $line->rest_expected_date->toDateString() !== ($pl->expected_date?->toDateString())) {
                            $pos->setLineDate($pl, $line->rest_expected_date->toDateString());
                        }
                    }
                }
                foreach ($touched as $po) {
                    $pos->recompute($po->fresh());
                }

                $gap = \App\Services\Tenant\ReceivingFromPo::invoiceGap($shipment, $shipment->items);
                $tol = \App\Services\Tenant\PurchaseOrderService::settings($tenant)['invoice_tolerance'];
                $shipment->invoice_mismatch_cents      = ($gap !== null && abs($gap) > $tol) ? $gap : null;
                $shipment->filled_special_order_ids    = array_values(array_unique($arrivedForPeople));

                $shipment->status                       = 'committed';
                $shipment->committed_at                 = now();
                $shipment->committed_by_tenant_user_id  = $tenantUserId;
                $shipment->save();
            });
        } catch (InsufficientStockException | InvalidQuantityException | TenantMismatchException $e) {
            Log::warning('Receive commit failed', [
                'tenant_id' => $tenant->id, 'shipment_id' => $shipment->id, 'error' => $e->getMessage(),
            ]);
            return back()->with('flash', ['type' => 'error', 'message' => 'Commit failed: ' . $e->getMessage()]);
        } catch (\Throwable $e) {
            Log::error('Receive commit unexpected error', [
                'tenant_id' => $tenant->id, 'shipment_id' => $shipment->id,
                'error' => $e->getMessage(), 'trace' => $e->getTraceAsString(),
            ]);
            return back()->with('flash', ['type' => 'error', 'message' => 'Commit failed: ' . $e->getMessage()]);
        }

        $notify = \App\Services\Tenant\PurchaseOrderService::settings($tenant)['notify_after_receive'];

        return redirect()
            ->route('tenant.inventory.receiving.show', ['id' => $shipment->id])
            ->with('flash', ['type' => 'success', 'message' => 'Shipment committed.'])
            ->with('so_notify', $notify ? array_values(array_unique($arrivedForPeople)) : []); // opens "Tell customers" on the shipment page
    }

    public function quickShowItem(string $id): JsonResponse
    {
        $tenant = tenant();
        abort_unless($tenant->retail_enabled, 403, 'Retail not enabled.');

        $item = TenantInventoryItem::with('category:id,name')
            ->where('id', $id)
            ->where('tenant_id', $tenant->id)
            ->first();

        if (! $item) {
            return response()->json(['ok' => false, 'message' => 'Item not found.'], 404);
        }

        $categories = \App\Models\Tenant\TenantInventoryCategory::where('tenant_id', $tenant->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'ok'   => true,
            'item' => [
                'id'                     => $item->id,
                'category_id'            => $item->category_id,
                'category_name'          => $item->category?->name,
                'sku'                    => $item->sku,
                'name'                   => $item->name,
                'description'            => $item->description,
                'shop_cost_dollars'      => $item->shop_cost_cents !== null ? number_format($item->shop_cost_cents / 100, 2, '.', '') : null,
                'shop_sell_price_dollars'=> $item->shop_sell_price_cents !== null ? number_format($item->shop_sell_price_cents / 100, 2, '.', '') : null,
                'shop_case_quantity'     => $item->shop_case_quantity,
                'shop_reorder_threshold' => $item->shop_reorder_threshold,
                'shop_reorder_quantity'  => $item->shop_reorder_quantity,
                'shop_bin_location'      => $item->shop_bin_location,
                'is_active'              => (bool) $item->is_active,
                'allow_oversell'         => (bool) $item->allow_oversell,
                'catalog_upc'            => $item->catalog_upc,
                'catalog_synced_at'      => $item->catalog_synced_at?->toIso8601String(),
            ],
            'categories' => $categories,
        ]);
    }

    public function quickUpdateItem(Request $request, string $id): JsonResponse
    {
        $tenant = tenant();
        abort_unless($tenant->retail_enabled, 403, 'Retail not enabled.');

        $item = TenantInventoryItem::where('id', $id)
            ->where('tenant_id', $tenant->id)
            ->first();

        if (! $item) {
            return response()->json(['ok' => false, 'message' => 'Item not found.'], 404);
        }

        $data = $request->validate([
            'category_id'             => ['required', 'uuid'],
            'sku'                     => ['required', 'string', 'max:64'],
            'name'                    => ['required', 'string', 'max:255'],
            'description'             => ['nullable', 'string'],
            'shop_cost_dollars'       => ['nullable', 'numeric', 'min:0'],
            'shop_sell_price_dollars' => ['nullable', 'numeric', 'min:0'],
            'shop_case_quantity'      => ['nullable', 'integer', 'min:1'],
            'shop_reorder_threshold'  => ['nullable', 'integer', 'min:0'],
            'shop_reorder_quantity'   => ['nullable', 'integer', 'min:1'],
            'shop_bin_location'       => ['nullable', 'string', 'max:50'],
            'is_active'               => ['nullable', 'boolean'],
            'allow_oversell'          => ['nullable', 'boolean'],
        ]);

        \App\Models\Tenant\TenantInventoryCategory::where('tenant_id', $tenant->id)
            ->where('id', $data['category_id'])
            ->firstOrFail();

        if ($data['sku'] !== $item->sku) {
            $taken = TenantInventoryItem::where('tenant_id', $tenant->id)
                ->where('sku', $data['sku'])
                ->where('id', '!=', $item->id)
                ->exists();
            if ($taken) {
                return response()->json([
                    'ok' => false,
                    'message' => "SKU '{$data['sku']}' is already taken.",
                ], 422);
            }
        }

        $item->update([
            'category_id'            => $data['category_id'],
            'sku'                    => $data['sku'],
            'name'                   => $data['name'],
            'description'            => $data['description'] ?? null,
            'shop_cost_cents'        => isset($data['shop_cost_dollars']) ? (int) round($data['shop_cost_dollars'] * 100) : null,
            'shop_sell_price_cents'  => isset($data['shop_sell_price_dollars']) ? (int) round($data['shop_sell_price_dollars'] * 100) : null,
            'shop_case_quantity'     => $data['shop_case_quantity'] ?? null,
            'shop_reorder_threshold' => $data['shop_reorder_threshold'] ?? null,
            'shop_reorder_quantity'  => $data['shop_reorder_quantity'] ?? null,
            'shop_bin_location'      => $data['shop_bin_location'] ?? null,
            'is_active'              => (bool) ($data['is_active'] ?? true),
            'allow_oversell'         => (bool) ($data['allow_oversell'] ?? true),
        ]);

        return response()->json([
            'ok'   => true,
            'item' => [
                'id'   => $item->id,
                'sku'  => $item->sku,
                'name' => $item->name,
            ],
        ]);
    }

    public function quickCreateItem(Request $request, string $id): JsonResponse
    {
        $tenant = tenant();
        abort_unless($tenant->retail_enabled, 403, 'Retail not enabled.');

        $shipment = $this->findOrFail($tenant, $id);
        $this->assertDraftJson($shipment);

        $data = $request->validate([
            'category_id'             => ['required', 'uuid'],
            'sku'                     => ['required', 'string', 'max:64'],
            'name'                    => ['required', 'string', 'max:255'],
            'description'             => ['nullable', 'string'],
            'shop_cost_dollars'       => ['nullable', 'numeric', 'min:0'],
            'shop_sell_price_dollars' => ['nullable', 'numeric', 'min:0'],
            'shop_case_quantity'      => ['nullable', 'integer', 'min:1'],
            'shop_reorder_threshold'  => ['nullable', 'integer', 'min:0'],
            'shop_reorder_quantity'   => ['nullable', 'integer', 'min:1'],
            'shop_bin_location'       => ['nullable', 'string', 'max:50'],
            'is_active'               => ['nullable', 'boolean'],
            'allow_oversell'          => ['nullable', 'boolean'],
            'add_as_line'             => ['nullable', 'boolean'],
            'received_quantity'       => ['nullable', 'integer', 'min:0', 'max:99999'],
            'line_id'                 => ['nullable', 'uuid'], // MARKER-PO: the line this item is for
        ]);

        \App\Models\Tenant\TenantInventoryCategory::where('tenant_id', $tenant->id)
            ->where('id', $data['category_id'])
            ->firstOrFail();

        $skuTaken = TenantInventoryItem::where('tenant_id', $tenant->id)
            ->where('sku', $data['sku'])
            ->exists();
        if ($skuTaken) {
            return response()->json([
                'ok' => false,
                'message' => "SKU '{$data['sku']}' already exists.",
            ], 422);
        }

        $result = DB::transaction(function () use ($tenant, $shipment, $data) {
            $item = TenantInventoryItem::create([
                'tenant_id'              => $tenant->id,
                'category_id'            => $data['category_id'],
                'sku'                    => $data['sku'],
                'name'                   => $data['name'],
                'description'            => $data['description'] ?? null,
                'shop_cost_cents'        => isset($data['shop_cost_dollars']) ? (int) round($data['shop_cost_dollars'] * 100) : null,
                'shop_sell_price_cents'  => isset($data['shop_sell_price_dollars']) ? (int) round($data['shop_sell_price_dollars'] * 100) : null,
                'shop_case_quantity'     => $data['shop_case_quantity'] ?? null,
                'shop_reorder_threshold' => $data['shop_reorder_threshold'] ?? null,
                'shop_reorder_quantity'  => $data['shop_reorder_quantity'] ?? null,
                'shop_bin_location'      => $data['shop_bin_location'] ?? null,
                'is_active'              => (bool) ($data['is_active'] ?? true),
                'allow_oversell'         => (bool) ($data['allow_oversell'] ?? true),
            ]);

            $line = null;
            // MARKER-PO: an existing line that wasn't in the catalog becomes this
            // item (and so does its PO line and the special orders riding on it)
            $existing = ! empty($data['line_id'])
                ? TenantInventoryReceiveShipmentItem::where('shipment_id', $shipment->id)->where('tenant_id', $tenant->id)->whereNull('inventory_item_id')->find($data['line_id'])
                : null;
            if ($existing) {
                $existing->inventory_item_id = $item->id;
                $existing->name = $item->name;
                $existing->sku = $item->sku;
                if ($existing->status === 'unexpected_pending') {
                    $existing->status = 'received';
                }
                if ($existing->unit_cost_cents === null) {
                    $existing->unit_cost_cents = $item->shop_cost_cents;
                }
                $existing->save();
                if ($existing->purchase_order_line_id) {
                    $pl = \App\Models\Tenant\TenantPurchaseOrderLine::find($existing->purchase_order_line_id);
                    if ($pl && ! $pl->inventory_item_id) {
                        $pl->forceFill(['inventory_item_id' => $item->id])->save();
                        \App\Models\Tenant\TenantSpecialOrder::where('purchase_order_line_id', $pl->id)->whereNull('inventory_item_id')
                            ->update(['inventory_item_id' => $item->id]);
                    }
                }
                $line = $existing;
            } elseif (! empty($data['add_as_line'])) {
                $receivedQty = (int) ($data['received_quantity'] ?? 1);
                $line = new TenantInventoryReceiveShipmentItem();
                $line->tenant_id         = $tenant->id;
                $line->shipment_id       = $shipment->id;
                $line->inventory_item_id = $item->id;
                $line->name              = $item->name;
                $line->sku               = $item->sku;
                $line->expected_quantity = 0;
                $line->received_quantity = $receivedQty;
                $line->status            = 'received';
                $line->unit_cost_cents   = $item->shop_cost_cents;
                $line->total_cost_cents  = $line->unit_cost_cents ? $line->unit_cost_cents * $receivedQty : null;
                $line->save();
            }

            return ['item' => $item, 'line' => $line];
        });

        $line = $result['line'];
        $shipment->refresh();

        return response()->json([
            'ok'     => true,
            'item'   => [
                'id'   => $result['item']->id,
                'sku'  => $result['item']->sku,
                'name' => $result['item']->name,
            ],
            'line'   => $line ? $this->serializeLine($line->fresh(['item.category'])) : null,
            'totals' => $this->serializeTotals($shipment),
        ] + array_intersect_key($this->payload($shipment)->getData(true), ['tbody' => 1, 'summary' => 1]));
    }

    public function categoriesForModal(): JsonResponse
    {
        $tenant = tenant();
        abort_unless($tenant->retail_enabled, 403, 'Retail not enabled.');

        $categories = \App\Models\Tenant\TenantInventoryCategory::where('tenant_id', $tenant->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json(['ok' => true, 'categories' => $categories]);
    }

    private function findOrFail($tenant, string $id): TenantInventoryReceiveShipment
    {
        $shipment = TenantInventoryReceiveShipment::where('id', $id)
            ->where('tenant_id', $tenant->id)
            ->first();
        abort_unless($shipment, 404);
        return $shipment;
    }

    private function assertDraft(TenantInventoryReceiveShipment $shipment): void
    {
        abort_unless($shipment->isDraft(), 422, 'Shipment is not editable.');
    }

    private function assertDraftJson(TenantInventoryReceiveShipment $shipment): void
    {
        if (! $shipment->isDraft()) {
            abort(response()->json(['ok' => false, 'message' => 'Shipment is not editable.'], 422));
        }
    }

    private function generateShipmentNumber($tenant): string
    {
        // MARKER-PO: shared, and skips a number a deleted draft still holds
        return \App\Services\Tenant\ReceivingFromPo::number($tenant);
    }

    private function serializeLine(TenantInventoryReceiveShipmentItem $line): array
    {
        return [
            'id'                => $line->id,
            'inventory_item_id' => $line->inventory_item_id,
            'name'              => $line->name,
            'sku'               => $line->sku,
            'upc'               => $line->upc,
            'category'          => $line->item?->category?->name,
            'expected_quantity' => $line->expected_quantity,
            'received_quantity' => $line->received_quantity,
            'status'            => $line->status,
            'status_label'      => $this->statusLabel($line->status),
            'unit_cost_cents'   => $line->unit_cost_cents,
            'total_cost_cents'  => $line->total_cost_cents,
            'is_unexpected'     => $line->isUnexpected(),
            'is_matched'        => $line->isMatched(),
        ];
    }

    private function serializeTotals(TenantInventoryReceiveShipment $shipment): array
    {
        $writable = $shipment->items
            ->filter(fn ($l) => $l->status === 'received'
                && $l->inventory_item_id !== null
                && $l->received_quantity > 0);

        return [
            'expected'     => $shipment->expected_count,
            'received'     => $shipment->received_count,
            'backorder'    => $shipment->backorder_count,
            'unexpected'   => $shipment->unexpected_count,
            'commit_lines' => $writable->count(),
            'commit_units' => (int) $writable->sum('received_quantity'),
            'can_commit'   => $writable->count() > 0,
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'expected'           => 'Expected',
            'received'           => 'Received',
            'backorder'          => 'Backorder',
            'unexpected_pending' => 'Pending',
            'unexpected_added'   => 'Added',
            'unexpected_hold'    => 'On hold',
            default              => $status,
        };
    }
}
