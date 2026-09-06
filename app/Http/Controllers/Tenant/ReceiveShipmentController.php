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

    public function store(Request $request): RedirectResponse
    {
        $tenant = tenant();
        abort_unless($tenant->retail_enabled, 403);

        $data = $request->validate([
            'shipment_number'    => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9\-\/_.]+$/'],
            'location_id'        => ['required', 'uuid', \Illuminate\Validation\Rule::exists('tenant_locations', 'id')
                ->where(fn ($q) => $q->where('tenant_id', $tenant->id))], // MARKER-EXISTS-TENANT-SCOPE
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

        return view('tenant.inventory.receiving.edit', [
            'shipment'        => $shipment,
            'locations'       => $locations,
            'pageTitle'       => 'Editing ' . $shipment->shipment_number,
            'matchedSos'      => $matchedSos,
            'neededHintCount' => $neededHintCount,
        ]);
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
        ]);

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
        $shipment->distributor_name    = $data['distributor_name'] ?? null;
        $shipment->distributor_code    = $data['distributor_code'] ?? null;
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

        $shipment->refresh();

        return response()->json([
            'ok'     => true,
            'line'   => $this->serializeLine($line->fresh(['item.category'])),
            'totals' => $this->serializeTotals($shipment),
        ]);
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
        ]);

        if (array_key_exists('unit_cost_dollars', $data) && $data['unit_cost_dollars'] !== null && $data['unit_cost_dollars'] !== '') {
            $cleaned = preg_replace('/[^0-9.\-]/', '', $data['unit_cost_dollars']);
            $data['unit_cost_cents'] = (int) round(((float) $cleaned) * 100);
        }

        if (array_key_exists('expected_quantity', $data) && $data['expected_quantity'] !== null) {
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

        $line->total_cost_cents = $line->unit_cost_cents
            ? $line->unit_cost_cents * $line->received_quantity
            : null;
        $line->save();

        $shipment->refresh();

        return response()->json([
            'ok'     => true,
            'line'   => $this->serializeLine($line->fresh(['item.category'])),
            'totals' => $this->serializeTotals($shipment),
        ]);
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
        $shipment->refresh();

        return response()->json(['ok' => true, 'totals' => $this->serializeTotals($shipment)]);
    }

    public function searchItems(Request $request): JsonResponse
    {
        $tenant = tenant();
        abort_unless($tenant->retail_enabled, 403, 'Retail not enabled.');

        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['ok' => true, 'results' => []]);
        }

        $results = TenantInventoryItem::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->where(function ($w) use ($q) {
                $w->where('sku', 'like', "{$q}%")
                  ->orWhere('catalog_upc', $q)
                  ->orWhere('name', 'like', "%{$q}%");
            })
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

        if ($shipment->received_count === 0) {
            return back()->with('flash', [
                'type' => 'error',
                'message' => 'Cannot commit a shipment with no received lines.',
            ]);
        }

        $tenantUser = auth('tenant')->user();
        $tenantUserId = $tenantUser?->id;

        try {
            DB::transaction(function () use ($tenant, $shipment, $tenantUser, $tenantUserId) {
                $locked = TenantInventoryReceiveShipment::where('id', $shipment->id)
                    ->where('tenant_id', $tenant->id)
                    ->lockForUpdate()
                    ->first();

                if (! $locked || $locked->status !== 'draft') {
                    throw new \RuntimeException('Shipment was already committed from another session.');
                }

                $writableLines = $shipment->items
                    ->filter(fn ($l) => $l->status === 'received'
                        && $l->inventory_item_id !== null
                        && $l->received_quantity > 0);

                foreach ($writableLines as $line) {
                    $item = TenantInventoryItem::where('id', $line->inventory_item_id)
                        ->where('tenant_id', $tenant->id)
                        ->firstOrFail();

                    $this->inventory->incrementStock(
                        tenant: $tenant,
                        item: $item,
                        location: $shipment->location,
                        quantity: $line->received_quantity,
                        referenceType: 'receive_shipment',
                        referenceId: $shipment->id,
                        tenantUser: $tenantUser,
                        costCentsAtTime: $line->unit_cost_cents,
                        movementType: 'receive',
                        notes: "Shipment {$shipment->shipment_number}",
                    );

                    // MARKER-RECEIVED-COST — this is the number you paid,
                    // rolled up onto the item. Catalog prefilled the line;
                    // whatever the receiver left on it is what committed.
                    if ($line->unit_cost_cents !== null) {
                        $this->inventory->recordReceivedCost(
                            $tenant, $item, (int) $line->received_quantity, (int) $line->unit_cost_cents, 'receive'
                        );
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

                        try {
                            $soService->markArrived(
                                $lockedSo->id,
                                $clamped,
                                $lockedSo->unit_cost_cents_estimated,
                                null,  // invoice number — not captured at receiving v1
                                null,  // invoice date — same
                            );
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

        return redirect()
            ->route('tenant.inventory.receiving.show', ['id' => $shipment->id])
            ->with('flash', ['type' => 'success', 'message' => 'Shipment committed.']);
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
            if (! empty($data['add_as_line'])) {
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
        ]);
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
        $today = now($tenant->timezone ?? 'UTC')->format('Ymd');
        $prefix = "RCV-{$today}-";
        $count = TenantInventoryReceiveShipment::where('tenant_id', $tenant->id)
            ->where('shipment_number', 'like', "{$prefix}%")
            ->count();
        return $prefix . str_pad((string) ($count + 1), 3, '0', STR_PAD_LEFT);
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
