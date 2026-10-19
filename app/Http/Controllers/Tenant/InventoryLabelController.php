<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantInventoryItem;
use App\Models\Tenant\TenantInventoryItemLocation;
use App\Models\Tenant\TenantInventoryReceiveShipment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * MARKER-LABELS: barcode labels for inventory items, from a receiving
 * shipment, the inventory list or an item page. Printing reads only — it
 * never changes stock, items or shipments.
 *
 *   data   the items and their counts for the Print labels panel
 *   sheet  the printable labels (the panel previews and prints this page)
 *   prefs  remembers the shop's barcode / stock / label contents choice
 */
class InventoryLabelController extends Controller
{
    public const STOCKS = [
        'r225'  => ['name' => 'Thermal roll 2.25 × 1.25 in', 'sub' => 'Zebra, Rollo, DYMO, Brother', 'w' => 2.25, 'h' => 1.25, 'roll' => true],
        'r2'    => ['name' => 'Thermal roll 2 × 1 in', 'sub' => 'Smaller items, hang tags', 'w' => 2.0, 'h' => 1.0, 'roll' => true],
        'a5160' => ['name' => 'Sheet 1 × 2⅝ in · 30 per page', 'sub' => 'Avery 5160 / 8160 on any printer', 'w' => 2.625, 'h' => 1.0, 'roll' => false],
    ];
    public const CODES = ['upc', 'sku', 'mpn'];
    public const INC   = ['name', 'price', 'variant', 'bin', 'shop'];
    private const MAX_ITEMS  = 300;
    private const MAX_LABELS = 2000;

    public static function prefs($tenant): array
    {
        $p = (array) ($tenant->settings['labels'] ?? []);
        $inc = array_values(array_intersect((array) ($p['inc'] ?? ['name', 'price', 'variant']), self::INC));
        return [
            'code'  => in_array($p['code'] ?? '', self::CODES, true) ? $p['code'] : 'upc',
            'stock' => isset(self::STOCKS[$p['stock'] ?? '']) ? $p['stock'] : 'r225',
            'inc'   => $inc,
        ];
    }

    public function data(Request $request): JsonResponse
    {
        $tenant = tenant();
        abort_unless($tenant->retail_enabled, 403);

        $received = [];
        $locationId = null;
        $context = '';
        $from = 'items';
        $skipped = 0;

        if ($request->filled('shipment')) {
            $from = 'shipment';
            $shipment = TenantInventoryReceiveShipment::where('tenant_id', $tenant->id)->where('id', (string) $request->input('shipment'))->first();
            abort_unless($shipment, 404);
            $locationId = $shipment->location_id;
            foreach ($shipment->items()->orderBy('created_at')->get() as $line) {
                if (! $line->inventory_item_id) { $skipped++; continue; }
                $received[$line->inventory_item_id] = ($received[$line->inventory_item_id] ?? 0) + max(0, (int) $line->received_quantity);
            }
            $ids = array_keys($received);
            $context = 'Shipment ' . $shipment->shipment_number . ($shipment->isDraft() ? ' · not committed yet' : '');
        } else {
            $ids = array_values(array_unique(array_filter(array_map('strval', (array) $request->input('items', [])))));
        }
        $ids = array_slice($ids, 0, self::MAX_ITEMS);

        $items = TenantInventoryItem::where('tenant_id', $tenant->id)->whereIn('id', $ids)->get()->keyBy('id');
        $locationId = $locationId ?: $this->currentLocationId($request, $tenant);
        $here = $locationId
            ? TenantInventoryItemLocation::whereIn('inventory_item_id', $ids)->where('location_id', $locationId)->pluck('computed_stock_count', 'inventory_item_id')->toArray()
            : [];

        $rows = [];
        foreach ($ids as $id) {
            $it = $items[$id] ?? null;
            if (! $it) continue;
            $price = $it->effectiveSellPriceCents();
            $rows[] = [
                'id'       => (string) $it->id,
                'name'     => (string) $it->name,
                'sku'      => (string) ($it->sku ?? ''),
                'upc'      => (string) ($it->catalog_upc ?? ''),
                'mpn'      => (string) ($it->catalog_mpn ?? ''),
                'price'    => $price,
                'stock'    => (int) ($locationId && array_key_exists($it->id, $here) ? $here[$it->id] : $it->computed_stock_count),
                'received' => $from === 'shipment' ? (int) ($received[$it->id] ?? 0) : null,
                'size'     => (string) ($it->size ?? ''),
                'color'    => (string) ($it->color ?? ''),
                'bin'      => (string) ($it->shop_bin_location ?? ''),
            ];
        }
        if ($from === 'items') {
            $context = count($rows) === 1 ? $rows[0]['name'] : count($rows) . ' items selected';
        }

        return response()->json([
            'from'    => $from,
            'context' => $context,
            'skipped' => $skipped,
            'items'   => $rows,
            'prefs'   => self::prefs($tenant),
            'stocks'  => self::STOCKS,
        ]);
    }

