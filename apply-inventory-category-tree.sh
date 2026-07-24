#!/bin/bash
# inventory-category-tree — the hierarchy the category admin already builds
# becomes visible where items are browsed, and rows say where stock sits.
#   THE BUG: categories support parents, but the inventory list matched
#   category_id EXACTLY — so filing items under Chains/Cassettes and then
#   selecting the Drivetrain parent returned ZERO items. (Seen in a demo.)
#   NOW:
#     · Selecting a parent includes every descendant at any depth, walked
#       over the already-loaded category collection (no extra queries).
#       A "show only items filed directly here" link narrows it, and the
#       scope chip says which mode you are in.
#     · Desktop gains a category tree beside the list: parents, indented
#       children, rolled-up counts, current selection highlighted. Plain
#       links, so filters stay deep-linkable and no JS is involved. Hidden
#       under 900px, where the existing filter sheet takes over.
#     · The category dropdown renders children indented under their parent
#       instead of one flat alphabetical list.
#     · Rows show the full category path (Drivetrain > Chains) rather than
#       a bare leaf name; category.parent is eager-loaded so no N+1.
#     · Multi-location tenants get per-location chips on every row — the
#       current location highlighted, others dimmed, zero-stock greyed
#       rather than hidden, so "none here, five downtown" is obvious.
#       Single-location tenants see no change.
#     · parent_id validation is now tenant-scoped in all three places it is
#       accepted (store, quick-create, reparent) — the same authorization
#       hole closed on transfers.
# No routes, no migrations. Server: view:clear.
set -e
cd "$(git rev-parse --show-toplevel)"
if grep -q "MARKER-CAT-TREE" app/Http/Controllers/Tenant/InventoryController.php; then
  echo "inventory-category-tree already applied — aborting."; exit 1
fi
if ! grep -q "MARKER-TRANSFER-SCOPE" app/Services/Tenant/TransferRequestService.php; then
  echo "wrong base — aborting."; exit 1
fi

cat > 'app/Http/Controllers/Tenant/InventoryController.php' <<'CATTREE_0_EOF'
<?php
// patch-99 color/size + UPC column

namespace App\Http\Controllers\Tenant;

use App\Exceptions\Pos\InsufficientStockException;
use App\Exceptions\Pos\InvalidQuantityException;
use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantInventoryCategory;
use App\Models\Tenant\TenantInventoryItem;
use App\Models\Tenant\TenantInventoryItemLocation;
use App\Http\Controllers\Tenant\Concerns\GuardsRetailAccess;
use App\Http\Controllers\Tenant\Concerns\GuardsPosAccess;
use App\Services\Pos\InventoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Inventory CRUD for tenants with the `retail` capability.
 *
 * Capability gate: every method calls assertRetailEnabled() at the top.
 * Branded and Scale tiers have retail bundled (free); Starter does not.
 *
 * The catalog/shop column-pair pattern is visualized in the views — the
 * edit form has two distinct sections, "Catalog data (synced)" and
 * "Your settings (never overwritten)", with the lime accent on the shop
 * fields making the architectural keystone visible to the user.
 *
 * Stock adjustments live in a separate flow (see adjustStockForm /
 * adjustStock) — different audit semantics from item metadata edits.
 */
class InventoryController extends Controller
{
    use GuardsRetailAccess;
    use GuardsPosAccess;

    public function __construct(
        protected InventoryService $inventory,
    ) {
    }

    public function index(Request $request): View
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        $search   = trim((string) $request->input('s', ''));
        $category = $request->input('category');
        $stock    = $request->input('stock'); // 'low', 'out', 'all'
        $sort     = $request->input('sort', 'name_asc');
        $page     = max(1, (int) $request->input('page', 1));
        $perPage  = 25;

        // patch-98 per-location list — resolve current location for the viewer
        // so list rows can show that location's stock instead of company total.
        $allLocations = $tenant->activeLocations()->get();
        $isMultiLocation = $allLocations->count() > 1;
        $currentLocationId = $request->session()->get('current_location_id');
        $currentLocation = null;
        if ($currentLocationId) {
            $currentLocation = $allLocations->firstWhere('id', $currentLocationId);
        }
        if (!$currentLocation) {
            $currentLocation = $allLocations->firstWhere('is_default', true) ?? $allLocations->first();
        }
        $hereLocId = $currentLocation?->id;

        // MARKER-CAT-TREE — categories already support parents (the category
        // admin builds a real tree) but this list matched category_id
        // exactly, so selecting a parent returned NOTHING when items were
        // filed on its children. Load the tree up front and expand.
        $includeSubs = $request->input('subs', '1') !== '0';
        $allCats = TenantInventoryCategory::where('tenant_id', $tenant->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $q = TenantInventoryItem::with(['category.parent']) // MARKER-CAT-TREE — path without N+1
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true);

        if ($search !== '') {
            // MARKER-PATCH-552 — tokenized any-field match
            $q->where(function ($q2) use ($search) {
                foreach (array_filter(preg_split('/\s+/', $search)) as $t) {
                    $q2->whereRaw("CONCAT_WS(' ', name, display_subtitle, sku, catalog_upc) LIKE ?", ['%' . $t . '%']);
                }
            });
        }

        if ($category) {
            // MARKER-CAT-TREE — a parent includes everything beneath it
            // unless the viewer narrowed to direct items only.
            $catIds = $includeSubs
                ? self::descendantCategoryIds($allCats, $category)
                : [$category];
            $q->whereIn('category_id', $catIds);
        }

        // Stock filter — patch-98: keyed off CURRENT LOCATION's stock row
        // when multi-location, falls back to item-level total otherwise.
        if ($stock === 'low') {
            if ($hereLocId) {
                // MARKER-PATCH-277 — match the dashboard low-stock count. An item is
                // low at this location if its location row's stock is <= the EFFECTIVE
                // threshold (location override, else item-level), OR — when it has no
                // row at this location yet — it qualifies at the item level. The old
                // check required a location-level threshold, so item-level-only items
                // were counted on the dashboard but missing here (empty page).
                $q->where(function ($outer) use ($hereLocId) {
                    $outer->whereHas('locations', function ($w) use ($hereLocId) {
                        $w->where('location_id', $hereLocId)
                          ->whereRaw('COALESCE(tenant_inventory_item_locations.shop_reorder_threshold, tenant_inventory_items.shop_reorder_threshold) IS NOT NULL')
                          ->whereRaw('tenant_inventory_item_locations.computed_stock_count <= COALESCE(tenant_inventory_item_locations.shop_reorder_threshold, tenant_inventory_items.shop_reorder_threshold)');
                    })->orWhere(function ($q2) use ($hereLocId) {
                        $q2->whereDoesntHave('locations', function ($w) use ($hereLocId) {
                                $w->where('location_id', $hereLocId);
                            })
                           ->whereNotNull('shop_reorder_threshold')
                           ->whereColumn('computed_stock_count', '<=', 'shop_reorder_threshold');
                    });
                });
            } else {
                $q->whereNotNull('shop_reorder_threshold')
                  ->whereColumn('computed_stock_count', '<=', 'shop_reorder_threshold');
            }
        } elseif ($stock === 'out') {
            if ($hereLocId) {
                $q->whereHas('locations', function ($w) use ($hereLocId) {
                    $w->where('location_id', $hereLocId)
                      ->where('computed_stock_count', '<=', 0);
                });
            } else {
                $q->where('computed_stock_count', '<=', 0);
            }
        }

        // patch-98 sort: stock_asc / stock_desc now order by current
        // location's count when multi-location; fall back to total otherwise.
        if (in_array($sort, ['stock_asc', 'stock_desc'], true) && $hereLocId) {
            $dir = $sort === 'stock_asc' ? 'asc' : 'desc';
            $q->leftJoin('tenant_inventory_item_locations as iil_sort', function ($j) use ($hereLocId) {
                  $j->on('iil_sort.inventory_item_id', '=', 'tenant_inventory_items.id')
                    ->where('iil_sort.location_id', '=', $hereLocId);
              })
              ->orderByRaw('COALESCE(iil_sort.computed_stock_count, 0) ' . $dir)
              ->select('tenant_inventory_items.*');
        } else {
            switch ($sort) {
                case 'name_desc': $q->orderBy('name', 'desc'); break;
                case 'sku_asc':   $q->orderBy('sku', 'asc'); break;
                case 'sku_desc':  $q->orderBy('sku', 'desc'); break;
                case 'stock_asc': $q->orderBy('computed_stock_count', 'asc'); break;
                case 'stock_desc':$q->orderBy('computed_stock_count', 'desc'); break;
                case 'name_asc':
                default:          $q->orderBy('name', 'asc');
            }
        }

        $total = (clone $q)->count();
        $items = $q->forPage($page, $perPage)->get();

        // patch-98 hereStocks lookup: item_id => current-location count
        $hereStocks = [];
        if ($hereLocId && $items->isNotEmpty()) {
            $hereStocks = \App\Models\Tenant\TenantInventoryItemLocation::whereIn(
                    'inventory_item_id', $items->pluck('id')
                )
                ->where('location_id', $hereLocId)
                ->pluck('computed_stock_count', 'inventory_item_id')
                ->toArray();
        }

