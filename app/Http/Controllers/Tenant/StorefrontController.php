<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantInventoryCategory;
use App\Models\Tenant\TenantInventoryItem;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Online Retail Wave 2: the read-only storefront.
 * Grid + product page over inventory the tenant has opted online.
 * No cart, no writes — Wave 3 adds the cart on top of these views.
 */
class StorefrontController extends Controller
{
    /**
     * storefront gates through the addon framework
     * (online_store: included branded+scale, never starter), replacing
     * 562's hardcoded tier match. One gating system everywhere.
     */
    private function guardTier(): void
    {
        $ok = app(\App\Services\FeatureAccessService::class)
            ->hasAddon(tenant(), 'online_store');
        // tenant master switch on top of the addon gate
        $ok = $ok && (bool) ((tenant()->settings['storefront']['enabled'] ?? true));
        abort_unless($ok, 404);
        if (! \App\Support\Launch\Launch::visible(tenant(), 'ecom')) { // MARKER-LAUNCH-CHECKLIST: preview = staff only
            abort(response()->view('public.launch-preview', ['module' => 'ecom'], 200));
        }
    }

    /** Base query for anything the store may show. */
    private function visible()
    {
        $this->guardTier();
        // MARKER-GOOGLE-FEED: one rule for what is for sale online, shared with the Google feed
        $q = \App\Services\Tenant\OnlineItems::query(tenant());

        return $q;
    }

    /**
     * MARKER-SHOP-SEARCH-V2: GET /shop. Browse, search, or both, with filters,
     * promotions, quick view and Add to cart. See App\Support\ShopSearch\ShopBrowse.
     */
    public function index(Request $request)
    {
        $tenant = tenant();
        $this->guardTier();

        $browse = new \App\Support\ShopSearch\ShopBrowse($tenant, $request);
        $data   = $browse->build();
        if (isset($data['redirect'])) {
            \App\Models\Tenant\TenantSearchQuery::log($tenant->id, mb_substr((string) $request->session()->getId(), 0, 64), (string) $request->query('q'), 1);
            return redirect($data['redirect']);
        }

        $f   = $data['f'];
        $qid = null;
        if ($f['q'] !== '') {
            $qid = \App\Models\Tenant\TenantSearchQuery::log($tenant->id, mb_substr((string) $request->session()->getId(), 0, 64), $f['q'], (int) $data['baseCount']);
            if (! $data['baseCount']) $this->buyingSignal($request, $f['q'], collect());
        }

        $items = $data['items'];
        $sup   = \App\Services\Tenant\OrderStock::forTenant($tenant)->supplierBacked($items->getCollection()->pluck('id')->all());
        $cards = $items->getCollection()->map(fn ($i) => \App\Support\ShopSearch\ShopSearch::card($i, $browse->display, $sup))->all();

        $cats    = $browse->search->categories();
        $catName = count($f['cat']) === 1 ? ($cats[$f['cat'][0]]['name'] ?? null) : null;

        // tiles for the no-results page and the top of the shop: categories that have items online
        $tileIds = array_keys(array_filter($browse->search->base(false)->toBase()->reorder()->selectRaw('category_id AS k, COUNT(*) AS n')->groupBy('category_id')->pluck('n', 'k')->all()));
        $tiles = collect($tileIds)->filter(fn ($id) => $id && isset($cats[$id]))
            ->map(fn ($id) => ['id' => (string) $id, 'name' => $cats[$id]['name'], 'img' => $browse->display['photos'] ? $browse->search->categoryImage((string) $id) : null])
            ->sortBy('name')->take(12)->values()->all();

        return \App\Services\Tenant\SiteChromeService::render($tenant, 'shop_index', [
            'tenant'     => $tenant,
            'browse'     => $data,
            'cards'      => $cards,
            'items'      => $items,
            'f'          => $f,
            'qid'        => $qid,
            'display'    => $browse->display,
            'catName'    => $catName,
            'tiles'      => $tiles,
            'popular'    => $browse->search->popular(),
            'contactUrl' => $browse->search->contactUrl(),
            'cartCount'  => \App\Services\Tenant\CartService::forTenant($tenant)->itemCount(),
            'browseLayout' => (string) (($tenant->settings['storefront']['browse_layout'] ?? null) ?: 'chips'),
            // kept for the GA4 list and older partials
            'q'          => $f['q'],
            'activeCat'  => $f['cat'][0] ?? null,
            'categories' => collect($cats)->map(fn ($c) => (object) ['id' => $c['id'], 'name' => $c['name']])->values(),
        ], [
            // MARKER-ITEM-SEO
            'title'       => $f['q'] !== '' ? 'Search: ' . $f['q'] : (($catName ? $catName . ' — ' : '') . 'Shop'),
            'description' => 'Shop online from ' . $tenant->name . '. ' . (trim((string) ($tenant->settings['storefront']['intro'] ?? '')) ?: 'Browse our products and order for pickup, delivery or shipping.'),
        ]);
    }

