#!/bin/bash
# ============================================================================
# patch-57-marketing-pages-seed.sh
# ----------------------------------------------------------------------------
# Extends PlatformMarketingSeeder with four new pages:
#   - home          (/)
#   - features      (/features)
#   - pricing       (/pricing)
#   - __for-industry  (template page for /for/{slug} routes)
#
# Also registers screen_showcase in PageBuilderController DEFAULTS so the
# admin page builder can add/edit sections of this type (the Blade partial
# already exists, but DEFAULTS registration was missing).
# ============================================================================

set -euo pipefail
cd "${REPO_ROOT:-$(pwd)}"

if [ ! -f "database/seeders/PlatformMarketingSeeder.php" ]; then
  echo "ERROR: PlatformMarketingSeeder.php not found." >&2
  exit 1
fi
if [ ! -f "app/Http/Controllers/Tenant/PageBuilderController.php" ]; then
  echo "ERROR: PageBuilderController.php not found." >&2
  exit 1
fi

# ─── 1. Register screen_showcase in DEFAULTS ────────────────────────────
python3 <<'PYEOF'
from pathlib import Path
p = Path("app/Http/Controllers/Tenant/PageBuilderController.php")
s = p.read_text()

old = "        'stats_row'        => ['eyebrow'=>'','heading'=>'','stats'=>[['number'=>'200+','label'=>'Businesses'],['number'=>'50k+','label'=>'Appointments'],['number'=>'24','label'=>'Industries']]],\n    ];"

new = """        'stats_row'        => ['eyebrow'=>'','heading'=>'','stats'=>[['number'=>'200+','label'=>'Businesses'],['number'=>'50k+','label'=>'Appointments'],['number'=>'24','label'=>'Industries']]],
        'screen_showcase'  => ['eyebrow'=>'','step_num'=>1,'heading'=>'Step heading','body'=>'Short body for this step.','points'=>[],'desktop_label'=>'Desktop','desktop_lines'=>[],'mobile_label'=>'Mobile','mobile_lines'=>[],'mobile_note'=>'','flip'=>false],
    ];"""

if "'screen_showcase'" in s:
    print("    SKIP screen_showcase — already registered in DEFAULTS")
elif old not in s:
    raise SystemExit("ABORT screen_showcase: DEFAULTS closing anchor not found")
else:
    s = s.replace(old, new, 1)
    p.write_text(s)
    print("    UPDATED — screen_showcase registered in DEFAULTS")
PYEOF

# ─── 2. Extend PlatformMarketingSeeder with new pages ───────────────────
python3 <<'PYEOF'
from pathlib import Path
p = Path("database/seeders/PlatformMarketingSeeder.php")
s = p.read_text()

old_anchor = "        $this->command->info('Seeded 5 marketing pages: roadmap, changelog, why-intake, contact, invest.');"

