<?php

namespace App\Support\ShopSearch;

use App\Models\Tenant;
use App\Models\Tenant\TenantInventoryItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-SHOP-SEARCH-V2: the /shop page. A search, a category, or both, with
 * filters whose counts follow what the shopper has already picked (each filter
 * counts as if it were not set, so a shopper can always widen again).
 */
class ShopBrowse
{
    public const PER_PAGE = 24;

    public array $f = [];
    public array $run = [];
    public ShopSearch $search;
    public array $display;

    public function __construct(public Tenant $tenant, Request $r)
    {
        $this->search  = ShopSearch::for($tenant);
        $this->display = SearchConfig::display($tenant);

        $list = fn ($k) => array_values(array_filter(array_map('strval', (array) $r->query($k, [])), fn ($v) => $v !== ''));
        $cats = $list('cat');
        if ($r->filled('category')) $cats[] = (string) $r->query('category'); // links from before keep working
        $dollars = fn ($k) => is_numeric($r->query($k)) ? max(0, (int) round(((float) $r->query($k)) * 100)) : null;

        $this->f = [
            'q'       => trim((string) $r->query('q', '')),
            'literal' => $r->boolean('literal'),
            'cat'     => array_values(array_unique($cats)),
            'brand'   => $list('brand'),
            'avail'   => array_values(array_intersect($list('avail'), ['in', 'so', 'out'])),
            'sale'    => $r->boolean('sale'),
            // MARKER-OPTION-FILTERS: option fields from master admin › Option splitting (casing, compound…), keyed s_*
            'opt'     => self::opts($r),
            'min'     => $dollars('min'),
            'max'     => $dollars('max'),
            'sort'    => in_array($r->query('sort'), ['best', 'featured', 'price_asc', 'price_desc', 'newest'], true) ? $r->query('sort') : null,
            'view'    => in_array($r->query('view'), ['grid', 'list'], true) ? $r->query('view') : $this->display['layout'],
        ];
        if (! $this->f['sort']) $this->f['sort'] = $this->f['q'] !== '' ? 'best' : 'featured';
    }

    /** Everything this page may show before the shopper's filters. */
    private function base(): Builder
    {
        if ($this->f['q'] === '') return $this->search->base(false);
        $ids = $this->run['ids'] ?: ['00000000-0000-0000-0000-000000000000'];
        return $this->search->base(false)->whereIn('tenant_inventory_items.id', $ids);
    }

    private function filtered(?string $skip = null): Builder
    {
        $q = $this->base();
        $f = $this->f;
        if ($skip !== 'cat' && $f['cat']) $q->whereIn('tenant_inventory_items.category_id', $this->search->withChildren($f['cat']));
        if ($skip !== 'brand' && $f['brand']) $q->whereIn(DB::raw(ShopSearch::BRAND_SQL), $f['brand']);
        if ($skip !== 'avail' && $f['avail']) {
            $q->where(function ($w) use ($f) {
                if (in_array('in', $f['avail'], true))  $w->orWhere('tenant_inventory_items.computed_stock_count', '>', 0);
                if (in_array('so', $f['avail'], true))  $w->orWhere(fn ($x) => $x->where('tenant_inventory_items.computed_stock_count', '<=', 0)->where('tenant_inventory_items.allow_oversell', true));
                if (in_array('out', $f['avail'], true)) $w->orWhere(fn ($x) => $x->where('tenant_inventory_items.computed_stock_count', '<=', 0)->where(fn ($y) => $y->where('tenant_inventory_items.allow_oversell', false)->orWhereNull('tenant_inventory_items.allow_oversell')));
            });
        }
        foreach ($f['opt'] as $k => $vals) {
            if ($skip === 'opt:' . $k || $skip === 'opt') continue;
            $q->whereHas('distributorCatalog', fn ($c) => $c->whereIn('spec_attrs->' . $k, $vals));
        }
        if ($skip !== 'sale' && $f['sale']) $q->whereRaw('tenant_inventory_items.compare_at_cents > ' . ShopSearch::PRICE_SQL);
        if ($skip !== 'price') {
            if ($f['min'] !== null) $q->whereRaw(ShopSearch::PRICE_SQL . ' >= ?', [$f['min']]);
            if ($f['max'] !== null) $q->whereRaw(ShopSearch::PRICE_SQL . ' <= ?', [$f['max']]);
        }
        return $q;
    }

