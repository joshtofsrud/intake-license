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

        $q = TenantInventoryItem::with(['category'])
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true);

        if ($search !== '') {
            $q->where(function ($q2) use ($search) {
                $q2->where('name', 'like', "%{$search}%")
                   ->orWhere('sku',  'like', "%{$search}%")
                   ->orWhere('catalog_upc', 'like', "%{$search}%");
            });
        }

        if ($category) {
            $q->where('category_id', $category);
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

        $categories = TenantInventoryCategory::where('tenant_id', $tenant->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $hasCategories = $categories->isNotEmpty();

        $posCap = $this->inventoryCapContext($tenant);

        return view('tenant.inventory.index', compact(
            'items', 'categories', 'hasCategories',
            'total', 'search', 'category', 'stock', 'sort', 'page', 'perPage',
            'posCap',
            'currentLocation', 'isMultiLocation', 'hereStocks'
        ));
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
                $q->where(function ($w) use ($s) {
                    $w->where('name', 'like', "%{$s}%")->orWhere('sku', 'like', "%{$s}%");
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
