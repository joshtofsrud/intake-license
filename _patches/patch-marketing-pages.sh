#!/bin/bash
# ============================================================================
# patch-marketing-pages.sh
# ----------------------------------------------------------------------------
# Seeds the following pages into the CMS (idempotent — safe to re-run):
#
#   NEW pages:
#     /how-it-works     — 5-step onboarding walkthrough + feature overview
#     /for/bike-shops   — via __for-industry template (token-substituted)
#     /for/salon-barber — via __for-industry template
#     /for/yoga-studio  — via __for-industry template
#
#   UPDATED pages:
#     /why-intake       — expanded with stack comparison + migration section
#     nav               — adds "How it works" link
#
#   NEW section blade:
#     resources/views/marketing/sections/service_menu.blade.php
#     — renders a two-column service/price table, used by __for-industry
#
# Deploy:
#   git pull
#   php artisan db:seed --class=MarketingPagesSeeder --force
#   php artisan optimize:clear
#   sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm
#
# ============================================================================

set -euo pipefail
REPO_ROOT="${REPO_ROOT:-$(pwd)}"
cd "$REPO_ROOT"

echo "==> patch-marketing-pages.sh"
echo ""

# ============================================================================
# Phase 1 — service_menu section blade
# ============================================================================
echo "--- Phase 1: service_menu section blade"

cat > resources/views/marketing/sections/service_menu.blade.php << 'BLADE'
{{--
    Service menu section.
    Content schema:
      eyebrow      string   optional
      heading      string   optional
      subheading   string   optional
      note         string   optional footer note ("Prices editable. This is a starting point.")
      columns      int      1 or 2 (default 2) — side-by-side tables
      tables[]
        heading    string   table heading (e.g. "Haircuts", "Color Services")
        cols       string[] column headers (e.g. ["Service", "Duration", "From"])
        rows[]
          cells    string[] one cell per col
          section  bool     if true, renders as a section-header row (no price)
--}}
@php
    $tables  = $c['tables'] ?? [];
    $numCols = max(1, min(2, (int)($c['columns'] ?? 2)));
    if (is_string($tables)) {
        $decoded = json_decode($tables, true);
        $tables = is_array($decoded) ? $decoded : [];
    }
@endphp

<style>
.mk-smenu-wrap {
    display: grid;
    grid-template-columns: repeat({{ $numCols }}, 1fr);
    gap: 16px;
}
.mk-smenu-table-wrap {
    border: 0.5px solid var(--mk-border);
    border-radius: var(--mk-r-lg);
    overflow: hidden;
}
.mk-smenu-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.mk-smenu-table thead th {
    padding: 10px 14px;
    text-align: left;
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .07em;
    color: var(--mk-muted);
    border-bottom: 0.5px solid var(--mk-border);
    background: rgba(255,255,255,.03);
}
.mk-smenu-table thead th.mk-smenu-th-head {
    font-size: 12px;
    font-weight: 700;
    text-transform: none;
    letter-spacing: 0;
    color: var(--mk-text);
    background: rgba(255,255,255,.04);
    border-bottom: 0.5px solid var(--mk-border2);
}
.mk-smenu-table td {
    padding: 9px 14px;
    border-bottom: 0.5px solid var(--mk-border);
    color: rgba(255,255,255,.65);
}
.mk-smenu-table tr:last-child td { border-bottom: none; }
.mk-smenu-table td:not(:first-child) { text-align: right; }
.mk-smenu-section-row td {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .07em;
    color: var(--mk-text);
    background: rgba(255,255,255,.04);
    padding: 8px 14px;
    border-bottom: 0.5px solid var(--mk-border2);
}
@media(max-width: 700px) { .mk-smenu-wrap { grid-template-columns: 1fr; } }
</style>