        // MARKER-CAT-TREE — per-location stock for every shown item, so a row
        // can say WHERE it is sitting instead of only how many are here.
        $locStocks = [];
        if ($isMultiLocation && $items->isNotEmpty()) {
            foreach (\App\Models\Tenant\TenantInventoryItemLocation::whereIn('inventory_item_id', $items->pluck('id'))
                        ->get(['inventory_item_id', 'location_id', 'computed_stock_count']) as $row) {
                $locStocks[$row->inventory_item_id][$row->location_id] = (int) $row->computed_stock_count;
            }
        }

        $categories    = $allCats;
        $hasCategories = $categories->isNotEmpty();

        // MARKER-CAT-TREE — roots with their children and rolled-up counts.
        $catCounts = TenantInventoryItem::where('tenant_id', $tenant->id)
            ->where('is_active', true) // MARKER-CAT-TREE — matches the list's own active filter
            ->whereNotNull('category_id')
            ->selectRaw('category_id, COUNT(*) as c')
            ->groupBy('category_id')
            ->pluck('c', 'category_id')
            ->toArray();

        $categoryTree = [];
        foreach ($allCats->whereNull('parent_id') as $root) {
            $children = [];
            foreach ($allCats->where('parent_id', $root->id) as $child) {
                $children[] = [
                    'cat'   => $child,
                    'count' => array_sum(array_map(
                        fn ($id) => $catCounts[$id] ?? 0,
                        self::descendantCategoryIds($allCats, $child->id)
                    )),
                ];
            }
            $categoryTree[] = [
                'cat'      => $root,
                'children' => $children,
                'count'    => array_sum(array_map(
                    fn ($id) => $catCounts[$id] ?? 0,
                    self::descendantCategoryIds($allCats, $root->id)
                )),
            ];
        }

        $posCap = $this->inventoryCapContext($tenant);

