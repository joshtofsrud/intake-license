#!/bin/bash
# ============================================================================
# patch-how-it-works.sh
# ----------------------------------------------------------------------------
# Adds the screen_showcase section blade and reseeds /how-it-works with
# full content — one section per step, each showing both the desktop admin
# view and the mobile customer/staff view side by side.
#
# Deploy:
#   bash patch-how-it-works.sh
#   php artisan db:seed --class=HowItWorksSeeder --force
#   php artisan optimize:clear
#   sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm
# ============================================================================

set -euo pipefail
REPO_ROOT="${REPO_ROOT:-$(pwd)}"
cd "$REPO_ROOT"

echo "==> patch-how-it-works.sh"

# ============================================================================
# Phase 1 — screen_showcase section blade
# Renders a two-column step: text + bullet points on the left,
# then two sub-columns showing desktop (labeled panel) and mobile (phone frame)
# Content schema:
#   eyebrow        string
#   step_num       int
#   heading        string
#   body           string
#   points[]       string[]
#   desktop_label  string   e.g. "Admin — Services"
#   desktop_lines[]         rows rendered in the desktop panel
#     { label, value, muted, badge, badge_color }
#   mobile_label   string   e.g. "Customer booking form"
#   mobile_lines[]          rows rendered in phone frame
#     { label, value, muted, badge, badge_color, selected }
#   mobile_note    string   small caption under phone
# ============================================================================
echo "--- Phase 1: screen_showcase section blade"

cat > resources/views/marketing/sections/screen_showcase.blade.php << 'BLADE'
{{--
    Screen showcase — step-by-step section showing desktop + mobile side by side.

    Content schema:
      eyebrow        string
      step_num       int|string
      heading        string
      body           string
      points[]       string[]
      desktop_label  string
      desktop_lines[]  {label, value?, muted?, badge?, badge_color?, accent?}
      mobile_label   string
      mobile_lines[]   {label, value?, muted?, badge?, badge_color?, selected?, divider?}
      mobile_note    string
      flip           bool   — if true, screens left / text right (alternates rhythm)
--}}
@php
    $points       = $c['points'] ?? [];
    $desktopLines = $c['desktop_lines'] ?? [];
    $mobileLines  = $c['mobile_lines'] ?? [];
    $flip         = !empty($c['flip']);

    $badgeStyle = function(string $color): string {
        return match($color) {
            'green'  => 'background:rgba(190,242,100,.12);color:#BEF264;',
            'blue'   => 'background:rgba(56,138,221,.15);color:#85B7EB;',
            'amber'  => 'background:rgba(186,117,23,.2);color:#EF9F27;',
            'purple' => 'background:rgba(168,85,247,.15);color:#C084FC;',
            'red'    => 'background:rgba(239,68,68,.15);color:#F87171;',
            default  => 'background:rgba(255,255,255,.08);color:rgba(255,255,255,.6);',
        };
    };
@endphp