    /** MARKER-BUYING: nothing found, or nothing found in stock, is an ordering signal. */
    private function buyingSignal(Request $request, string $q, $items): void
    {
        try {
            $top = $items->first();
            \App\Services\Tenant\BuyingSearchLog::record(
                tenant()->id, 'online', $request->session()->getId(), null, $q,
                $top?->id, $top ? (int) $top->computed_stock_count : null,
                $items->contains(fn ($i) => (int) $i->computed_stock_count > 0)
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * MARKER-SHOP-SEARCH-V2: GET /shop/search.json, the instant dropdown.
     * Empty q: recent (kept in the browser), popular, category tiles, a promotion.
     */
    public function searchJson(Request $request)
    {
        $this->guardTier();
        $tenant  = tenant();
        $search  = \App\Support\ShopSearch\ShopSearch::for($tenant);
        $display = \App\Support\ShopSearch\SearchConfig::display($tenant);
        $q       = trim((string) $request->query('q', ''));
        $literal = $request->boolean('literal');
        $cats    = $search->categories();
        $tile    = fn ($id, $n = null) => ['id' => (string) $id, 'name' => $cats[$id]['name'] ?? '', 'img' => $display['photos'] ? $search->categoryImage((string) $id) : null, 'n' => $n, 'url' => '/shop?cat[]=' . urlencode((string) $id)];

        if (mb_strlen($q) < 2) {
            $promo = $search->promotionsLive()->first(fn ($p) => $p->places('dropdown'));
            \App\Support\ShopSearch\ShopSearch::seen(array_filter([$promo]));
            $ids = $display['dd_categories']
                ? array_keys(array_filter($search->base(false)->toBase()->reorder()->selectRaw('category_id AS k, COUNT(*) AS n')->groupBy('category_id')->orderByDesc('n')->limit(6)->pluck('n', 'k')->all()))
                : [];
            return response()->json([
                'popular'    => $display['popular'] ? $search->popular() : [],
                'categories' => collect($ids)->filter(fn ($id) => $id && isset($cats[$id]))->map(fn ($id) => $tile($id))->values(),
                'promo'      => \App\Support\ShopSearch\ShopSearch::promoArray($promo),
            ]);
        }

        $run = $search->run($q, $literal);
        $sid = mb_substr((string) $request->session()->getId(), 0, 64);

        if ($run['redirect']) {
            \App\Models\Tenant\TenantSearchQuery::log($tenant->id, $sid, $q, 1);
            return response()->json(['items' => [], 'redirect' => $run['redirect']]);
        }

        $total = count($run['ids']);
        $top   = array_slice($run['ids'], 0, 6);
        $byId  = \App\Models\Tenant\TenantInventoryItem::query()->whereIn('id', $top)
            ->with(['distributorCatalog:id,manufacturer,images,distributor_code', 'itemImages.media'])->get()->keyBy(fn ($i) => (string) $i->id);
        $items = collect($top)->map(fn ($id) => $byId[$id] ?? null)->filter()->values();

        $qid = \App\Models\Tenant\TenantSearchQuery::log($tenant->id, $sid, $q, $total);
        $this->buyingSignal($request, $q, $items);

        $promo = $search->promotions($run['words'], [], 'dropdown')->first();
        \App\Support\ShopSearch\ShopSearch::seen(array_filter([$promo]));

        return response()->json([
            'qid'         => $qid,
            'corrected'   => $run['corrected'],
            'total'       => $total,
            'highlight'   => $run['highlight'],
            'suggestions' => $search->suggestions($q),
            'categories'  => $display['dd_categories'] ? collect(array_slice($run['cats'], 0, 3, true))->map(fn ($n, $id) => $tile($id, $n))->values() : [],
            'promo'       => \App\Support\ShopSearch\ShopSearch::promoArray($promo),
            'popular'     => $total ? [] : $search->popular(),
            'items'       => $items->map(function ($i) use ($display) {
                $c = \App\Support\ShopSearch\ShopSearch::card($i, $display);
                $c['price_text'] = \App\Support\ShopSearch\ShopSearch::money($c['price']);
                $c['was_text']   = $c['was'] ? \App\Support\ShopSearch\ShopSearch::money($c['was']) : null;
                if (! $display['photos']) $c['img'] = null;
                return $c;
            })->values(),
        ]);
    }

    /** MARKER-SHOP-SEARCH-V2: GET /shop/search/click. A shopper opened an item from search. */
    public function searchClick(Request $request)
    {
        $this->guardTier();
        $item = (string) $request->query('item', '');
        $qid  = (string) $request->query('qid', '');
        if ($item !== '' && \Illuminate\Support\Str::isUuid($item)) {
            \App\Support\ShopSearch\SearchTracking::click(tenant(), $request, $item, $qid ?: null, (string) $request->query('q', ''));
        }
        return response()->noContent();
    }

    /** MARKER-SHOP-SEARCH-V2: GET /shop/search/promo/{id}. Count the click, then go where the shop chose. */
    public function promoClick(Request $request, string $id)
    {
        $this->guardTier();
        $p = \App\Models\Tenant\TenantSearchPromotion::where('tenant_id', tenant()->id)->findOrFail($id);
        $pc = (array) session('shop_search_pc', []);
        if (! isset($pc[$p->id])) { // one click per visit, so a shopper going back and forth counts once
            $p->newQuery()->whereKey($p->id)->increment('click_count');
            $pc[$p->id] = 1;
            session(['shop_search_pc' => array_slice($pc, -40, null, true)]);
            \App\Support\ShopSearch\SearchTracking::promo(tenant(), $request, $p);
        }
        return redirect($p->targetUrl());
    }

    /** MARKER-SHOP-SEARCH-V2: GET /shop/quick/{id}. What quick view shows. */
    public function quick(string $id)
    {
        $item = $this->visible()->with(['distributorCatalog', 'itemImages.media'])->findOrFail($id);
        $display = \App\Support\ShopSearch\SearchConfig::display(tenant());
        $sup = \App\Services\Tenant\OrderStock::forTenant(tenant())->supplierBacked([$item->id]);
        $c = \App\Support\ShopSearch\ShopSearch::card($item, $display, $sup);
        $c['images']     = collect($item->displayImages(5))->reject(fn ($x) => $x['hidden'])->pluck('url')->values();
        $c['price_text'] = \App\Support\ShopSearch\ShopSearch::money($c['price']);
        $c['was_text']   = $c['was'] ? \App\Support\ShopSearch\ShopSearch::money($c['was']) : null;
        $c['blurb']      = \Illuminate\Support\Str::limit(trim(strip_tags((string) ($item->description ?: $item->display_subtitle))), 260);
        $c['state_text'] = match ($c['state']) {
            'in'    => $c['stock'] > 0 ? $c['stock'] . ' in stock' : 'In stock',
            'so'    => 'Special order',
            default => \App\Services\Tenant\OrderService::wording(tenant())['out_label'],
        };
        $c['can_add'] = $c['state'] !== 'out' && $c['price'] !== null;
        return response()->json($c);
    }


    public function show(string $id)
    {
        // MARKER-ITEM-SEO: /shop/{words}-{id}. The id finds the item; if the words are old or
        // missing, send the visitor (and search engines) to the right address for good.
        $realId = \App\Support\ItemSeo::idFromParam($id) ?? $id;
        $item = $this->visible()
            ->with(['distributorCatalog', 'category:id,name'])
            ->where('id', $realId)
            ->firstOrFail();

        $canonicalParam = \App\Support\ItemSeo::param((string) $item->id, $item->name);
        if ($id !== $canonicalParam) {
            $qs = request()->getQueryString();
            return redirect('/shop/' . $canonicalParam . ($qs ? '?' . $qs : ''), 301);
        }

        return $this->renderItem($item);
    }

    /**
     * The item page for one item. Also used by master admin's catalog
     * preview (CatalogPreviewController), so the preview is this page.
     */
    public function renderItem($item)
    {
        $cat = $item->distributorCatalog;

        // was passing $cat?->tenant_id, but
        // $cat is a platform catalog row shared across tenants and has no
        // tenant_id at all. The CLS prefix is per tenant, so that resolved to
        // null and QBP galleries came out empty.
        //
        // Hidden distributor images are honoured here too: switching one off
        // on the item page takes it off the storefront, which is the point.
        $images = collect($item->displayImages(6))
            ->reject(fn ($img) => $img['hidden'])
            ->pluck('url')
            ->values()
            ->all();

        $attrs = collect((array) ($cat?->attributes ?? []))
            ->filter(fn ($a) => is_array($a) && !empty($a['Name']) && trim((string) ($a['Value'] ?? '')) !== '')
            ->map(fn ($a) => ['name' => $a['Name'], 'value' => $a['Value']])
            ->values()->all();

        // MARKER-PRODUCT-DETAILS: specs in groups, geometry, and the other sizes that are for sale here
        $pd = \App\Support\ProductDetails::for($item);
        $pdSizes = [];
        if (! empty($pd['sizes'])) {
            $ids = array_values(array_filter(array_column($pd['sizes'], 'item_id')));
            $online = $ids ? \App\Services\Tenant\OnlineItems::query(tenant())->whereIn('id', $ids)->pluck('name', 'id')->all() : [];
            foreach ($pd['sizes'] as $z) {
                if ($z['current']) {
                    $pdSizes[] = $z + ['href' => null];
                } elseif ($z['item_id'] && isset($online[$z['item_id']])) {
                    $pdSizes[] = $z + ['href' => '/shop/' . \App\Support\ItemSeo::param((string) $z['item_id'], (string) $online[$z['item_id']])];
                }
            }
            if (count($pdSizes) < 2) {
                $pdSizes = [];
            }
        }

        return \App\Services\Tenant\SiteChromeService::render(tenant(), 'shop_show', [
            'shopItem' => $item, // collision-proof alias
            'cartCount' => \App\Services\Tenant\CartService::forTenant(tenant())->itemCount(),
            'tenant' => tenant(),
            'item'   => $item,
            'images' => $images,
            'attrs'  => $attrs,
            'pd'     => $pd,
            'pdSizes' => $pdSizes,
            'brand'  => $item->shop_brand ?: $cat?->manufacturer,
            'shareOn' => (bool) (tenant()->settings['storefront']['share_on'] ?? true), // MARKER-ITEM-SEO
        ], $this->seoMeta($item, $images, $item->shop_brand ?: $cat?->manufacturer));
    }

    /** MARKER-ITEM-SEO: the item page's title, description and link-preview card. */
    private function seoMeta($item, array $images, ?string $brand): array
    {
        $tenant = tenant();
        $price  = $item->effectiveSellPriceCents();
        $extra  = [];
        if ($price !== null) {
            $extra[] = ['product:price:amount', number_format($price / 100, 2, '.', '')];
            $extra[] = ['product:price:currency', strtoupper((string) ($tenant->currency ?: 'USD'))];
        }
        if ($brand) $extra[] = ['product:brand', $brand];

        return [
            'title'       => \App\Support\ItemSeo::title($item->seo_title, $item->name, $brand),
            'description' => \App\Support\ItemSeo::description($item->seo_description, $item->description, $item->display_subtitle, $item->name, $brand, $tenant->name),
            'image'       => $images[0] ?? null,
            'og_type'     => 'product',
            'og_extra'    => $extra,
        ];
    }
}
