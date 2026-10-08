<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\Tenant\TenantInventoryItem;
use App\Models\Tenant\TenantLocation;
use App\Models\Tenant\TenantPage;
use Illuminate\Http\Request;

/**
 * one place that decides, for any public request, whether
 * search engines may index it, what its canonical address is and what
 * structured data it carries. The middleware, robots.txt, the sitemaps and the
 * nightly seo:check all read from here, so they cannot disagree.
 *
 * Rules:
 *   intake.works         — marketing pages indexable; everything else not.
 *   app.intake.works     — never indexed.
 *   shop sites           — public pages indexable when the shop is live (active,
 *                          not the demo, not suspended, welcome page off, has a
 *                          published home page). Staff, account, cart, checkout and token
 *                          pages never are. Canonical always points at the
 *                          shop's primary address (custom domain when it has one).
 */
class Seo
{
    /** Marketing routes that may be indexed. Anything else on intake.works is not. */
    public const MARKETING_ROUTES = [
        'marketing.home', 'marketing.pricing', 'marketing.changelog', 'marketing.roadmap',
        'marketing.features', 'marketing.why-intake', 'marketing.docs', 'marketing.contact',
        'marketing.show', 'marketing.industry',
    ];

    /** Shop routes that may be indexed. Anything else on a shop host is not. */
    public const TENANT_ROUTES = [
        'tenant.home', 'tenant.page', 'tenant.booking', 'tenant.shop.index', 'tenant.shop.show',
        'tenant.rentals.browse', 'tenant.customer.classes', 'tenant.gift-cards.public.buy',
    ];

    /** Products per sitemap file. Google's limit is 50,000; smaller files load faster. */
    public const PRODUCTS_PER_SITEMAP = 5000;

    private static array $siteMemo = [];

    public static function rootDomain(): string
    {
        return strtolower((string) config('intake.domain', 'intake.works'));
    }

    public static function marketingBase(): string
    {
        return 'https://' . self::rootDomain();
    }

    public static function hostKind(string $host): string
    {
        $host = strtolower($host);
        $root = self::rootDomain();
        if ($host === $root || $host === 'www.' . $root) return 'marketing';
        if ($host === 'app.' . $root) return 'app';
        return 'tenant';
    }

    /** Is this shop's public site meant to be in search at all? */
    public static function tenantIndexable(?Tenant $tenant): bool
    {
        if (! $tenant) return false;
        if (array_key_exists($tenant->id, self::$siteMemo)) return self::$siteMemo[$tenant->id];

        $ok = (bool) $tenant->is_active
            && ! $tenant->is_demo
            && ! ($tenant->is_platform ?? false)
            && $tenant->suspended_at === null
            && ! WelcomePage::enabled($tenant)
            && TenantPage::where('tenant_id', $tenant->id)
                ->where('is_home', true)->where('is_published', true)->exists();

        return self::$siteMemo[$tenant->id] = $ok;
    }

    /** Plain words for why a shop is kept out of search (used by seo:check). */
    public static function tenantHiddenReason(Tenant $tenant): ?string
    {
        if (! $tenant->is_active)             return 'shop is inactive';
        if ($tenant->is_demo)                 return 'demo shop';
        if ($tenant->is_platform ?? false)    return 'platform record';
        if ($tenant->suspended_at !== null)   return 'shop is suspended';
        if (WelcomePage::enabled($tenant))    return 'welcome page is on';
        if (! TenantPage::where('tenant_id', $tenant->id)->where('is_home', true)->where('is_published', true)->exists()) {
            return 'no published home page';
        }
        return null;
    }

    /**
     * The verdict for one request: ['index' => bool, 'canonical' => ?string, 'jsonld' => ?array].
     */
    public static function verdict(Request $request): array
    {
        $no   = ['index' => false, 'canonical' => null, 'jsonld' => null];
        $path = '/' . ltrim($request->path(), '/');
        $name = optional($request->route())->getName();
        $kind = self::hostKind($request->getHost());

        if ($kind === 'app') return $no;

        if ($kind === 'marketing') {
            if (! in_array($name, self::MARKETING_ROUTES, true)) return $no;
            return [
                'index'     => true,
                'canonical' => self::marketingBase() . ($path === '/' ? '/' : $path),
                'jsonld'    => $name === 'marketing.home' ? self::organization() : null,
            ];
        }

        $tenant = app()->bound('tenant') ? app('tenant') : null;
        if (! self::tenantIndexable($tenant)) return $no;
        if (! in_array($name, self::TENANT_ROUTES, true)) return $no;

        $jsonld = null;
        if ($name === 'tenant.home') {
            $jsonld = self::localBusiness($tenant);
        } elseif ($name === 'tenant.shop.show') {
            $jsonld = self::product($tenant, (string) $request->route('id'));
        }

        return [
            'index'     => true,
            'canonical' => rtrim($tenant->publicUrl(), '/') . ($path === '/' ? '/' : $path),
            'jsonld'    => $jsonld,
        ];
    }

