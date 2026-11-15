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
            // a parent category counts what is under it too, since picking it shows those
            $rows = [];
            foreach ($cats as $id => $c) {
                $n = 0;
                foreach ($this->search->withChildren([$id]) as $sub) $n += (int) ($raw[$sub] ?? 0);
                if (! $n) continue;
                $rows[] = ['id' => (string) $id, 'name' => $c['name'], 'parent' => $c['parent'] ? ($cats[$c['parent']]['name'] ?? null) : null, 'n' => $n];
            }
            usort($rows, fn ($a, $b) => $b['n'] <=> $a['n'] ?: strcmp($a['name'], $b['name']));
            // a picked category that now has nothing still shows, so it can be unpicked
            foreach ($f['cat'] as $id) if (isset($cats[$id]) && ! collect($rows)->firstWhere('id', $id)) $rows[] = ['id' => $id, 'name' => $cats[$id]['name'], 'parent' => null, 'n' => 0];
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
        if ($f['min'] !== null || $f['max'] !== null) {
            $q = request()->query(); unset($q['min'], $q['max'], $q['page']);
            $out[] = ['label' => ($f['min'] !== null ? ShopSearch::money($f['min']) : '$0') . ' – ' . ($f['max'] !== null ? ShopSearch::money($f['max']) : 'any'), 'url' => '/shop' . ($q ? '?' . http_build_query($q) : '')];
        }
        return $out;
    }
}
