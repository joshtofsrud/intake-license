<?php

namespace App\Services\Demo;

use App\Models\Tenant;
use App\Models\Tenant\TenantNavItem;
use App\Models\Tenant\TenantPage;
use App\Models\Tenant\TenantPageSection;
use App\Services\Demo\Industries\IndustryDataContract;
use Closure;

/**
 * Seeds realistic marketing pages — home, about, contact.
 *
 * Uses the tenant_page_sections.content JSON shape documented in the
 * create_tenant_pages_tables migration. Content keys are my best
 * interpretation of what the page editor expects for each section type.
 * If something renders with gaps, adjust the content shape below.
 */
class PagesSeeder
{
    public function __construct(
        private readonly IndustryDataContract $industry,
        private readonly Closure $logger,
    ) {}

    private function log(string $msg): void { ($this->logger)($msg); }

    public function seed(Tenant $tenant): void
    {
        $pageContent = $this->industry->pageContent($tenant);

        // Nav items — shared across pages
        $navOrder = 10;
        foreach ([
            ['label' => 'Services',  'url' => '/services'],
            ['label' => 'About',     'url' => '/about'],
            ['label' => 'Contact',   'url' => '/contact'],
        ] as $n) {
            TenantNavItem::create([
                'tenant_id'      => $tenant->id,
                'label'          => $n['label'],
                'url'            => $n['url'],
                'is_external'    => false,
                'open_in_new_tab'=> false,
                'sort_order'     => $navOrder,
            ]);
            $navOrder += 10;
        }

        // Home
        $home = TenantPage::create([
            'tenant_id'        => $tenant->id,
            'title'            => 'Home',
            'slug'             => 'home',
            'meta_title'       => $pageContent['home']['meta_title'] ?? null,
            'meta_description' => $pageContent['home']['meta_description'] ?? null,
            'is_home'          => true,
            'is_published'     => true,
            'is_in_nav'        => false,
            'nav_order'        => 0,
        ]);
        $this->createSections($tenant, $home, $pageContent['home']['sections']);

        // About
        $about = TenantPage::create([
            'tenant_id'        => $tenant->id,
            'title'            => 'About',
            'slug'             => 'about',
            'meta_title'       => $pageContent['about']['meta_title'] ?? null,
            'meta_description' => $pageContent['about']['meta_description'] ?? null,
            'is_home'          => false,
            'is_published'     => true,
            'is_in_nav'        => true,
            'nav_order'        => 20,
        ]);
        $this->createSections($tenant, $about, $pageContent['about']['sections']);

        // Contact
        $contact = TenantPage::create([
            'tenant_id'        => $tenant->id,
            'title'            => 'Contact',
            'slug'             => 'contact',
            'meta_title'       => $pageContent['contact']['meta_title'] ?? null,
            'meta_description' => $pageContent['contact']['meta_description'] ?? null,
            'is_home'          => false,
            'is_published'     => true,
            'is_in_nav'        => true,
            'nav_order'        => 30,
        ]);
        $this->createSections($tenant, $contact, $pageContent['contact']['sections']);

        $this->log("  Pages: 3 (home, about, contact) + nav items.");
    }

    private function createSections(Tenant $tenant, TenantPage $page, array $sections): void
    {
        $order = 10;
        foreach ($sections as $s) {
            TenantPageSection::create([
                'tenant_id'    => $tenant->id,
                'page_id'      => $page->id,
                'section_type' => $s['type'],
                'content'      => $s['content'],
                'bg_color'     => $s['bg_color'] ?? null,
                'padding'      => $s['padding'] ?? 'normal',
                'is_visible'   => true,
                'sort_order'   => $order,
            ]);
            $order += 10;
        }
    }
}