<section class="mk-section">
    <div class="mk-container">
        @if(!empty($c['eyebrow']))
            <div class="mk-eyebrow">{{ $c['eyebrow'] }}</div>
        @endif
        @if(!empty($c['heading']))
            <h2 class="mk-section-title">{{ $c['heading'] }}</h2>
        @endif
        @if(!empty($c['subheading']))
            <p class="mk-section-sub">{{ $c['subheading'] }}</p>
        @endif

        <div class="mk-smenu-wrap">
            @foreach($tables as $table)
                <div class="mk-smenu-table-wrap">
                    <table class="mk-smenu-table">
                        <thead>
                            @if(!empty($table['heading']))
                                <tr>
                                    <th class="mk-smenu-th-head" colspan="{{ count($table['cols'] ?? ['Service', 'Price']) }}">
                                        {{ $table['heading'] }}
                                    </th>
                                </tr>
                            @endif
                            <tr>
                                @foreach(($table['cols'] ?? ['Service', 'Price']) as $col)
                                    <th>{{ $col }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach(($table['rows'] ?? []) as $row)
                                @if(!empty($row['section']))
                                    <tr class="mk-smenu-section-row">
                                        <td colspan="{{ count($table['cols'] ?? ['Service', 'Price']) }}">
                                            {{ $row['cells'][0] ?? '' }}
                                        </td>
                                    </tr>
                                @else
                                    <tr>
                                        @foreach(($row['cells'] ?? []) as $cell)
                                            <td>{{ $cell }}</td>
                                        @endforeach
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endforeach
        </div>

        @if(!empty($c['note']))
            <p style="font-size:12px;color:var(--mk-dim);margin-top:12px">{{ $c['note'] }}</p>
        @endif
    </div>
</section>
BLADE

echo "    Written: resources/views/marketing/sections/service_menu.blade.php"


# ============================================================================
# Phase 2 — MarketingPagesSeeder
# ============================================================================
echo "--- Phase 2: MarketingPagesSeeder"

cat > database/seeders/MarketingPagesSeeder.php << 'PHP'
<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\Tenant\TenantPage;
use App\Models\Tenant\TenantPageSection;
use App\Models\Tenant\TenantNavItem;
use Illuminate\Database\Seeder;

/**
 * MarketingPagesSeeder
 *
 * Seeds new pages and updates existing ones:
 *   NEW:     /how-it-works, __for-industry template
 *   UPDATED: /why-intake (full content), nav (adds How it works)
 *
 * Idempotent — safe to re-run. Existing page rows are updated;
 * sections are wiped and reseeded on each run.
 */
class MarketingPagesSeeder extends Seeder
{
    public function run(): void
    {
        $platform = Tenant::where('is_platform', true)->first();

        if (! $platform) {
            $this->command->error('No platform tenant found.');
            return;
        }

        // ----------------------------------------------------------------
        // Nav — add "How it works" between Pricing and Roadmap
        // We don't wipe the full nav here — just ensure the item exists
        // at the right sort_order. PlatformMarketingSeeder owns the full
        // nav wipe; we just insert/update our new item.
        // ----------------------------------------------------------------
        TenantNavItem::updateOrCreate(
            ['tenant_id' => $platform->id, 'url' => '/how-it-works'],
            [
                'label'           => 'How it works',
                'is_external'     => false,
                'open_in_new_tab' => false,
                'sort_order'      => 25,  // between Pricing(20) and Roadmap(30)
            ]
        );
        $this->command->info('Nav: ensured "How it works" at sort_order 25.');

        // ----------------------------------------------------------------
        // /how-it-works
        // ----------------------------------------------------------------
        $this->seedPage($platform, 'how-it-works', 'How it works', 'How Intake works — from sign-up to first booking', [

            ['type' => 'hero', 'content' => [
                'eyebrow'           => 'How it works',
                'headline'          => 'From sign-up to first booking in under 10 minutes',
                'accent_words'      => 'first booking',
                'subheading'        => 'No developer needed. No data migration headache. Your shop, online — with everything in one place.',
                'text_align'        => 'center',
                'height'            => 'medium',
                'cta_primary_label' => 'Start free trial',
                'cta_primary_url'   => '/signup',
                'note'              => 'Free 14-day trial · No credit card required',
            ]],

            ['type' => 'step_timeline', 'content' => [
                'eyebrow'    => 'The setup',
                'heading'    => 'Up and running in minutes',
                'subheading' => 'Five steps from zero to taking real bookings.',
                'steps'      => [
                    ['title' => 'Sign up',        'desc' => 'Create your account and claim your yourshop.intake.works subdomain instantly.',                  'done' => true],
                    ['title' => 'Add services',   'desc' => 'Start from your industry\'s pre-loaded catalog. Edit names, prices, and durations.',              'done' => true],
                    ['title' => 'Set capacity',   'desc' => 'How many jobs per day, minimum notice, how far ahead customers can book. Date overrides for holidays.', 'done' => true],
                    ['title' => 'Connect payments', 'desc' => 'Link Stripe or PayPal in about 2 minutes. Deposits or full payment at booking.',               'done' => true],
                    ['title' => 'Share your link', 'desc' => 'Put it in your Instagram bio, Google Business, or email footer. Start taking bookings.',         'done' => false],
                ],
            ]],

            ['type' => 'feature_grid', 'content' => [
                'eyebrow'    => 'What you get on day one',
                'heading'    => 'Everything in one place from the start',
                'subheading' => 'No add-ons to configure. No integrations to wire. It\'s all there when you sign up.',
                'columns'    => 3,
                'features'   => [
                    ['icon' => '📅', 'title' => 'Online booking form',       'body' => 'Your branded booking page — service selection, date picker, payment — live immediately at your subdomain.'],
                    ['icon' => '📋', 'title' => 'Work orders',               'body' => 'Every booking creates a work order. Move it through status stages, add charges, leave staff notes.'],
                    ['icon' => '🖥',  'title' => 'POS register',              'body' => 'Walk-in sales, retail, drafts, quotes, and refunds. Inventory decrements automatically at commit.'],
                    ['icon' => '📆', 'title' => 'Calendar & scheduling',     'body' => 'Day view with resource columns, staff assignment, walk-in holds, and a live now-line.'],
                    ['icon' => '👤', 'title' => 'Customer CRM',              'body' => 'Full history, lifetime spend, and notes auto-built from every booking. No data entry.'],
                    ['icon' => '💳', 'title' => 'Stripe + PayPal',           'body' => 'Collect deposits or full payment at booking. Funds go directly to you — no Intake cut ever.'],
                ],
                'cta_label' => 'See all features',
                'cta_url'   => '/features',
            ]],

            ['type' => 'stats_row', 'content' => [
                'stats' => [
                    ['number' => '< 10 min', 'label' => 'to first live booking page'],
                    ['number' => '0',        'label' => 'transaction fees from Intake'],
                    ['number' => '14 days',  'label' => 'free trial, no card needed'],
                    ['number' => '1',        'label' => 'login for everything'],
                ],
            ]],

            ['type' => 'faq_accordion', 'content' => [
                'heading' => 'Common questions',
                'items'   => [
                    ['q' => 'Do I need a developer to set up Intake?',
                     'a' => 'No. The whole setup — services, capacity, payments, booking page — is done through the admin dashboard. No code, no DNS wrangling (unless you want a custom domain, which is a single CNAME record).'],
                    ['q' => 'Can I migrate my existing customer data?',
                     'a' => 'Yes. Send us your data in whatever format you have it — spreadsheet, CSV, export from your old tool. We do the import and cleanup. Free on the Scale plan, $299 one-time on others.'],
                    ['q' => 'What happens to my booking page URL?',
                     'a' => 'On Starter you get yourshop.intake.works. On Branded and above you can use your own domain (e.g. book.yourshop.com) with a simple CNAME record. Your customers never see "intake.works" unless you want them to.'],
                    ['q' => 'How does the 14-day trial work?',
                     'a' => 'Full access to all features on your chosen plan. No credit card required. At the end of your trial you subscribe or your account pauses — no charges, no data deleted.'],
                    ['q' => 'Can I use Intake on my phone?',
                     'a' => 'Yes. The admin is fully mobile-optimised — the schedule view, work orders, customer list, and register all have dedicated phone layouts. You can run your whole day from your pocket.'],
                ],
            ]],

            ['type' => 'cta_banner', 'content' => [
                'headline'   => 'Ready to try it?',
                'subheading' => 'Free 14-day trial. No credit card. No setup fee.',
                'cta_label'  => 'Start your free trial',
                'cta_url'    => '/signup',
            ]],

        ]);

        // ----------------------------------------------------------------
        // /why-intake — full replacement with stack comparison content
        // ----------------------------------------------------------------
        $this->seedPage($platform, 'why-intake', 'Why Intake', 'Why Intake — built for service shops', [

            ['type' => 'hero', 'content' => [
                'eyebrow'           => 'Why Intake',
                'headline'          => 'Built for service shops — not against them.',
                'accent_words'      => 'service shops',
                'subheading'        => 'Most booking and POS tools were built for retail or restaurants. Intake was built specifically for shops that take things in, do work, and give them back — by someone who runs one.',
                'text_align'        => 'center',
                'height'            => 'medium',
                'cta_primary_label' => 'Start free trial',
                'cta_primary_url'   => '/signup',
            ]],

            ['type' => 'feature_grid', 'content' => [
                'eyebrow'    => 'What makes us different',
                'heading'    => 'Three things you can\'t get elsewhere',
                'subheading' => 'These aren\'t just features — they\'re architectural decisions most competitors can\'t bolt on later.',
                'columns'    => 3,
                'features'   => [
                    ['icon' => '🔒', 'title' => 'Real concurrency locks',    'body' => 'Advisory locks at the database level. Two customers booking the same slot at the same time? Only one gets it. Most competitors are eventually-consistent — you find out about the double-book after the fact.'],
                    ['icon' => '⚡', 'title' => 'POS + booking unified',     'body' => 'Walk-in sales, work orders, appointments, and inventory in one tool. No syncing between Square and Acuity. No reconciling reports at end of day. One source of truth.'],
                    ['icon' => '🏪', 'title' => 'Operator-built defaults',   'body' => 'Pre-loaded service catalogs with realistic pricing tiers and status workflows that match how service shops actually run — not generic "appointment software" mapped awkwardly onto your shop.'],
                ],
            ]],

            ['type' => 'comparison_table', 'content' => [
                'heading'     => 'Intake vs. the patchwork stack',
                'competitors' => ['Intake Branded', 'Acuity + Square + Mailchimp + Sheets'],
                'rows'        => [
                    ['feature' => 'Online booking form',        'values' => ['yes', 'yes']],
                    ['feature' => 'Work order tracking',        'values' => ['yes', 'no']],
                    ['feature' => 'POS register',               'values' => ['yes', 'yes']],
                    ['feature' => 'Customer history + CRM',     'values' => ['yes', 'partial']],
                    ['feature' => 'Email campaigns',            'values' => ['yes', 'paid']],
                    ['feature' => 'Race-safe concurrency',      'values' => ['yes', 'no']],
                    ['feature' => 'Staff assignment',           'values' => ['yes', 'add-on']],
                    ['feature' => 'Inventory management',       'values' => ['yes', 'partial']],
                    ['feature' => 'Logins required',            'values' => ['1', '4+']],
                    ['feature' => 'Monthly cost',               'values' => ['$79/mo', '~$70/mo + sync headaches']],
                ],
            ]],

            ['type' => 'feature_grid', 'content' => [
                'eyebrow'  => 'Switching is easier than you think',
                'heading'  => 'Migration is included',
                'columns'  => 3,
                'features' => [
                    ['icon' => '📥', 'title' => 'Data import',    'body' => 'CSV, spreadsheet, or export from your current tool — we handle the cleanup and import. Free on Scale, $299 one-time on other plans.'],
                    ['icon' => '🎧', 'title' => 'Guided setup',   'body' => 'A real person walks you through your first week. Not a chatbot, not a 40-page help doc.'],
                    ['icon' => '🌐', 'title' => 'Keep your URL',  'body' => 'Bring your own domain on Branded. Your customers won\'t notice the switch — and that\'s the point.'],
                ],
                'cta_label' => 'Start free trial',
                'cta_url'   => '/signup',
            ]],

            ['type' => 'cta_banner', 'content' => [
                'headline'   => 'Try it on your own shop',
                'subheading' => 'Free 14-day trial. No credit card. Migration help included.',
                'cta_label'  => 'Start your free trial',
                'cta_url'    => '/signup',
            ]],

        ]);

        // ----------------------------------------------------------------
        // __for-industry  (template page — slug starts with __ so it's
        // invisible to the generic /{slug} route and the nav)
        // All user-visible text uses {industry_*} tokens which
        // MarketingController::substituteTokens() replaces at render time
        // with values from config/industry_packs.php.
        // ----------------------------------------------------------------
        $this->seedPage($platform, '__for-industry', '__for-industry template', '__for-industry', [

            ['type' => 'hero', 'content' => [
                'eyebrow'           => 'For {industry_name}',
                'headline'          => '{industry_tagline}',
                'subheading'        => 'Intake comes pre-loaded with a realistic service catalog, the right workflow statuses, and a booking form your customers can use on any device.',
                'text_align'        => 'left',
                'height'            => 'medium',
                'cta_primary_label' => 'Start free trial',
                'cta_primary_url'   => '/signup?industry={industry_slug}',
                'cta_secondary_label' => 'See all features',
                'cta_secondary_url'   => '/features',
                'note'              => 'Free 14-day trial · No credit card · Pre-loaded service catalog',
            ]],

            ['type' => 'feature_grid', 'content' => [
                'eyebrow'    => 'Pre-loaded for {industry_name}',
                'heading'    => 'Ready in minutes, not days',
                'subheading' => '{industry_services_blurb}',
                'columns'    => 3,
                'features'   => [
                    ['icon' => '📋', 'title' => 'Service catalog — pre-loaded', 'body' => '{industry_services_blurb}'],
                    ['icon' => '🔄', 'title' => 'Workflow statuses',            'body' => '{industry_workflow_blurb}'],
                    ['icon' => '📅', 'title' => 'Branded booking form',         'body' => 'Your colors, your URL. Customers pick service, date, and pay — all on mobile. Works from your Instagram bio or Google Business profile link.'],
                    ['icon' => '🖥',  'title' => 'Walk-in POS',                 'body' => 'Same register for walk-in customers. Inventory decrements automatically at sale.'],
                    ['icon' => '📊', 'title' => 'Work order tracking',          'body' => 'Every job gets a reference number. Your team sees status; customers get a confirmation. No more "is it ready?" calls.'],
                    ['icon' => '👤', 'title' => 'Customer history',             'body' => 'See everything a customer has had done, what they spent, and when they last visited. Build the relationship, not just the ticket.'],
                ],
            ]],

            ['type' => 'step_timeline', 'content' => [
                'eyebrow'    => 'How it works',
                'heading'    => 'Up and running in minutes',
                'steps'      => [
                    ['title' => 'Sign up',         'desc' => 'Create your account. Your booking page is live immediately.',   'done' => true],
                    ['title' => 'Edit services',   'desc' => 'Your pre-loaded catalog is ready. Adjust names and prices.',    'done' => true],
                    ['title' => 'Set capacity',    'desc' => 'How many jobs per day, min notice, booking window.',            'done' => true],
                    ['title' => 'Connect payments','desc' => 'Link Stripe or PayPal. Deposits or full payment at booking.',   'done' => true],
                    ['title' => 'Share & book',    'desc' => 'Send your booking link. Start taking real jobs.',               'done' => false],
                ],
            ]],

            ['type' => 'faq_accordion', 'content' => [
                'heading' => 'Frequently asked questions',
                'items'   => [
                    ['q' => 'Is the service catalog locked in?',
                     'a' => 'Not at all. The pre-loaded catalog is a starting point. Add, remove, or rename any service. Change prices, durations, and tiers whenever you like.'],
                    ['q' => 'Can multiple staff members have separate calendars?',
                     'a' => 'Yes. Each resource (staff member or workbench) gets their own calendar column. Customers can pick a specific person or book "any available."'],
                    ['q' => 'Do you take a cut of my bookings?',
                     'a' => 'No. Intake charges a flat monthly fee. We take zero cut of your bookings or sales. You pay Stripe\'s or PayPal\'s standard processing rate — that\'s it.'],
                    ['q' => 'Can I use my own domain?',
                     'a' => 'Yes, on the Branded plan ($79/mo) and above. On Starter you get yourshop.intake.works — which is still a clean, shareable URL.'],
                    ['q' => 'What if I already have customer data somewhere?',
                     'a' => 'We do free data migrations on the Scale plan, and $299 one-time on Starter and Branded. Send us whatever you have — spreadsheet, CSV, old system export — and we handle the rest.'],
                ],
            ]],

            ['type' => 'cta_banner', 'content' => [
                'headline'   => 'Built for {industry_name}. Free to try.',
                'subheading' => '14-day trial. No credit card. Pre-loaded catalog included.',
                'cta_label'  => 'Start free trial',
                'cta_url'    => '/signup?industry={industry_slug}',
            ]],

        ]);

        $this->command->info('Seeded: how-it-works, why-intake (updated), __for-industry template.');
        $this->command->info('Nav: "How it works" added at sort_order 25.');
        $this->command->info('');
        $this->command->info('Industry vertical URLs now live (once __for-industry is published):');
        $this->command->info('  /for/bike-shops    /for/salon-barber    /for/yoga-studio');
        $this->command->info('  /for/auto-detailing  /for/massage-therapy  /for/personal-trainer');
        $this->command->info('  ... and all other slugs in config/industry_packs.php');
    }

    /**
     * Idempotent — matches PlatformMarketingSeeder::seedPage() exactly.
     * Updates the page row if it exists; always replaces sections.
     */
    private function seedPage(Tenant $platform, string $slug, string $title, string $metaTitle, array $sections): void
    {
        $page = TenantPage::updateOrCreate(
            ['tenant_id' => $platform->id, 'slug' => $slug],
            [
                'title'       => $title,
                'meta_title'  => $metaTitle,
                'is_home'     => false,
                'is_published' => true,
                'is_in_nav'   => false,
                'nav_order'   => 0,
            ]
        );

        TenantPageSection::where('page_id', $page->id)->delete();

        foreach ($sections as $i => $sec) {
            TenantPageSection::create([
                'tenant_id'    => $platform->id,
                'page_id'      => $page->id,
                'section_type' => $sec['type'],
                'content'      => $sec['content'],
                'sort_order'   => ($i + 1) * 10,
                'is_visible'   => true,
            ]);
        }
    }
}
PHP

echo "    Written: database/seeders/MarketingPagesSeeder.php"


# ============================================================================
# Phase 3 — Register section type in PageBuilderController::DEFAULTS
#           so it appears in the editor section-type picker
# ============================================================================
echo "--- Phase 3: register service_menu in PageBuilderController"

CONTROLLER="app/Http/Controllers/Tenant/PageBuilderController.php"

if grep -q "service_menu" "$CONTROLLER" 2>/dev/null; then
    echo "    service_menu already registered — skipping"
else
    python3 - << 'PYEOF'
import re, sys

path = "app/Http/Controllers/Tenant/PageBuilderController.php"
try:
    content = open(path).read()
except FileNotFoundError:
    print(f"    WARN: {path} not found — skipping DEFAULTS registration")
    sys.exit(0)

# Find the DEFAULTS array and append service_menu entry
# Look for the last entry before the closing bracket of DEFAULTS
old = "'roadmap_grid'    => ["
if old not in content:
    # Try another anchor
    old = "'changelog_list'  => ["
    if old not in content:
        print("    WARN: Could not find DEFAULTS anchor — add service_menu manually")
        sys.exit(0)

insert = """'service_menu' => [
        'label'   => 'Service menu',
        'icon'    => 'table',
        'content' => [
            'eyebrow'  => '',
            'heading'  => 'Services',
            'columns'  => 2,
            'note'     => 'All prices editable. This is a starting point.',
            'tables'   => [],
        ],
    ],
    """ + old

assert content.count(old) == 1, f"Expected 1 occurrence of anchor, found {content.count(old)}"
content = content.replace(old, insert, 1)
open(path, 'w').write(content)
print(f"    Registered service_menu in {path}")
PYEOF
fi


# ============================================================================
# Phase 4 — Add MarketingPagesSeeder to DatabaseSeeder call chain (optional)
# ============================================================================
echo "--- Phase 4: DatabaseSeeder call chain"

DB_SEEDER="database/seeders/DatabaseSeeder.php"
if grep -q "MarketingPagesSeeder" "$DB_SEEDER" 2>/dev/null; then
    echo "    Already registered — skipping"
else
    python3 - << 'PYEOF'
path = "database/seeders/DatabaseSeeder.php"
try:
    content = open(path).read()
except FileNotFoundError:
    print(f"    WARN: {path} not found — run seeder manually")
    import sys; sys.exit(0)

# Insert after PlatformMarketingSeeder call if present
if 'PlatformMarketingSeeder' in content:
    content = content.replace(
        '$this->call(PlatformMarketingSeeder::class);',
        '$this->call(PlatformMarketingSeeder::class);\n        $this->call(MarketingPagesSeeder::class);'
    )
    open(path, 'w').write(content)
    print("    Added MarketingPagesSeeder after PlatformMarketingSeeder")
else:
    print("    WARN: PlatformMarketingSeeder not found in DatabaseSeeder — add MarketingPagesSeeder manually")
PYEOF
fi


# ============================================================================
# Done
# ============================================================================
echo ""
echo "==> Done. Deploy with:"
echo ""
echo "    git add resources/views/marketing/sections/service_menu.blade.php"
echo "    git add database/seeders/MarketingPagesSeeder.php"
echo "    git add database/seeders/DatabaseSeeder.php"
echo "    git add app/Http/Controllers/Tenant/PageBuilderController.php"
echo "    git commit -m 'patch: marketing pages — how-it-works, why-intake, for-industry template'"
echo "    git push"
echo ""
echo "    # On server:"
echo "    git pull"
echo "    php artisan db:seed --class=MarketingPagesSeeder --force"
echo "    php artisan optimize:clear"
echo "    sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm"
echo ""
echo "    # Verify:"
echo "    curl -s -o /dev/null -w '%{http_code}' https://intake.works/how-it-works"
echo "    curl -s -o /dev/null -w '%{http_code}' https://intake.works/for/bike-shops"
echo "    curl -s -o /dev/null -w '%{http_code}' https://intake.works/for/salon-barber"
echo "    curl -s -o /dev/null -w '%{http_code}' https://intake.works/for/yoga-studio"
