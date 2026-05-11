<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\Tenant\TenantPage;
use App\Models\Tenant\TenantPageSection;
use App\Models\Tenant\TenantNavItem;
use Illuminate\Database\Seeder;

/**
 * Seeds canonical nav + the 5 still-static pages as CMS rows.
 *
 * Idempotent — uses updateOrCreate / firstOrCreate. Safe to re-run.
 * Scoped exclusively to the platform tenant (is_platform=true).
 */
class PlatformMarketingSeeder extends Seeder
{
    public function run(): void
    {
        $platform = Tenant::where('is_platform', true)->first();

        if (! $platform) {
            $this->command->error('No platform tenant found. Skipping marketing seed.');
            return;
        }

        // Canonical nav items — order matters.
        $navItems = [
            ['label' => 'Features',  'url' => '/features',  'sort_order' => 10],
            ['label' => 'Pricing',   'url' => '/pricing',   'sort_order' => 20],
            ['label' => 'Roadmap',   'url' => '/roadmap',   'sort_order' => 30],
            ['label' => 'Changelog', 'url' => '/changelog', 'sort_order' => 40],
            ['label' => 'Docs',      'url' => '/docs',      'sort_order' => 50],
        ];

        // Wipe + reseed nav (idempotent + ensures canonical order)
        TenantNavItem::where('tenant_id', $platform->id)->delete();
        foreach ($navItems as $item) {
            TenantNavItem::create([
                'tenant_id'        => $platform->id,
                'label'            => $item['label'],
                'url'              => $item['url'],
                'is_external'      => false,
                'open_in_new_tab'  => false,
                'sort_order'       => $item['sort_order'],
            ]);
        }
        $this->command->info('Seeded '.count($navItems).' nav items for platform tenant.');

        // Seed pages that don't already exist as CMS rows.
        $this->seedPage($platform, 'roadmap', 'Roadmap', 'What is coming.', [
            ['type' => 'hero', 'content' => [
                'eyebrow' => 'Roadmap',
                'headline' => 'What is coming.',
                'subheading' => 'Timing is intentionally rough. We commit to direction, not dates.',
                'text_align' => 'center',
                'height' => 'small',
            ]],
            ['type' => 'roadmap_grid', 'content' => [
                'intro_text' => '',
            ]],
        ]);

        $this->seedPage($platform, 'changelog', 'Changelog', 'What we shipped.', [
            ['type' => 'hero', 'content' => [
                'eyebrow' => 'Changelog',
                'headline' => 'What we shipped.',
                'subheading' => 'Real updates, reverse-chronological. The most recent on top.',
                'text_align' => 'center',
                'height' => 'small',
            ]],
            ['type' => 'changelog_list', 'content' => [
                'intro_text' => '',
            ]],
        ]);

        $this->seedPage($platform, 'why-intake', 'Why Intake', 'Why Intake', [
            ['type' => 'hero', 'content' => [
                'eyebrow' => 'Why Intake',
                'headline' => 'Built for service shops, not against them.',
                'accent_words' => 'shops',
                'subheading' => 'Most booking and POS tools were built for a different kind of business. Intake was built specifically for service shops — bike, salon, fitness, pet — by someone who runs one.',
                'text_align' => 'center',
                'height' => 'medium',
                'cta_primary_label' => 'Start free trial',
                'cta_primary_url' => '/signup',
            ]],
            ['type' => 'feature_grid', 'content' => [
                'eyebrow' => 'What makes us different',
                'heading' => 'Three things you can\'t get elsewhere',
                'columns' => 3,
                'features' => [
                    ['icon' => '✦', 'title' => 'Real concurrency', 'body' => 'Advisory locks at the database level. Two customers booking the same slot at the same time? Only one gets it. Most competitors are eventually-consistent.'],
                    ['icon' => '✦', 'title' => 'POS + booking unified', 'body' => 'Walk-in sales, work orders, appointments, and inventory in one tool. No syncing between Square and Acuity. No reconciling reports.'],
                    ['icon' => '✦', 'title' => 'Migration concierge', 'body' => 'Send us your data however you have it. We do the import, the cleanup, and walk you through your new setup. $299 one-time, free on Custom annual.'],
                ],
            ]],
        ]);

        $this->seedPage($platform, 'contact', 'Contact', 'Contact us', [
            ['type' => 'hero', 'content' => [
                'eyebrow' => 'Contact',
                'headline' => 'Get in touch.',
                'subheading' => 'Pre-sales questions, support, partnership ideas. Real humans on the other end.',
                'text_align' => 'center',
                'height' => 'small',
            ]],
            ['type' => 'contact_form', 'content' => [
                'heading' => 'Send us a message',
                'subheading' => 'We typically respond within 1 business day.',
                'show_phone' => true,
                'show_message' => true,
            ]],
        ]);

        $this->seedPage($platform, 'invest', 'Invest', 'Invest in Intake', [
            ['type' => 'hero', 'content' => [
                'eyebrow' => 'Investing',
                'headline' => 'Invest in Intake.',
                'subheading' => 'Equity crowdfunding via Republic. Details and offering terms on the Republic page.',
                'text_align' => 'center',
                'height' => 'small',
                'cta_primary_label' => 'View on Republic',
                'cta_primary_url' => 'https://republic.com/intake',
            ]],
            ['type' => 'text_image', 'content' => [
                'eyebrow' => 'About this offering',
                'heading' => 'Built by an operator',
                'body' => 'Intake is a B2B SaaS for service-based small businesses. Real customers, real revenue. The full offering details, financials, and risks are on Republic.',
                'image_position' => 'right',
            ]],
        ]);

        $this->command->info('Seeded 5 marketing pages: roadmap, changelog, why-intake, contact, invest.');
    }

    /**
     * Idempotent page+sections seeding. Updates the page if exists, replaces sections.
     */
    private function seedPage(Tenant $platform, string $slug, string $title, string $metaTitle, array $sections): void
    {
        $page = TenantPage::updateOrCreate(
            ['tenant_id' => $platform->id, 'slug' => $slug],
            [
                'title' => $title,
                'meta_title' => $metaTitle,
                'is_home' => false,
                'is_published' => true,
                'is_in_nav' => false, // nav is managed by TenantNavItem now
                'nav_order' => 0,
            ]
        );

        // Replace sections entirely — simplest idempotent approach.
        TenantPageSection::where('page_id', $page->id)->delete();
        foreach ($sections as $i => $sec) {
            TenantPageSection::create([
                'tenant_id'     => $platform->id,
                'page_id'       => $page->id,
                'section_type'  => $sec['type'],
                'content'       => $sec['content'],
                'sort_order'    => ($i + 1) * 10,
                'is_visible'    => true,
            ]);
        }
    }
}