        return view('tenant.inventory.index', compact(
            'items', 'categories', 'hasCategories',
            'categoryTree', 'includeSubs', 'locStocks', 'allLocations', // MARKER-CAT-TREE
            'total', 'search', 'category', 'stock', 'sort', 'page', 'perPage',
            'posCap',
            'currentLocation', 'isMultiLocation', 'hereStocks'
        ));
    }

    /**
     * MARKER-CAT-TREE — a category id plus every id beneath it, at any depth.
     * Walks the already-loaded collection, so no extra queries and no
     * recursion into another tenant's rows.
     */
    protected static function descendantCategoryIds($cats, string $rootId): array
    {
        $ids   = [$rootId];
        $queue = [$rootId];

        while ($queue) {
            $parentId = array_shift($queue);
            foreach ($cats->where('parent_id', $parentId) as $child) {
                if (! in_array($child->id, $ids, true)) {
                    $ids[]   = $child->id;
                    $queue[] = $child->id;
                }
            }
        }

        return $ids;
    }

    /**
     * MARKER-PATCH-HLC23 — items with no category, for bulk assignment.
     */
    /**
     * MARKER-PATCH-HLC24 — bucket worklist + size sub-groups + destination tree.
     */
    public function uncategorized(Request $request): View
    {
        $tenant = tenant();
        $catTable = 'platform_distributor_catalogs';

        $bucket = trim((string) $request->query('bucket', ''));
        $size   = trim((string) $request->query('size', '')) ?: null;

        // Buckets: group uncategorized items by catalog category.
        $bucketRows = TenantInventoryItem::query()
            ->where('tenant_inventory_items.tenant_id', $tenant->id)
            ->whereNull('tenant_inventory_items.category_id')
            ->leftJoin($catTable . ' as c', 'tenant_inventory_items.distributor_catalog_id', '=', 'c.id')
            ->selectRaw('c.category as cat, count(*) as n')
            ->groupBy('c.category')
            ->get();

        $buckets = [];
        $noneCount = 0;
        foreach ($bucketRows as $r) {
            if ($r->cat === null || $r->cat === '') { $noneCount += (int) $r->n; }
            else { $buckets[] = ['key' => $r->cat, 'label' => $r->cat, 'count' => (int) $r->n]; }
        }
        usort($buckets, fn ($a, $b) => $b['count'] <=> $a['count']);
        $total = array_sum(array_column($buckets, 'count')) + $noneCount;

        if ($bucket === '') { $bucket = $buckets[0]['key'] ?? '__none__'; }

        // Active bucket worklist (bounded).
        $wq = TenantInventoryItem::query()->with('distributorCatalog')
            ->where('tenant_id', $tenant->id)->whereNull('category_id');
        if ($bucket === '__none__') {
            $wq->where(function ($w) {
                $w->whereNull('distributor_catalog_id')
                  ->orWhereHas('distributorCatalog', fn ($x) => $x->whereNull('category')->orWhere('category', ''));
            });
        } else {
            $wq->whereHas('distributorCatalog', fn ($x) => $x->where('category', $bucket));
        }
        $all = $wq->orderBy('name')->limit(500)->get();
        $bucketTotal = $all->count();

        // Size sub-groups (touch of A) — reuse the composer's data-driven patterns.
        $composer = app(\App\Services\Distributors\CatalogTitleComposer::class);
        $sizeCounts = [];
        foreach ($all as $it) {
            $cat  = $it->distributorCatalog;
            $code = $cat->distributor_code ?? '*';
            $desc = $cat->description ?? $it->name ?? '';
            $tok  = $cat ? $composer->extractSize($code, (string) $desc) : '';
            $dia  = '';
            if ($tok !== '') {
                $parts = preg_split('/[\x{00d7}xX]/u', $tok);
                $dia = trim($parts[0] ?? '');
            }
            $it->_size = $tok;
            $it->_dia  = $dia;
            if ($dia !== '') { $sizeCounts[$dia] = ($sizeCounts[$dia] ?? 0) + 1; }
        }
        uksort($sizeCounts, fn ($a, $b) => (float) $b <=> (float) $a);

        $items = $size ? $all->filter(fn ($it) => $it->_dia === $size)->values() : $all;

        // Category tree (nested by parent_id) with item counts.
        $cats = TenantInventoryCategory::where('tenant_id', $tenant->id)->orderBy('name')->get();
        $countsByCat = TenantInventoryItem::where('tenant_id', $tenant->id)
            ->whereNotNull('category_id')->selectRaw('category_id, count(*) as n')
            ->groupBy('category_id')->pluck('n', 'category_id');
        $tree = $this->buildCategoryTree($cats, $countsByCat);
        $pathMap = collect($tree)->keyBy('id');

        // Recently used destinations (session).
        $recentIds = collect(session('inv_recent_categories', []))->take(5)->values();
        $recent = $recentIds->map(fn ($id) => $cats->firstWhere('id', $id))->filter()->values();
        $recent->each(function ($c) use ($pathMap, $countsByCat) {
            $c->_path  = $pathMap[$c->id]['path'] ?? $c->name;
            $c->_count = (int) ($countsByCat[$c->id] ?? 0);
        });

        return view('tenant.inventory.uncategorized', [
            'buckets' => $buckets, 'noneCount' => $noneCount, 'total' => $total,
            'activeBucket' => $bucket, 'items' => $items, 'sizeCounts' => $sizeCounts,
            'activeSize' => $size, 'bucketTotal' => $bucketTotal,
            'tree' => $tree, 'recent' => $recent,
        ]);
    }

    /** Flatten tenant categories into a pre-ordered tree with depth + path + count. */
    private function buildCategoryTree($cats, $counts, $parentId = null, int $depth = 0, string $parentPath = ''): array
    {
        $out = [];
        $children = $parentId === null
            ? $cats->whereNull('parent_id')
            : $cats->where('parent_id', $parentId);
        foreach ($children as $c) {
            $path = $parentPath === '' ? $c->name : ($parentPath . ' › ' . $c->name);
            $out[] = ['id' => $c->id, 'name' => $c->name, 'depth' => $depth,
                      'path' => $path, 'count' => (int) ($counts[$c->id] ?? 0)];
            $out = array_merge($out, $this->buildCategoryTree($cats, $counts, $c->id, $depth + 1, $path));
        }
        return $out;
    }

    public function uncategorizedAssign(Request $request): RedirectResponse
    {
        $tenant = tenant();

        $data = $request->validate([
            'category_id' => ['required', 'uuid', 'exists:tenant_inventory_categories,id'],
            'item_ids'    => ['nullable', 'array'],
            'item_ids.*'  => ['uuid'],
            'select_all'  => ['nullable', 'boolean'],
            'f_brand'     => ['nullable', 'string', 'max:128'],
            'f_cat'       => ['nullable', 'string', 'max:128'],
            'f_q'         => ['nullable', 'string', 'max:128'],
        ]);

        $category = TenantInventoryCategory::where('tenant_id', $tenant->id)
            ->where('id', $data['category_id'])->first();
        if (! $category) {
            return back()->with('flash', ['type' => 'error', 'message' => 'Category not found.']);
        }

        $q = TenantInventoryItem::query()
            ->where('tenant_id', $tenant->id)
            ->whereNull('category_id');

        if ($request->boolean('select_all')) {
            if (filled($data['f_brand'] ?? null)) {
                $q->whereHas('distributorCatalog', fn ($w) => $w->where('manufacturer', $data['f_brand']));
            }
            if (filled($data['f_cat'] ?? null)) {
                $q->whereHas('distributorCatalog', fn ($w) => $w->where('category', $data['f_cat']));
            }
            if (filled($data['f_q'] ?? null)) {
                $s = $data['f_q'];
                $q->where(function ($w) use ($s) { // MARKER-PATCH-552 — tokenized
                    foreach (array_filter(preg_split('/\s+/', $s)) as $t) {
                        $w->whereRaw("CONCAT_WS(' ', name, display_subtitle, sku, catalog_upc) LIKE ?", ['%' . $t . '%']);
                    }
                });
            }
        } else {
            $q->whereIn('id', $data['item_ids'] ?? []);
        }

        $count = $q->update(['category_id' => $category->id]);

        // Remember this destination for quick re-pick (most-recent first, capped).
        $recent = collect(session('inv_recent_categories', []))
            ->reject(fn ($id) => $id === $category->id)
            ->prepend($category->id)->take(8)->values()->all();
        session(['inv_recent_categories' => $recent]);

        return back()->with('flash', [
            'type' => 'success',
            'message' => "Assigned {$count} item(s) to {$category->name}.",
        ]);
    }

    public function create(Request $request): View|RedirectResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        // POS hard cap: block reaching the form when at or over.
        // Edits to existing items remain unaffected (only adds blocked).
        if (!$this->inventoryAddIsAllowed($tenant)) {
            return redirect()->route('tenant.inventory.index')
                ->with('flash', [
                    'type'    => 'error',
                    'message' => 'You\'ve reached the ' . self::POS_INVENTORY_HARD_CAP . '-item inventory cap on your current plan. Add the POS add-on for unlimited inventory; existing items keep working.',
                ]);
        }

        $categories = TenantInventoryCategory::where('tenant_id', $tenant->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        if ($categories->isEmpty()) {
            return redirect()->route('tenant.inventory.categories.index')
                ->with('flash', ['type' => 'info', 'message' => 'Create at least one category before adding items.']);
        }

        return view('tenant.inventory.create', compact('categories'));
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        // POS hard cap: defense in depth — even if create() was bypassed,
        // refuse to actually write the 121st item.
        if (!$this->inventoryAddIsAllowed($tenant)) {
            return redirect()->route('tenant.inventory.index')
                ->with('flash', [
                    'type'    => 'error',
                    'message' => 'You\'ve reached the ' . self::POS_INVENTORY_HARD_CAP . '-item inventory cap on your current plan. Add the POS add-on for unlimited inventory; existing items keep working.',
                ]);
        }

        $data = $request->validate([
            'category_id'           => ['required', 'uuid', 'exists:tenant_inventory_categories,id'],
            'sku'                   => ['required', 'string', 'max:64'],
            'name'                  => ['required', 'string', 'max:255'],
            'description'           => ['nullable', 'string'],
            'color'                 => ['nullable', 'string', 'max:60'],
            'size'                  => ['nullable', 'string', 'max:60'],
            'shop_cost_dollars'     => ['nullable', 'numeric', 'min:0'],
            'shop_sell_price_dollars' => ['nullable', 'numeric', 'min:0'],
            'shop_case_quantity'    => ['nullable', 'integer', 'min:1'],
            'shop_reorder_threshold' => ['nullable', 'integer', 'min:0'],
            'shop_reorder_quantity' => ['nullable', 'integer', 'min:1'],
            'shop_bin_location'     => ['nullable', 'string', 'max:50'],
            'allow_oversell'        => ['nullable', 'boolean'],
            'initial_stock'         => ['nullable', 'integer', 'min:0'],
        ]);

        // Verify category belongs to tenant (defense in depth)
        $category = TenantInventoryCategory::where('tenant_id', $tenant->id)
            ->where('id', $data['category_id'])
            ->firstOrFail();

        // Enforce SKU uniqueness within tenant
        $skuTaken = TenantInventoryItem::where('tenant_id', $tenant->id)
            ->where('sku', $data['sku'])
            ->exists();
        if ($skuTaken) {
            return back()->withInput()->withErrors([
                'sku' => "SKU '{$data['sku']}' already exists for this tenant.",
            ]);
        }

        $item = TenantInventoryItem::create([
            'tenant_id'              => $tenant->id,
            'category_id'            => $data['category_id'],
            'sku'                    => $data['sku'],
            'name'                   => $data['name'],
            'description'            => $data['description'] ?? null,
            'color'                  => $data['color'] ?? null,
            'size'                   => $data['size'] ?? null,
            'shop_cost_cents'        => isset($data['shop_cost_dollars']) ? (int) round($data['shop_cost_dollars'] * 100) : null,
            'shop_sell_price_cents'  => isset($data['shop_sell_price_dollars']) ? (int) round($data['shop_sell_price_dollars'] * 100) : null,
            'shop_case_quantity'     => $data['shop_case_quantity'] ?? null,
            'shop_reorder_threshold' => $data['shop_reorder_threshold'] ?? null,
            'shop_reorder_quantity'  => $data['shop_reorder_quantity'] ?? null,
            'shop_bin_location'      => $data['shop_bin_location'] ?? null,
            'allow_oversell'         => (bool) ($data['allow_oversell'] ?? true),
            'is_active'              => true,
        ]);

        // Optional initial stock — record at the default location
        if (!empty($data['initial_stock']) && $data['initial_stock'] > 0) {
            $defaultLocation = $tenant->defaultLocation;
            if ($defaultLocation) {
                $this->inventory->recordInitialStock(
                    tenant: $tenant,
                    item: $item,
                    location: $defaultLocation,
                    quantity: (int) $data['initial_stock'],
                    tenantUser: Auth::guard('tenant')->user(),
                );
            }
        }

        return redirect()->route('tenant.inventory.show', $item->id)
            ->with('flash', ['type' => 'success', 'message' => "Item '{$item->name}' created."]);
    }

    public function show(Request $request, string $id): View
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        $item = TenantInventoryItem::with(['category', 'distributorCatalog', 'locations.location', 'specialOrders.vendor', 'specialOrders.customer', 'specialOrders.appointment', 'vendors'])
            ->where('tenant_id', $tenant->id)
            ->findOrFail($id);

        // Most recent 50 movements for the activity log
        $recentMovements = $item->movements()
            ->with('location')
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        $locations = $tenant->activeLocations()->get();

        $vendors = \App\Models\Tenant\TenantVendor::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        // patch-97 hero data — resolve which location is "Here" for this
        // viewer. Prefer session current_location_id; fall back to default.
        $currentLocationId = $request->session()->get('current_location_id');
        $currentLocation = null;
        if ($currentLocationId) {
            $currentLocation = $locations->firstWhere('id', $currentLocationId);
        }
        if (!$currentLocation) {
            $currentLocation = $locations->firstWhere('is_default', true) ?? $locations->first();
        }

        // SO summary for this item — counts by status + earliest ETA on
        // anything still ordered. Uses already-eager-loaded specialOrders.
        $openSoStatuses = ['needed', 'ordered', 'arrived'];
        $openSos = $item->specialOrders->whereIn('status', $openSoStatuses);
        $soSummary = [
            'open_count'    => $openSos->count(),
            'by_status'     => $openSos->groupBy('status')->map->count()->toArray(),
            'earliest_eta'  => $openSos->where('status', 'ordered')
                ->whereNotNull('expected_arrival_date')
                ->sortBy('expected_arrival_date')
                ->first()?->expected_arrival_date,
        ];

        return view('tenant.inventory.show', compact(
            'item', 'recentMovements', 'locations', 'vendors',
            'currentLocation', 'soSummary'
        ));
    }

    public function edit(string $id): View
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        $item = TenantInventoryItem::with(['category', 'distributorCatalog'])
            ->where('tenant_id', $tenant->id)
            ->findOrFail($id);

        $categories = TenantInventoryCategory::where('tenant_id', $tenant->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return view('tenant.inventory.edit', compact('item', 'categories'));
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        $item = TenantInventoryItem::where('tenant_id', $tenant->id)->findOrFail($id);

        $data = $request->validate([
            'category_id'             => ['required', 'uuid', 'exists:tenant_inventory_categories,id'],
            'sku'                     => ['required', 'string', 'max:64'],
            'name'                    => ['required', 'string', 'max:255'],
            'description'             => ['nullable', 'string'],
            'color'                 => ['nullable', 'string', 'max:60'],
            'size'                  => ['nullable', 'string', 'max:60'],
            'shop_cost_dollars'       => ['nullable', 'numeric', 'min:0'],
            'shop_sell_price_dollars' => ['nullable', 'numeric', 'min:0'],
            'shop_case_quantity'      => ['nullable', 'integer', 'min:1'],
            'shop_reorder_threshold'  => ['nullable', 'integer', 'min:0'],
            'shop_reorder_quantity'   => ['nullable', 'integer', 'min:1'],
            'shop_bin_location'       => ['nullable', 'string', 'max:50'],
            'allow_oversell'          => ['nullable', 'boolean'],
            'is_active'               => ['nullable', 'boolean'],
        ]);

        // Tenant-bound category check
        TenantInventoryCategory::where('tenant_id', $tenant->id)
            ->where('id', $data['category_id'])
            ->firstOrFail();

        // SKU uniqueness — exclude current item
        $skuTaken = TenantInventoryItem::where('tenant_id', $tenant->id)
            ->where('sku', $data['sku'])
            ->where('id', '!=', $item->id)
            ->exists();
        if ($skuTaken) {
            return back()->withInput()->withErrors([
                'sku' => "SKU '{$data['sku']}' already exists for this tenant.",
            ]);
        }

        $item->update([
            'category_id'            => $data['category_id'],
            'sku'                    => $data['sku'],
            'name'                   => $data['name'],
            'description'            => $data['description'] ?? null,
            'color'                  => $data['color'] ?? null,
            'size'                   => $data['size'] ?? null,
            'shop_cost_cents'        => isset($data['shop_cost_dollars']) ? (int) round($data['shop_cost_dollars'] * 100) : null,
            'shop_sell_price_cents'  => isset($data['shop_sell_price_dollars']) ? (int) round($data['shop_sell_price_dollars'] * 100) : null,
            'shop_case_quantity'     => $data['shop_case_quantity'] ?? null,
            'shop_reorder_threshold' => $data['shop_reorder_threshold'] ?? null,
            'shop_reorder_quantity'  => $data['shop_reorder_quantity'] ?? null,
            'shop_bin_location'      => $data['shop_bin_location'] ?? null,
            'allow_oversell'         => (bool) ($data['allow_oversell'] ?? true),
            'is_active'              => (bool) ($data['is_active'] ?? true),
        ]);

        return redirect()->route('tenant.inventory.show', $item->id)
            ->with('flash', ['type' => 'success', 'message' => 'Item updated.']);
    }

    /**
     * POST /admin/inventory/{id}/stock — manual stock adjustment.
     *
     * Writes a movement of type 'adjustment' with a required reason from
     * the v1 reason taxonomy. The InventoryService refuses empty reasons
     * via InvalidQuantityException; we surface that as a validation error.
     */
    public function adjustStock(Request $request, string $id): RedirectResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        $item = TenantInventoryItem::where('tenant_id', $tenant->id)->findOrFail($id);

        $data = $request->validate([
            'location_id' => ['required', 'uuid', 'exists:tenant_locations,id'],
            'new_count'   => ['required', 'integer', 'min:0'],
            'reason_code' => ['required', 'string', 'in:damaged,expired,theft_shrinkage,count_correction,found,vendor_credit,donation,internal_use,display,sample,other'],
            'reason_text' => ['nullable', 'string', 'max:500'],
            'notes'       => ['nullable', 'string', 'max:1000'],
        ]);

        // 'other' requires a reason_text
        if ($data['reason_code'] === 'other' && empty(trim((string) ($data['reason_text'] ?? '')))) {
            return back()->withInput()->withErrors([
                'reason_text' => 'Please provide a reason when selecting "Other".',
            ]);
        }

        $location = $tenant->activeLocations()->where('id', $data['location_id'])->firstOrFail();

        // Build the reason string: if 'other', use the typed text; otherwise use the code
        $reasonString = $data['reason_code'] === 'other'
            ? trim($data['reason_text'])
            : $data['reason_code'];

        try {
            $this->inventory->adjustStock(
                tenant: $tenant,
                item: $item,
                location: $location,
                newCount: (int) $data['new_count'],
                reason: $reasonString,
                tenantUser: Auth::guard('tenant')->user(),
                notes: $data['notes'] ?? null,
            );
        } catch (InvalidQuantityException $e) {
            return back()->withInput()->withErrors(['reason_code' => $e->getMessage()]);
        }

        return redirect()->route('tenant.inventory.show', $item->id)
            ->with('flash', ['type' => 'success', 'message' => 'Stock adjusted.']);
    }

    public function destroy(string $id): RedirectResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        $item = TenantInventoryItem::where('tenant_id', $tenant->id)->findOrFail($id);
        $item->update(['is_active' => false]);
        $item->delete(); // soft-delete

        return redirect()->route('tenant.inventory.index')
            ->with('flash', ['type' => 'success', 'message' => "Item '{$item->name}' archived."]);
    }

}
CATTREE_0_EOF