new_pages_php = """        // ═══════════════════════════════════════════════════════════════════════
        // HOME PAGE — operator voice, no proof bar (re-add when 10+ shops)
        // ═══════════════════════════════════════════════════════════════════════
        $this->seedPage($platform, 'home', 'Home', 'Intake — Run your shop without juggling five tools', [
            ['type' => 'hero', 'content' => [
                'eyebrow'           => 'Built by a shop owner',
                'headline'          => 'Run your shop without juggling five tools',
                'accent_words'      => 'five tools',
                'subheading'        => 'Booking, POS, and work orders — one login, one truth. Made for the shops most software ignores. Live in under 10 minutes.',
                'text_align'        => 'center',
                'height'            => 'large',
                'cta_primary_label' => 'Start free trial',
                'cta_primary_url'   => '/signup',
            ]],
            ['type' => 'feature_grid', 'content' => [
                'eyebrow'  => 'Everything included',
                'heading'  => 'What it takes to run a shop. In one tool.',
                'subheading' => 'Every tool a service shop needs — without stitching together a dozen apps.',
                'columns'  => 3,
                'features' => [
                    ['icon' => '📅', 'title' => 'Online booking', 'body' => 'Multi-step form with service selection, resource picker, availability calendar, and payment. Race-safe advisory locks — no double-books ever.'],
                    ['icon' => '📋', 'title' => 'Work orders', 'body' => 'Every booking creates a work order. Track status, add charges, leave staff notes — from intake through pickup.'],
                    ['icon' => '🛒', 'title' => 'POS register', 'body' => 'Walk-in sales, retail, drafts, quotes, and refunds. Inventory decrements automatically at commit.'],
                    ['icon' => '👥', 'title' => 'Customer CRM', 'body' => 'Auto-built from every booking. Full history, lifetime spend, last visit, and internal notes.'],
                    ['icon' => '🗓', 'title' => 'Calendar & scheduling', 'body' => 'Day view with resource columns, staff assignment, walk-in holds, breaks, and a live now-line.'],
                    ['icon' => '💳', 'title' => 'Stripe + PayPal', 'body' => 'Deposits or full payment at booking. Funds go directly to you — Intake never takes a cut.'],
                ],
                'cta_label' => 'See all features →',
                'cta_url'   => '/features',
            ]],
            ['type' => 'industry_pack_showcase', 'content' => [
                'eyebrow'      => 'Industries',
                'heading'      => 'Made for your kind of shop',
                'subheading'   => 'Pre-configured catalogs, workflows, and terminology — not generic appointment software.',
                'limit'        => 12,
                'show_all_link'=> true,
            ]],
            ['type' => 'step_timeline', 'content' => [
                'eyebrow' => 'How it works',
                'heading' => 'Up and running in minutes',
                'steps' => [
                    ['title' => 'Sign up',           'desc' => 'Claim your subdomain',          'done' => true],
                    ['title' => 'Add services',      'desc' => 'Edit your pre-loaded catalog',  'done' => true],
                    ['title' => 'Set capacity',      'desc' => 'Jobs per day, notice, window',  'done' => true],
                    ['title' => 'Connect payments',  'desc' => 'Stripe or PayPal in 2 min',     'done' => true],
                    ['title' => 'Share & book',      'desc' => 'Send the link, start taking jobs', 'done' => false],
                ],
            ]],
            ['type' => 'pricing_table', 'content' => [
                'eyebrow'    => 'Pricing',
                'heading'    => 'Simple plans, no surprises',
                'subheading' => '',
                'source'     => 'config',
                'featured'   => 'branded',
                'plans'      => [],
                'footnote'   => '14-day free trial · No credit card · No transaction fees',
            ]],
            ['type' => 'cta_banner', 'content' => [
                'headline'   => 'Ready to fill your calendar?',
                'subheading' => 'Free 14-day trial. No credit card required.',
                'cta_label'  => 'Start your free trial',
                'cta_url'    => '/signup',
            ]],
        ]);

        // ═══════════════════════════════════════════════════════════════════════
        // FEATURES PAGE
        // ═══════════════════════════════════════════════════════════════════════
        $this->seedPage($platform, 'features', 'Features', 'Features — everything a service shop needs', [
            ['type' => 'hero', 'content' => [
                'eyebrow'    => 'Features',
                'headline'   => 'Everything a service shop needs',
                'subheading' => 'Built for shops that take things in, do work, and give them back — not generic appointment software mapped onto your shop.',
                'text_align' => 'center',
                'height'     => 'small',
            ]],
            ['type' => 'screen_showcase', 'content' => [
                'eyebrow'       => 'Booking engine',
                'step_num'      => 1,
                'heading'       => 'A booking experience customers actually finish',
                'body'          => 'Your branded booking form — service selection, resource picker, availability calendar, and payment all in one flow. Advisory locks at the database level mean two customers can never steal the same slot.',
                'points' => [
                    'Multi-step form with progress indicator',
                    'Service catalog with Standard / Full Service / Rush tiers',
                    'Per-resource availability calendar',
                    'Stripe and PayPal at booking',
                    'Race-safe concurrency — no double-books, ever',
                ],
                'desktop_label' => 'Customer booking',
                'desktop_lines' => [
                    ['label' => 'Fox 36 full service', 'value' => '$180', 'badge' => '✓', 'badge_color' => 'green'],
                    ['label' => 'Brake bleed (pair)', 'value' => '$65', 'badge' => '✓', 'badge_color' => 'green'],
                    ['label' => 'Drivetrain clean', 'badge' => 'Add?', 'badge_color' => 'amber'],
                ],
                'mobile_label'  => 'Pay',
                'mobile_lines'  => [
                    ['label' => 'Card ending 4242', 'badge' => 'Selected', 'badge_color' => 'green'],
                    ['label' => 'PayPal'],
                ],
                'mobile_note'   => 'Deposit $90 due',
                'flip'          => false,
            ]],
            ['type' => 'screen_showcase', 'content' => [
                'eyebrow'       => 'Calendar & scheduling',
                'step_num'      => 2,
                'heading'       => 'See your whole day at a glance',
                'body'          => 'Day view with one column per resource — staff member or workbench. Walk-in holds, breaks, and a live now-line keep you oriented without hunting through menus.',
                'points' => [
                    'Per-resource day columns',
                    'Status colors: pending → confirmed → in progress → completed',
                    'Walk-in hold blocks with lime highlight',
                    'Staff assignment on appointments',
                    'Break scheduling per resource',
                ],
                'desktop_label' => 'Calendar',
                'desktop_lines' => [
                    ['label' => '9:00 — Jane Smith', 'badge' => 'In progress', 'badge_color' => 'purple'],
                    ['label' => '10:30 — Tom Lee', 'badge' => 'Confirmed', 'badge_color' => 'blue'],
                    ['label' => 'Walk-in hold', 'accent' => true],
                ],
                'mobile_label'  => 'Today',
                'mobile_lines'  => [
                    ['label' => '3 in progress'],
                    ['label' => '2 ready for pickup'],
                    ['label' => '$1,240 today', 'badge_color' => 'blue'],
                ],
                'flip'          => true,
            ]],
            ['type' => 'screen_showcase', 'content' => [
                'eyebrow'       => 'Work orders',
                'step_num'      => 3,
                'heading'       => 'Every job tracked from drop-off to pickup',
                'body'          => 'Each booking creates a work order with a unique reference number. Move it through status stages, add mid-job charges, and leave staff notes customers never see.',
                'points' => [
                    'Unique reference number per job',
                    'Status pipeline: pending → confirmed → in progress → completed → closed',
                    'Add extra charges mid-job',
                    'Internal staff notes — never visible to customers',
                    'Payment tracking: deposit paid, balance due, fully paid',
                ],
                'desktop_label' => 'Work orders',
                'desktop_lines' => [
                    ['label' => 'SPK-A3F9 · Jane Smith', 'badge' => 'In progress', 'badge_color' => 'purple'],
                    ['label' => 'SPK-B2E8 · Tom Lee', 'badge' => 'Ready', 'badge_color' => 'green'],
                    ['label' => 'SPK-C1D7 · Mia Park', 'badge' => 'Confirmed', 'badge_color' => 'blue'],
                ],
                'mobile_label'  => 'Job detail',
                'mobile_lines'  => [
                    ['label' => 'SPK-A3F9'],
                    ['label' => 'Fox 36 service', 'value' => '$180'],
                    ['label' => 'Add charge', 'badge' => '+', 'badge_color' => 'green'],
                ],
                'flip'          => false,
            ]],
            ['type' => 'screen_showcase', 'content' => [
                'eyebrow'       => 'POS Register',
                'step_num'      => 4,
                'heading'       => 'Walk-ins and retail — handled',
                'body'          => 'A full register for walk-in customers and retail sales. Cart lives in the browser until commit, then inventory decrements automatically. Supports drafts, quotes, and refunds in the same flow.',
                'points' => [
                    'Walk-in cart — no account required',
                    'Inventory auto-decrements at commit',
                    'Save as draft or quote — resume later',
                    'Auto-save prevents lost transactions',
                    'Refund flow built in',
                ],
                'desktop_label' => 'POS Register',
                'desktop_lines' => [
                    ['label' => 'Park Tool BBT-90.3', 'value' => '$24.99'],
                    ['label' => 'Chain lube 4oz', 'value' => '$12.00'],
                    ['label' => 'SRAM Eagle chain', 'value' => '$52.00'],
                    ['label' => 'Total', 'value' => '$96.73', 'accent' => true],
                ],
                'mobile_label'  => 'Walk-in',
                'mobile_lines'  => [
                    ['label' => 'New customer', 'badge_color' => 'green'],
                    ['label' => 'Search existing'],
                    ['label' => 'Anonymous sale'],
                ],
                'flip'          => true,
            ]],
            ['type' => 'cta_banner', 'content' => [
                'headline'   => 'Stop running your shop on five tools.',
                'subheading' => 'Free 14-day trial. No credit card required.',
                'cta_label'  => 'Start your free trial',
                'cta_url'    => '/signup',
            ]],
        ]);

        // ═══════════════════════════════════════════════════════════════════════
        // PRICING PAGE
        // ═══════════════════════════════════════════════════════════════════════
        $this->seedPage($platform, 'pricing', 'Pricing', 'Pricing — simple plans, no surprises', [
            ['type' => 'hero', 'content' => [
                'eyebrow'    => 'Pricing',
                'headline'   => 'Simple plans, no surprises',
                'subheading' => 'Start free. Upgrade when you are ready. Cancel anytime. No transaction fees — ever.',
                'text_align' => 'center',
                'height'     => 'small',
            ]],
            ['type' => 'pricing_table', 'content' => [
                'eyebrow'    => '',
                'heading'    => '',
                'subheading' => '',
                'source'     => 'config',
                'featured'   => 'branded',
                'plans'      => [],
                'footnote'   => '',
            ]],
            ['type' => 'comparison_table', 'content' => [
                'eyebrow'    => '',
                'heading'    => 'Compare plans',
                'subheading' => '',
                'competitors'=> ['Starter', 'Branded', 'Scale'],
                'rows' => [
                    ['feature' => 'Online booking form',          'values' => ['yes', 'yes', 'yes']],
                    ['feature' => 'Calendar + resource columns',  'values' => ['yes', 'yes', 'yes']],
                    ['feature' => 'Staff assignment',             'values' => ['yes', 'yes', 'yes']],
                    ['feature' => 'Race-safe concurrency',        'values' => ['yes', 'yes', 'yes']],
                    ['feature' => 'Work order management',        'values' => ['yes', 'yes', 'yes']],
                    ['feature' => 'POS register',                 'values' => ['yes', 'yes', 'yes']],
                    ['feature' => 'Customer CRM',                 'values' => ['yes', 'yes', 'yes']],
                    ['feature' => 'Inventory management',         'values' => ['yes', 'yes', 'yes']],
                    ['feature' => 'Team members',                 'values' => ['3', '10', 'Unlimited']],
                    ['feature' => 'Custom domain',                'values' => ['no', 'yes', 'yes']],
                    ['feature' => 'Remove Intake branding',       'values' => ['no', 'yes', 'yes']],
                    ['feature' => 'Email campaigns',              'values' => ['no', 'yes', 'yes']],
                    ['feature' => 'Email support',                'values' => ['yes', 'yes', 'yes']],
                    ['feature' => 'Priority support',             'values' => ['no', 'yes', 'yes']],
                    ['feature' => 'Dedicated account manager',    'values' => ['no', 'no', 'yes']],
                    ['feature' => 'Multi-location',               'values' => ['no', 'no', 'yes']],
                ],
            ]],
            ['type' => 'faq_accordion', 'content' => [
                'eyebrow'    => '',
                'heading'    => 'Frequently asked questions',
                'subheading' => '',
                'items' => [
                    ['q' => 'How does the free trial work?', 'a' => '14 days free with full access on your chosen plan. No credit card required. At the end, subscribe or your account pauses — no charges, no data deleted.'],
                    ['q' => 'Do you take a cut of my bookings?', 'a' => 'No transaction fees from Intake, ever. You pay your plan fee plus Stripe and PayPal standard rates (typically 2.9% + 30¢). That is it.'],
                    ['q' => 'Can I switch plans later?', 'a' => 'Yes, any time. Upgrades take effect immediately. Downgrades take effect at the end of your billing period.'],
                    ['q' => 'What happens to my data if I cancel?', 'a' => 'Your account pauses — all data retained for 90 days. Export everything or reactivate any time within that window.'],
                    ['q' => 'Can I use my own domain on Starter?', 'a' => 'Custom domains are on Branded and above. On Starter you get yourshop.intake.works — still a clean, shareable URL.'],
                ],
            ]],
        ]);

        // ═══════════════════════════════════════════════════════════════════════
        // __FOR-INDUSTRY TEMPLATE — renders at /for/{slug} with token substitution
        // Tokens: {industry_name}, {industry_tagline}, {industry_services_blurb},
        //         {industry_workflow_blurb}, {industry_icon}
        // ═══════════════════════════════════════════════════════════════════════
        $this->seedPage($platform, '__for-industry', 'For {industry_name}', 'Intake for {industry_name}', [
            ['type' => 'hero', 'content' => [
                'eyebrow'           => 'For {industry_name}',
                'headline'          => '{industry_tagline}',
                'subheading'        => 'Intake comes pre-loaded with a realistic service catalog, drop-off workflow statuses, and a booking form your customers can use on their phone from Instagram.',
                'text_align'        => 'left',
                'height'            => 'medium',
                'cta_primary_label' => 'Start free trial',
                'cta_primary_url'   => '/signup',
                'note'              => 'Free 14-day trial · No credit card · Pre-loaded service catalog',
            ]],
            ['type' => 'feature_grid', 'content' => [
                'eyebrow'    => 'Pre-loaded for {industry_name}',
                'heading'    => 'Ready in minutes, not days',
                'subheading' => 'Your {industry_name} pack includes a pre-built service catalog, realistic pricing, and the right workflow statuses — no starting from a blank slate.',
                'columns'    => 3,
                'features'   => [
                    ['icon' => '📱', 'title' => 'Works from your Instagram bio', 'body' => 'Your branded booking form. Customers pick service, date, and pay on mobile. No app, no signup wall — just the link in your bio.'],
                    ['icon' => '🔧', 'title' => 'Service catalog — pre-loaded', 'body' => '{industry_services_blurb}'],
                    ['icon' => '🛤', 'title' => 'Drop-off workflow', 'body' => '{industry_workflow_blurb}'],
                    ['icon' => '💻', 'title' => 'Walk-in POS', 'body' => 'Same register for walk-ins buying retail items or services. Inventory decrements automatically.'],
                    ['icon' => '📋', 'title' => 'Work order tracking', 'body' => 'Every job gets a reference number. No more "is it ready?" calls — customers get a confirmation email.'],
                    ['icon' => '👥', 'title' => 'Customer history', 'body' => 'See every job a customer has brought in, what was done, and what they spent. Build the relationship.'],
                ],
            ]],
            ['type' => 'cta_banner', 'content' => [
                'headline'   => 'Built for {industry_name}. Free to try.',
                'subheading' => '14-day trial. No credit card. Pre-loaded service catalog included.',
                'cta_label'  => 'Start free trial',
                'cta_url'    => '/signup',
            ]],
        ]);

        // Refresh existing why-intake page with operator-voice edits.
        // updateOrCreate + section-replace makes this idempotent.
        $this->seedPage($platform, 'why-intake', 'Why Intake', 'Why Intake', [
            ['type' => 'hero', 'content' => [
                'eyebrow'           => 'Why Intake',
                'headline'          => 'Built for service shops — not against them.',
                'accent_words'      => 'not against them',
                'subheading'        => 'Most booking and POS tools were built for retail or restaurants. Intake was built specifically for shops that take things in, do work, and give them back — by someone who runs one.',
                'text_align'        => 'center',
                'height'            => 'medium',
                'cta_primary_label' => 'Start free trial',
                'cta_primary_url'   => '/signup',
            ]],
            ['type' => 'feature_grid', 'content' => [
                'eyebrow'  => 'What makes us different',
                'heading'  => 'Three things you cannot get elsewhere',
                'subheading' => 'These are not just features — they are architectural decisions most competitors cannot bolt on later.',
                'columns'  => 3,
                'features' => [
                    ['icon' => '🔒', 'title' => 'Real concurrency locks', 'body' => 'Advisory locks at the database level. Two customers booking the same slot simultaneously? Only one gets it. Most competitors are eventually-consistent — you find out about the double-book after the fact.'],
                    ['icon' => '🧰', 'title' => 'POS + booking unified', 'body' => 'Walk-in sales, work orders, appointments, and inventory in one tool. No syncing between Square and Acuity. No reconciling reports at end of day. One login, one source of truth.'],
                    ['icon' => '🏪', 'title' => 'Built by someone running a shop', 'body' => 'Every default — the service tiers, the status pipeline, the deposit logic — comes from running a real service business. Not from a product manager imagining one.'],
                ],
            ]],
            ['type' => 'cta_banner', 'content' => [
                'headline'   => 'Try it on your own shop',
                'subheading' => 'Free 14-day trial. No credit card. Migration help included.',
                'cta_label'  => 'Start your free trial',
                'cta_url'    => '/signup',
            ]],
        ]);

        $this->command->info('Seeded 9 marketing pages: home, features, pricing, roadmap, changelog, why-intake, contact, invest, __for-industry.');"""