    // ------------------------------------------------------------------ robots

    public static function robotsFor(Request $request): string
    {
        $kind = self::hostKind($request->getHost());

        if ($kind === 'app') {
            return "User-agent: *\nDisallow: /\n";
        }

        if ($kind === 'marketing') {
            return "User-agent: *\n"
                . "Disallow: /admin\nDisallow: /rep\nDisallow: /invest\nDisallow: /book/manage\nDisallow: /platform-email\nDisallow: /mkt/\n" // traffic beacon, not a page
                . "\nSitemap: " . self::marketingBase() . "/sitemap.xml\n";
        }

        $tenant = app()->bound('tenant') ? app('tenant') : null;
        if (! self::tenantIndexable($tenant)) {
            return "User-agent: *\nDisallow: /\n";
        }

        return "User-agent: *\n"
            . "Disallow: /admin\nDisallow: /account\nDisallow: /cart\nDisallow: /checkout\nDisallow: /pay-display\nDisallow: /d/\n"
            . "\nSitemap: " . rtrim($tenant->publicUrl(), '/') . "/sitemap.xml\n";
    }

    // ---------------------------------------------------------------- sitemaps

    /** [[loc, lastmod|null], ...] for the marketing site. */
    public static function marketingUrls(): array
    {
        $platform = Tenant::where('is_platform', true)->first();
        if (! $platform) return [[self::marketingBase() . '/', null]];

        $urls = [];
        $pages = TenantPage::where('tenant_id', $platform->id)
            ->where('is_published', true)
            ->where(fn ($w) => $w->whereNull('kind')->orWhere('kind', 'page'))
            ->get(['slug', 'is_home', 'updated_at']);

        foreach ($pages as $p) {
            $slug = (string) $p->slug;
            if ($slug === '' || str_starts_with($slug, '__') || $slug === 'invest') continue;
            $path = $slug === MarketingNav::homeSlug() ? '/' : '/' . $slug;
            $urls[$path] = [self::marketingBase() . $path, $p->updated_at?->toAtomString()];
        }
        if (! isset($urls['/'])) $urls['/'] = [self::marketingBase() . '/', null];

        $hasIndustryTemplate = TenantPage::where('tenant_id', $platform->id)
            ->where('slug', '__for-industry')->where('is_published', true)->exists();
        if ($hasIndustryTemplate) {
            foreach (array_keys((array) config('industry_packs', [])) as $slug) {
                $urls['/for/' . $slug] = [self::marketingBase() . '/for/' . $slug, null];
            }
        }

        return array_values($urls);
    }

    /** [[loc, lastmod|null], ...] for a shop's pages (not products). */
    public static function tenantPageUrls(Tenant $tenant): array
    {
        $base = rtrim($tenant->publicUrl(), '/');
        $urls = [[$base . '/', null]];

        $pages = TenantPage::where('tenant_id', $tenant->id)
            ->where('is_published', true)
            ->where('is_home', false)
            ->where(fn ($w) => $w->where('is_splash', false)->orWhereNull('is_splash'))
            ->where(fn ($w) => $w->whereNull('kind')->orWhere('kind', 'page'))
            ->get(['slug', 'updated_at']);
        foreach ($pages as $p) {
            if ((string) $p->slug === '' || $p->slug === 'book') continue;
            $urls[] = [$base . '/' . $p->slug, $p->updated_at?->toAtomString()];
        }

        $urls[] = [$base . '/book', null];
        if (self::storefrontOn($tenant))            $urls[] = [$base . '/shop', null];
        if ($tenant->rentals_visible)               $urls[] = [$base . '/rentals', null];
        if ($tenant->gift_cards_enabled)            $urls[] = [$base . '/gift-cards', null];
        if (\App\Models\Tenant\TenantClassTemplate::where('tenant_id', $tenant->id)->active()->exists()) {
            $urls[] = [$base . '/classes', null];
        }

        return $urls;
    }

    public static function storefrontOn(Tenant $tenant): bool
    {
        return app(\App\Services\FeatureAccessService::class)->hasAddon($tenant, 'online_store')
            && (bool) (($tenant->settings['storefront']['enabled'] ?? true));
    }

    public static function productQuery(Tenant $tenant)
    {
        return TenantInventoryItem::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->where('show_online', true);
    }

    public static function productSitemapCount(Tenant $tenant): int
    {
        if (! self::storefrontOn($tenant)) return 0;
        return (int) ceil(self::productQuery($tenant)->count() / self::PRODUCTS_PER_SITEMAP);
    }

    /** [[loc, lastmod|null], ...] for one products sitemap file (1-based). */
    public static function tenantProductUrls(Tenant $tenant, int $part): array
    {
        if ($part < 1 || ! self::storefrontOn($tenant)) return [];
        $base = rtrim($tenant->publicUrl(), '/');

        return self::productQuery($tenant)
            ->orderBy('id')
            ->offset(($part - 1) * self::PRODUCTS_PER_SITEMAP)
            ->limit(self::PRODUCTS_PER_SITEMAP)
            ->get(['id', 'updated_at'])
            ->map(fn ($i) => [$base . '/shop/' . $i->id, $i->updated_at?->toAtomString()])
            ->all();
    }