    public function sheet(Request $request): View
    {
        $tenant = tenant();
        abort_unless($tenant->retail_enabled, 403);

        $code  = in_array($request->input('code'), self::CODES, true) ? $request->input('code') : 'upc';
        $stock = isset(self::STOCKS[$request->input('stock')]) ? $request->input('stock') : 'r225';
        $inc   = array_values(array_intersect((array) $request->input('inc', []), self::INC));
        $start = $stock === 'a5160' ? max(0, min(29, (int) $request->input('start', 0))) : 0;

        $qty = [];
        foreach ((array) $request->input('q', []) as $id => $n) {
            $n = max(0, min(999, (int) $n));
            if ($n > 0) $qty[(string) $id] = $n;
        }
        $qty = array_slice($qty, 0, self::MAX_ITEMS, true);
        $items = TenantInventoryItem::where('tenant_id', $tenant->id)->whereIn('id', array_keys($qty))->get()->keyBy('id');

        $sym = $tenant->currency_symbol ?: '$';
        $labels = [];
        foreach ($qty as $id => $n) {
            $it = $items[$id] ?? null;
            if (! $it) continue;
            $value = trim((string) ($code === 'upc' ? $it->catalog_upc : ($code === 'mpn' ? $it->catalog_mpn : $it->sku)));
            if ($value === '') $value = (string) $it->sku;          // nothing for the chosen code: the SKU
            if ($value === '') continue;
            $price = $it->effectiveSellPriceCents();
            $label = [
                'name'    => (string) $it->name,
                'price'   => $price !== null ? $sym . number_format($price / 100, 2) : '',
                'variant' => implode(' · ', array_filter([(string) $it->size, (string) $it->color])),
                'bin'     => (string) ($it->shop_bin_location ?? ''),
                'code'    => $value,
                'bc'      => \App\Support\LabelBarcode::svg($value)['svg'],
            ];
            for ($i = 0; $i < $n && count($labels) < self::MAX_LABELS; $i++) $labels[] = $label;
        }

        return view('tenant.inventory.labels-sheet', [
            'labels' => $labels,
            'stock'  => self::STOCKS[$stock] + ['key' => $stock],
            'inc'    => array_flip($inc),
            'start'  => $start,
            'shop'   => (string) $tenant->name,
            'capped' => count($labels) >= self::MAX_LABELS,
        ]);
    }

    public function savePrefs(Request $request): JsonResponse
    {
        $tenant = tenant();
        abort_unless($tenant->retail_enabled, 403);
        $settings = $tenant->settings ?? [];
        $settings['labels'] = [
            'code'  => in_array($request->input('code'), self::CODES, true) ? $request->input('code') : 'upc',
            'stock' => isset(self::STOCKS[$request->input('stock')]) ? $request->input('stock') : 'r225',
            'inc'   => array_values(array_intersect((array) $request->input('inc', []), self::INC)),
        ];
        $tenant->settings = $settings;
        $tenant->save();
        return response()->json(['ok' => true]);
    }

    /** The viewer's current location, as the inventory list resolves it. */
    private function currentLocationId(Request $request, $tenant): ?string
    {
        $all = $tenant->activeLocations()->get();
        $cur = $all->firstWhere('id', $request->session()->get('current_location_id'))
            ?? $all->firstWhere('is_default', true) ?? $all->first();
        return $cur?->id ? (string) $cur->id : null;
    }
}
