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
        // MARKER-CAT-PLACEHOLDER — in-stock is the landing state. Only when
        // the parameter is ABSENT: ?stock= (chosen "All stock levels") is an
        // explicit choice and is honoured, or the filter could never be
        // cleared.
        $stock    = $request->has('stock')
            ? $request->input('stock')
            : 'in'; // '', 'in', 'low', 'out', 'archived' — MARKER-INV-IN-STOCK
        $sort     = $request->input('sort', 'name_asc');
        // MARKER-INV-BRAND-DIST
        $brand       = trim((string) $request->input('brand', ''));
        $distributor = trim((string) $request->input('distributor', ''));
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

        // MARKER-ARCHIVE-MOVE — an archived item is soft-deleted AND
        // is_active=false, so it is invisible twice over. This is the only
        // way back to it.
        // MARKER-INV-LIST — reachable as a stock level now, with the old
        // ?archived=1 links still honoured so nothing bookmarked breaks.
        $archived = $request->boolean('archived') || $request->query('stock') === 'archived';

        $q = TenantInventoryItem::with(['category.parent']) // MARKER-CAT-TREE — path without N+1
            ->where('tenant_id', $tenant->id);

        if ($archived) {
            $q->onlyTrashed();
        } else {
            $q->where('is_active', true);
        }

        // MARKER-ITEM-IDENTIFIERS — the four identifiers a shop actually
        // quotes: SKU, UPC, EAN and MPN. The last two were not even stored
        // before this patch, so searching them returned nothing.
        if ($search !== '') {
            // MARKER-PATCH-552 — tokenized any-field match
            $q->where(function ($q2) use ($search) {
                foreach (array_filter(preg_split('/\s+/', $search)) as $t) {
                    $q2->where(function ($w) use ($t) {
                        $w->whereRaw(
                            "CONCAT_WS(' ', name, display_subtitle, sku, catalog_upc, catalog_ean, catalog_mpn) LIKE ?",
                            ['%' . $t . '%']
                        )
                        // MARKER-ITEM-IDENTIFIERS — a multi-sourced item can
                        // carry a different part number per supplier; any of
                        // them should find it.
                        ->orWhereExists(function ($sub) use ($t) {
                            $sub->selectRaw('1')->from('tenant_inventory_item_vendors as v')
                                ->whereColumn('v.inventory_item_id', 'tenant_inventory_items.id')
                                ->where('v.vendor_sku', 'like', '%' . $t . '%');
                        });
                    });
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
        } elseif ($stock === 'in') {
            // MARKER-INV-IN-STOCK — what the shop actually holds. Mirrors the
            // 'out' branch: with a location in scope the question is about
            // THAT location's shelf, not the sum across the company. An item
            // with no row at this location has never been stocked here, so it
            // is correctly absent rather than counted as zero.
            if ($hereLocId) {
                $q->whereHas('locations', function ($w) use ($hereLocId) {
                    $w->where('location_id', $hereLocId)
                      ->where('computed_stock_count', '>', 0);
                });
            } else {
                $q->where('computed_stock_count', '>', 0);
            }
        }

        // MARKER-INV-BRAND-DIST — brand lives on the linked catalog row.
        if ($brand !== '') {
            $q->whereHas('distributorCatalog', fn ($w) => $w->where('manufacturer', $brand));
        }

        // MARKER-INV-BRAND-DIST — "available from", not "created by". An item
        // matched across distributors carries several sources, and a shop
        // asking what BTI can supply means all of it. whereExists keeps the
        // row unique — a join would list a two-source item twice.
        if ($distributor !== '') {
            $q->whereExists(function ($w) use ($distributor) {
                $w->selectRaw('1')
                  ->from('tenant_inventory_item_vendors as iv_f')
                  ->whereColumn('iv_f.inventory_item_id', 'tenant_inventory_items.id')
                  ->where('iv_f.distributor_code', $distributor);
            });
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
        } elseif (in_array($sort, ['brand_asc', 'brand_desc'], true)) {
            // MARKER-INV-BRAND-DIST — LEFT join, and the select is pinned back
            // to the items table: a hand-created item has no catalog row and
            // must not disappear from the list because someone sorted by
            // brand. Those sort last instead.
            $dir = $sort === 'brand_asc' ? 'asc' : 'desc';
            $q->leftJoin('platform_distributor_catalogs as pdc_sort', 'pdc_sort.id', '=', 'tenant_inventory_items.distributor_catalog_id')
              ->orderByRaw("COALESCE(NULLIF(pdc_sort.manufacturer, ''), 'zzzz') {$dir}")
              ->orderBy('tenant_inventory_items.name')
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

        // MARKER-INV-BRAND-DIST — built from what this tenant carries, not the
        // whole shared catalog, so the dropdown stays usable.
        $brandOptions = \App\Models\PlatformDistributorCatalog::query()
            ->whereIn('id', function ($w) {
                $w->select('distributor_catalog_id')
                  ->from('tenant_inventory_items')
                  ->where('tenant_id', tenant()->id)
                  ->whereNotNull('distributor_catalog_id');
            })
            ->whereNotNull('manufacturer')->where('manufacturer', '!=', '')
            ->distinct()->orderBy('manufacturer')->pluck('manufacturer');

        $distributorOptions = \Illuminate\Support\Facades\DB::table('tenant_inventory_item_vendors as iv')
            ->join('tenant_inventory_items as it', 'it.id', '=', 'iv.inventory_item_id')
            ->where('it.tenant_id', tenant()->id)
            ->whereNotNull('iv.distributor_code')->where('iv.distributor_code', '!=', '')
            ->distinct()->orderBy('iv.distributor_code')
            ->pluck('iv.distributor_code');

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

        // MARKER-CAT-DEPTH — this used to walk roots and their direct
        // children ONLY, so anything nested deeper was invisible in the
        // sidebar and absent from the filter even though it held items.
        // Flat-with-depth (not nested) because a <select> can't nest, and
        // it keeps the sidebar, the dropdown and the scope chip reading
        // the same shape.
        $categoryTree = [];
        $walk = function ($parentId, int $depth) use (&$walk, $allCats, $catCounts, &$categoryTree) {
            foreach ($allCats->where('parent_id', $parentId) as $cat) {
                $kids = $allCats->where('parent_id', $cat->id);
                $categoryTree[] = [
                    'cat'      => $cat,
                    'depth'    => $depth,
                    'kids'     => $kids->count(),
                    // Count rolls up the whole subtree, as it always did.
                    'count'    => array_sum(array_map(
                        fn ($id) => $catCounts[$id] ?? 0,
                        self::descendantCategoryIds($allCats, $cat->id)
                    )),
                    // Kept so anything still reading ['children'] gets the
                    // direct children rather than a fatal.
                    'children' => [],
                ];
                $walk($cat->id, $depth + 1);
            }
        };
        $walk(null, 0);

        $posCap = $this->inventoryCapContext($tenant);

        // MARKER-INV-LIST — colour and size are empty for most catalogs, and
        // two columns of "—" cost about a fifth of the table width. Decide
        // per result set rather than per tenant, so filtering to a category
        // that does use them still shows them.
        $showColor = $items->contains(fn ($i) => filled($i->color ?? null));
        $showSize  = $items->contains(fn ($i) => filled($i->size ?? null));

        return view('tenant.inventory.index', compact(
            'items', 'categories', 'hasCategories',
            'categoryTree', 'includeSubs', 'locStocks', 'allLocations', // MARKER-CAT-TREE
            'showColor', 'showSize', // MARKER-INV-LIST
            'archived', // MARKER-ARCHIVE-MOVE
            'total', 'search', 'category', 'stock', 'sort', 'page', 'perPage',
            'brand', 'distributor', 'brandOptions', 'distributorOptions', // MARKER-INV-BRAND-DIST
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
    /**
     * MARKER-ITEM-CAT-TREE — categories as roots with their children, for a
     * select that shows nesting instead of a flat alphabetical jumble.
     *
     * Returns a flat list of ['cat' => model, 'depth' => 0|1] so the view
     * stays simple. Orphans — a category whose parent is missing or
     * inactive — are appended at depth 0 rather than dropped; an item you
     * cannot file is worse than a category shown in the wrong place.
     *
     * @return array<int, array{cat: TenantInventoryCategory, depth: int}>
     */
    public static function categoryOptions(string $tenantId): array
    {
        // MARKER-CAT-DEPTH2 — walks the whole tree. This emitted roots and
        // their direct children, then appended anything unseen to the END, so
        // a grandchild ("27.5 / 650b" under "tires" under "Parts") landed in a
        // clump at the bottom instead of nested under its parent.
        $all = TenantInventoryCategory::where('tenant_id', $tenantId)
            ->orderBy('sort_order')->orderBy('name')->get();

        $byParent = [];
        foreach ($all as $c) {
            $byParent[$c->parent_id ?? ''][] = $c;
        }

        $out  = [];
        $seen = [];

        $walk = function ($parentKey, int $depth) use (&$walk, &$out, &$seen, $byParent) {
            foreach ($byParent[$parentKey] ?? [] as $c) {
                if (isset($seen[$c->id]) || $depth > 8) {
                    continue;
                }
                $seen[$c->id] = true;
                $out[] = ['cat' => $c, 'depth' => $depth];
                $walk($c->id, $depth + 1);
            }
        };

        $walk('', 0);

        foreach ($all as $c) {
            if (! isset($seen[$c->id])) {
                $out[] = ['cat' => $c, 'depth' => 0];
            }
        }

        return $out;
    }

    public function uncategorized(Request $request): View
    {
        $tenant = tenant();
        $catTable = 'platform_distributor_catalogs';

        $bucket = trim((string) $request->query('bucket', ''));
        // MARKER-SPLIT-BY — attr '' means "not chosen, use the default";
        // 'none' means the user turned the row off. Neither is remembered.
        $attrKey = trim((string) $request->query('attr', ''));
        $attrVal = trim((string) $request->query('val', '')) ?: null;

        // MARKER-UNCAT-SOURCE — buckets group by the item's SOURCE, whichever
        // kind it has: a distributor's catalog category, or the category string
        // a CSV import kept. One list, one flow — an imported item used to
        // appear here AND on a separate mappings tab, with two ways to assign
        // it. The key carries the kind so two sources can use the same word.
        $bucketRows = TenantInventoryItem::query()
            ->where('tenant_inventory_items.tenant_id', $tenant->id)
            ->whereNull('tenant_inventory_items.category_id')
            ->leftJoin($catTable . ' as c', 'tenant_inventory_items.distributor_catalog_id', '=', 'c.id')
            ->selectRaw('c.category as cat, c.distributor_code as dist, count(*) as n')
            ->groupBy('c.category', 'c.distributor_code')
            ->get();

        $importRows = TenantInventoryItem::query()
            ->where('tenant_id', $tenant->id)
            ->whereNull('category_id')
            ->whereNotNull('source_category')
            ->where('source_category', '!=', '')
            ->selectRaw('source_category as cat, source_name as src, count(*) as n')
            ->groupBy('source_category', 'source_name')
            ->get();

        $buckets = [];
        $noneCount = 0;

        foreach ($bucketRows as $r) {
            if ($r->cat === null || $r->cat === '') { $noneCount += (int) $r->n; continue; }
            $buckets[] = ['key' => 'cat:' . $r->cat, 'label' => $r->cat,
                          'count' => (int) $r->n, 'source' => $r->dist ?: 'catalog', 'kind' => 'catalog'];
        }
        foreach ($importRows as $r) {
            $buckets[] = ['key' => 'src:' . $r->cat, 'label' => $r->cat,
                          'count' => (int) $r->n, 'source' => $r->src ?: 'import', 'kind' => 'import'];
        }

        // An item with a catalog category AND an import string is counted in
        // both, so the none-count must exclude anything with either.
        $noneCount = TenantInventoryItem::query()
            ->where('tenant_id', $tenant->id)->whereNull('category_id')
            ->where(function ($w) { $w->whereNull('source_category')->orWhere('source_category', ''); })
            ->where(function ($w) use ($catTable) {
                $w->whereNull('distributor_catalog_id')
                  ->orWhereHas('distributorCatalog', fn ($x) => $x->whereNull('category')->orWhere('category', ''));
            })
            ->count();

        usort($buckets, fn ($a, $b) => $b['count'] <=> $a['count']);
        $total = TenantInventoryItem::where('tenant_id', $tenant->id)->whereNull('category_id')->count();

        if ($bucket === '') { $bucket = $buckets[0]['key'] ?? '__none__'; }

        // MARKER-UNCAT-SOURCE — a key is "cat:<catalog category>" or
        // "src:<import string>". Bare keys from an older link still resolve as
        // catalog, so a bookmarked URL does not 404 into an empty bucket.
        $bucketKind = 'catalog';
        $bucketName = $bucket;
        if (str_starts_with($bucket, 'src:'))      { $bucketKind = 'import';  $bucketName = substr($bucket, 4); }
        elseif (str_starts_with($bucket, 'cat:'))  { $bucketKind = 'catalog'; $bucketName = substr($bucket, 4); }

        // Active bucket worklist (bounded).
        $wq = TenantInventoryItem::query()->with('distributorCatalog')
            ->where('tenant_id', $tenant->id)->whereNull('category_id');
        if ($bucket === '__none__') {
            $wq->where(function ($w) { $w->whereNull('source_category')->orWhere('source_category', ''); })
               ->where(function ($w) {
                   $w->whereNull('distributor_catalog_id')
                     ->orWhereHas('distributorCatalog', fn ($x) => $x->whereNull('category')->orWhere('category', ''));
               });
        } elseif ($bucketKind === 'import') {
            $wq->where('source_category', $bucketName);
        } else {
            $wq->whereHas('distributorCatalog', fn ($x) => $x->where('category', $bucketName));
        }
        $all = $wq->orderBy('name')->limit(500)->get();
        // MARKER-UNCAT-LABEL — the view shows the name, never the prefixed key.
        // MARKER-UNCAT-LABEL2 — $bucketLabel feeds the heading and predates the
        // cat:/src: keys, so it was printing "cat:Crankset".
        $activeBucketLabel  = $bucket === '__none__' ? 'No catalog signal' : $bucketName;
        $bucketLabel        = $activeBucketLabel;
        $activeBucketKind   = $bucketKind;
        $activeBucketSource = collect($buckets)->firstWhere('key', $bucket)['source'] ?? null;

        $bucketTotal = $all->count();

        // MARKER-SPLIT-BY — tally every attribute across the bucket, then
        // rank them. Coverage alone is not enough: on Wheels, Position
        // (100%/3) and Rim Color (100%/4) beat Wheel Diameter on coverage
        // and would win a coverage-only race, giving a three-way split
        // nobody wants. Hence a floor on distinct values as well.
        $tally = [];
        foreach ($all as $it) {
            $cat = $it->distributorCatalog;
            if (! $cat) { continue; }

            $seen = [];
            foreach (($cat->attributes ?? []) as $a) {
                if (! is_array($a) || ! isset($a['Name'])) { continue; }
                $name = trim((string) $a['Name']);
                $val  = trim((string) ($a['Value'] ?? ''));
                if ($name === '' || $val === '' || isset($seen[$name])) { continue; }
                $seen[$name] = true;                       // one row counts once
                $tally[$name]['rows'] = ($tally[$name]['rows'] ?? 0) + 1;
                $tally[$name]['vals'][$val] = ($tally[$name]['vals'][$val] ?? 0) + 1;
            }

            // Brand is not an attribute. It is offered because it is often
            // the only usable grouping, and deliberately never ranked.
            $brand = trim((string) ($cat->manufacturer ?? ''));
            if ($brand !== '') {
                $tally['__brand']['rows'] = ($tally['__brand']['rows'] ?? 0) + 1;
                $tally['__brand']['vals'][$brand] = ($tally['__brand']['vals'][$brand] ?? 0) + 1;
            }
        }

        $denom = max(1, $bucketTotal);
        $attrOptions = [];
        foreach ($tally as $name => $t) {
            $isBrand = $name === '__brand';
            $cov  = (int) round((($t['rows'] ?? 0) / $denom) * 100);
            $vals = count($t['vals'] ?? []);

            $qualifies = ! $isBrand && $cov >= 60 && $vals >= 5 && $vals <= 60;
            $reason = '';
            if (! $qualifies) {
                if ($isBrand)          { $reason = 'not an attribute'; }
                elseif ($cov < 60)     { $reason = 'covers only ' . $cov . '%'; }
                elseif ($vals < 5)     { $reason = 'only ' . $vals . ' values'; }
                else                   { $reason = $vals . ' values — too many'; }
            }

            $attrOptions[] = [
                'key' => $name, 'label' => $isBrand ? 'Brand' : $name,
                'cov' => $cov, 'vals' => $vals,
                'qualifies' => $qualifies, 'reason' => $reason, 'brand' => $isBrand,
            ];
        }

        usort($attrOptions, function ($a, $b) {
            if ($a['qualifies'] !== $b['qualifies']) { return $b['qualifies'] <=> $a['qualifies']; }
            return ($b['cov'] <=> $a['cov']) ?: ($b['vals'] <=> $a['vals']);
        });

        // Default: the top qualifier, or nothing.
        if ($attrKey === '') {
            $top = null;
            foreach ($attrOptions as $o) { if ($o['qualifies']) { $top = $o['key']; break; } }
            $attrKey = $top ?? 'none';
        }
        $known = array_column($attrOptions, 'key');
        if ($attrKey !== 'none' && ! in_array($attrKey, $known, true)) { $attrKey = 'none'; }

        $activeAttr = $attrKey === 'none' ? null : $attrKey;
        $activeAttrLabel = 'Value';
        foreach ($attrOptions as $o) {
            if ($o['key'] === $activeAttr) { $activeAttrLabel = $o['label']; break; }
        }

        // MARKER-SPLIT-BY-CLIENT — every attribute's value counts, and every
        // row's values, go to the browser so switching attribute and picking a
        // value are both instant. The bucket is capped at 500 rows, so this is
        // a few tens of KB, not a page weight problem.
        $valuesByAttr = [];
        foreach ($tally as $name => $t) {
            $vals = $t['vals'] ?? [];
            arsort($vals);
            $valuesByAttr[$name] = $vals;
        }

        foreach ($all as $it) {
            $vals = [];
            $cat  = $it->distributorCatalog;
            if ($cat) {
                foreach (($cat->attributes ?? []) as $a) {
                    if (! is_array($a) || ! isset($a['Name'])) { continue; }
                    $n = trim((string) $a['Name']);
                    $v = trim((string) ($a['Value'] ?? ''));
                    if ($n !== '' && $v !== '' && ! isset($vals[$n])) { $vals[$n] = $v; }
                }
                $brand = trim((string) ($cat->manufacturer ?? ''));
                if ($brand !== '') { $vals['__brand'] = $brand; }
            }
            $it->_attrs = $vals;
            $it->_val   = $activeAttr !== null ? ($vals[$activeAttr] ?? '') : '';
        }

        // No server-side filtering — the browser hides rows.
        $items = $all;

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

        // MARKER-CAT-UNDO — recent assignments for the rail, and a suggestion
        // per bucket from this shop's own history.
        $assignments = \Illuminate\Support\Facades\DB::table('tenant_category_assignments')
            ->where('tenant_id', $tenant->id)->orderByDesc('created_at')->limit(6)->get();
        $suggest = app(\App\Services\Tenant\CategorySuggestService::class);
        $suggestions = $suggest->forBuckets($tenant->id, array_column($buckets, 'key'));
        $activeSuggestion = $suggestions[$bucket] ?? null;
        if ($activeSuggestion) {
            $activeSuggestion['path'] = collect($tree)->firstWhere('id', $activeSuggestion['category_id'])['path'] ?? $activeSuggestion['category_name'];
        }

        return view('tenant.inventory.uncategorized', [
            'assignments' => $assignments, 'suggestions' => $suggestions, 'activeSuggestion' => $activeSuggestion,
            'activeBucketLabel' => $activeBucketLabel, 'activeBucketKind' => $activeBucketKind, 'activeBucketSource' => $activeBucketSource,
            'buckets' => $buckets, 'noneCount' => $noneCount, 'total' => $total,
            'activeBucket' => $bucket, 'items' => $items,
            // MARKER-SPLIT-BY
            'attrOptions' => $attrOptions, 'activeAttr' => $activeAttr,
            'activeAttrLabel' => $activeAttrLabel, 'valuesByAttr' => $valuesByAttr,
            'bucketTotal' => $bucketTotal,
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

    /**
     * MARKER-CAT-UNDO — reverse one assignment. Restores each item's prior
     * category; an item whose category has since been changed by hand is
     * kept and counted, not clobbered.
     */
    /** MARKER-CAT-RAIL2 — what an assignment touched, for the undo preview. */
    public function uncategorizedAssignmentItems(Request $request, string $id)
    {
        $tenant = tenant();
        $a = \Illuminate\Support\Facades\DB::table('tenant_category_assignments')
            ->where('tenant_id', $tenant->id)->where('id', $id)->first();
        abort_unless($a, 404);

        $rows = \Illuminate\Support\Facades\DB::table('tenant_category_assignment_items as ai')
            ->join('tenant_inventory_items as i', 'i.id', '=', 'ai.item_id')
            ->leftJoin('tenant_inventory_categories as pc', 'pc.id', '=', 'ai.prior_category_id')
            ->where('ai.assignment_id', $a->id)
            ->orderBy('i.name')->limit(300)
            ->get(['i.id', 'i.name', 'i.sku', 'i.category_id', 'ai.restored_at', 'pc.name as prior_name']);

        $kept = 0;
        $items = $rows->map(function ($r) use ($a, &$kept) {
            $changedSince = (string) $r->category_id !== (string) $a->category_id && ! $r->restored_at;
            if ($changedSince) $kept++;
            return ['name' => $r->name, 'sku' => $r->sku, 'back_to' => $r->prior_name ?: 'Uncategorized',
                    'restored' => (bool) $r->restored_at, 'changed_since' => $changedSince];
        });

        return response()->json([
            'count' => (int) $a->item_count, 'shown' => $items->count(), 'kept' => $kept,
            'category' => $a->category_name, 'bucket' => $a->bucket_key, 'undone' => (bool) $a->undone_at,
            'items' => $items,
        ]);
    }

    public function uncategorizedUndo(Request $request, string $id): RedirectResponse
    {
        $tenant = tenant();
        $a = \Illuminate\Support\Facades\DB::table('tenant_category_assignments')
            ->where('tenant_id', $tenant->id)->where('id', $id)->first();
        if (! $a) {
            return back()->with('flash', ['type' => 'error', 'message' => 'That assignment is gone.']);
        }
        if ($a->undone_at) {
            return back()->with('flash', ['type' => 'info', 'message' => 'Already undone.']);
        }

        $restored = 0; $kept = 0;
        \Illuminate\Support\Facades\DB::table('tenant_category_assignment_items')
            ->where('assignment_id', $a->id)->whereNull('restored_at')
            ->orderBy('id')->chunkById(500, function ($rows) use (&$restored, &$kept, $a, $tenant) {
                foreach ($rows as $r) {
                    $item = TenantInventoryItem::where('tenant_id', $tenant->id)->find($r->item_id);
                    if (! $item) { $kept++; continue; }
                    if ((string) $item->category_id !== (string) $a->category_id) {
                        $kept++;   // changed by hand since — theirs now
                        continue;
                    }
                    $item->update(['category_id' => $r->prior_category_id]);
                    \Illuminate\Support\Facades\DB::table('tenant_category_assignment_items')
                        ->where('id', $r->id)->update(['restored_at' => now()]);
                    $restored++;
                }
            });

        \Illuminate\Support\Facades\DB::table('tenant_category_assignments')->where('id', $a->id)
            ->update(['undone_at' => now(), 'kept_count' => $kept, 'updated_at' => now()]);

        // The rule this taught is unlearned too — it was wrong.
        if ($a->bucket_key) {
            \Illuminate\Support\Facades\DB::table('tenant_bucket_rules')
                ->where('tenant_id', $tenant->id)->where('bucket_key', $a->bucket_key)
                ->where('category_id', $a->category_id)->delete();
        }

        return back()->with('flash', ['type' => 'success',
            'message' => "Undone: {$restored} item(s) back to uncategorized" . ($kept ? ", {$kept} kept (changed since)." : '.')]);
    }

    public function uncategorizedAssign(Request $request): RedirectResponse
    {
        $tenant = tenant();

        $data = $request->validate([
            'category_id' => ['required', 'uuid', \Illuminate\Validation\Rule::exists('tenant_inventory_categories', 'id')
                ->where(fn ($q) => $q->where('tenant_id', $tenant->id))], // MARKER-EXISTS-TENANT-SCOPE
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
                // MARKER-UNCAT-SOURCE — "assign all in this bucket" must follow
                // the bucket's KIND. Scoping an import bucket as a catalog
                // category matched nothing, so select-all silently assigned
                // only the ticked rows.
                if ($bucketKind === 'import') {
                    $q->where('source_category', $bucketKey);
                } else {
                    $q->whereHas('distributorCatalog', fn ($w) => $w->where('category', $bucketKey));
                }
            }
            if (filled($data['f_q'] ?? null)) {
                $s = $data['f_q'];
                $q->where(function ($w) use ($s) { // MARKER-PATCH-552 — tokenized
                    foreach (array_filter(preg_split('/\s+/', $s)) as $t) {
                        // MARKER-ITEM-IDENTIFIERS — same four identifiers as the list.
                        $w->whereRaw("CONCAT_WS(' ', name, display_subtitle, sku, catalog_upc, catalog_ean, catalog_mpn) LIKE ?", ['%' . $t . '%']);
                    }
                });
            }
        } else {
            $q->whereIn('id', $data['item_ids'] ?? []);
        }

        // MARKER-CAT-UNDO — record what each item had BEFORE, so this can be
        // undone. One batch row, one ledger row per item.
        $rows = (clone $q)->get(['id', 'category_id']);
        $assignmentId = (string) \Illuminate\Support\Str::uuid();
        // MARKER-UNCAT-SOURCE — the scope carries its kind; the ledger keeps
        // the bare string, as before.
        $rawBucket = trim((string) ($data['f_cat'] ?? $request->input('bucket', '')));
        $bucketKind = str_starts_with($rawBucket, 'src:') ? 'import' : 'catalog';
        $bucketKey  = preg_replace('/^(src|cat):/', '', $rawBucket);

        \Illuminate\Support\Facades\DB::transaction(function () use ($rows, $assignmentId, $tenant, $category, $bucketKey, $request, $q) {
            \Illuminate\Support\Facades\DB::table('tenant_category_assignments')->insert([
                'id' => $assignmentId, 'tenant_id' => $tenant->id,
                'bucket_key' => $bucketKey !== '' ? $bucketKey : null,
                'category_id' => $category->id, 'category_name' => $category->name,
                'item_count' => $rows->count(),
                'source' => in_array($request->input('source'), ['rule', 'model'], true) ? $request->input('source') : 'hand',
                'created_by' => auth('tenant')->id(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($rows->chunk(500) as $chunk) {
                \Illuminate\Support\Facades\DB::table('tenant_category_assignment_items')->insert(
                    $chunk->map(fn ($r) => ['id' => (string) \Illuminate\Support\Str::uuid(), 'assignment_id' => $assignmentId,
                                           'item_id' => $r->id, 'prior_category_id' => $r->category_id])->all()
                );
            }
        });

        $count = $q->update(['category_id' => $category->id]);

        // MARKER-CAT-UNDO — learn the rule. Picking something else next time
        // overwrites it, so a wrong first guess never sticks.
        // MARKER-CAT-RAIL2 — only a WHOLE-bucket assignment teaches a rule. A
        // partial one (47 of 1,839, split by size) says nothing about the
        // bucket, and learning from it produced "assign all 1,839 to 27.5".
        // MARKER-SOURCE-CAT — no learning. A bucket almost never maps whole:
        // assigning one size out of "Tires" taught "all Tires -> 27.5 / 650b",
        // and against no distributor in particular, so it spoke for every
        // catalog at once. Mapping is something the shop does deliberately on
        // the Category mappings page, not something inferred here.

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

        // MARKER-ITEM-CAT-TREE — same parent/children shape the index filter
        // uses, so the picker reads like the rest of inventory.
        $categories = self::categoryOptions($tenant->id);

        // MARKER-ITEM-SOURCES-EDIT — an item can come from several vendors,
        // same as everywhere else in the system.
        $vendors = \App\Models\Tenant\TenantVendor::where('tenant_id', $tenant->id)
            ->where('is_active', true)->orderBy('name')->get();

        // MARKER-ERR-HOME — categoryOptions() is declared `: array` and returns
        // one. Calling ->isEmpty() on it fataled every visit to Add Item.
        if (empty($categories)) {
            return redirect()->route('tenant.inventory.categories.index')
                ->with('flash', ['type' => 'info', 'message' => 'Create at least one category before adding items.']);
        }

        // MARKER-ITEM-SOURCES-COMPACT — _sources.blade.php loops $vendors.
        return view('tenant.inventory.create', compact('categories', 'vendors'));
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
            'category_id'           => ['required', 'uuid', \Illuminate\Validation\Rule::exists('tenant_inventory_categories', 'id')
                ->where(fn ($q) => $q->where('tenant_id', $tenant->id))], // MARKER-EXISTS-TENANT-SCOPE
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

        $this->syncItemSources($request, $item); // MARKER-ITEM-SOURCES-EDIT

        return redirect()->route('tenant.inventory.show', $item->id)
            ->with('flash', ['type' => 'success', 'message' => "Item '{$item->name}' created."]);
    }

    /**
     * MARKER-ITEM-SOURCES-EDIT — write the manual vendor rows from the form.
     *
     * Only touches rows with a NULL distributor_code. Anything the importer
     * owns is left alone: tier-2 refreshes its cost and availability, so a
     * value typed here would be silently reverted on the next sync.
     *
     * Rows the form no longer lists are deleted, so removing a vendor in the
     * UI actually removes it.
     */
    private function syncItemSources(Request $request, TenantInventoryItem $item): void
    {
        $tenantId = $item->tenant_id;

        $rows = collect((array) $request->input('sources', []))
            ->filter(fn ($r) => filled($r['vendor_id'] ?? null));

        $validVendorIds = \App\Models\Tenant\TenantVendor::where('tenant_id', $tenantId)
            ->pluck('id')->flip();

        $keptIds = [];

        foreach ($rows as $r) {
            if (! $validVendorIds->has($r['vendor_id'])) {
                continue; // not this tenant's vendor
            }

            $cost = $r['unit_cost'] ?? null;

            $payload = [
                'vendor_sku'      => filled($r['vendor_sku'] ?? null) ? $r['vendor_sku'] : null,
                'unit_cost_cents' => ($cost === null || $cost === '') ? null : (int) round((float) $cost * 100),
                'lead_time_days'  => filled($r['lead_time_days'] ?? null) ? (int) $r['lead_time_days'] : null,
            ];

            // firstOrNew on the pair, because (inventory_item_id, vendor_id)
            // is unique — the same vendor twice in the form must not collide.
            $row = \App\Models\Tenant\TenantInventoryItemVendor::firstOrNew([
                'inventory_item_id' => $item->id,
                'vendor_id'         => $r['vendor_id'],
            ]);

            if ($row->exists && $row->distributor_code !== null) {
                $keptIds[] = $row->id; // synced row, leave it be
                continue;
            }

            $row->fill($payload)->save();
            $keptIds[] = $row->id;
        }

        // Drop manual rows the form dropped. Synced rows are never removed
        // here — only the importer owns those.
        \App\Models\Tenant\TenantInventoryItemVendor::where('inventory_item_id', $item->id)
            ->whereNull('distributor_code')
            ->whereNotIn('id', $keptIds ?: ['-'])
            ->delete();

        // Preferred is single-choice across every source, synced included,
        // because autoAssignVendor's `preferred` rule reads exactly one flag.
        $preferred = (string) $request->input('preferred_source', '');
        if ($preferred !== '') {
            \App\Models\Tenant\TenantInventoryItemVendor::where('inventory_item_id', $item->id)
                ->update(['is_preferred' => false]);
            \App\Models\Tenant\TenantInventoryItemVendor::where('inventory_item_id', $item->id)
                ->where('id', $preferred)->update(['is_preferred' => true]);
        }
    }

    public function show(Request $request, string $id): View
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        // MARKER-ARCHIVE-MOVE — withTrashed, otherwise the archived list links
        // to a 404 and Restore is unreachable.
        $item = TenantInventoryItem::withTrashed()
            ->with(['category', 'distributorCatalog', 'locations.location', 'specialOrders.vendor', 'specialOrders.customer', 'specialOrders.appointment', 'vendors'])
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

        // MARKER-ITEM-CAT-TREE — same parent/children shape the index filter
        // uses, so the picker reads like the rest of inventory.
        $categories = self::categoryOptions($tenant->id);

        // MARKER-ITEM-SOURCES-EDIT — an item can come from several vendors,
        // same as everywhere else in the system.
        $vendors = \App\Models\Tenant\TenantVendor::where('tenant_id', $tenant->id)
            ->where('is_active', true)->orderBy('name')->get();

        // MARKER-ITEM-SOURCES-COMPACT
        return view('tenant.inventory.edit', compact('item', 'categories', 'vendors'));
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        $item = TenantInventoryItem::where('tenant_id', $tenant->id)->findOrFail($id);

        $data = $request->validate([
            'category_id'             => ['required', 'uuid', \Illuminate\Validation\Rule::exists('tenant_inventory_categories', 'id')
                ->where(fn ($q) => $q->where('tenant_id', $tenant->id))], // MARKER-EXISTS-TENANT-SCOPE
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

        $this->syncItemSources($request, $item); // MARKER-ITEM-SOURCES-EDIT

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
            'location_id' => ['required', 'uuid', \Illuminate\Validation\Rule::exists('tenant_locations', 'id')
                ->where(fn ($q) => $q->where('tenant_id', $tenant->id))], // MARKER-EXISTS-TENANT-SCOPE
            'new_count'   => ['required', 'integer', 'min:0'],
            'reason_code' => ['required', 'string', 'in:damaged,expired,theft_shrinkage,count_correction,found,vendor_credit,donation,internal_use,display,sample,other'],
            'reason_text' => ['nullable', 'string', 'max:500'],
            'notes'       => ['nullable', 'string', 'max:1000'],
            'unit_cost'   => ['nullable', 'numeric', 'min:0'], // MARKER-RECEIVED-COST
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
                unitCostCents: isset($data['unit_cost']) && $data['unit_cost'] !== '' && $data['unit_cost'] !== null
                    ? (int) round(((float) $data['unit_cost']) * 100) : null, // MARKER-RECEIVED-COST
            );
        } catch (InvalidQuantityException $e) {
            return back()->withInput()->withErrors(['reason_code' => $e->getMessage()]);
        }

        return redirect()->route('tenant.inventory.show', $item->id)
            ->with('flash', ['type' => 'success', 'message' => 'Stock adjusted.']);
    }

    /**
     * MARKER-ARCHIVE-MOVE — undo an archive.
     *
     * destroy() does two things, so this undoes both: the soft delete and
     * the is_active flag. Nothing else is touched by either, so the item
     * returns exactly as it was — stock, vendor sources and history
     * included.
     */
    public function restore(string $id): RedirectResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        $item = TenantInventoryItem::withTrashed()
            ->where('tenant_id', $tenant->id)->findOrFail($id);

        $item->restore();
        $item->update(['is_active' => true]);

        return redirect()->route('tenant.inventory.show', $item->id)
            ->with('flash', ['type' => 'success', 'message' => "'{$item->name}' restored."]);
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