cat > 'app/Http/Controllers/Tenant/InventoryCategoryController.php' <<'CATTREE_1_EOF'
<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantInventoryCategory;
use App\Services\FeatureAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Inventory categories — minimal v1 stub.
 *
 * v1 supports: list, quick-add. Full CRUD (rename, delete, hierarchy,
 * tax classes, sort order) comes in a follow-up session.
 */
class InventoryCategoryController extends Controller
{
    public function __construct(protected FeatureAccessService $featureAccess)
    {
    }

    public function index(Request $request): View
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        $categories = TenantInventoryCategory::where('tenant_id', $tenant->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $counts = \App\Models\Tenant\TenantInventoryItem::where('tenant_id', $tenant->id)
            ->where('is_active', true)->whereNotNull('category_id')
            ->selectRaw('category_id, count(*) as n')->groupBy('category_id')->pluck('n', 'category_id');

        $tree = $this->treeFor($categories, $counts);

        return view('tenant.inventory.categories.index', compact('categories', 'tree'));
    }

    /** Flatten tenant categories into a pre-ordered tree with depth + path + count. */
    private function treeFor($cats, $counts, $parentId = null, int $depth = 0, string $parentPath = ''): array
    {
        $out = [];
        $children = $parentId === null ? $cats->whereNull('parent_id') : $cats->where('parent_id', $parentId);
        foreach ($children as $c) {
            $path = $parentPath === '' ? $c->name : ($parentPath . ' › ' . $c->name);
            $out[] = ['id' => $c->id, 'name' => $c->name, 'parent_id' => $c->parent_id,
                      'depth' => $depth, 'path' => $path, 'count' => (int) ($counts[$c->id] ?? 0)];
            $out = array_merge($out, $this->treeFor($cats, $counts, $c->id, $depth + 1, $path));
        }
        return $out;
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            // MARKER-CAT-TREE — ownership, not mere existence (same hole closed on transfers)
            'parent_id' => ['nullable', 'uuid', \Illuminate\Validation\Rule::exists('tenant_inventory_categories', 'id')
                ->where(fn ($q) => $q->where('tenant_id', $tenant->id))],
        ]);

        TenantInventoryCategory::create([
            'tenant_id' => $tenant->id,
            'name' => $data['name'],
            'slug' => $this->uniqueSlug($tenant, $data['name']),
            'parent_id' => $this->ownedParentId($tenant, $data['parent_id'] ?? null),
            'sort_order' => 0,
            'source' => 'manual',
        ]);

        return redirect()->route('tenant.inventory.categories.index')
            ->with('flash', ['type' => 'success', 'message' => "Category '{$data['name']}' added."]);
    }

    /** MARKER-PATCH-HLC25 — inline create from the mapping worklist (no navigation). */
    public function quickStore(Request $request): JsonResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            // MARKER-CAT-TREE — ownership, not mere existence (same hole closed on transfers)
            'parent_id' => ['nullable', 'uuid', \Illuminate\Validation\Rule::exists('tenant_inventory_categories', 'id')
                ->where(fn ($q) => $q->where('tenant_id', $tenant->id))],
        ]);

        $cat = TenantInventoryCategory::create([
            'tenant_id' => $tenant->id,
            'name' => $data['name'],
            'slug' => $this->uniqueSlug($tenant, $data['name']),
            'parent_id' => $this->ownedParentId($tenant, $data['parent_id'] ?? null),
            'sort_order' => 0,
            'source' => 'manual',
        ]);

        $path = $this->categoryPath($cat);

        return response()->json([
            'id' => $cat->id,
            'name' => $cat->name,
            'path' => $path,
            'depth' => substr_count($path, ' › '),
        ]);
    }

    /** MARKER-PATCH-HLC25 — turn an existing category into a child (or move to top). */
    public function reparent(Request $request, string $id): RedirectResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        $cat = TenantInventoryCategory::where('tenant_id', $tenant->id)->findOrFail($id);

        $data = $request->validate([
            // MARKER-CAT-TREE — ownership, not mere existence (same hole closed on transfers)
            'parent_id' => ['nullable', 'uuid', \Illuminate\Validation\Rule::exists('tenant_inventory_categories', 'id')
                ->where(fn ($q) => $q->where('tenant_id', $tenant->id))],
        ]);
        $newParent = $this->ownedParentId($tenant, $data['parent_id'] ?? null);

        if ($newParent === $cat->id) {
            return back()->with('flash', ['type' => 'error', 'message' => "A category can't be its own parent."]);
        }
        if ($newParent && $this->isDescendant($tenant, $newParent, $cat->id)) {
            return back()->with('flash', ['type' => 'error', 'message' => "Can't move \"{$cat->name}\" under its own sub-category."]);
        }

        $cat->update(['parent_id' => $newParent]);

        return back()->with('flash', ['type' => 'success', 'message' => "Moved \"{$cat->name}\"."]);
    }

    private function uniqueSlug($tenant, string $name): string
    {
        $slug = Str::slug($name);
        $base = $slug;
        $suffix = 1;
        while (TenantInventoryCategory::where('tenant_id', $tenant->id)->where('slug', $slug)->exists()) {
            $slug = $base . '-' . (++$suffix);
        }
        return $slug;
    }

    private function ownedParentId($tenant, ?string $parentId): ?string
    {
        if (! $parentId) {
            return null;
        }
        return TenantInventoryCategory::where('tenant_id', $tenant->id)->where('id', $parentId)->value('id');
    }

    private function categoryPath(TenantInventoryCategory $cat): string
    {
        $names = [];
        $cur = $cat;
        $guard = 0;
        while ($cur && $guard++ < 100) {
            array_unshift($names, $cur->name);
            $cur = $cur->parent_id ? TenantInventoryCategory::find($cur->parent_id) : null;
        }
        return implode(' › ', $names);
    }

    /** Is $candidateId inside the subtree of $ancestorId? (walk up from candidate). */
    private function isDescendant($tenant, string $candidateId, string $ancestorId): bool
    {
        $map = TenantInventoryCategory::where('tenant_id', $tenant->id)->pluck('parent_id', 'id');
        $cur = $candidateId;
        $guard = 0;
        while ($cur !== null && $guard++ < 100) {
            if ($cur === $ancestorId) {
                return true;
            }
            $cur = $map[$cur] ?? null;
        }
        return false;
    }

    protected function assertRetailEnabled($tenant): void
    {
        if (!$this->featureAccess->hasAddon($tenant, 'retail')) {
            abort(403, 'Inventory requires the Retail capability. Upgrade to Branded or Scale to access.');
        }
    }
}
CATTREE_1_EOF

