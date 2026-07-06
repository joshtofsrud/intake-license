<?php

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Models\Tenant\TenantNavItem;
use App\Models\Tenant\TenantPage;
use App\Models\Tenant\TenantPageSection;

/**
 * MARKER-PATCH-579 — one chrome, everywhere.
 *
 * Standalone public pages (shop, cart, checkout, …) render THROUGH the
 * page-builder layout by wrapping their body in a synthetic section
 * between the tenant's inherited nav + footer chrome. One nav edit in
 * the builder updates the whole site — booking pages, store, all of it.
 */
class SiteChromeService
{
    /**
     * Render $bodyType (a public.sections._* partial suffix) inside the
     * tenant's site chrome. $data is passed to the view so the body
     * partial sees its page data; $meta carries title/description.
     */
    /**
     * MARKER-PATCH-581 — chrome pieces for standalone documents that keep
     * their own <html> shell (booking family, rentals, portal): the home
     * page's nav/footer sections + global nav items, request-cached.
     */
    public static function parts(Tenant $tenant): array
    {
        static $cache = [];
        if (isset($cache[$tenant->id])) return $cache[$tenant->id];

        $home = TenantPage::query()
            ->where('tenant_id', $tenant->id)->where('is_home', true)->first();
        $chrome = $home
            ? TenantPageSection::query()->where('page_id', $home->id)
                ->where('is_visible', true)
                ->whereIn('section_type', ['nav', 'footer'])->get()
            : collect();

        return $cache[$tenant->id] = [
            'nav'      => $chrome->firstWhere('section_type', 'nav'),
            'footer'   => $chrome->firstWhere('section_type', 'footer'),
            'navItems' => TenantNavItem::where('tenant_id', $tenant->id)->orderBy('sort_order')->get(),
        ];
    }

    public static function render(Tenant $tenant, string $bodyType, array $data = [], array $meta = [])
    {
        // Home page chrome (same source withInheritedChrome uses)
        $home = TenantPage::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_home', true)
            ->first();

        $chrome = $home
            ? TenantPageSection::query()
                ->where('page_id', $home->id)
                ->where('is_visible', true)
                ->whereIn('section_type', ['nav', 'footer'])
                ->get()
            : collect();

        $body = new TenantPageSection([
            'section_type' => $bodyType,
            'content'      => [],
        ]);
        $body->is_visible = true;

        $sections = collect();
        if ($nav = $chrome->firstWhere('section_type', 'nav')) $sections->push($nav);
        $sections->push($body);
        if ($footer = $chrome->firstWhere('section_type', 'footer')) $sections->push($footer);

        $navItems = TenantNavItem::where('tenant_id', $tenant->id)
            ->orderBy('sort_order')->get();

        // Layout titles come from a $page object; fake the fields it reads.
        $page = new TenantPage([
            'title'            => $meta['title'] ?? 'Shop',
            'meta_title'       => $meta['title'] ?? null,
            'meta_description' => $meta['description'] ?? null,
        ]);

        return view('public.page', array_merge($data, [
            'page'     => $page,
            'sections' => $sections,
            'navItems' => $navItems,
            'catalog'  => collect(), // nav partial tolerates empty catalog
        ]));
    }
}