<style>
.sc-wrap{display:grid;grid-template-columns:1fr 1fr;gap:clamp(32px,5vw,72px);align-items:start;padding:clamp(48px,6vw,80px) 0;border-bottom:.5px solid var(--mk-border)}
.sc-wrap:last-of-type{border-bottom:none}
.sc-step-num{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:50%;background:var(--mk-accent);color:var(--mk-accent-text);font-size:13px;font-weight:700;margin-bottom:14px}
.sc-heading{font-size:clamp(20px,2.5vw,28px);font-weight:700;letter-spacing:-.02em;line-height:1.2;margin-bottom:10px}
.sc-body{font-size:14px;color:var(--mk-muted);line-height:1.7;margin-bottom:16px}
.sc-points{display:flex;flex-direction:column;gap:8px}
.sc-point{font-size:13px;color:rgba(255,255,255,.6);display:flex;align-items:flex-start;gap:8px;line-height:1.45}
.sc-point-dot{width:4px;height:4px;border-radius:50%;background:var(--mk-accent);flex-shrink:0;margin-top:8px}
.sc-screens{display:grid;grid-template-columns:1fr 1fr;gap:14px;align-items:start}
.sc-screen-label{font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--mk-dim);font-weight:600;margin-bottom:8px;text-align:center}
.sc-desktop{background:rgba(255,255,255,.02);border:.5px solid var(--mk-border);border-radius:10px;overflow:hidden}
.sc-desktop-bar{background:#0a0a0a;padding:8px 12px;display:flex;align-items:center;gap:6px;border-bottom:.5px solid var(--mk-border)}
.sc-desktop-dot{width:7px;height:7px;border-radius:50%}
.sc-desktop-body{padding:12px}
.sc-desktop-row{display:flex;align-items:center;justify-content:space-between;padding:7px 0;border-bottom:.5px solid rgba(255,255,255,.05);font-size:11px}
.sc-desktop-row:last-child{border-bottom:none}
.sc-desktop-label{color:rgba(255,255,255,.55)}
.sc-desktop-value{color:var(--mk-text);font-weight:500;text-align:right}
.sc-desktop-accent{color:var(--mk-accent);font-weight:600}
.sc-badge{font-size:9px;font-weight:600;padding:2px 6px;border-radius:4px}
.sc-phone{background:#0e0e0e;border-radius:20px;border:1.5px solid rgba(255,255,255,.15);overflow:hidden;margin:0 auto;max-width:160px}
.sc-phone-bar{height:24px;background:#0a0a0a;display:flex;align-items:center;justify-content:center}
.sc-phone-time{font-size:9px;font-weight:600;color:var(--mk-text)}
.sc-phone-body{padding:10px}
.sc-phone-row{padding:6px 0;border-bottom:.5px solid rgba(255,255,255,.06);font-size:10px}
.sc-phone-row:last-child{border-bottom:none}
.sc-phone-row-inner{display:flex;align-items:center;justify-content:space-between}
.sc-phone-label{color:rgba(255,255,255,.7);font-weight:500}
.sc-phone-value{color:var(--mk-muted)}
.sc-phone-muted{font-size:9px;color:var(--mk-dim);margin-top:2px}
.sc-phone-selected{background:rgba(190,242,100,.08);border:.5px solid rgba(190,242,100,.3);border-radius:6px;padding:6px 8px;margin-bottom:5px}
.sc-phone-selected .sc-phone-label{color:var(--mk-accent)}
.sc-note{font-size:11px;color:var(--mk-dim);text-align:center;margin-top:6px}
@media(max-width:860px){.sc-wrap{grid-template-columns:1fr}.sc-screens{grid-template-columns:1fr 1fr}}
@media(max-width:480px){.sc-screens{grid-template-columns:1fr}}
</style>

<div class="sc-wrap" style="{{ $flip ? 'direction:rtl' : '' }}">
    {{-- Text column --}}
    <div style="{{ $flip ? 'direction:ltr' : '' }}">
        @if(!empty($c['eyebrow']))
            <div class="mk-eyebrow">{{ $c['eyebrow'] }}</div>
        @endif
        @if(!empty($c['step_num']))
            <div class="sc-step-num">{{ $c['step_num'] }}</div>
        @endif
        <div class="sc-heading">{{ $c['heading'] ?? '' }}</div>
        @if(!empty($c['body']))
            <p class="sc-body">{{ $c['body'] }}</p>
        @endif
        @if(!empty($points))
            <div class="sc-points">
                @foreach($points as $pt)
                    <div class="sc-point"><div class="sc-point-dot"></div>{{ $pt }}</div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Screens column --}}
    <div style="{{ $flip ? 'direction:ltr' : '' }}">
        <div class="sc-screens">

            {{-- Desktop panel --}}
            <div>
                @if(!empty($c['desktop_label']))
                    <div class="sc-screen-label">{{ $c['desktop_label'] }}</div>
                @endif
                <div class="sc-desktop">
                    <div class="sc-desktop-bar">
                        <div class="sc-desktop-dot" style="background:#FF5F57"></div>
                        <div class="sc-desktop-dot" style="background:#FEBC2E"></div>
                        <div class="sc-desktop-dot" style="background:#28C840"></div>
                        <div style="flex:1;height:14px;background:rgba(255,255,255,.04);border-radius:3px;margin-left:6px"></div>
                    </div>
                    <div class="sc-desktop-body">
                        @foreach($desktopLines as $row)
                            @if(!empty($row['section']))
                                <div style="font-size:9px;text-transform:uppercase;letter-spacing:.07em;color:var(--mk-dim);padding:8px 0 4px;font-weight:600;border-bottom:.5px solid rgba(255,255,255,.05)">
                                    {{ $row['label'] }}
                                </div>
                            @else
                                <div class="sc-desktop-row">
                                    <span class="sc-desktop-label">{{ $row['label'] ?? '' }}</span>
                                    @if(!empty($row['badge']))
                                        <span class="sc-badge" style="{{ $badgeStyle($row['badge_color'] ?? 'default') }}">{{ $row['badge'] }}</span>
                                    @elseif(!empty($row['value']))
                                        <span class="{{ !empty($row['accent']) ? 'sc-desktop-accent' : 'sc-desktop-value' }}">
                                            {{ $row['value'] }}
                                        </span>
                                    @endif
                                </div>
                            @endif
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Mobile phone --}}
            <div>
                @if(!empty($c['mobile_label']))
                    <div class="sc-screen-label">{{ $c['mobile_label'] }}</div>
                @endif
                <div class="sc-phone">
                    <div class="sc-phone-bar">
                        <span class="sc-phone-time">9:41</span>
                    </div>
                    <div class="sc-phone-body">
                        @foreach($mobileLines as $row)
                            @if(!empty($row['selected']))
                                <div class="sc-phone-selected">
                                    <div class="sc-phone-row-inner">
                                        <span class="sc-phone-label">{{ $row['label'] ?? '' }}</span>
                                        @if(!empty($row['badge']))
                                            <span class="sc-badge" style="{{ $badgeStyle($row['badge_color'] ?? 'green') }}">{{ $row['badge'] }}</span>
                                        @elseif(!empty($row['value']))
                                            <span style="font-size:10px;color:var(--mk-accent);font-weight:600">{{ $row['value'] }}</span>
                                        @endif
                                    </div>
                                    @if(!empty($row['muted']))
                                        <div class="sc-phone-muted">{{ $row['muted'] }}</div>
                                    @endif
                                </div>
                            @elseif(!empty($row['divider']))
                                <div style="height:.5px;background:rgba(255,255,255,.06);margin:4px 0"></div>
                            @else
                                <div class="sc-phone-row">
                                    <div class="sc-phone-row-inner">
                                        <span class="sc-phone-label">{{ $row['label'] ?? '' }}</span>
                                        @if(!empty($row['badge']))
                                            <span class="sc-badge" style="{{ $badgeStyle($row['badge_color'] ?? 'default') }}">{{ $row['badge'] }}</span>
                                        @elseif(!empty($row['value']))
                                            <span class="sc-phone-value">{{ $row['value'] }}</span>
                                        @endif
                                    </div>
                                    @if(!empty($row['muted']))
                                        <div class="sc-phone-muted">{{ $row['muted'] }}</div>
                                    @endif
                                </div>
                            @endif
                        @endforeach
                    </div>
                </div>
                @if(!empty($c['mobile_note']))
                    <div class="sc-note">{{ $c['mobile_note'] }}</div>
                @endif
            </div>

        </div>
    </div>
</div>
BLADE

echo "    Written: resources/views/marketing/sections/screen_showcase.blade.php"


# ============================================================================
# Phase 2 — HowItWorksSeeder
# ============================================================================
echo "--- Phase 2: HowItWorksSeeder"

cat > database/seeders/HowItWorksSeeder.php << 'PHP'
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
PHP

echo "    Written: database/seeders/HowItWorksSeeder.php"


# ============================================================================
# Done
# ============================================================================
echo ""
echo "==> Done. Deploy with:"
echo ""
echo "    git add resources/views/marketing/sections/screen_showcase.blade.php \\"
echo "            database/seeders/HowItWorksSeeder.php"
echo "    git commit -m 'feat: how-it-works — screen_showcase section, full step content'"
echo "    git push"
echo ""
echo "    # On server:"
echo "    git pull"
echo "    php artisan db:seed --class=HowItWorksSeeder --force"
echo "    php artisan optimize:clear"
echo "    sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm"
echo ""
echo "    # Verify:"
echo "    curl -s -o /dev/null -w '%{http_code}\\n' https://intake.works/how-it-works"
