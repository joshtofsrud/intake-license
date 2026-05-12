<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\Tenant\TenantPage;
use App\Models\Tenant\TenantPageSection;
use Illuminate\Database\Seeder;

/**
 * HowItWorksSeeder
 *
 * Full content seed for /how-it-works.
 * Each step uses the screen_showcase section type which renders
 * desktop admin panel + mobile phone frame side by side.
 *
 * Idempotent — safe to re-run.
 */
class HowItWorksSeeder extends Seeder
{
    public function run(): void
    {
        $platform = Tenant::where('is_platform', true)->first();

        if (! $platform) {
            $this->command->error('No platform tenant found.');
            return;
        }

        $page = TenantPage::updateOrCreate(
            ['tenant_id' => $platform->id, 'slug' => 'how-it-works'],
            [
                'title'        => 'How it works',
                'meta_title'   => 'How Intake works — from sign-up to first booking in minutes',
                'meta_description' => 'See how Intake works step by step — sign up, configure services, take bookings, manage your calendar, and track every job from your phone or desktop.',
                'is_home'      => false,
                'is_published' => true,
                'is_in_nav'    => false,
                'nav_order'    => 0,
            ]
        );

        TenantPageSection::where('page_id', $page->id)->delete();

        $sections = [

            // ── Hero ────────────────────────────────────────────────────
            [
                'type'    => 'hero',
                'content' => [
                    'eyebrow'             => 'How it works',
                    'headline'            => 'From sign-up to first booking in under 10 minutes',
                    'accent_words'        => 'first booking',
                    'subheading'          => 'No developer needed. No data migration headache. Your shop online — booking, POS, calendar, and work orders all in one place from day one.',
                    'text_align'          => 'center',
                    'height'              => 'medium',
                    'cta_primary_label'   => 'Start free trial',
                    'cta_primary_url'     => '/signup',
                    'note'                => 'Free 14-day trial · No credit card required',
                ],
            ],

            // ── Step 1 — Sign up ─────────────────────────────────────────
            [
                'type'    => 'screen_showcase',
                'content' => [
                    'eyebrow'  => 'Step 1',
                    'step_num' => 1,
                    'heading'  => 'Sign up and claim your subdomain',
                    'body'     => 'Create your account in 60 seconds. Tell us your shop name, pick your industry, and your booking page is live immediately at yourshop.intake.works. Bring your own domain on Branded and above.',
                    'points'   => [
                        'yourshop.intake.works live instantly — no waiting',
                        'Industry picker pre-loads your service catalog',
                        'Custom domain (yourshop.com) on Branded plan',
                        'No credit card for the 14-day trial',
                    ],
                    'desktop_label' => 'Admin — onboarding',
                    'desktop_lines' => [
                        ['label' => 'Shop name',    'value' => 'The Bike Hub'],
                        ['label' => 'Subdomain',    'value' => 'thebikehub.intake.works', 'accent' => true],
                        ['label' => 'Industry',     'badge' => 'Bike shop', 'badge_color' => 'green'],
                        ['label' => 'Plan',         'badge' => 'Starter — free trial', 'badge_color' => 'blue'],
                        ['label' => 'Status',       'badge' => 'Live', 'badge_color' => 'green'],
                    ],
                    'mobile_label' => 'Customer — booking page',
                    'mobile_lines' => [
                        ['label' => 'The Bike Hub', 'muted' => 'thebikehub.intake.works'],
                        ['divider' => true],
                        ['label' => 'Book a service', 'badge' => 'Open', 'badge_color' => 'green'],
                        ['label' => 'View services', 'value' => '→'],
                        ['label' => 'Contact us',    'value' => '→'],
                    ],
                    'mobile_note' => 'Your branded booking page',
                ],
            ],

            // ── Step 2 — Services ────────────────────────────────────────
            [
                'type'    => 'screen_showcase',
                'content' => [
                    'eyebrow'  => 'Step 2',
                    'step_num' => 2,
                    'heading'  => 'Configure your services and booking rules',
                    'body'     => 'Your industry pack pre-loads a realistic service catalog with tiers and pricing. Edit what you need, then set capacity — how many jobs per day, minimum advance notice, and how far ahead customers can book.',
                    'points'   => [
                        'Pre-loaded catalog with Standard / Full Service / Rush tiers',
                        'Per-day booking caps by day of week',
                        'Minimum advance notice — e.g. no same-day bookings',
                        'Date overrides for holidays and closures',
                        'Per-resource daily limits — per staff member or bench',
                    ],
                    'flip'     => true,
                    'desktop_label' => 'Admin — services',
                    'desktop_lines' => [
                        ['section' => true, 'label' => 'Tune-ups'],
                        ['label' => 'Basic tune-up',      'value' => '$65 · 60 min'],
                        ['label' => 'Full service',       'value' => '$90 · 90 min'],
                        ['label' => 'Rush tune-up',       'value' => '$120 · 60 min'],
                        ['section' => true, 'label' => 'Suspension'],
                        ['label' => 'Fork service',       'value' => '$120 · 120 min'],
                        ['label' => 'Shock service',      'value' => '$110 · 90 min'],
                    ],
                    'mobile_label' => 'Admin — capacity rules',
                    'mobile_lines' => [
                        ['label' => 'Mon – Fri', 'value' => '6 jobs/day'],
                        ['label' => 'Saturday',  'value' => '4 jobs/day'],
                        ['label' => 'Sunday',    'badge' => 'Closed', 'badge_color' => 'amber'],
                        ['divider' => true],
                        ['label' => 'Min notice', 'value' => '24 hrs'],
                        ['label' => 'Book ahead', 'value' => '60 days'],
                    ],
                    'mobile_note' => 'Capacity settings',
                ],
            ],

            // ── Step 3 — Customer booking flow ──────────────────────────
            [
                'type'    => 'screen_showcase',
                'content' => [
                    'eyebrow'  => 'Step 3',
                    'step_num' => 3,
                    'heading'  => 'Customers book on any device in under a minute',
                    'body'     => 'Share your booking link in your Instagram bio, Google Business profile, or email footer. Customers pick their service, choose a date and time, fill in details, and pay — all in one seamless flow, no account required.',
                    'points'   => [
                        'Multi-step form: services → date → details → pay',
                        'Real-time availability — only open slots shown',
                        'Race-safe locks — two customers can\'t steal the same slot',
                        'Stripe or PayPal deposit or full payment at booking',
                        'Instant confirmation email to customer and your team',
                    ],
                    'desktop_label' => 'Customer — service selection',
                    'desktop_lines' => [
                        ['label' => 'Progress',          'value' => 'Step 1 of 4'],
                        ['section' => true, 'label' => 'Selected'],
                        ['label' => 'Fox 36 full service', 'value' => '$180', 'accent' => true],
                        ['label' => 'Brake bleed (pair)',   'value' => '$65',  'accent' => true],
                        ['section' => true, 'label' => 'Total'],
                        ['label' => 'Deposit due now',    'value' => '$45', 'accent' => true],
                        ['label' => 'Balance at pickup',  'value' => '$200'],
                    ],
                    'mobile_label' => 'Customer — pick a time',
                    'mobile_lines' => [
                        ['label' => 'Mon May 12', 'muted' => 'Choose a slot'],
                        ['divider' => true],
                        ['label' => '9:00 am',  'badge' => 'Full',   'badge_color' => 'amber'],
                        ['label' => '10:00 am', 'badge' => 'Open',   'badge_color' => 'green', 'selected' => true],
                        ['label' => '11:00 am', 'badge' => 'Open',   'badge_color' => 'green'],
                        ['label' => '1:00 pm',  'badge' => 'Open',   'badge_color' => 'green'],
                        ['label' => '3:00 pm',  'badge' => 'Full',   'badge_color' => 'amber'],
                    ],
                    'mobile_note' => 'Time slot picker',
                ],
            ],

            // ── Step 4 — Calendar ────────────────────────────────────────
            [
                'type'    => 'screen_showcase',
                'content' => [
                    'eyebrow'  => 'Step 4',
                    'step_num' => 4,
                    'heading'  => 'Manage your day from the calendar',
                    'body'     => 'Every booking lands on your calendar the moment it\'s confirmed. The desktop day view shows one column per staff member — so you see exactly who\'s doing what. The mobile schedule view gives you the same information in a swipeable list optimised for your phone.',
                    'points'   => [
                        'Desktop: resource columns — one per staff member or workbench',
                        'Status colors: pending → confirmed → in progress → completed',
                        'Walk-in holds block time with a lime highlight',
                        'Live now-line shows where you are in the day',
                        'Mobile: swipeable day list with resource color stripes',
                        'Gap indicators show free windows between appointments',
                    ],
                    'flip'     => true,
                    'desktop_label' => 'Admin — day view (desktop)',
                    'desktop_lines' => [
                        ['section' => true, 'label' => 'Tue May 12  ·  Alex'],
                        ['label' => '9:00 — Jane Smith',  'badge' => 'In progress', 'badge_color' => 'purple'],
                        ['label' => 'Fox 36 full service', 'value' => '120 min'],
                        ['section' => true, 'label' => 'Jordan'],
                        ['label' => '9:00 — Tom Lee',      'badge' => 'Confirmed', 'badge_color' => 'blue'],
                        ['label' => 'Brake bleed + tune',  'value' => '75 min'],
                        ['section' => true, 'label' => '12:30 — Walk-in hold'],
                        ['label' => 'Blocked slot',        'badge' => 'Hold', 'badge_color' => 'green'],
                    ],
                    'mobile_label' => 'Admin — schedule (mobile)',
                    'mobile_lines' => [
                        ['label' => 'Jane Smith',    'badge' => 'In progress', 'badge_color' => 'purple', 'muted' => '9:00 · Fox 36 full service'],
                        ['divider' => true],
                        ['label' => '45 min free', 'muted' => 'gap'],
                        ['divider' => true],
                        ['label' => 'Tom Lee',       'badge' => 'Confirmed',   'badge_color' => 'blue',   'muted' => '10:30 · Brake bleed'],
                        ['divider' => true],
                        ['label' => 'Walk-in hold',  'badge' => 'Hold',        'badge_color' => 'green',  'muted' => '12:30 · 60 min'],
                        ['label' => 'Mia Park',      'badge' => 'Pending',     'badge_color' => 'amber',  'muted' => '2:00 · Build consult'],
                    ],
                    'mobile_note' => 'Mobile schedule view',
                ],
            ],

            // ── Step 5 — Work orders + POS ───────────────────────────────
            [
                'type'    => 'screen_showcase',
                'content' => [
                    'eyebrow'  => 'Step 5',
                    'step_num' => 5,
                    'heading'  => 'Work orders, walk-ins, and the register',
                    'body'     => 'Every booking creates a work order with a unique reference number. Tap it to open the full detail — status pipeline, services, charges, payment, and staff notes. Walk-in customers go straight through the POS register without needing a booking.',
                    'points'   => [
                        'Work order status: pending → confirmed → in progress → completed → closed',
                        'Add mid-job charges for parts or extra work',
                        'Internal staff notes — never shown to customers',
                        'POS register for walk-ins — inventory decrements at commit',
                        'Save as draft or quote — resume later',
                        'Full refund flow built in',
                    ],
                    'flip'     => false,
                    'desktop_label' => 'Admin — work order (desktop)',
                    'desktop_lines' => [
                        ['label' => 'Reference',   'value' => 'SPK-A3F9'],
                        ['label' => 'Customer',    'value' => 'Jane Smith'],
                        ['label' => 'Status',      'badge' => 'In progress', 'badge_color' => 'purple'],
                        ['section' => true, 'label' => 'Services'],
                        ['label' => 'Fox 36 full service', 'value' => '$180'],
                        ['label' => 'Brake bleed (pair)',   'value' => '$65'],
                        ['section' => true, 'label' => 'Payment'],
                        ['label' => 'Deposit paid',  'value' => '$45',  'accent' => true],
                        ['label' => 'Balance due',   'value' => '$200'],
                        ['label' => 'Total',         'value' => '$245', 'accent' => true],
                    ],
                    'mobile_label' => 'Admin — POS register (mobile)',
                    'mobile_lines' => [
                        ['label' => 'Park Tool BBT-90.3', 'value' => '$24.99'],
                        ['label' => 'Chain lube 4oz',     'value' => '$12.00'],
                        ['label' => 'SRAM Eagle chain',   'value' => '$52.00'],
                        ['divider' => true],
                        ['label' => 'Subtotal', 'value' => '$88.99'],
                        ['label' => 'Tax 8.7%', 'value' => '$7.74'],
                        ['label' => 'Total',    'value' => '$96.73', 'selected' => true],
                    ],
                    'mobile_note' => 'Walk-in POS register',
                ],
            ],

            // ── Step 6 — Customer profiles ───────────────────────────────
            [
                'type'    => 'screen_showcase',
                'content' => [
                    'eyebrow'  => 'Step 6',
                    'step_num' => 6,
                    'heading'  => 'Customer profiles build themselves',
                    'body'     => 'Every booking, walk-in sale, and work order contributes to the customer\'s profile automatically. Before a customer even walks in you can see their full history, what they\'ve spent, and any notes your team has left.',
                    'points'   => [
                        'Auto-created from every booking — no data entry',
                        'Full booking and purchase history per customer',
                        'Lifetime spend and visit count',
                        'Internal staff notes — never visible to customers',
                        'Search by name, email, or phone',
                        'One-tap new appointment from the profile',
                    ],
                    'flip'     => true,
                    'desktop_label' => 'Admin — customer profile (desktop)',
                    'desktop_lines' => [
                        ['label' => 'Name',       'value' => 'Jane Smith'],
                        ['label' => 'Email',      'value' => 'jane@example.com'],
                        ['label' => 'Visits',     'value' => '12', 'accent' => true],
                        ['label' => 'Spent',      'value' => '$940', 'accent' => true],
                        ['label' => 'Last visit', 'value' => 'May 12'],
                        ['section' => true, 'label' => 'Recent'],
                        ['label' => 'Fox 36 full service', 'badge' => 'In progress', 'badge_color' => 'purple'],
                        ['label' => 'Tune-up + brakes',    'badge' => 'Completed',   'badge_color' => 'green'],
                    ],
                    'mobile_label' => 'Admin — customer (mobile)',
                    'mobile_lines' => [
                        ['label' => 'Jane Smith',  'muted' => 'jane@example.com'],
                        ['divider' => true],
                        ['label' => '12 visits',   'value' => '$940 spent'],
                        ['divider' => true],
                        ['label' => 'Fox 36 full service', 'badge' => 'In progress', 'badge_color' => 'purple', 'muted' => 'May 12'],
                        ['label' => 'Tune-up + brakes',    'badge' => 'Completed',   'badge_color' => 'green',  'muted' => 'Apr 14'],
                        ['label' => 'Fork service',        'badge' => 'Completed',   'badge_color' => 'green',  'muted' => 'Mar 3'],
                    ],
                    'mobile_note' => 'Customer profile',
                ],
            ],

            // ── Stats ────────────────────────────────────────────────────
            [
                'type'    => 'stats_row',
                'content' => [
                    'stats' => [
                        ['number' => '< 10 min', 'label' => 'to your first live booking page'],
                        ['number' => '$0',        'label' => 'transaction fees from Intake'],
                        ['number' => '14 days',   'label' => 'free trial, no card needed'],
                        ['number' => '1',         'label' => 'login for everything'],
                    ],
                ],
            ],

            // ── FAQ ──────────────────────────────────────────────────────
            [
                'type'    => 'faq_accordion',
                'content' => [
                    'heading' => 'Common questions',
                    'items'   => [
                        [
                            'q' => 'Do I need a developer to set up Intake?',
                            'a' => 'No. The whole setup — services, capacity, payments, booking page — is done through the admin dashboard. No code required. Custom domain setup is a single CNAME record if you want it.',
                        ],
                        [
                            'q' => 'Does it work on mobile?',
                            'a' => 'Yes — fully. The admin has a dedicated mobile layout: swipeable schedule, mobile-optimised work order list, register, and customer profiles. Your customers\' booking form is also fully mobile-first.',
                        ],
                        [
                            'q' => 'Can I migrate my existing customer data?',
                            'a' => 'Yes. Send us your data in whatever format you have — spreadsheet, CSV, or an export from your old tool. We handle the import and cleanup. Free on Scale, $299 one-time on Starter and Branded.',
                        ],
                        [
                            'q' => 'What happens to my booking page URL when I upgrade?',
                            'a' => 'On Starter you get yourshop.intake.works. On Branded ($79/mo) and above you can point your own domain to it with a CNAME record. The yourshop.intake.works URL continues to work and redirects.',
                        ],
                        [
                            'q' => 'How does the free trial work?',
                            'a' => 'Full access to all features on your chosen plan for 14 days. No credit card required. At the end you subscribe or your account pauses — no charges, no data deleted.',
                        ],
                    ],
                ],
            ],

            // ── CTA ──────────────────────────────────────────────────────
            [
                'type'    => 'cta_banner',
                'content' => [
                    'headline'   => 'Ready to try it?',
                    'subheading' => 'Free 14-day trial. No credit card. No setup fee.',
                    'cta_label'  => 'Start your free trial',
                    'cta_url'    => '/signup',
                ],
            ],

        ];

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

        $this->command->info('Seeded /how-it-works with ' . count($sections) . ' sections.');
        $this->command->info('Includes screen_showcase sections for steps 1–6.');
    }
}