    private function counts(?string $skip, string $expr): array
    {
        return $this->filtered($skip)->toBase()->reorder()
            ->selectRaw($expr . ' AS k, COUNT(*) AS n')->groupBy('k') // grouped by the alias: strict MySQL (only_full_group_by) rejects repeating an expression with a subquery in it
            ->pluck('n', 'k')->all();
    }

    public function build(): array
    {
        $f = $this->f;
        if ($f['q'] !== '') {
            $this->run = $this->search->run($f['q'], $f['literal']);
            if ($this->run['redirect']) return ['redirect' => $this->run['redirect']['url']];
        }

        // ---- the listing
        $page = max(1, (int) request()->query('page', 1));
        if ($f['q'] !== '' && $f['sort'] === 'best') {
            $allowed = array_flip($this->filtered()->pluck('tenant_inventory_items.id')->map(fn ($x) => (string) $x)->all());
            $ordered = array_values(array_filter($this->run['ids'], fn ($id) => isset($allowed[$id])));
            $total   = count($ordered);
            $slice   = array_slice($ordered, ($page - 1) * self::PER_PAGE, self::PER_PAGE);
            $byId    = $this->load(TenantInventoryItem::query()->whereIn('id', $slice))->get()->keyBy(fn ($i) => (string) $i->id);
            $items   = collect($slice)->map(fn ($id) => $byId[$id] ?? null)->filter()->values();
            $paginator = new LengthAwarePaginator($items, $total, self::PER_PAGE, $page, ['path' => '/shop', 'query' => request()->query()]);
        } else {
            $q = $this->filtered();
            match ($f['sort']) {
                'price_asc'  => $q->orderByRaw(ShopSearch::PRICE_SQL . ' ASC'),
                'price_desc' => $q->orderByRaw(ShopSearch::PRICE_SQL . ' DESC'),
                'newest'     => $q->orderByDesc('tenant_inventory_items.created_at'),
                default      => $q->orderByDesc('tenant_inventory_items.computed_stock_count')->orderBy('tenant_inventory_items.name'),
            };
            $paginator = $this->load($q)->paginate(self::PER_PAGE)->withQueryString();
            $paginator->withPath('/shop');
        }

        // ---- the filters, each counted as if it were not set
        $facets = [];
        $on = array_flip($this->display['facets']);
        $cats = $this->search->categories();
        if (isset($on['category'])) {
            $raw = $this->counts('cat', 'tenant_inventory_items.category_id');
            // a parent category counts what is under it too, since picking it shows those.
            // Rows come out as a tree: top-level categories by count, each followed by its own
            // children indented beneath it (depth), never mixed in at the same level.
            $kids = [];
            foreach ($cats as $id => $c) $kids[$c['parent'] && isset($cats[$c['parent']]) ? $c['parent'] : ''][] = (string) $id;
            $count = [];
            foreach ($cats as $id => $c) {
                $n = 0;
                foreach ($this->search->withChildren([$id]) as $sub) $n += (int) ($raw[$sub] ?? 0);
                $count[(string) $id] = $n;
            }
            $picked = array_flip($f['cat']);
            $rows = [];
            $emit = function (string $parent, int $depth, ?int $group) use (&$emit, &$rows, $kids, $cats, $count, $picked) {
                $list = array_values(array_filter($kids[$parent] ?? [], fn ($id) => $count[$id] > 0 || array_intersect_key($picked, array_flip($this->search->withChildren([$id])))));
                usort($list, fn ($a, $b) => $count[$b] <=> $count[$a] ?: strcmp($cats[$a]['name'], $cats[$b]['name']));
                foreach ($list as $i => $id) {
                    $g = $group ?? $i; // which top-level group the row belongs to, for "Show all"
                    $rows[] = ['id' => $id, 'name' => $cats[$id]['name'], 'parent' => null, 'depth' => $depth, 'group' => $g, 'n' => $count[$id]];
                    $emit($id, $depth + 1, $g);
                }
            };
            $emit('', 0, null);
            foreach ($rows as &$r) $r['img'] = $this->display['photos'] ? $this->search->categoryImage($r['id']) : null;
            unset($r);
            $facets['category'] = $rows;
        }
        if (isset($on['brand'])) {
            $raw = $this->counts('brand', ShopSearch::BRAND_SQL);
            $rows = [];
            foreach ($raw as $b => $n) if ($b !== null && $b !== '') $rows[] = ['id' => (string) $b, 'name' => (string) $b, 'n' => (int) $n];
            usort($rows, fn ($a, $b) => $b['n'] <=> $a['n'] ?: strcmp($a['name'], $b['name']));
            if (count($rows) > 1 || $f['brand']) $facets['brand'] = $rows;
        }
        if ($opts = $this->optionFacets()) $facets['options'] = $opts;
        if (isset($on['availability'])) {
            $raw = $this->counts('avail', "CASE WHEN tenant_inventory_items.computed_stock_count > 0 THEN 'in' WHEN tenant_inventory_items.allow_oversell = 1 THEN 'so' ELSE 'out' END");
            $names = ['in' => 'In stock', 'so' => 'Special order', 'out' => 'Out of stock'];
            $rows = [];
            foreach ($names as $k => $label) if (! empty($raw[$k]) || in_array($k, $f['avail'], true)) $rows[] = ['id' => $k, 'name' => $label, 'n' => (int) ($raw[$k] ?? 0)];
            if (count($rows) > 1 || $f['avail']) $facets['availability'] = $rows;
        }
        if (isset($on['sale'])) {
            $n = $this->filtered('sale')->whereRaw('tenant_inventory_items.compare_at_cents > ' . ShopSearch::PRICE_SQL)->count();
            if ($n || $f['sale']) $facets['sale'] = $n;
        }
        if (isset($on['price'])) {
            $mm = $this->filtered('price')->toBase()->reorder()->selectRaw('MIN(' . ShopSearch::PRICE_SQL . ') AS lo, MAX(' . ShopSearch::PRICE_SQL . ') AS hi')->first();
            $facets['price'] = ['lo' => $mm?->lo !== null ? (int) floor($mm->lo / 100) : null, 'hi' => $mm?->hi !== null ? (int) ceil($mm->hi / 100) : null];
        }

        // ---- promotions: for the words searched, or the categories picked
        $words = $this->run['words'] ?? [];
        $banner = $this->search->promotions($words, $f['cat'], 'banner')->first();
        $card   = $this->search->promotions($words, $f['cat'], 'card')->first();
        ShopSearch::seen(array_filter([$banner, $card]));

        $base = $f['q'] !== '' ? count($this->run['ids']) : null;

        return [
            'items'     => $paginator,
            'facets'    => $facets,
            'f'         => $f,
            'run'       => $this->run,
            'baseCount' => $base,
            'banner'    => ShopSearch::promoArray($banner),
            'card'      => ShopSearch::promoArray($card),
            'chips'     => $this->chips($cats),
        ];
    }