cat > 'resources/views/tenant/inventory/index.blade.php' <<'CATTREE_2_EOF'
@extends('layouts.tenant.app')
@php
  $pageTitle = 'Inventory';
  $sortLabels = [
    'name_asc'   => 'Name A–Z',
    'name_desc'  => 'Name Z–A',
    'sku_asc'    => 'SKU A–Z',
    'sku_desc'   => 'SKU Z–A',
    'stock_asc'  => 'Stock low → high',
    'stock_desc' => 'Stock high → low',
  ];
  $stockLabels = [
    ''     => 'All stock levels',
    'low'  => 'Low stock only',
    'out'  => 'Out of stock only',
  ];
@endphp


@push('styles')
<style>
/* Inventory mobile list (patch #38) — scoped via .inv- prefix.
   Desktop ia-table stays. Mobile shows .inv-mobile via display swap. */
.inv-mobile{display:none}
.inv-mobile-list{background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-lg);overflow:hidden}
.inv-row-m{padding:14px 16px;border-bottom:0.5px solid var(--ia-border);display:flex;align-items:center;gap:12px;text-decoration:none;color:inherit;transition:background var(--ia-t)}
.inv-row-m:last-child{border-bottom:none}
.inv-row-m:active{background:var(--ia-hover)}
.inv-dot{width:8px;height:8px;border-radius:50%;background:var(--ia-accent);flex-shrink:0}
.inv-dot.low{background:#FAB46A}
.inv-dot.out{background:#F47373}
.inv-identity-m{min-width:0;flex:1}
.inv-name-m{font-size:14.5px;font-weight:500;color:var(--ia-text);line-height:1.25;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.inv-meta-m{font-size:11.5px;color:var(--ia-text-muted);margin-top:2px;line-height:1.3;display:flex;gap:6px;flex-wrap:wrap}
.inv-sku-m{font-family:ui-monospace,monospace;font-size:11px;color:var(--ia-text-dim,rgba(255,255,255,.38))}
.inv-right-m{text-align:right;flex-shrink:0;min-width:64px}
.inv-stock-m{font-size:17px;font-weight:600;color:var(--ia-text);font-variant-numeric:tabular-nums;line-height:1}
.inv-stock-m.low{color:#FAB46A}
.inv-stock-m.out{color:#F47373}
.inv-price-m{font-size:11.5px;color:var(--ia-text-muted);font-variant-numeric:tabular-nums;margin-top:4px}

/* Page-head: stack on mobile, icon-button row right */
.inv-head-m{display:none}
.inv-actions-m{display:flex;gap:6px;align-items:center}
.inv-icon-btn-m{width:36px;height:36px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;background:var(--ia-surface);border:0.5px solid var(--ia-border);color:var(--ia-text-muted);text-decoration:none;font-family:inherit;font-size:16px;cursor:pointer}
.inv-icon-btn-m.primary{background:var(--ia-accent);color:#000;border-color:var(--ia-accent);font-weight:600}

/* Toolbar (search + filter sheet trigger) */
.inv-tb-m{display:none;gap:8px;margin-bottom:12px;align-items:center}
.inv-search-m{flex:1;position:relative}
.inv-search-m input{width:100%;padding:10px 12px 10px 36px;background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:10px;color:var(--ia-text);font-size:14px;font-family:inherit;outline:none}
.inv-search-icon-m{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--ia-text-dim,rgba(255,255,255,.38));pointer-events:none}
.inv-filter-m{width:40px;height:40px;border-radius:10px;background:var(--ia-surface);border:0.5px solid var(--ia-border);color:var(--ia-text-muted);display:inline-flex;align-items:center;justify-content:center;position:relative;cursor:pointer;font-family:inherit}
.inv-filter-m.has-dot::after{content:'';position:absolute;top:7px;right:7px;width:7px;height:7px;background:var(--ia-accent);border-radius:50%}

/* Active filter chips */
.inv-chips-m{display:none;gap:6px;margin-bottom:12px;overflow-x:auto;scrollbar-width:none;padding-bottom:2px}
.inv-chips-m::-webkit-scrollbar{display:none}
.inv-chip-m{flex-shrink:0;padding:5px 11px;border-radius:999px;background:var(--ia-surface);border:0.5px solid var(--ia-border);color:var(--ia-text);font-size:12px;display:inline-flex;align-items:center;gap:4px;text-decoration:none;font-family:inherit}
.inv-chip-m.muted{color:var(--ia-text-muted)}
.inv-chip-m .x{opacity:.6;padding-left:2px}

/* Filter sheet */
.inv-sheet-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:90;opacity:0;pointer-events:none;transition:opacity .15s}
.inv-sheet-overlay.is-open{opacity:1;pointer-events:all}
.inv-sheet{display:none;position:fixed;bottom:0;left:0;right:0;background:var(--ia-bg,#0a0a0a);border-radius:18px 18px 0 0;padding:12px 16px calc(20px + env(safe-area-inset-bottom, 0px));z-index:91;border-top:0.5px solid var(--ia-border);transform:translateY(100%);transition:transform .2s ease;max-height:80%;overflow-y:auto}
.inv-sheet.is-open{transform:translateY(0)}
.inv-sheet-handle{width:36px;height:4px;border-radius:2px;background:rgba(255,255,255,.2);margin:0 auto 14px}
.inv-sheet-title{font-size:16px;font-weight:600;margin-bottom:16px;color:var(--ia-text)}
.inv-sheet-group{margin-bottom:18px}
.inv-sheet-group-label{font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:var(--ia-text-muted);font-weight:500;margin-bottom:8px}
.inv-sheet-options{display:flex;flex-wrap:wrap;gap:6px}
.inv-sheet-option{padding:8px 14px;border-radius:8px;background:var(--ia-surface);border:0.5px solid var(--ia-border);color:var(--ia-text);font-size:13px;cursor:pointer;font-family:inherit}
.inv-sheet-option.active{background:var(--ia-accent);color:#000;border-color:var(--ia-accent)}
.inv-sheet-primary{width:100%;padding:14px;background:var(--ia-accent);color:#000;border:none;border-radius:var(--ia-r-md);font-size:15px;font-weight:600;cursor:pointer;font-family:inherit;margin-top:4px}
.inv-sheet-secondary{width:100%;padding:12px;background:transparent;color:var(--ia-text-muted);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);font-size:14px;margin-top:8px;cursor:pointer;font-family:inherit;text-align:center;text-decoration:none;display:block}

@media(max-width:640px){
  /* Hide desktop chrome */
  .ia-toolbar,
  .ia-table-wrap{display:none !important}
  /* Hide the default ia-page-actions row that has 2 desktop buttons */
  .ia-page-head .ia-page-actions{display:none}
  /* Show mobile head row + toolbar + chip strip + card list */
  .inv-head-m{display:flex}
  .inv-tb-m{display:flex}
  .inv-chips-m{display:flex}
  .inv-mobile{display:block}
  .inv-sheet-overlay,
  .inv-sheet{display:block}
  /* Hide the desktop table wrapper on mobile so its empty .ia-card
     shell doesn't render between the search bar and the mobile cards. */
  .inv-desk-card{display:none}
}

/* patch-99 list redesign — row styling */
.inv-row { transition: background 120ms ease; }
.inv-row:hover { background: var(--ia-hover); }
.inv-row td { vertical-align: middle; }
.inv-row-bar { padding: 0 !important; }
.inv-row-identity { padding-left: 12px !important; }
.inv-row-name { font-size: 14px; font-weight: 500; margin-bottom: 3px; color: var(--ia-text); }
.inv-row-meta { display: flex; align-items: center; gap: 8px; font-size: 12px; flex-wrap: wrap; }
/* MARKER-CAT-TREE */
.inv-split{display:flex;gap:16px;align-items:flex-start}
.inv-cattree{width:230px;flex:none;background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-lg);padding:10px}
.inv-cattree .hd{font-size:10.5px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:var(--ia-text-muted);padding:4px 8px 8px}
.inv-cattree a{display:flex;align-items:center;gap:8px;padding:7px 9px;border-radius:8px;text-decoration:none;color:var(--ia-text);font-size:13px}
.inv-cattree a:hover{background:var(--ia-hover)}
.inv-cattree a.sel{background:color-mix(in srgb, var(--ia-accent) 14%, transparent);color:var(--ia-accent);font-weight:700}
.inv-cattree a .cnt{margin-left:auto;font-size:11.5px;color:var(--ia-text-muted);font-variant-numeric:tabular-nums}
.inv-cattree a.sel .cnt{color:var(--ia-accent)}
.inv-cattree .kids{margin-left:12px;border-left:0.5px solid var(--ia-border);padding-left:5px}
.inv-cattree .kids a{font-size:12.5px;color:var(--ia-text-2,var(--ia-text-muted))}
.inv-scope{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px;font-size:12.5px}
.inv-loc{font-size:10.5px;font-weight:600;border-radius:100px;padding:2px 8px;border:0.5px solid var(--ia-border);color:var(--ia-text-muted);white-space:nowrap}
.inv-loc.here{background:color-mix(in srgb, var(--ia-accent) 12%, transparent);border-color:color-mix(in srgb, var(--ia-accent) 40%, transparent);color:var(--ia-accent)}
.inv-loc.zero{opacity:.45}
.inv-locs{display:flex;gap:5px;flex-wrap:wrap;margin-top:4px}
.inv-catpath .par{color:var(--ia-text-muted)}
@media(max-width:900px){.inv-cattree{display:none}.inv-split{display:block}}
.inv-row-sku { font-family: var(--font-mono, monospace); color: var(--ia-text-muted); font-size: 11.5px; background: transparent; padding: 0; }
.inv-row-pill { display: inline-block; padding: 1px 8px; background: var(--ia-hover); color: var(--ia-text-muted); border-radius: 99px; font-size: 11px; }
.inv-row-bin { color: var(--ia-text-muted); font-size: 11px; }
.inv-row-upc code { font-family: var(--font-mono, monospace); font-size: 11.5px; color: var(--ia-text-muted); }
.inv-row-color, .inv-row-size { font-size: 13px; color: var(--ia-text); }
.inv-row-dash { color: var(--ia-text-muted); }
.inv-row-stock { text-align: right; }
.inv-row-stock-num { font-size: 16px; font-weight: 500; font-variant-numeric: tabular-nums; }
.inv-row-stock-meta { font-size: 11px; color: var(--ia-text-muted); margin-top: 1px; }
.inv-row-price, .inv-row-cost { text-align: right; font-variant-numeric: tabular-nums; }
.inv-row-cost { color: var(--ia-text-muted); }
</style>
@endpush

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Inventory</h1>
    <p class="ia-page-subtitle">{{ number_format($total) }} {{ Str::plural('item', $total) }}</p>
  </div>
  <div class="ia-page-actions">
    {{-- MARKER-PATCH-158-G10 — Categories link always visible (was only shown
         when categories were empty, leaving no entry point once 1+ existed). --}}
    <a href="{{ route('tenant.inventory.categories.index') }}" class="ia-btn">Categories</a>
    <a href="{{ route('tenant.inventory.receiving.index') }}" class="ia-btn">Receiving ↓</a>
    @if($hasCategories)
      <a href="{{ route('tenant.inventory.create') }}" class="ia-btn ia-btn--primary">+ New item</a>
    @else
      <a href="{{ route('tenant.inventory.categories.index') }}" class="ia-btn ia-btn--primary">Set up categories</a>
    @endif
  </div>
  {{-- Mobile-only action row (right-aligned icon buttons). --}}
  <div class="inv-head-m inv-actions-m" style="margin-left:auto">
    {{-- MARKER-PATCH-158-G10 — Categories icon button on mobile too --}}
    <a href="{{ route('tenant.inventory.categories.index') }}" class="inv-icon-btn-m" title="Categories" aria-label="Categories">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M3 7h18M3 12h18M3 17h18"/></svg>
    </a>
    <a href="{{ route('tenant.inventory.receiving.index') }}" class="inv-icon-btn-m" title="Receiving" aria-label="Receiving">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="M3.27 6.96L12 12.01l8.73-5.05"/><path d="M12 22.08V12"/></svg>
    </a>
    @if($hasCategories)
      <a href="{{ route('tenant.inventory.create') }}" class="inv-icon-btn-m primary" title="New item" aria-label="New item">+</a>
    @else
      <a href="{{ route('tenant.inventory.categories.index') }}" class="inv-icon-btn-m primary" title="Set up categories" aria-label="Set up categories">+</a>
    @endif
  </div>
</div>

@include('layouts.tenant._inventory-tabs')

@if(session('flash'))
  <div class="ia-flash ia-flash--{{ session('flash')['type'] }}">{{ session('flash')['message'] }}</div>
@endif

{{-- POS inventory cap banner.
     Renders only for tenants without the `pos` capability (typically
     Branded plans that haven't added the POS module). Starter tenants
     never see inventory at all (blocked upstream by RequireRetailCapability).
     The banner surfaces friction at the add point; existing items above
     the cap are still fully usable. --}}
@if(!empty($posCap) && !$posCap['pos_enabled'])
  @php
    $atCap = $posCap['at_or_over'];
    $remaining = $posCap['remaining'];
  @endphp
  <div class="ia-card" style="border-left:3px solid {{ $atCap ? '#F59E0B' : 'var(--ia-border-strong)' }}; margin-bottom:20px; background:{{ $atCap ? 'rgba(245,158,11,0.04)' : 'transparent' }}">
    <div class="ia-card-body" style="display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap">
      <div>
        @if($atCap)
          <strong>You're at the inventory cap on your current plan.</strong>
          <span style="color:var(--ia-text-muted)">
            {{ number_format($posCap['item_count']) }} of {{ $posCap['cap'] }} items used.
            Add the POS add-on for unlimited inventory. Existing items keep working — edit, restock, and ring them as usual.
          </span>
        @else
          <strong>{{ number_format($posCap['item_count']) }} / {{ $posCap['cap'] }} items used</strong>
          <span style="color:var(--ia-text-muted)">
            · {{ $remaining }} {{ Str::plural('slot', $remaining) }} left on your current plan. Add the POS add-on for unlimited inventory.
          </span>
        @endif
      </div>
      <div>
        <a href="{{ route('tenant.feature_addons.index') }}" class="ia-btn ia-btn--primary ia-btn--sm">Upgrade to POS</a>
      </div>
    </div>
  </div>
@endif

@if(!$hasCategories)
  <div class="ia-card" style="border-left: 4px solid var(--ia-accent); margin-bottom: 20px">
    <div class="ia-card-body">
      <strong>Get started:</strong> Create at least one category before adding items. Categories help you organize and filter your inventory — Drivetrain, Tubes, Lubes, Tools, etc.
    </div>
  </div>
@else

<form method="get" action="{{ route('tenant.inventory.index') }}" class="ia-toolbar">
  <input type="search" name="s" class="ia-input" value="{{ $search }}"
    placeholder="Search name, SKU, or UPC…" style="max-width:300px">

  {{-- MARKER-CAT-TREE — parents first, children indented beneath them --}}
  <select name="category" class="ia-input" style="width:auto">
    <option value="">All categories</option>
    @foreach($categoryTree as $node)
      <option value="{{ $node['cat']->id }}" @selected($category === $node['cat']->id)>{{ $node['cat']->name }}</option>
      @foreach($node['children'] as $child)
        <option value="{{ $child['cat']->id }}" @selected($category === $child['cat']->id)>&nbsp;&nbsp;└ {{ $child['cat']->name }}</option>
      @endforeach
    @endforeach
  </select>
  @unless($includeSubs)<input type="hidden" name="subs" value="0">@endunless

  <select name="stock" class="ia-input" style="width:auto">
    @foreach($stockLabels as $val => $label)
      <option value="{{ $val }}" @selected($stock === $val)>{{ $label }}</option>
    @endforeach
  </select>

  <select name="sort" class="ia-input" style="width:auto">
    @foreach($sortLabels as $val => $label)
      <option value="{{ $val }}" @selected($sort === $val)>{{ $label }}</option>
    @endforeach
  </select>

  <button type="submit" class="ia-btn ia-btn--secondary">Filter</button>
  @if($search || $category || $stock || $sort !== 'name_asc')
    <a href="{{ route('tenant.inventory.index') }}" class="ia-btn ia-btn--ghost">Reset</a>
  @endif
</form>

{{-- Mobile toolbar — search + filter-sheet trigger.
     Same URL params as the desktop form. Search submits on Enter; the filter
     button opens the sheet which submits a form with category/stock/sort. --}}
<form method="get" action="{{ route('tenant.inventory.index') }}" class="inv-tb-m" id="inv-mobile-search-form">
  <input type="hidden" name="category" value="{{ $category }}">
  <input type="hidden" name="stock" value="{{ $stock }}">
  <input type="hidden" name="sort" value="{{ $sort }}">
  <div class="inv-search-m">
    <svg class="inv-search-icon-m" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.35-4.35"/></svg>
    <input type="search" name="s" value="{{ $search }}" placeholder="Search name, SKU, or UPC…">
  </div>
  @php
    $hasActiveFilters = ($category || $stock || $sort !== 'name_asc');
  @endphp
  <button type="button" class="inv-filter-m {{ $hasActiveFilters ? 'has-dot' : '' }}" onclick="invOpenSheet()" aria-label="Filter">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"/></svg>
  </button>
</form>

{{-- Active filter chip strip (mobile). Shows applied filters with a tap-to-clear
     × link for each. Chips link back to the current URL minus that one param. --}}
@if($hasActiveFilters || $search)
  <div class="inv-chips-m">
    @if($category)
      @php $catName = $categories->firstWhere('id', $category)?->name ?? 'Category'; @endphp
      <a href="{{ route('tenant.inventory.index', array_filter(['s'=>$search,'stock'=>$stock,'sort'=>$sort!=='name_asc'?$sort:null])) }}" class="inv-chip-m">{{ $catName }} <span class="x">×</span></a>
    @endif
    @if($stock)
      <a href="{{ route('tenant.inventory.index', array_filter(['s'=>$search,'category'=>$category,'sort'=>$sort!=='name_asc'?$sort:null])) }}" class="inv-chip-m">{{ $stockLabels[$stock] ?? $stock }} <span class="x">×</span></a>
    @endif
    @if($sort !== 'name_asc')
      <a href="{{ route('tenant.inventory.index', array_filter(['s'=>$search,'category'=>$category,'stock'=>$stock])) }}" class="inv-chip-m">{{ $sortLabels[$sort] ?? $sort }} <span class="x">×</span></a>
    @endif
    <button type="button" class="inv-chip-m muted" onclick="invOpenSheet()">+ Add filter</button>
  </div>
@endif

{{-- MARKER-CAT-TREE — the hierarchy the category admin already builds,
     finally visible where items are browsed. Plain links keep filters
     deep-linkable and need no JS. --}}
<div class="inv-split">
@if($hasCategories)
<aside class="inv-cattree">
  <div class="hd">Categories</div>
  <a href="{{ route('tenant.inventory.index', array_filter(['s'=>$search,'stock'=>$stock,'sort'=>$sort!=='name_asc'?$sort:null])) }}"
     class="{{ $category ? '' : 'sel' }}">All items</a>
  @foreach($categoryTree as $node)
    <a href="{{ route('tenant.inventory.index', array_filter(['s'=>$search,'stock'=>$stock,'sort'=>$sort!=='name_asc'?$sort:null,'category'=>$node['cat']->id,'subs'=>$includeSubs?null:'0'])) }}"
       class="{{ $category === $node['cat']->id ? 'sel' : '' }}">
      <span>{{ $node['cat']->name }}</span><span class="cnt">{{ $node['count'] }}</span>
    </a>
    @if(count($node['children']))
      <div class="kids">
        @foreach($node['children'] as $child)
          <a href="{{ route('tenant.inventory.index', array_filter(['s'=>$search,'stock'=>$stock,'sort'=>$sort!=='name_asc'?$sort:null,'category'=>$child['cat']->id])) }}"
             class="{{ $category === $child['cat']->id ? 'sel' : '' }}">
            <span>{{ $child['cat']->name }}</span><span class="cnt">{{ $child['count'] }}</span>
          </a>
        @endforeach
      </div>
    @endif
  @endforeach
</aside>
@endif

<div style="flex:1;min-width:0">
@php
  $selNode = collect($categoryTree)->firstWhere('cat.id', $category);
  $subCount = $selNode ? count($selNode['children']) : 0;
@endphp
@if($category && $subCount)
  <div class="inv-scope">
    <span class="inv-chip-m">
      {{ $selNode['cat']->name }}@if($includeSubs) + {{ $subCount }} {{ Str::plural('subcategory', $subCount) }}@endif
    </span>
    <a href="{{ route('tenant.inventory.index', array_filter(['s'=>$search,'stock'=>$stock,'sort'=>$sort!=='name_asc'?$sort:null,'category'=>$category,'subs'=>$includeSubs?'0':null])) }}"
       style="color:var(--ia-text-muted);text-decoration:underline">
      {{ $includeSubs ? 'Show only items filed directly here' : 'Include subcategories' }}
    </a>
  </div>
@endif

<div class="ia-card inv-desk-card">
  @if($items->isEmpty())
    <div class="ia-card-body" style="text-align:center;padding:40px 20px;color:var(--ia-text-muted)">
      No items match your filters.
    </div>
  @else
<div class="ia-table-wrap">
    <table class="ia-table">
      {{-- patch-99 list redesign — column set + CSS --}}
      <thead>
        <tr>
          <th style="width:4px;padding:0"></th>
          <th>Item</th>
          <th>UPC</th>
          <th>Color</th>
          <th>Size</th>
          <th style="text-align:right">{{ ($isMultiLocation ?? false) && ($currentLocation->name ?? null) ? 'Stock at ' . $currentLocation->name : 'Stock' }}</th>
          <th style="text-align:right">Price</th>
          <th style="text-align:right">Cost</th>
        </tr>
      </thead>
      <tbody>
        @foreach($items as $item)
          @include('tenant.inventory._partials.item-card', ['item' => $item])
        @endforeach
      </tbody>
    </table>
</div>
  @endif
</div>
</div>{{-- /flex:1 --}}
</div>{{-- /inv-split MARKER-CAT-TREE --}}

{{-- Mobile card list (≤640px). Same data, different shape. --}}
<div class="inv-mobile">
  @if($items->isEmpty())
    <div class="inv-mobile-list" style="padding:40px 20px;text-align:center;color:var(--ia-text-muted);font-size:13px">
      No items match your filters.
    </div>
  @else
    <div class="inv-mobile-list">
      @foreach($items as $item)
        @php
          // patch-98 mobile per-location
          $totalStock = (int) $item->computed_stock_count;
          $stockCount = ($hereStocks ?? null) && array_key_exists($item->id, $hereStocks)
                          ? (int) $hereStocks[$item->id]
                          : $totalStock;
          $threshold  = $item->shop_reorder_threshold;
          $isLow  = $threshold !== null && $stockCount > 0 && $stockCount <= $threshold;
          $isOut  = $stockCount <= 0;
          $dotCls = $isOut ? 'out' : ($isLow ? 'low' : '');
          $sellPrice = $item->effectiveSellPriceCents();
          $showTotal = ($isMultiLocation ?? false) && $totalStock !== $stockCount;
        @endphp
        <a href="{{ route('tenant.inventory.show', $item->id) }}" class="inv-row-m">
          <div class="inv-dot {{ $dotCls }}"></div>
          <div class="inv-identity-m">
            <div class="inv-name-m">{{ $item->name }}</div>
            <div class="inv-meta-m">
              <span class="inv-sku-m">{{ $item->sku }}</span>
              @if($item->category)
                {{-- MARKER-CAT-TREE — full path, not a bare leaf name --}}
                <span class="inv-catpath">·
                  @if($item->category->parent)<span class="par">{{ $item->category->parent->name }} ›</span> @endif{{ $item->category->name }}
                </span>
              @endif
              @if($item->shop_bin_location)
                <span>· Bin {{ $item->shop_bin_location }}</span>
              @endif
            </div>
            @if(($isMultiLocation ?? false) && !empty($locStocks))
              <div class="inv-locs">
                @foreach($allLocations as $loc)
                  @php $lq = (int) ($locStocks[$item->id][$loc->id] ?? 0); @endphp
                  <span class="inv-loc {{ ($currentLocation && $loc->id === $currentLocation->id) ? 'here' : '' }} {{ $lq <= 0 ? 'zero' : '' }}">{{ $loc->name }} {{ $lq }}</span>
                @endforeach
              </div>
            @endif
          </div>
          <div class="inv-right-m">
            <div class="inv-stock-m {{ $dotCls }}">{{ $stockCount }}</div>
            @if($showTotal)
              <div style="font-size:10.5px;color:var(--ia-text-muted);margin-top:1px">{{ $totalStock }} total</div>
            @endif
            <div class="inv-price-m">{{ $sellPrice !== null ? '$' . number_format($sellPrice / 100, 2) : '—' }}</div>
          </div>
        </a>
      @endforeach
    </div>
  @endif
</div>

{{-- Filter sheet (mobile) --}}
<div class="inv-sheet-overlay" id="inv-sheet-overlay" onclick="invCloseSheet()"></div>
<div class="inv-sheet" id="inv-sheet" role="dialog" aria-label="Filter & sort">
  <div class="inv-sheet-handle"></div>
  <div class="inv-sheet-title">Filter &amp; sort</div>
  <form method="get" action="{{ route('tenant.inventory.index') }}" id="inv-sheet-form">
    <input type="hidden" name="s" value="{{ $search }}">

    <div class="inv-sheet-group">
      <div class="inv-sheet-group-label">Category</div>
      <div class="inv-sheet-options">
        <button type="button" class="inv-sheet-option {{ $category === '' || $category === null ? 'active' : '' }}" data-field="category" data-value="">All</button>
        @foreach($categories as $cat)
          <button type="button" class="inv-sheet-option {{ $category === $cat->id ? 'active' : '' }}" data-field="category" data-value="{{ $cat->id }}">{{ $cat->name }}</button>
        @endforeach
      </div>
      <input type="hidden" name="category" value="{{ $category }}" id="inv-sheet-category">
    </div>

    <div class="inv-sheet-group">
      <div class="inv-sheet-group-label">Stock level</div>
      <div class="inv-sheet-options">
        @foreach($stockLabels as $val => $label)
          <button type="button" class="inv-sheet-option {{ $stock === $val ? 'active' : '' }}" data-field="stock" data-value="{{ $val }}">{{ $label }}</button>
        @endforeach
      </div>
      <input type="hidden" name="stock" value="{{ $stock }}" id="inv-sheet-stock">
    </div>

    <div class="inv-sheet-group">
      <div class="inv-sheet-group-label">Sort by</div>
      <div class="inv-sheet-options">
        @foreach($sortLabels as $val => $label)
          <button type="button" class="inv-sheet-option {{ $sort === $val ? 'active' : '' }}" data-field="sort" data-value="{{ $val }}">{{ $label }}</button>
        @endforeach
      </div>
      <input type="hidden" name="sort" value="{{ $sort }}" id="inv-sheet-sort">
    </div>

    <button type="submit" class="inv-sheet-primary">Apply filters</button>
  </form>
  <a href="{{ route('tenant.inventory.index') }}" class="inv-sheet-secondary">Reset all</a>
</div>

@push('scripts')
<script>
(function(){
  window.invOpenSheet = function(){
    document.getElementById('inv-sheet-overlay').classList.add('is-open');
    document.getElementById('inv-sheet').classList.add('is-open');
    document.body.style.overflow = 'hidden';
  };
  window.invCloseSheet = function(){
    document.getElementById('inv-sheet-overlay').classList.remove('is-open');
    document.getElementById('inv-sheet').classList.remove('is-open');
    document.body.style.overflow = '';
  };
  document.addEventListener('keydown', function(e){
    if(e.key === 'Escape') invCloseSheet();
  });
  // Sheet option buttons act like radio buttons within their group.
  // They update the matching hidden input, mark themselves active,
  // and clear siblings in the same group. Form submits on "Apply".
  document.querySelectorAll('#inv-sheet-form .inv-sheet-option').forEach(function(btn){
    btn.addEventListener('click', function(){
      var field = btn.dataset.field;
      var value = btn.dataset.value;
      var hidden = document.getElementById('inv-sheet-' + field);
      if (hidden) hidden.value = value;
      // toggle active class within siblings
      btn.parentElement.querySelectorAll('.inv-sheet-option').forEach(function(b){
        b.classList.toggle('active', b === btn);
      });
    });
  });
})();
</script>
@endpush

@if($total > $perPage)
  <div class="ia-pagination">
    @php
      $pages = (int) ceil($total / $perPage);
      $qs = function($p) use ($search, $category, $stock, $sort) {
        return http_build_query(array_filter([
          's' => $search, 'category' => $category, 'stock' => $stock,
          'sort' => $sort, 'page' => $p,
        ]));
      };
    @endphp
    @if($page > 1)
      <a href="?{{ $qs($page - 1) }}" class="ia-btn ia-btn--ghost">← Prev</a>
    @endif
    <span class="ia-pagination-info">Page {{ $page }} of {{ $pages }}</span>
    @if($page < $pages)
      <a href="?{{ $qs($page + 1) }}" class="ia-btn ia-btn--ghost">Next →</a>
    @endif
  </div>
@endif

@endif

@endsection
CATTREE_2_EOF

cat > 'resources/views/tenant/inventory/_partials/item-card.blade.php' <<'CATTREE_3_EOF'
@php
  $totalStock = (int) $item->computed_stock_count;
  $hereStock  = ($hereStocks ?? null) && array_key_exists($item->id, $hereStocks)
                  ? (int) $hereStocks[$item->id]
                  : $totalStock;
  $stock = $hereStock;
  $threshold = $item->shop_reorder_threshold;
  $isOversold = $stock < 0;
  $isOut = $stock === 0;
  $isLow = !$isOut && !$isOversold && $threshold !== null && $stock <= $threshold;

  $barColor = 'transparent';
  $statusCopy = null;
  if ($isOversold) {
    $barColor = '#E24B4A';
    $statusCopy = 'Oversold';
  } elseif ($isOut) {
    $barColor = '#EF9F27';
    $statusCopy = 'Out';
  } elseif ($isLow) {
    $barColor = '#EF9F27';
    $statusCopy = 'Low';
  }

  $stockColor = $isOversold ? '#E24B4A' : ($isOut || $isLow ? '#BA7517' : 'inherit');
  $detailUrl = route('tenant.inventory.show', $item->id);
  $sellPrice = $item->effectiveSellPriceCents();
  $cost = $item->effectiveCostCents();
  $isMulti = $isMultiLocation ?? false;
@endphp

<tr class="inv-row" onclick="window.location='{{ $detailUrl }}'" style="cursor:pointer">
  <td class="inv-row-bar" style="width:4px;padding:0;background:{{ $barColor }};border-radius:0"></td>

  <td class="inv-row-identity">
    <div class="inv-row-name">{{ $item->name }}</div>
    <div class="inv-row-meta">
      <code class="inv-row-sku">{{ $item->sku }}</code>
      @if($item->category)
        {{-- MARKER-CAT-TREE — show the path so a child category reads in context --}}
        <span class="inv-row-pill inv-catpath">@if($item->category->parent)<span class="par">{{ $item->category->parent->name }} › </span>@endif{{ $item->category->name }}</span>
      @endif
      @if($item->shop_bin_location)
        <span class="inv-row-bin">Bin {{ $item->shop_bin_location }}</span>
      @endif
    </div>
    @if(($isMultiLocation ?? false) && !empty($locStocks ?? []))
      {{-- MARKER-CAT-TREE — where this item is actually sitting --}}
      <div class="inv-locs">
        @foreach(($allLocations ?? []) as $loc)
          @php $lq = (int) ($locStocks[$item->id][$loc->id] ?? 0); @endphp
          <span class="inv-loc {{ (($currentLocation ?? null) && $loc->id === $currentLocation->id) ? 'here' : '' }} {{ $lq <= 0 ? 'zero' : '' }}">{{ $loc->name }} {{ $lq }}</span>
        @endforeach
      </div>
    @endif
  </td>

  <td class="inv-row-upc">
    @if($item->catalog_upc)
      <code>{{ $item->catalog_upc }}</code>
    @else
      <span class="inv-row-dash">—</span>
    @endif
  </td>

  <td class="inv-row-color">
    {{ $item->color ?? '—' }}
  </td>

  <td class="inv-row-size">
    {{ $item->size ?? '—' }}
  </td>

  <td class="inv-row-stock">
    <div class="inv-row-stock-num" style="color:{{ $stockColor }}">{{ $stock }}</div>
    @if($statusCopy || ($isMulti && $totalStock !== $hereStock))
      <div class="inv-row-stock-meta">
        @if($statusCopy) {{ $statusCopy }} @endif
        @if($statusCopy && $isMulti && $totalStock !== $hereStock) · @endif
        @if($isMulti && $totalStock !== $hereStock) {{ $totalStock }} total @endif
      </div>
    @endif
  </td>

  <td class="inv-row-price">
    {{ $sellPrice !== null ? '$' . number_format($sellPrice / 100, 2) : '—' }}
  </td>

  <td class="inv-row-cost">
    {{ $cost !== null ? '$' . number_format($cost / 100, 2) : '—' }}
  </td>
</tr>
CATTREE_3_EOF

echo "inventory-category-tree applied — server: git pull && php artisan view:clear"
