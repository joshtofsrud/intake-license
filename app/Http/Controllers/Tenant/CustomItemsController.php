<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantInventoryItem;
use App\Services\Tenant\CustomItemsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * MARKER-CUSTOM-ITEMS: Register > Custom items. Lists what was rung up as a
 * custom item and lets the shop add it to inventory (through the normal
 * inventory store, so every rule of Add Item applies), match it to an item,
 * or mark it as not inventory. Sales are never changed.
 */
class CustomItemsController extends Controller
{
    public function index(Request $request): View
    {
        $tenant = tenant();
        $svc    = new CustomItemsService($tenant);
        $all    = $svc->groups();
        $counts = $svc->counts($all);

        $filter = in_array($request->query('show'), ['open', 'added', 'matched', 'skipped'], true) ? $request->query('show') : 'open';
        $range  = in_array($request->query('range'), ['today', 'week', 'all'], true) ? $request->query('range') : 'all';
        $since  = match ($range) {
            'today' => $svc->todayStart(),
            'week'  => $svc->todayStart()->subDays(6),
            default => null,
        };

        $rows = $all->filter(function ($g) use ($filter, $since) {
            if ($since && $g['last_at'] < $since) {
                return false;
            }
            return $filter === 'open' ? $g['open'] > 0 : $g['status'] === $filter && $g['open'] === 0;
        })->sortByDesc(fn ($g) => $filter === 'open' ? [$g['open'], $g['last_at']] : [$g['last_at']])->values();

        $categories = [];
        foreach (InventoryController::categoryOptions($tenant->id) as $opt) {
            $categories[$opt['cat']->id] = str_repeat("\u{00A0}\u{00A0}\u{00A0}", max(0, $opt['depth'] - 1)) . ($opt['depth'] ? '└ ' : '') . $opt['cat']->name;
        }

        return view('tenant.register.custom-items', [
            'rows'       => $rows,
            'counts'     => $counts,
            'tabCounts'  => [
                'open'    => $counts['open'],
                'added'   => $all->where('status', 'added')->where('open', 0)->count(),
                'matched' => $all->where('status', 'matched')->where('open', 0)->count(),
                'skipped' => $all->where('status', 'skipped')->where('open', 0)->count(),
            ],
            'filter'     => $filter,
            'range'      => $range,
            'categories' => $categories,
        ]);
    }

    public function resolve(Request $request): RedirectResponse
    {
        $tenant = tenant();
        $data = $request->validate([
            'key'               => ['required', 'string', 'max:255'],
            'status'            => ['required', 'in:matched,skipped'],
            'inventory_item_id' => ['nullable', 'uuid'],
            'reason'            => ['nullable', 'in:labor,supplies,one_off,other'],
            'ignore_always'     => ['nullable', 'boolean'],
            'display_name'      => ['nullable', 'string', 'max:255'],
        ]);

        $itemId = null;
        if ($data['status'] === 'matched') {
            $item = TenantInventoryItem::where('tenant_id', $tenant->id)->find($data['inventory_item_id'] ?? null);
            if (! $item) {
                return back()->with('error', 'Pick the inventory item to match it to.');
            }
            $itemId = $item->id;
        }

        (new CustomItemsService($tenant))->resolve(
            $data['key'], $data['status'], $itemId,
            $data['status'] === 'skipped' ? ($data['reason'] ?? 'other') : null,
            $data['status'] === 'skipped' && $request->boolean('ignore_always'),
            $data['display_name'] ?? null,
            optional(Auth::guard('tenant')->user())->id
        );

        $name = $data['display_name'] ?? 'Custom item';
        return back()->with('success', $data['status'] === 'matched' ? "\"{$name}\" matched to {$item->name}." : "\"{$name}\" marked as not inventory.");
    }

    public function undo(Request $request): RedirectResponse
    {
        $request->validate(['key' => ['required', 'string', 'max:255']]);
        (new CustomItemsService(tenant()))->undo($request->input('key'));
        return back()->with('success', 'Moved back to To sort.');
    }

    /** Item search for Match: name, SKU or barcode, a short list. */
    public function search(Request $request): JsonResponse
    {
        $tenant = tenant();
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['items' => []]);
        }
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        $items = TenantInventoryItem::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('sku', 'like', $like)
                ->orWhere('catalog_upc', $q)->orWhere('catalog_ean', $q))
            ->orderBy('name')->limit(8)->get();

        return response()->json(['items' => $items->map(fn ($i) => [
            'id'    => $i->id,
            'name'  => $i->name,
            'sku'   => $i->sku,
            'price' => $i->effectiveSellPriceCents(),
            'stock' => (int) ($i->computed_stock_count ?? 0),
        ])->values()]);
    }
}