    /** opt[s_casing][]=EXO → ['s_casing' => ['EXO']], only keys an active Option splitting rule defines. */
    private static function opts(Request $r): array
    {
        $known = array_flip(array_column(\App\Support\CatalogSpecs::rules(), 'key'));
        $out = [];
        foreach ((array) $r->query('opt', []) as $k => $vals) {
            $k = (string) $k;
            if (! isset($known[$k]) || ! preg_match('/^s_[a-z0-9_]+$/', $k)) continue;
            $vals = array_values(array_unique(array_filter(array_map('strval', (array) $vals), fn ($v) => $v !== '')));
            if ($vals) $out[$k] = $vals;
        }
        return $out;
    }

    /** Most rows read to build the option filters. Past this the shopper is browsing too wide for them to mean much. */
    public const OPTION_SCAN_MAX = 5000;

    /**
     * MARKER-OPTION-FILTERS: one filter per Option splitting field that the
     * items on this page carry, shown once a category or search narrows the
     * page. Each field counts as if it were not set, like the others.
     * [[key, name, rows:[[id,name,n]]]]
     */
    private function optionFacets(): array
    {
        $f = $this->f;
        $rules = \App\Support\CatalogSpecs::rules();
        if (! $rules || ($f['q'] === '' && ! $f['cat'] && ! $f['opt'])) return [];
        // the shop's base query has unqualified columns (is_active, tenant_id), so no join:
        // read the items' catalog ids, then the catalog rows on their own
        $catIds = $this->filtered('opt')->toBase()->reorder()
            ->whereNotNull('tenant_inventory_items.distributor_catalog_id')
            ->limit(self::OPTION_SCAN_MAX)
            ->pluck('tenant_inventory_items.distributor_catalog_id')->all();
        $rows = [];
        foreach (array_chunk($catIds, 1000) as $chunk) {
            foreach (DB::table('platform_distributor_catalogs')->whereIn('id', $chunk)->whereNotNull('spec_attrs')->pluck('spec_attrs', 'id') as $id => $j) {
                $rows[(string) $id] = $j;
            }
        }
        // one entry per item, not per catalog row: two items on one catalog row count twice
        $rows = array_values(array_filter(array_map(fn ($id) => $rows[(string) $id] ?? null, $catIds)));
        if (! $rows) return [];
        $rows = array_map(fn ($j) => is_array($d = json_decode((string) $j, true)) ? $d : [], $rows);
        $out = [];
        foreach ($rules as $rule) {
            $k = $rule['key'];
            if (isset($out[$k])) continue;
            $n = [];
            foreach ($rows as $a) {
                // the shopper's picks on the OTHER option fields still narrow this one
                foreach ($f['opt'] as $ok => $vals) {
                    if ($ok !== $k && ! in_array((string) ($a[$ok] ?? ''), $vals, true)) continue 2;
                }
                $v = (string) ($a[$k] ?? '');
                if ($v !== '') $n[$v] = ($n[$v] ?? 0) + 1;
            }
            foreach ($f['opt'][$k] ?? [] as $picked) $n[$picked] = $n[$picked] ?? 0;
            if (count($n) < 2 && empty($f['opt'][$k])) continue;
            $list = [];
            foreach ($n as $v => $c) $list[] = ['id' => (string) $v, 'name' => (string) $v, 'n' => (int) $c];
            usort($list, fn ($a, $b) => $b['n'] <=> $a['n'] ?: strnatcasecmp($a['name'], $b['name']));
            $out[$k] = ['key' => $k, 'name' => $rule['name'], 'rows' => $list];
        }
        return array_values($out);
    }

