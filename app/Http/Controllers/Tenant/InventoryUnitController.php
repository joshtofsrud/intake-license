<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantInventoryCategory;
use App\Models\Tenant\TenantInventoryItem;
use App\Models\Tenant\TenantInventoryReceiveShipment;
use App\Models\Tenant\TenantInventoryReceiveShipmentItem;
use App\Models\Tenant\TenantInventoryUnit;
use App\Models\Tenant\TenantLocation;
use App\Support\SerialTracking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * MARKER-SERIAL-FOUNDATION — serial numbers: the category switch, adding a
 * serial to a piece already on hand, and serials on a draft receiving line.
 */
class InventoryUnitController extends Controller
{
    /** Categories page: switch serial tracking on or off for one category (and so its subcategories). */
    public function categorySerials(Request $request, string $id): RedirectResponse
    {
        $tenant = tenant();
        abort_unless($tenant->retail_enabled, 403);
        $user = auth('tenant')->user();
        abort_unless($user && $user->can('inventory.categories.rename'), 403);

        $cat = TenantInventoryCategory::where('tenant_id', $tenant->id)->findOrFail($id);
        $on = $request->boolean('track_serials');
        $cat->update(['track_serials' => $on]);

        return back()->with('flash', ['type' => 'success', 'message' => $on
            ? "Serial numbers are on for \"{$cat->name}\" and everything under it."
            : "Serial numbers are off for \"{$cat->name}\". Serials already recorded stay on their units."]);
    }

    /** Item page: give a serial to one on-hand piece that doesn't have one. */
    public function store(Request $request, string $itemId): RedirectResponse
    {
        $tenant = tenant();
        abort_unless($tenant->retail_enabled, 403);

        $item = TenantInventoryItem::where('tenant_id', $tenant->id)->findOrFail($itemId);
        abort_unless(SerialTracking::isTracked($item), 422, 'This item does not track serial numbers.');

        $data = $request->validate([
            'serial'      => ['required', 'string', 'max:100'],
            'location_id' => ['required', 'uuid'],
        ]);
        $serial = TenantInventoryUnit::clean($data['serial']);
        if ($serial === '') {
            return back()->with('flash', ['type' => 'error', 'message' => 'Enter a serial number.']);
        }

        $location = TenantLocation::where('tenant_id', $tenant->id)->findOrFail($data['location_id']);
        $gap = SerialTracking::needsSerialByLocation($item)[$location->id] ?? 0;
        if ($gap < 1) {
            return back()->with('flash', ['type' => 'error',
                'message' => "Every piece at {$location->name} already has a serial."]);
        }

        if ($there = SerialTracking::inStock($tenant->id, $serial)) {
            return back()->with('flash', ['type' => 'error', 'message' => "{$serial} is already in stock: "
                . ($there->item->name ?? 'another item') . ' at ' . ($there->location->name ?? 'another location') . '.']);
        }

        $this->saveUnit($tenant->id, $item, $location->id, $serial, [
            'cost_cents' => $item->received_cost_cents ?? $item->shop_cost_cents,
            'notes'      => 'Serial added to stock already on hand.',
        ]);

        return back()->with('flash', ['type' => 'success', 'message' => "Saved {$serial}."]);
    }

    /** Receiving: the serialized lines on a draft, with their serials. */
    public function receivingSerials(string $id): JsonResponse
    {
        $tenant = tenant();
        abort_unless($tenant->retail_enabled, 403);
        $shipment = TenantInventoryReceiveShipment::where('tenant_id', $tenant->id)->findOrFail($id);

        $lines = TenantInventoryReceiveShipmentItem::where('tenant_id', $tenant->id)
            ->where('shipment_id', $shipment->id)->whereNotNull('inventory_item_id')
            ->with('item')->orderBy('created_at')->get()
            ->filter(fn ($l) => SerialTracking::isTracked($l->item))
            ->map(fn ($l) => [
                'id'       => $l->id,
                'name'     => $l->name,
                'sku'      => $l->sku,
                'received' => (int) $l->received_quantity,
                'serials'  => array_values((array) ($l->serials ?? [])),
            ])->values();

        return response()->json(['ok' => true, 'draft' => $shipment->status === 'draft', 'lines' => $lines]);
    }

