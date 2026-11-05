<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Tenant\Concerns\GuardsRetailAccess;
use App\Models\Tenant\TenantInventoryItem;
use App\Models\Tenant\TenantLocation;
use App\Models\Tenant\TenantPurchaseOrder as PO;
use App\Models\Tenant\TenantPurchaseOrderLine as Line;
use App\Models\Tenant\TenantSpecialOrder;
use App\Models\Tenant\TenantVendor;
use App\Services\Tenant\PurchaseOrderException;
use App\Services\Tenant\PurchaseOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * MARKER-PO: purchase orders. Every action answers JSON to the page's fetch
 * calls and redirects a plain form; refusals say why in plain words.
 */
class PurchaseOrderController extends Controller
{
    use GuardsRetailAccess;

    public function __construct(private PurchaseOrderService $service) {}

    private function canCost(): bool
    {
        $u = Auth::guard('tenant')->user();

        return (bool) ($u && $u->canAccessSection('cost_margins'));
    }

    private function po(string $id): PO
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        return PO::where('tenant_id', $tenant->id)->findOrFail($id);
    }

    private function line(PO $po, string $lineId): Line
    {
        return Line::where('purchase_order_id', $po->id)->findOrFail($lineId);
    }

    private function fail(Request $request, string $msg, int $code = 422): RedirectResponse|JsonResponse
    {
        return $request->wantsJson()
            ? response()->json(['ok' => false, 'error' => $msg, 'message' => $msg], $code)
            : back()->with('flash', ['type' => 'error', 'message' => $msg]);
    }

    private function done(Request $request, PO $po, string $msg, array $extra = []): RedirectResponse|JsonResponse
    {
        return $request->wantsJson()
            ? response()->json(array_merge(['ok' => true, 'message' => $msg], $extra))
            : redirect()->route('tenant.purchase-orders.show', ['id' => $po->id])->with('flash', ['type' => 'success', 'message' => $msg]);
    }

    private static function cents($v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }

        return (int) round(((float) preg_replace('/[^0-9.]/', '', (string) $v)) * 100);
    }

    // ────────────────────────────────────────────────────────

    public function index(Request $request): View
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        $tabs = [
            'draft'    => [PO::DRAFT],
            'open'     => PO::OPEN,
            'received' => [PO::RECEIVED],
            'closed'   => [PO::CLOSED_SHORT, PO::CANCELLED],
        ];
        $tab = array_key_exists($request->query('tab'), $tabs) ? $request->query('tab') : 'open';

        $base = PO::where('tenant_id', $tenant->id);
        $counts = [];
        foreach ($tabs as $k => $st) {
            $counts[$k] = (clone $base)->whereIn('status', $st)->count();
        }

        $q = (clone $base)->whereIn('status', $tabs[$tab])->with(['vendor:id,name', 'location:id,name', 'lines']);
        if ($request->filled('vendor')) {
            $q->where('vendor_id', $request->query('vendor'));
        }
        if ($request->filled('location')) {
            $q->where('location_id', $request->query('location'));
        }
        $q->orderByDesc($tab === 'draft' ? 'updated_at' : 'placed_at');
        $pos = $q->limit(300)->get();

        $open = (clone $base)->whereIn('status', PO::OPEN)->with('lines')->get();
        $summary = [
            'open'    => $open->count(),
            'value'   => $open->sum(fn ($p) => $p->owedValueCents()),
            'overdue' => $open->filter(fn ($p) => $p->isOverdue())->count(),
        ];

        return view('tenant.purchase-orders.index', [
            'pos' => $pos, 'tab' => $tab, 'counts' => $counts, 'summary' => $summary,
            'vendors' => TenantVendor::where('tenant_id', $tenant->id)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'locations' => TenantLocation::where('tenant_id', $tenant->id)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'drafts' => PO::where('tenant_id', $tenant->id)->where('status', PO::DRAFT)->get(['id', 'po_number', 'vendor_id', 'location_id']),
            'soCount' => TenantSpecialOrder::where('tenant_id', $tenant->id)->open()->count(),
            'canCost' => $this->canCost(),
        ]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);
        $data = $request->validate([
            'vendor_id'   => ['required', 'uuid'],
            'location_id' => ['required', 'uuid'],
        ]);
        try {
            $po = $this->service->createDraft($tenant, $data['vendor_id'], $data['location_id']);
        } catch (PurchaseOrderException $e) {
            return $this->fail($request, $e->getMessage());
        }

        return $request->wantsJson()
            ? response()->json(['ok' => true, 'url' => route('tenant.purchase-orders.show', ['id' => $po->id])])
            : redirect()->route('tenant.purchase-orders.show', ['id' => $po->id]);
    }

    public function show(string $id): View
    {
        $po = $this->po($id);
        $po->load(['vendor', 'location', 'lines.specialOrders.customer', 'lines.specialOrders.appointment', 'lines.item', 'events.user', 'placedBy']);
        $tenant = tenant();

        $hand = [];
        $itemIds = $po->lines->pluck('inventory_item_id')->filter()->all();
        if ($itemIds) {
            $hand = \App\Models\Tenant\TenantInventoryItemLocation::whereIn('inventory_item_id', $itemIds)
                ->where('location_id', $po->location_id)->get()
                ->mapWithKeys(fn ($r) => [$r->inventory_item_id => (int) $r->computed_stock_count])->all();
        }
        $shipments = \App\Models\Tenant\TenantInventoryReceiveShipment::where('tenant_id', $tenant->id)
            ->whereIn('id', \App\Models\Tenant\TenantInventoryReceiveShipmentItem::whereIn('purchase_order_line_id', $po->lines->pluck('id'))->select('shipment_id'))
            ->orderByDesc('received_date')->get();
        $draftShipment = $shipments->firstWhere('status', 'draft');

        return view('tenant.purchase-orders.show', [
            'po' => $po, 'hand' => $hand, 'shipments' => $shipments, 'draftShipment' => $draftShipment,
            'canCost' => $this->canCost(),
            'defaultExpected' => $po->vendor ? PurchaseOrderService::defaultExpected($po->vendor, $po) : null,
            'leadNote' => $po->vendor ? PurchaseOrderService::leadTimeNote($po->vendor) : '',
            'freeFreight' => $po->vendor?->free_freight_cents,
        ]);
    }

    public function update(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $po = $this->po($id);
        $data = $request->validate([
            'notes_vendor'   => ['nullable', 'string', 'max:2000'],
            'notes_internal' => ['nullable', 'string', 'max:2000'],
        ]);
        $fill = ['notes_internal' => $data['notes_internal'] ?? null];
        if ($po->isDraft()) {
            $fill['notes_vendor'] = $data['notes_vendor'] ?? null;
        }
        $po->forceFill($fill)->save();

        return $this->done($request, $po, 'Saved.');
    }

    public function destroy(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $po = $this->po($id);
        try {
            $n = $this->service->deleteDraft($po);
        } catch (PurchaseOrderException $e) {
            return $this->fail($request, $e->getMessage());
        }
        $msg = "{$po->po_number} deleted." . ($n ? " {$n} special order" . ($n === 1 ? '' : 's') . ' back to Needed.' : '');

        return $request->wantsJson()
            ? response()->json(['ok' => true, 'message' => $msg, 'url' => route('tenant.purchase-orders.index', ['tab' => 'draft'])])
            : redirect()->route('tenant.purchase-orders.index', ['tab' => 'draft'])->with('flash', ['type' => 'success', 'message' => $msg]);
    }

    /** Item search for a PO: their part number and cost, and whether it's already on order. */
    public function searchItems(Request $request, string $id): JsonResponse
    {
        $po = $this->po($id);
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['ok' => true, 'results' => []]);
        }
        $itemQuery = TenantInventoryItem::where('tenant_id', $po->tenant_id)->where('is_active', true);
        $hit = \App\Support\InventorySearch::apply($itemQuery, $po->tenant_id, $q);
        \App\Support\InventorySearch::rank($itemQuery, $hit['used']);
        $items = $itemQuery->limit(12)->get();
        $canCost = $this->canCost();

        return response()->json(['ok' => true, 'results' => $items->map(function ($i) use ($po, $canCost) {
            $t = PurchaseOrderService::vendorTerms($po->vendor_id, $i->id);
            $chk = $this->service->checkItem($po, $i->id);

            return [
                'id' => $i->id, 'name' => $i->name, 'sku' => $i->sku, 'vendor_sku' => $t['sku'],
                'cost_cents' => $canCost ? $t['cost'] : null,
                'here' => (bool) $chk['here'], 'elsewhere' => $chk['elsewhere'],
            ];
        })->values()]);
    }

    public function addLine(Request $request, string $id): JsonResponse
    {
        $po = $this->po($id);
        $data = $request->validate([
            'item_id' => ['nullable', 'uuid'],
            'name'    => ['nullable', 'string', 'max:255'],
            'qty'     => ['required', 'integer', 'min:1', 'max:99999'],
            'cost'    => ['nullable', 'string', 'max:20'],
            'confirm' => ['nullable', 'in:raise,add'],
        ]);
        $after = ! $po->isDraft();

        if (! empty($data['item_id'])) {
            $chk = $this->service->checkItem($po, $data['item_id']);
            if ($chk['here'] && ($data['confirm'] ?? null) !== 'add') {
                if (($data['confirm'] ?? null) === 'raise' && $po->isDraft()) {
                    $line = $chk['here'];
                    $line->forceFill(['qty_ordered' => (int) $line->qty_ordered + (int) $data['qty']])->save();

                    return response()->json(['ok' => true, 'message' => "{$line->name_snapshot} raised to {$line->qty_ordered}."]);
                }
                if ($po->isDraft()) {
                    return response()->json(['ok' => false, 'needs_confirm' => 'here', 'line_qty' => $chk['here']->qty_ordered, 'name' => $chk['here']->name_snapshot]);
                }
            }
            if ($chk['elsewhere'] && ! ($data['confirm'] ?? null)) {
                return response()->json(['ok' => false, 'needs_confirm' => 'elsewhere', 'elsewhere' => $chk['elsewhere']]);
            }
        }

        try {
            $line = $this->service->addLine($po, $data['item_id'] ?? null, $data['name'] ?? null, (int) $data['qty'],
                $this->canCost() ? self::cents($data['cost'] ?? null) : null, $after);
        } catch (PurchaseOrderException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'message' => "{$line->name_snapshot} added."]);
    }

    public function updateLine(Request $request, string $id, string $lineId): JsonResponse
    {
        $po = $this->po($id);
        $line = $this->line($po, $lineId);
        $data = $request->validate(['qty' => ['nullable', 'integer', 'min:0', 'max:99999'], 'cost' => ['nullable', 'string', 'max:20']]);
        $costGiven = $this->canCost() && $request->has('cost');
        try {
            $this->service->updateLine($line, array_key_exists('qty', $data) && $data['qty'] !== null ? (int) $data['qty'] : null,
                $costGiven ? self::cents($data['cost'] ?? null) : null, $costGiven);
        } catch (PurchaseOrderException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage(), 'qty' => $line->fresh()->qty_ordered], 422);
        }

        return response()->json(['ok' => true]);
    }

    public function removeLine(Request $request, string $id, string $lineId): JsonResponse
    {
        $po = $this->po($id);
        $line = $this->line($po, $lineId);
        try {
            $n = $this->service->removeLine($line);
        } catch (PurchaseOrderException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'message' => "{$line->name_snapshot} removed." . ($n ? " {$n} special order" . ($n === 1 ? '' : 's') . ' still needed.' : '')]);
    }

    public function place(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $po = $this->po($id);
        $data = $request->validate([
            'via'              => ['required', Rule::in(array_keys(PO::VIA))],
            'email'            => ['nullable', 'string', 'max:128'],
            'vendor_reference' => ['nullable', 'string', 'max:64'],
            'expected_date'    => ['required', 'date', 'after_or_equal:today'],
        ], ['expected_date.required' => 'Set when you expect it.', 'expected_date.after_or_equal' => 'The expected date can\'t be in the past.']);
        try {
            $r = $this->service->place($po, $data['via'], $data['email'] ?? null, $data['vendor_reference'] ?? null, $data['expected_date']);
        } catch (PurchaseOrderException $e) {
            return $this->fail($request, $e->getMessage());
        }
        $msg = "{$po->po_number} placed"
            . ($data['via'] === 'email' ? ($r['emailed'] ? ' and emailed' : '. The email didn\'t go through — use Email again, or send it another way') : '')
            . ($r['ordered'] ? " · {$r['ordered']} special order" . ($r['ordered'] === 1 ? '' : 's') . ' marked Ordered' : '') . '.';

        return $this->done($request, $po, $msg);
    }

    public function email(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $po = $this->po($id);
        $data = $request->validate(['email' => ['required', 'email', 'max:128']]);
        if ($po->isDraft()) {
            return $this->fail($request, 'Place it first; placing it can email it.');
        }
        $ok = $this->service->emailPo($po, $data['email'], (bool) $po->changed_since_placed);
        if ($ok && $po->changed_since_placed) {
            $po->forceFill(['changed_since_placed' => false, 'changes_note' => null])->save();
        }

        return $ok ? $this->done($request, $po, "Emailed to {$data['email']}.") : $this->fail($request, 'The email didn\'t go through. Nothing was sent.');
    }

    public function told(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $po = $this->po($id);
        $this->service->markVendorTold($po);

        return $this->done($request, $po, 'Marked the vendor told.');
    }

    public function setDate(Request $request, string $id, string $lineId): JsonResponse
    {
        $po = $this->po($id);
        $line = $this->line($po, $lineId);
        $data = $request->validate(['date' => ['required', 'date']]);
        try {
            $waiting = $this->service->setLineDate($line, $data['date']);
        } catch (PurchaseOrderException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
        $tell = $waiting->filter(fn ($so) => $so->customer_id)->pluck('id')->values();

        return response()->json(['ok' => true, 'message' => 'Expected date updated.', 'tell' => $tell]);
    }

    public function cancelRest(Request $request, string $id, string $lineId): JsonResponse
    {
        $po = $this->po($id);
        $line = $this->line($po, $lineId);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        if (! $po->isOpen()) {
            return response()->json(['ok' => false, 'error' => "{$po->po_number} is {$po->statusLabel()}."], 422);
        }
        $n = $this->service->cancelRemaining($line, $data['reason'] ?? null);

        return response()->json(['ok' => true, 'message' => "{$n} × {$line->name_snapshot} cancelled."]);
    }

    public function closeShort(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $po = $this->po($id);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        try {
            $this->service->closeShort($po->load('lines'), $data['reason'] ?? null);
        } catch (PurchaseOrderException $e) {
            return $this->fail($request, $e->getMessage());
        }

        return $this->done($request, $po, "{$po->po_number} closed short.");
    }

    public function cancel(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $po = $this->po($id);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        try {
            $this->service->cancelPo($po->load('lines'), $data['reason'] ?? null);
        } catch (PurchaseOrderException $e) {
            return $this->fail($request, $e->getMessage());
        }

        return $this->done($request, $po, "{$po->po_number} cancelled.");
    }

    /** Receive: a new shipment at the PO's location with every line still owed filled in. */
    public function receive(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $po = $this->po($id);
        if (! $po->isOpen()) {
            return $this->fail($request, "{$po->po_number} is {$po->statusLabel()}; nothing is owed.");
        }
        $existing = \App\Models\Tenant\TenantInventoryReceiveShipment::where('tenant_id', $po->tenant_id)->where('status', 'draft')
            ->whereIn('id', \App\Models\Tenant\TenantInventoryReceiveShipmentItem::whereIn('purchase_order_line_id', $po->lines()->select('id'))->select('shipment_id'))
            ->first();
        $shipment = $existing ?? \App\Services\Tenant\ReceivingFromPo::start(tenant(), $po->location_id, $po->vendor_id, collect([$po->load('lines')]));
        $url = route('tenant.inventory.receiving.edit', ['id' => $shipment->id]);

        return $request->wantsJson() ? response()->json(['ok' => true, 'url' => $url]) : redirect($url);
    }

    public function pdf(Request $request, string $id)
    {
        $po = $this->po($id);
        $bytes = $this->service->pdfBytes($po, (bool) $po->changed_since_placed);
        $disp = $request->boolean('download') ? 'attachment' : 'inline';

        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "{$disp}; filename=\"{$po->po_number}.pdf\""]);
    }

    public function csv(string $id)
    {
        $po = $this->po($id)->load('lines');
        $rows = [['Part number', 'Description', 'Quantity']];
        foreach ($po->lines as $l) {
            $qty = $po->isDraft() ? $l->qty_ordered : $l->owed();
            if ($qty > 0) {
                $rows[] = [$l->vendor_sku ?: '', $l->name_snapshot, $qty];
            }
        }
        $out = fopen('php://temp', 'r+');
        foreach ($rows as $r) {
            fputcsv($out, $r);
        }
        rewind($out);
        $csv = stream_get_contents($out);

        return response($csv, 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => "attachment; filename=\"{$po->po_number}.csv\""]);
    }

    /** Special orders board → PO. */
    public function fromSpecialOrders(Request $request): JsonResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:200'], 'ids.*' => ['uuid']]);
        $r = $this->service->addSpecialOrders($tenant, $data['ids']);
        $parts = collect($r['pos'])->map(fn ($x) => "{$x['count']} to {$x['po']->po_number}")->values()->all();
        if (! $parts) {
            return response()->json(['ok' => false, 'error' => $r['skipped'] ? implode('; ', $r['skipped']) . '.' : 'Nothing to add.'], 422);
        }
        $first = collect($r['pos'])->first()['po'];

        return response()->json([
            'ok' => true,
            'message' => 'Added ' . implode(', ', $parts) . '.' . ($r['skipped'] ? ' Skipped: ' . implode('; ', $r['skipped']) . '.' : ''),
            'url' => count($r['pos']) === 1 ? route('tenant.purchase-orders.show', ['id' => $first->id]) : route('tenant.purchase-orders.index', ['tab' => 'draft']),
        ]);
    }
}