    private function load(Builder $q): Builder
    {
        return $q->with(['distributorCatalog:id,manufacturer,images,distributor_code', 'category:id,name', 'itemImages.media']);
    }

    /** The filters the shopper has on, each with the address that takes it off. */
    private function chips(array $cats): array
    {
        $f = $this->f;
        $out = [];
        $without = function (string $key, $value = null) {
            $q = request()->query();
            unset($q['page']);
            if ($key === 'cat') unset($q['category']);
            if ($value === null) unset($q[$key]);
            else $q[$key] = array_values(array_filter((array) ($q[$key] ?? []), fn ($v) => (string) $v !== (string) $value));
            return '/shop' . ($q ? '?' . http_build_query($q) : '');
        };
        foreach ($f['cat'] as $c) $out[] = ['label' => $cats[$c]['name'] ?? 'Category', 'url' => $without('cat', $c)];
        foreach ($f['brand'] as $b) $out[] = ['label' => $b, 'url' => $without('brand', $b)];
        $names = ['in' => 'In stock', 'so' => 'Special order', 'out' => 'Out of stock'];
        foreach ($f['avail'] as $a) $out[] = ['label' => $names[$a], 'url' => $without('avail', $a)];
        if ($f['sale']) $out[] = ['label' => 'On sale', 'url' => $without('sale')];
        $names2 = array_column(\App\Support\CatalogSpecs::rules(), 'name', 'key');
        foreach ($f['opt'] as $k => $vals) {
            foreach ($vals as $v) {
                $q = request()->query(); unset($q['page']);
                $q['opt'][$k] = array_values(array_filter((array) ($q['opt'][$k] ?? []), fn ($x) => (string) $x !== (string) $v));
                if (! $q['opt'][$k]) unset($q['opt'][$k]);
                if (empty($q['opt'])) unset($q['opt']);
                $out[] = ['label' => ($names2[$k] ?? 'Option') . ': ' . $v, 'url' => '/shop' . ($q ? '?' . http_build_query($q) : '')];
            }
        }
        if ($f['min'] !== null || $f['max'] !== null) {
            $q = request()->query(); unset($q['min'], $q['max'], $q['page']);
            $out[] = ['label' => ($f['min'] !== null ? ShopSearch::money($f['min']) : '$0') . ' – ' . ($f['max'] !== null ? ShopSearch::money($f['max']) : 'any'), 'url' => '/shop' . ($q ? '?' . http_build_query($q) : '')];
        }
        return $out;
    }
}