    /** Receiving: add a scanned serial to a draft line (a scan past the count raises Received). */
    public function receivingAddSerial(Request $request, string $id, string $lineId): JsonResponse
    {
        [$shipment, $line] = $this->draftLine($id, $lineId);
        if (! $line) {
            return response()->json(['ok' => false, 'message' => 'That line is gone, or the shipment is committed.'], 404);
        }

        $data = $request->validate(['serial' => ['required', 'string', 'max:100']]);
        $serial = TenantInventoryUnit::clean($data['serial']);
        if ($serial === '') {
            return response()->json(['ok' => false, 'message' => 'Enter a serial number.'], 422);
        }
        $key = TenantInventoryUnit::keyFor($serial);

        // Not twice on this shipment…
        $onShipment = TenantInventoryReceiveShipmentItem::where('tenant_id', $shipment->tenant_id)
            ->where('shipment_id', $shipment->id)->get(['id', 'name', 'serials']);
        foreach ($onShipment as $other) {
            foreach ((array) ($other->serials ?? []) as $s) {
                if (TenantInventoryUnit::keyFor((string) $s) === $key) {
                    return response()->json(['ok' => false,
                        'message' => "{$serial} is already on this shipment" . ($other->id === $line->id ? '.' : " ({$other->name}).")], 422);
                }
            }
        }
        // …and not already sitting in stock.
        if ($there = SerialTracking::inStock($shipment->tenant_id, $serial)) {
            return response()->json(['ok' => false, 'message' => "{$serial} is already in stock: "
                . ($there->item->name ?? 'another item') . ' at ' . ($there->location->name ?? 'another location') . '.'], 422);
        }

        $serials = array_values((array) ($line->serials ?? []));
        $serials[] = $serial;
        $line->serials = $serials;
        if (count($serials) > (int) $line->received_quantity) {
            $line->received_quantity = count($serials);
            if (in_array($line->status, ['expected', 'backorder'], true)) {
                $line->status = 'received';
            }
            $line->total_cost_cents = $line->unit_cost_cents ? $line->unit_cost_cents * $line->received_quantity : null;
        }
        $line->save();

        return response()->json(['ok' => true, 'serials' => $serials, 'received' => (int) $line->received_quantity]);
    }

    /** Receiving: take a serial off a draft line. The Received count is left as it is. */
    public function receivingRemoveSerial(Request $request, string $id, string $lineId): JsonResponse
    {
        [, $line] = $this->draftLine($id, $lineId);
        if (! $line) {
            return response()->json(['ok' => false, 'message' => 'That line is gone, or the shipment is committed.'], 404);
        }
        $key = TenantInventoryUnit::keyFor((string) $request->input('serial', ''));
        $serials = array_values(array_filter((array) ($line->serials ?? []),
            fn ($s) => TenantInventoryUnit::keyFor((string) $s) !== $key));
        $line->serials = $serials ?: null;
        $line->save();

        return response()->json(['ok' => true, 'serials' => $serials, 'received' => (int) $line->received_quantity]);
    }

    /**
     * Create the unit for a serial, or bring a serial that has been here
     * before (sold, written off) back into stock under this item.
     */
    public static function saveUnit(string $tenantId, TenantInventoryItem $item, ?string $locationId, string $serial, array $extra = []): TenantInventoryUnit
    {
        $key = TenantInventoryUnit::keyFor($serial);
        $unit = TenantInventoryUnit::where('tenant_id', $tenantId)->where('serial_key', $key)->first()
            ?? new TenantInventoryUnit(['tenant_id' => $tenantId, 'serial_key' => $key]);

        $unit->fill(array_merge([
            'inventory_item_id' => $item->id,
            'location_id'       => $locationId,
            'serial'            => $serial,
            'status'            => TenantInventoryUnit::STATUS_IN_STOCK,
            'received_at'       => now(),
            'sale_id'           => null,
            'sold_at'           => null,
            'written_off_at'    => null,
            'write_off_reason'  => null,
            'created_by'        => auth('tenant')->id(),
        ], $extra));
        $unit->save();

        return $unit;
    }

    /** @return array{0: TenantInventoryReceiveShipment, 1: ?TenantInventoryReceiveShipmentItem} */
    private function draftLine(string $id, string $lineId): array
    {
        $tenant = tenant();
        abort_unless($tenant->retail_enabled, 403);
        $shipment = TenantInventoryReceiveShipment::where('tenant_id', $tenant->id)->findOrFail($id);
        if ($shipment->status !== 'draft') {
            return [$shipment, null];
        }
        $line = TenantInventoryReceiveShipmentItem::where('tenant_id', $tenant->id)
            ->where('shipment_id', $shipment->id)->where('id', $lineId)->with('item')->first();
        if ($line && ! SerialTracking::isTracked($line->item)) {
            $line = null;
        }
        return [$shipment, $line];
    }
}
