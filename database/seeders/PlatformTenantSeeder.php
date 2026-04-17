<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\Tenant\TenantPage;
use App\Models\Tenant\TenantPageSection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * PlatformTenantSeeder
 *
 * Creates the reserved platform tenant + its initial marketing pages.
 * Safe to run multiple times — idempotent on both the tenant row and
 * each page slug.
 *
 * Called by DatabaseSeeder, and can be run ad-hoc:
 *   php artisan db:seed --class=PlatformTenantSeeder
 */
class PlatformTenantSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::firstOrCreate(
            ['is_platform' => true],
            [
                'id'                => (string) Str::uuid(),
                'name'              => 'Intake (Platform)',
                'subdomain'         => '__platform',
                'is_active'         => false, // never routed as a tenant subdomain
                'plan_tier'         => 'custom',
                'onboarding_status' => 'complete',
                'accent_color'      => '#7C3AED', // violet to match brand
                'text_color'        => '#111111',
                'bg_color'          => '#ffffff',
                'currency'          => 'USD',
                'currency_symbol'   => '$',
            ]
        );

        $this->seedPages($tenant);

        $this->command?->info("Platform tenant ready: {$tenant->id}");
    }

    /**
     * Seed the standard marketing pages. Each page is created only if its
     * slug doesn't already exist, so editing content then re-running the
     * seeder is safe — existing pages are untouched.
     */
    private function seedPages(Tenant $tenant): void
    {
        $pages = [
            [
                'slug'     => 'home',
                'title'    => 'Home',
                'is_home'  => true,
                'is_in_nav'=> false,
                'sections' => $this->homeSections(),
            ],
            [
                'slug'     => 'pricing',
                'title'    => 'Pricing',
                'is_home'  => false,
                'is_in_nav'=> true,
                'nav_order'=> 1,
                'sections' => $this->pricingSections(),
            ],
            [
                'slug'     => 'features',
                'title'    => 'Features',
                'is_home'  => false,
                'is_in_nav'=> true,
                'nav_order'=> 2,
                'sections' => $this->featuresSections(),
            ],
            [
                'slug'     => 'contact',
                'title'    => 'Contact',
                'is_home'  => false,
                'is_in_nav'=> true,
                'nav_order'=> 3,
                'sections' => $this->contactSections(),
            ],
            [
                'slug'     => 'docs',
                'title'    => 'Docs',
                'is_home'  => false,
                'is_in_nav'=> true,
                'nav_order'=> 4,
                'sections' => $this->docsSections(),
            ],
            [
                // Template that /for/{industry} pages inherit. When a visitor
                // hits /for/bike-shops, the controller clones these sections
                // and merges industry-specific copy from the pack.
                'slug'     => '__industry_template',
                'title'    => 'Industry page template',
                'is_home'  => false,
                'is_in_nav'=> false,
                'sections' => $this->industryTemplateSections(),
            ],
        ];

        foreach ($pages as $p) {
            $page = TenantPage::firstOrCreate(
                ['tenant_id' => $tenant->id, 'slug' => $p['slug']],
                [
                    'title'        => $p['title'],
                    'is_home'      => $p['is_home'],
                    'is_published' => true,
                    'is_in_nav'    => $p['is_in_nav'],
                    'nav_order'    => $p['nav_order'] ?? 0,
                ]
            );

            // Only seed sections on first creation — don't overwrite edits.
            if ($page->sections()->count() === 0) {
                foreach ($p['sections'] as $i => $section) {
                    TenantPageSection::create([
                        'page_id'      => $page->id,
                        'tenant_id'    => $tenant->id,
                        'section_type' => $section['type'],
                        'content'      => $section['content'],
                        'padding'      => $section['padding'] ?? 'normal',
                        'is_visible'   => true,
                        'sort_order'   => $i,
                    ]);
                }
            }
        }
    }

    // ================================================================
    // Section definitions per page
    // ================================================================

    private function homeSections(): array
    {
        return [
            ['type' => 'nav',    'content' => ['show_logo' => true, 'cta_label' => 'Start free trial', 'cta_url' => 'https://app.intake.works/signup', 'bg_style' => 'solid']],
            ['type' => 'hero',   'content' => [
                'headline'            => 'Booking & intake, built for service businesses',
                'subheading'          => 'Give customers a beautiful booking site. Keep your appointments organized. Get paid faster.',
                'text_align'          => 'center',
                'bg_color'            => '#0F172A',
                'text_color'          => '#FFFFFF',
                'cta_primary_label'   => 'Start free trial',
                'cta_primary_url'     => 'https://app.intake.works/signup',
                'cta_secondary_label' => 'See features',
                'cta_secondary_url'   => '/features',
                'height'              => 'large',
            ]],
            ['type' => 'logo_bar', 'content' => [
                'heading' => 'Trusted by 200+ service businesses',
                'logos'   => [],
            ]],
            ['type' => 'feature_grid', 'content' => [
                'heading'    => 'Everything you need to take bookings',
                'subheading' => 'One platform, set up in minutes, works for any service business.',
                'columns'    => 3,
                'features'   => [
                    ['icon' => '📅', 'title' => 'Smart scheduling',   'body' => 'Set capacity rules, blackout dates, and buffer times. Customers only see what you can actually deliver.'],
                    ['icon' => '💳', 'title' => 'Built-in payments',  'body' => 'Take deposits or full payment at booking via Stripe or PayPal.'],
                    ['icon' => '✉',  'title' => 'Email & SMS',        'body' => 'Automatic reminders, confirmations, and status updates — with your branding.'],
                    ['icon' => '🎨', 'title' => 'Your brand, your site','body' => 'Three-column live editor. Change anything, see it instantly.'],
                    ['icon' => '📊', 'title' => 'Reviews & ratings',   'body' => 'Collect post-service reviews automatically. Display them on your site.'],
                    ['icon' => '🧾', 'title' => 'Customer CRM',        'body' => 'Every appointment, payment, and note per customer — searchable, exportable.'],
                ],
            ]],
            ['type' => 'industry_pack_showcase', 'content' => [
                'heading'    => 'Built for your industry',
                'subheading' => 'Pick your industry, get a pre-configured site with services, pricing, and content that fits.',
            ]],
            ['type' => 'cta_banner', 'content' => [
                'headline'   => 'Ready to take better bookings?',
                'subheading' => '14-day free trial. No credit card required.',
                'cta_label'  => 'Start free trial',
                'cta_url'    => 'https://app.intake.works/signup',
            ]],
            ['type' => 'footer', 'content' => [
                'show_logo' => true, 'show_copyright' => true,
                'copyright_text' => '© ' . date('Y') . ' Intake. All rights reserved.',
            ]],
        ];
    }

    private function pricingSections(): array
    {
        return [
            ['type' => 'nav',    'content' => ['show_logo' => true, 'cta_label' => 'Start free trial', 'cta_url' => 'https://app.intake.works/signup', 'bg_style' => 'solid']],
            ['type' => 'hero',   'content' => [
                'headline'   => 'Simple, transparent pricing',
                'subheading' => 'Start free for 14 days. Cancel anytime.',
                'text_align' => 'center',
                'bg_color'   => '#F8FAFC',
                'text_color' => '#0F172A',
                'height'     => 'small',
            ]],
            ['type' => 'pricing_table', 'content' => [
                'source'   => 'config', // pulls from config('intake.plan_prices')
                'featured' => 'branded',
            ]],
            ['type' => 'faq_accordion', 'content' => [
                'heading' => 'Pricing questions',
                'items' => [
                    ['q' => 'Is there a free trial?',           'a' => 'Yes — 14 days, no credit card needed. Upgrade or cancel whenever.'],
                    ['q' => 'Can I change plans later?',         'a' => 'Any time. Upgrades prorate immediately, downgrades apply next billing cycle.'],
                    ['q' => 'What payment methods do you accept?','a' => 'All major credit cards via Stripe. ACH available on annual plans.'],
                    ['q' => 'Do you charge transaction fees?',   'a' => 'No — only your payment processor (Stripe or PayPal) charges their standard fees.'],
                ],
            ]],
            ['type' => 'footer', 'content' => ['show_logo' => true, 'show_copyright' => true]],
        ];
    }

    private function featuresSections(): array
    {
        return [
            ['type' => 'nav',    'content' => ['show_logo' => true, 'cta_label' => 'Start free trial', 'cta_url' => 'https://app.intake.works/signup']],
            ['type' => 'hero',   'content' => [
                'headline'   => 'Every feature your service business needs',
                'subheading' => 'Scheduling, payments, communications, reviews, and a beautiful site — all in one place.',
                'text_align' => 'center',
                'bg_color'   => '#0F172A',
                'text_color' => '#FFFFFF',
                'height'     => 'medium',
            ]],
            ['type' => 'feature_grid', 'content' => [
                'heading' => 'The complete platform',
                'columns' => 2,
                'features' => [
                    ['icon' => '📅', 'title' => 'Appointment management', 'body' => 'Drag-and-drop scheduling. Status pipelines. Capacity rules per day and per service.'],
                    ['icon' => '💳', 'title' => 'Payments',                'body' => 'Stripe, PayPal. Deposits, full payment, or pay-later. Automatic receipts.'],
                    ['icon' => '✉',  'title' => 'Email & SMS',             'body' => 'Templates you control. Automatic reminders, confirmations, status updates.'],
                    ['icon' => '📣', 'title' => 'Marketing campaigns',     'body' => 'Segment your customer list. Send newsletters, promotions, re-engagement emails.'],
                    ['icon' => '⭐', 'title' => 'Reviews & ratings',       'body' => 'Auto-request reviews after service. Display on your site. Respond to feedback.'],
                    ['icon' => '🎨', 'title' => 'Site editor',             'body' => 'Drag, drop, preview live. No code. Your brand, your voice.'],
                ],
            ]],
            ['type' => 'comparison_table', 'content' => [
                'heading'     => 'How we compare',
                'competitors' => ['Intake', 'Competitor A', 'Competitor B'],
                'rows' => [
                    ['feature' => 'Custom booking site',         'values' => ['yes', 'yes', 'no']],
                    ['feature' => 'Industry presets',             'values' => ['yes', 'no', 'no']],
                    ['feature' => 'Built-in payments',            'values' => ['yes', 'yes', 'yes']],
                    ['feature' => 'SMS reminders',                'values' => ['yes', 'extra', 'yes']],
                    ['feature' => 'Email campaigns',              'values' => ['yes', 'no', 'extra']],
                    ['feature' => 'Transparent pricing',          'values' => ['yes', 'no', 'yes']],
                ],
            ]],
            ['type' => 'cta_banner', 'content' => [
                'headline'  => 'See it in action',
                'cta_label' => 'Start free trial',
                'cta_url'   => 'https://app.intake.works/signup',
            ]],
            ['type' => 'footer', 'content' => ['show_logo' => true, 'show_copyright' => true]],
        ];
    }

    private function contactSections(): array
    {
        return [
            ['type' => 'nav',    'content' => ['show_logo' => true, 'cta_label' => 'Start free trial', 'cta_url' => 'https://app.intake.works/signup']],
            ['type' => 'hero',   'content' => [
                'headline'   => 'Get in touch',
                'subheading' => 'Questions, demo requests, or partnership inquiries — we usually respond within a few hours.',
                'text_align' => 'center',
                'height'     => 'small',
            ]],
            ['type' => 'contact_form', 'content' => ['heading' => 'Send us a message', 'show_phone' => false, 'show_message' => true]],
            ['type' => 'footer', 'content' => ['show_logo' => true, 'show_copyright' => true]],
        ];
    }

    private function docsSections(): array
    {
        return [
            ['type' => 'nav',    'content' => ['show_logo' => true, 'cta_label' => 'Start free trial', 'cta_url' => 'https://app.intake.works/signup']],
            ['type' => 'hero',   'content' => [
                'headline'   => 'Documentation',
                'subheading' => 'Everything you need to get the most out of Intake.',
                'text_align' => 'center',
                'height'     => 'small',
            ]],
            ['type' => 'text_image', 'content' => [
                'heading' => 'Getting started',
                'body'    => 'Sign up, pick your industry, customize your site, and share the booking link. Most shops are live in under 15 minutes.',
                'image_position' => 'right',
            ]],
            ['type' => 'footer', 'content' => ['show_logo' => true, 'show_copyright' => true]],
        ];
    }

    private function industryTemplateSections(): array
    {
        // Placeholder copy — {industry_*} tokens get replaced at render time
        // with values from the industry pack (see MasterPageController).
        return [
            ['type' => 'nav',    'content' => ['show_logo' => true, 'cta_label' => 'Start free trial', 'cta_url' => 'https://app.intake.works/signup']],
            ['type' => 'hero',   'content' => [
                'headline'            => 'Booking software for {industry_name}',
                'subheading'          => '{industry_tagline}',
                'text_align'          => 'center',
                'bg_color'            => '#0F172A',
                'text_color'          => '#FFFFFF',
                'cta_primary_label'   => 'Start free trial',
                'cta_primary_url'     => 'https://app.intake.works/signup?pack={industry_slug}',
                'cta_secondary_label' => 'See demo',
                'cta_secondary_url'   => '/pricing',
                'height'              => 'large',
            ]],
            ['type' => 'feature_grid', 'content' => [
                'heading' => 'Built for {industry_name}',
                'columns' => 3,
                'features' => [
                    ['icon' => '⚙', 'title' => 'Pre-loaded services',  'body' => '{industry_services_blurb}'],
                    ['icon' => '📅', 'title' => 'The right workflow',   'body' => '{industry_workflow_blurb}'],
                    ['icon' => '💬', 'title' => 'The right language',  'body' => 'From "Drop-off date" to "Appointment time" — labels match your industry.'],
                ],
            ]],
            ['type' => 'cta_banner', 'content' => [
                'headline'   => 'Ready to go live as a {industry_name} shop?',
                'cta_label'  => 'Start free trial',
                'cta_url'    => 'https://app.intake.works/signup?pack={industry_slug}',
            ]],
            ['type' => 'footer', 'content' => ['show_logo' => true, 'show_copyright' => true]],
        ];
    }
}
