<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantInventoryCategory;
use App\Models\Tenant\TenantInventoryItem;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * MARKER-PATCH-561 — Online Retail Wave 2: the read-only storefront.
 * Grid + product page over inventory the tenant has opted online.
 * No cart, no writes — Wave 3 adds the cart on top of these views.
 */
class StorefrontController extends Controller
{
    /**
     * MARKER-PATCH-563 — storefront gates through the addon framework
     * (online_store: included branded+scale, never starter), replacing
     * 562's hardcoded tier match. One gating system everywhere.
     */
    private function guardTier(): void
    {
        $ok = app(\App\Services\FeatureAccessService::class)
            ->hasAddon(tenant(), 'online_store');
        // MARKER-PATCH-569 — tenant master switch on top of the addon gate
        $ok = $ok && (bool) ((tenant()->settings['storefront']['enabled'] ?? true));
        abort_unless($ok, 404);
    }

    /** Base query for anything the store may show. */
    private function visible()
    {
        $this->guardTier();
        return TenantInventoryItem::query()
            ->where('tenant_id', tenant()->id)
            ->where('is_active', true)
            ->where('show_online', true);
    }

    public function index(Request $request): View
    {
        $tenant = tenant();
        $q   = trim((string) $request->query('q', ''));
        $cat = $request->query('category');

        $items = $this->visible()
            ->with(['distributorCatalog:id,manufacturer,images', 'category:id,name'])
            ->when($q !== '', function ($w) use ($q) {
                // Same tokenized any-field match as the register (patch-552).
                $w->where(function ($x) use ($q) {
                    foreach (array_filter(preg_split('/\s+/', $q)) as $t) {
                        $x->whereRaw("CONCAT_WS(' ', name, display_subtitle, sku, catalog_upc, catalog_ean, catalog_mpn) LIKE ?", ['%' . $t . '%']);
                    }
                });
            })
            ->when($cat, fn ($w) => $w->where('category_id', $cat))
            // MARKER-PATCH-583 — shopper-facing sorts
            ->when(true, function ($w) use ($request) {
                match ($request->query('sort', 'featured')) {
                    'price_asc'  => $w->orderByRaw('COALESCE(shop_sell_price_cents, catalog_msrp_cents) ASC'),
                    'price_desc' => $w->orderByRaw('COALESCE(shop_sell_price_cents, catalog_msrp_cents) DESC'),
                    'newest'     => $w->orderByDesc('created_at'),
                    default      => $w->orderByDesc('computed_stock_count')->orderBy('name'),
                };
            })
            ->paginate(24)
            ->withQueryString();

        // Category chips: only categories that actually contain visible items.
        $categories = TenantInventoryCategory::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('id', $this->visible()->select('category_id'))
            ->orderBy('name')
            ->get(['id', 'name']);

        // MARKER-PATCH-583 — per-category counts for the sidebar layout
        $catCounts = $this->visible()
            ->selectRaw('category_id, COUNT(*) AS n')
            ->groupBy('category_id')->pluck('n', 'category_id');

        return \App\Services\Tenant\SiteChromeService::render($tenant, 'shop_index', [ // MARKER-PATCH-579
            'browseLayout' => (string) (($tenant->settings['storefront']['browse_layout'] ?? null) ?: 'chips'),
            'catCounts'    => $catCounts,
            'sort'         => $request->query('sort', 'featured'),
            'cartCount'  => \App\Services\Tenant\CartService::forTenant($tenant)->itemCount(), // MARKER-PATCH-564
            'tenant'     => $tenant,
            'items'      => $items,
            'categories' => $categories,
            'q'          => $q,
            'activeCat'  => $cat,
        ]);
    }