    public static function urlsetXml(array $urls): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
           . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as [$loc, $lastmod]) {
            $x .= '  <url><loc>' . htmlspecialchars($loc, ENT_XML1) . '</loc>'
                . ($lastmod ? '<lastmod>' . $lastmod . '</lastmod>' : '') . "</url>\n";
        }
        return $x . "</urlset>\n";
    }

    public static function indexXml(array $locs): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
           . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($locs as $loc) {
            $x .= '  <sitemap><loc>' . htmlspecialchars($loc, ENT_XML1) . "</loc></sitemap>\n";
        }
        return $x . "</sitemapindex>\n";
    }

    // --------------------------------------------------------- structured data

    public static function organization(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type'    => 'Organization',
            'name'     => 'Intake',
            'url'      => self::marketingBase() . '/',
            'logo'     => Brand::url('icon'),
        ];
    }

    /**
     * LocalBusiness from the shop's own records. Opening hours are left out on
     * purpose: booking capacity rules are not shop hours, and publishing them
     * as hours would tell Google something the shop never said.
     */
    public static function localBusiness(Tenant $tenant): array
    {
        $base = rtrim($tenant->publicUrl(), '/');
        $data = [
            '@context' => 'https://schema.org',
            '@type'    => 'LocalBusiness',
            'name'     => $tenant->name,
            'url'      => $base . '/',
        ];

        if ($logo = self::absolute($tenant->logo_url, $base)) {
            $data['logo']  = $logo;
            $data['image'] = $logo;
        }

        $loc = TenantLocation::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderByDesc('is_default')->orderBy('sort_order')
            ->first();
        if ($loc) {
            if (trim((string) $loc->phone) !== '') $data['telephone'] = $loc->phone;
            if (trim((string) $loc->address_line_1) !== '') {
                $data['address'] = array_filter([
                    '@type'           => 'PostalAddress',
                    'streetAddress'   => trim($loc->address_line_1 . ' ' . ($loc->address_line_2 ?? '')),
                    'addressLocality' => $loc->city,
                    'addressRegion'   => $loc->state,
                    'postalCode'      => $loc->postal_code,
                    'addressCountry'  => $loc->country ?: 'US',
                ], fn ($v) => $v !== null && $v !== '');
            }
        }

        return $data;
    }

    public static function product(Tenant $tenant, string $id): ?array
    {
        if (! self::storefrontOn($tenant)) return null;
        $item = self::productQuery($tenant)->with('distributorCatalog')->where('id', $id)->first();
        if (! $item) return null;

        $base  = rtrim($tenant->publicUrl(), '/');
        $price = $item->effectiveSellPriceCents();
        $img   = collect($item->displayImages(1))->reject(fn ($i) => $i['hidden'] ?? false)->pluck('url')->first();

        $data = [
            '@context' => 'https://schema.org',
            '@type'    => 'Product',
            'name'     => $item->name,
            'url'      => $base . '/shop/' . $item->id,
        ];
        if ($img = self::absolute($img, $base))                 $data['image'] = $img;
        if ($brand = $item->distributorCatalog?->manufacturer)  $data['brand'] = ['@type' => 'Brand', 'name' => $brand];
        if ($item->sku)                                          $data['sku']   = (string) $item->sku;
        if ($price !== null) {
            $data['offers'] = [
                '@type'         => 'Offer',
                'price'         => number_format($price / 100, 2, '.', ''),
                'priceCurrency' => strtoupper((string) ($tenant->currency ?: 'USD')),
                'availability'  => $item->availableCount() > 0
                    ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                'url'           => $data['url'],
            ];
        }

        return $data;
    }

    // ----------------------------- share image

    /** A shop page's share image: its own, else the shop's fallback. Null = none. */
    public static function shareImage(TenantPage $page, ?Tenant $tenant): ?string
    {
        return Brand::storagePublicUrl($page->og_image_url) ?? self::shareImageFallback($tenant);
    }

    /** The shop's logo, unless it is an SVG (link previews don't show SVGs). */
    public static function shareImageFallback(?Tenant $tenant): ?string
    {
        if (! $tenant) return null;
        $logo = Brand::storagePublicUrl($tenant->logo_url);
        if (! $logo) return null;
        $ext = strtolower(pathinfo((string) parse_url($logo, PHP_URL_PATH), PATHINFO_EXTENSION));
        return $ext === 'svg' ? null : $logo;
    }

    private static function absolute(?string $url, string $base): ?string
    {
        $url = trim((string) $url);
        if ($url === '') return null;
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) return $url;
        if (str_starts_with($url, '//')) return 'https:' . $url;
        return $base . '/' . ltrim($url, '/');
    }
}
