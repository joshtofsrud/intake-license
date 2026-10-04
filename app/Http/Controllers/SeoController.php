<?php

namespace App\Http\Controllers;

use App\Support\Seo;
use Illuminate\Http\Request;

/**
 * MARKER-SEO-SIGNALS — robots.txt and sitemaps for every host. intake.works,
 * app.intake.works and every shop host each get their own, built from
 * App\Support\Seo so they always agree with the page-level signals.
 */
class SeoController extends Controller
{
    public function robots(Request $request)
    {
        return response(Seo::robotsFor($request), 200)
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    public function sitemap(Request $request)
    {
        $kind = Seo::hostKind($request->getHost());

        if ($kind === 'marketing') {
            return $this->xml(Seo::urlsetXml(Seo::marketingUrls()));
        }

        $tenant = app()->bound('tenant') ? app('tenant') : null;
        abort_unless($kind === 'tenant' && Seo::tenantIndexable($tenant), 404);

        $base = rtrim($tenant->publicUrl(), '/');
        $locs = [$base . '/sitemap-pages.xml'];
        for ($n = 1, $max = Seo::productSitemapCount($tenant); $n <= $max; $n++) {
            $locs[] = $base . '/sitemap-products-' . $n . '.xml';
        }

        return $this->xml(Seo::indexXml($locs));
    }

    public function pages(Request $request)
    {
        $tenant = app()->bound('tenant') ? app('tenant') : null;
        abort_unless(Seo::tenantIndexable($tenant), 404);

        return $this->xml(Seo::urlsetXml(Seo::tenantPageUrls($tenant)));
    }

    public function products(Request $request, string $part)
    {
        $tenant = app()->bound('tenant') ? app('tenant') : null;
        abort_unless(Seo::tenantIndexable($tenant), 404);

        $urls = Seo::tenantProductUrls($tenant, (int) $part);
        abort_if($urls === [], 404);

        return $this->xml(Seo::urlsetXml($urls));
    }

    private function xml(string $body)
    {
        return response($body, 200)->header('Content-Type', 'application/xml; charset=UTF-8');
    }
}