if "'home', 'Home'" in s and "'features', 'Features'" in s and "'pricing', 'Pricing'" in s and "__for-industry" in s:
    print("    SKIP seeder — new pages already seeded")
elif old_anchor not in s:
    raise SystemExit("ABORT seeder: closing message anchor not found")
else:
    s = s.replace(old_anchor, new_pages_php, 1)
    p.write_text(s)
    print("    UPDATED — added home, features, pricing, __for-industry pages + why-intake refresh")
PYEOF

cat <<EONOTE

==> Patch 57 applied locally.

Deploy:
  git add app/Http/Controllers/Tenant/PageBuilderController.php \\
          database/seeders/PlatformMarketingSeeder.php \\
          patch-57-marketing-pages-seed.sh
  git commit -m "feat: seed home/features/pricing + for-industry template (patch 57)"
  git push

On server:
  cd /var/www/intake
  git pull
  php artisan optimize:clear
  php artisan db:seed --class=PlatformMarketingSeeder --force
  sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm

Verify:
  1. https://intake.works/         → new home page renders with operator-voice hero
  2. https://intake.works/features → 4 screen_showcase sections
  3. https://intake.works/pricing  → plans + comparison table + FAQ
  4. https://intake.works/why-intake → updated copy with founder-story card
  5. https://intake.works/for/bike-shops → renders __for-industry template
     with bike-shop tokens substituted in
  6. https://intake.works/for/salons → same template, salons content
  7. https://intake.works/for/auto-detailing → same template, auto-detail content

Admin verify:
  - Master admin → marketing pages: 9 pages listed
  - Edit home page → page builder shows all sections, editable
  - Try adding a "Screen showcase" section → now appears in Add Section menu

If a page renders weirdly: the seeder is idempotent, so you can edit
PlatformMarketingSeeder and re-run db:seed safely. Page rows persist; only
sections inside reseeded pages get rewritten.
EONOTE