    /**
     * MARKER-PATCH-582 / 621 — GET /shop/search.json — instant search feed.
     * Relevance-scored (exact > name prefix > name word > brand > SKU, with a
     * gentle in-stock nudge) instead of stock-count-then-alphabetical, and
     * every query logs to tenant_search_queries for the Traffic report.
     */
    public function searchJson(\Illuminate\Http\Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['items' => []]);
        }

        // MARKER-PATCH-622 — exact-phrase redirect rules beat product matching.
        if ($rd = \App\Models\Tenant\TenantSearchRule::redirectFor(tenant()->id, $q)) {
            \App\Models\Tenant\TenantSearchQuery::log(
                tenant()->id, mb_substr((string) $request->session()->getId(), 0, 64), $q, 1
            );
            return response()->json(['items' => [], 'redirect' => [
                'label' => $rd->label ?: $rd->to_value,
                'url'   => $rd->to_value,
            ]]);
        }

        $tokens = array_values(array_filter(preg_split('/\s+/', mb_strtolower($q)), fn ($t) => mb_strlen($t) >= 2));

        // MARKER-PATCH-622 — synonym expansion (seeds + tenant rules).
        $synonyms  = \App\Models\Tenant\TenantSearchRule::synonymMap(tenant()->id);
        $corrected = false;
        $tokens = array_map(function ($t) use ($synonyms, &$corrected) {
            if (isset($synonyms[$t])) { $corrected = true; return $synonyms[$t]; }
            return $t;
        }, $tokens);

        // Same AND-token recall as before, but pull a wider candidate set and
        // rank in PHP — scoring in SQL across 5 fields is unreadable; 60 rows
        // is a trivial in-memory sort even at 10K-item tenants.
        $fetch = function (array $toks) {
            return $this->visible()
                ->with('distributorCatalog:id,manufacturer,images')
                ->where(function ($x) use ($toks) {
                    foreach ($toks as $t) {
                        $x->whereRaw("CONCAT_WS(' ', name, display_subtitle, sku, catalog_upc, catalog_ean, catalog_mpn) LIKE ?", ['%' . $t . '%']);
                    }
                })
                ->limit(60)
                ->get();
        };
        $candidates = $fetch($tokens);

        // MARKER-PATCH-622 — typo fallback: zero results → correct each token
        // against the tenant vocabulary and retry once.
        if ($candidates->isEmpty()) {
            $fixed = array_map(function ($t) use (&$corrected) {
                $c = \App\Models\Tenant\TenantSearchTerm::correct(tenant()->id, $t);
                if ($c) { $corrected = true; return $c; }
                return $t;
            }, $tokens);
            if ($fixed !== $tokens) {
                $tokens = $fixed;
                $candidates = $fetch($tokens);
            }
        }

        $raw = implode(' ', $tokens);
        $scored = $candidates->map(function ($i) use ($tokens, $raw) {
            $name  = mb_strtolower($i->name ?? '');
            $sub   = mb_strtolower($i->display_subtitle ?? '');
            $brand = mb_strtolower($i->distributorCatalog?->manufacturer ?? '');
            $sku   = mb_strtolower(($i->sku ?? '') . ' ' . ($i->catalog_upc ?? ''));
            $words = preg_split('/[^a-z0-9]+/', $name);

            $s = 0;
            if ($name === $raw)                     $s += 100; // exact name
            elseif (str_starts_with($name, $raw))   $s += 60;  // name prefix

            foreach ($tokens as $t) {
                if (in_array($t, $words, true))          $s += 20; // whole word in name
                elseif (str_contains($name, $t))         $s += 10; // substring in name
                elseif (str_contains($brand, $t))        $s += 8;
                elseif (str_contains($sub, $t))          $s += 7;
                elseif (str_contains($sku, $t))          $s += 6;
            }

            if ((int) ($i->computed_stock_count ?? 0) > 0) $s += 3; // nudge, not the sort key

            return ['item' => $i, 'score' => $s];
        })
        ->sortBy([['score', 'desc']])
        ->take(8)
        ->pluck('item');

        // MARKER-PATCH-621 — analytics: log the query with its result count.
        \App\Models\Tenant\TenantSearchQuery::log(
            tenant()->id,
            mb_substr((string) $request->session()->getId(), 0, 64),
            $q,
            $scored->count()
        );

        return response()->json(['corrected' => $corrected && $scored->isNotEmpty() ? implode(' ', $tokens) : null, 'items' => $scored->map(function ($i) {
            $ims = (array) ($i->distributorCatalog?->images ?? []);
            // MARKER-QBP-IMAGES-EVERYWHERE — a QBP entry is a bare filename,
            // not a URL, so this was putting a filename in a src on the shop's
            // public product pages.
            // Derived from the item, not from variables that may not exist in
            // this scope: $catCode/$tenantId were not defined here, so passing
            // them would quietly resolve every QBP image to null.
            $img = \App\Support\CatalogImages::urls(
                $ims,
                $p->distributorCatalog?->distributor_code ?? null,
                $p->tenant_id ?? null,
                1,
            )[0] ?? null;
            return [
                'name'  => $i->name,
                'brand' => $i->distributorCatalog?->manufacturer,
                'price' => $i->effectiveSellPriceCents() !== null
                    ? '$' . number_format($i->effectiveSellPriceCents() / 100, 2) : null,
                'img'   => $img,
                'stock' => (int) ($i->computed_stock_count ?? 0) > 0,
                'url'   => '/shop/' . $i->id,
            ];
        })->values()]);
    }

    public function show(string $id): View
    {
        $item = $this->visible()
            ->with(['distributorCatalog', 'category:id,name'])
            ->where('id', $id)
            ->firstOrFail();

        $cat = $item->distributorCatalog;

        // MARKER-QBP-IMAGES-EVERYWHERE-2 — the product gallery had its own copy
        // of the same lookup, so a QBP product page showed six broken images.
        $images = \App\Support\CatalogImages::urls(
            $cat?->images ?? [],
            $cat?->distributor_code ?? null,
            $cat?->tenant_id ?? null,
            6,
        );

        $attrs = collect((array) ($cat?->attributes ?? []))
            ->filter(fn ($a) => is_array($a) && !empty($a['Name']) && trim((string) ($a['Value'] ?? '')) !== '')
            ->map(fn ($a) => ['name' => $a['Name'], 'value' => $a['Value']])
            ->values()->all();

        return \App\Services\Tenant\SiteChromeService::render(tenant(), 'shop_show', [ // MARKER-PATCH-579
            'shopItem' => $item, // MARKER-PATCH-585 — collision-proof alias
            'cartCount' => \App\Services\Tenant\CartService::forTenant(tenant())->itemCount(), // MARKER-PATCH-564
            'tenant' => tenant(),
            'item'   => $item,
            'images' => $images,
            'attrs'  => $attrs,
            'brand'  => $cat?->manufacturer,
        ]);
    }
}

