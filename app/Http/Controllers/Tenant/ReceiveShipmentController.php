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

    public function create(Request $request): View|RedirectResponse
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

        return view('tenant.inventory.receiving.create', [
            'locations'         => $locations,
            'defaultLocationId' => $tenant->defaultLocation?->id ?? $locations->first()->id,
            'defaultNumber'     => $this->generateShipmentNumber($tenant),
            'today'             => now($tenant->timezone ?? 'UTC')->toDateString(),
            'pageTitle'         => 'New shipment',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = tenant();
        abort_unless($tenant->retail_enabled, 403);

        $data = $request->validate([
            'shipment_number'    => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9\-\/_.]+$/'],
            'location_id'        => ['required', 'uuid', 'exists:tenant_locations,id'],
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

    public function show(string $subdomain, string $id): View|RedirectResponse
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

    public function edit(string $subdomain, string $id): View|RedirectResponse
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

        return view('tenant.inventory.receiving.edit', [
            'shipment'  => $shipment,
            'locations' => $locations,
            'pageTitle' => 'Editing ' . $shipment->shipment_number,
        ]);
    }

    public function update(Request $request, string $subdomain, string $id): RedirectResponse
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
            'notes'              => ['nullable', 'string', 'max:2000'],
        ]);

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

    public function destroy(string $subdomain, string $id): RedirectResponse
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

    public function addItem(Request $request, string $subdomain, string $id): JsonResponse
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
        ]);

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

    public function updateItem(Request $request, string $subdomain, string $id, string $itemId): JsonResponse
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
            'notes'             => ['nullable', 'string', 'max:500'],
        ]);

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

    public function removeItem(string $subdomain, string $id, string $itemId): JsonResponse
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

    public function commit(Request $request, string $subdomain, string $id): RedirectResponse
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
