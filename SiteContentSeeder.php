<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * SiteContentSeeder
 *
 * Seeds public-facing changelog + roadmap entries from the April 30 session.
 * Idempotent: uses updateOrInsert keyed on title so re-running the seeder
 * updates existing rows instead of duplicating them.
 *
 * Transactional: if any row fails (e.g., category enum violation), the entire
 * seeder rolls back so we never end up half-written.
 *
 * Run:
 *   php artisan db:seed --class=SiteContentSeeder
 *
 * If you ever want to wipe the seeded entries and start over, edit titles
 * inline below before re-running, or truncate the tables and re-seed.
 *
 * Categories used:
 *   Existing changelog: Bugfix / Booking / Calendar / Polish
 *   New changelog:      Onboarding / Reports / Infrastructure
 *   Existing roadmap:   Payments / Calendar / Booking / Stripe / Customer / Workflow
 *   New roadmap:        Onboarding / POS / Infrastructure / Pricing
 *
 * If your category column is a hard enum and rejects the new values,
 * the transaction rolls back cleanly. Run with the categories already
 * extended in your migration, or remap to existing categories per the
 * inline comments below.
 */
class SiteContentSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $this->seedChangelog();
            $this->seedRoadmap();
        });

        $this->command?->info('SiteContentSeeder complete: 7 changelog entries, 10 roadmap entries.');
    }

    /**
     * Changelog entries — public at /changelog and tenant-side /admin/whats-new.
     * Ordered newest-first via shipped_on.
     */
    private function seedChangelog(): void
    {
        $entries = [
            [
                'title'          => 'AI-powered onboarding · Tell us about your shop, get a populated booking page in 2 minutes',
                'shipped_on'     => '2026-04-30',
                'category'       => 'Onboarding', // NEW category — change to 'Polish' if hard enum
                'is_highlighted' => true,
                'body'           => <<<'MD'
The new onboarding wizard walks every new tenant through eight quick screens — Industry, Identity, Booking style, Hours, Services, Team, Payment, Done. Each step writes to the database as you go, so you can leave and come back without losing progress.

The big upgrade: **AI Quick Setup** on step 2. Describe your business in 2-3 sentences ("I run a bike shop. Standard tune-up is $89, brake adjustment $40. Open Tue-Sat 8-4.") and the wizard fills out your hours, services, booking style, classes flag, and tagline automatically. You always review before commit — nothing happens silently.

Manual setup is still there if you'd rather walk through every step yourself. Either way, you land on a populated dashboard ready to take your first booking.
MD,
            ],
            [
                'title'          => 'Reports · Six zones, one global toggle',
                'shipped_on'     => '2026-04-30',
                'category'       => 'Reports', // NEW category — change to 'Polish' if hard enum
                'is_highlighted' => true,
                'body'           => <<<'MD'
The new Reports section at /admin/reports surfaces six lenses on your shop:

- **Revenue** — hourly today, daily for week or month, plus a service mix breakdown
- **Bookings & cancellations** — confirmed, cancelled, no-show, same-day counts
- **Customers & retention** — new vs returning daily, plus your top spenders
- **Service popularity** — which services bring in the most bookings or revenue
- **Staff utilization** — per-resource cards with healthy / under-used / overloaded flags
- **Capacity utilization** — heatmap of your hot windows and dead zones

A single global Today / Week / Month toggle drives everything at once. Need a specific window? Click "Custom range" and pick a date or date span on the calendar modal — the whole page reframes.

Honest numbers throughout: staff utilization uses your actual open hours from capacity rules (not a generic 8-hour baseline). No-show rate counts only confirmed appointments past their grace window, not anything pending. Walk-ins are labeled "same-day" because that's what the data actually represents.
MD,
            ],
            [
                'title'          => 'Attention cards · Click any card to see the appointments behind it',
                'shipped_on'     => '2026-04-30',
                'category'       => 'Polish',
                'is_highlighted' => false,
                'body'           => <<<'MD'
The "Needs your attention" cards on your dashboard now actually do something when you click them. Click "Pending bookings · 8" and you land on the Appointments page filtered to those exact 8 — with the same card row at the top showing your other open items, a "Clear filter" link to return to the full list, and the page title swapped to match.

We also overhauled the colors so they actually mean something: red cards need *your* action (Pending bookings, Overdue not started, Overdue in progress) and amber cards are waiting on your *customer* (Unpaid completed, Ready for pickup, Stale pickups). One glance tells you what you can act on right now versus what you should follow up on.
MD,
            ],
            [
                'title'          => 'Class booking is live · Yoga, fitness, lessons, classes',
                'shipped_on'     => '2026-04-29',
                'category'       => 'Booking',
                'is_highlighted' => true,
                'body'           => <<<'MD'
Class-based businesses (yoga, CrossFit, Pilates, fitness, music lessons, and more) now have first-class support on Intake.

**For shop admins:** define class templates with name, duration, capacity, and price. Schedule sessions one-time, weekly (pick days + an end date), or daily across a date range. The session detail page gives you a full roster with check-in, no-show, and waitlist management. Sell unlimited or capped monthly memberships and credit packs with expiry dates.

**For customers:** browse classes with capacity bars and free / full badges. Pay by membership (auto-pre-selected if active), pack credits, or per-class. Join a waitlist for full sessions — when someone cancels, the next person on the list is automatically promoted and their payment source is re-resolved at promotion time.

A new customer portal at /account shows upcoming registrations, history, active membership with period info, and pack credits. Customers create an account with email + password (no magic links — too painful when phones and laptops use different inboxes).

Classes coexist with appointment booking — a yoga studio with private sessions can run both on the same account.
MD,
            ],
            [
                'title'          => 'Your data is now backed up nightly to a separate region',
                'shipped_on'     => '2026-04-29',
                'category'       => 'Infrastructure', // NEW category — change to 'Polish' if hard enum
                'is_highlighted' => false,
                'body'           => <<<'MD'
Every night at 2am, every tenant's database gets dumped, compressed, and uploaded to a separate-region S3 bucket. Backups are kept for 30 days with automatic cleanup of older copies. The restore process has been tested end-to-end.

This is invisible plumbing — you'll never see it on the surface — but it means we can recover from a hardware failure, a bad deploy, or a database mistake without losing more than 24 hours of activity.
MD,
            ],
            [
                'title'          => 'Calendar · Week and month views, smarter overlap rendering, faster break management',
                'shipped_on'     => '2026-04-26',
                'category'       => 'Calendar',
                'is_highlighted' => false,
                'body'           => <<<'MD'
Three calendar additions worth highlighting:

**Week and month views.** Per-resource swimlanes for the week (rows = staff, columns = days). Density grid for the month (color bars per day with hover tooltips). Sunday-anchored. Your view choice persists across reloads.

**Side-by-side rendering for overlapping appointments.** When two or more appointments share a time slot on the same resource, the calendar splits the column horizontally so all of them stay visible. Connected-component cluster detection ensures all overlapping appointments share the same lane denominator — no more "last-rendered wins" silently hiding work.

**Click-to-remove on breaks and walk-in holds.** Click any non-recurring break or walk-in hold on the calendar to delete it via a confirm modal. Recurring entries point you to the capacity admin where they belong. The create → delete loop is now closed for one-off entries.
MD,
            ],
            [
                'title'          => 'New public pages · See what is coming and what just shipped',
                'shipped_on'     => '2026-04-26',
                'category'       => 'Polish',
                'is_highlighted' => false,
                'body'           => <<<'MD'
Two new public pages on intake.works:

- **/changelog** — the running log of what we've shipped, organized by date with tags
- **/roadmap** — what's actively being built and what's coming next

The same data renders inside your admin under "What's New" and "What's Coming" so you can see updates without leaving your shop.

Edited by master admin via Filament. One source of truth for both public marketing pages and tenant-side updates.
MD,
            ],
        ];

        foreach ($entries as $entry) {
            DB::table('changelog_entries')->updateOrInsert(
                ['title' => $entry['title']],
                array_merge($entry, [
                    'is_published' => true,
                    'updated_at'   => Carbon::now(),
                    'created_at'   => DB::raw('COALESCE(created_at, NOW())'),
                ])
            );
        }
    }

    /**
     * Roadmap entries — public at /roadmap and tenant-side /admin/whats-coming.
     * Ordered by display_order ascending.
     */
    private function seedRoadmap(): void
    {
        $entries = [
            [
                'title'           => 'Customer payments — Stripe Connect for taking payments at booking or completion',
                'status'          => 'in_progress',
                'category'        => 'Payments',
                'rough_timeframe' => 'This week',
                'display_order'   => 10,
                'body'            => <<<'MD'
Tenants will be able to take payment from their customers at booking or at completion via Stripe Connect. Refund flow is part of the same surface — process refunds from the appointment view without leaving the admin.

Four sub-decisions still being scoped:
- Standard, Express, or Custom Connect account type
- Platform fee structure (or pure pass-through)
- Required at signup, or progressive after first booking
- Per-tenant opt-out for cash-only shops

Estimated build: 3-5 days after scoping is complete. This is the load-bearing item between now and our first real paying customer.
MD,
            ],
            [
                'title'           => 'POS Phase 1 — Inventory foundation with catalog/shop column separation',
                'status'          => 'next_up',
                'category'        => 'POS', // NEW — remap to 'Workflow' if hard enum
                'rough_timeframe' => '2-3 weeks',
                'display_order'   => 20,
                'body'            => <<<'MD'
Phase 1 of the POS surface: inventory data model with the catalog/shop column-pair pattern (catalog_* fields are overwritten by nightly distributor sync, shop_* fields are never touched), an InventoryService for CRUD, manual receiving, and a basic inventory report.

This is the architectural foundation for everything that follows — walk-in register (Phase 2), parts on appointments (Phase 3), sales reports (Phase 4). Designed to make the Lightspeed Retail / Ascend RMS bug pattern (where shop-defined specifics get reset by catalog updates) structurally impossible.

Phase 1 can begin in parallel with customer payments — no Stripe dependency. Phase 2+ requires customer payments shipped.
MD,
            ],
            [
                'title'           => 'Real pricing — $29 / $79 / $199',
                'status'          => 'next_up',
                'category'        => 'Pricing', // NEW — remap to 'Stripe' if hard enum
                'rough_timeframe' => 'This week',
                'display_order'   => 30,
                'body'            => <<<'MD'
Swap placeholder pricing for real launch pricing: $29 / $79 / $199 monthly, with 10× annual pricing for the discount. Update Stripe products + the public pricing page.

Estimated build: 1.25 hours including the self-QA Loom recording walkthrough.
MD,
            ],
            [
                'title'           => 'Stripe addons — Buy add-ons from inside your billing portal',
                'status'          => 'next_up',
                'category'        => 'Stripe',
                'rough_timeframe' => '2 weeks',
                'display_order'   => 40,
                'body'            => <<<'MD'
Tenants will be able to add or remove paid add-ons (offline sync, Bike Pack distributor sync, etc.) directly from the billing portal. Today add-on management is admin-only; this opens it up to self-service.

Estimated build: 3 days. Sequenced after customer payments because both reuse the same Stripe Connect onboarding flow.
MD,
            ],
            [
                'title'           => 'Master admin billing dashboard — MRR, dunning, churn at a glance',
                'status'          => 'next_up',
                'category'        => 'Stripe',
                'rough_timeframe' => '2 weeks',
                'display_order'   => 50,
                'body'            => <<<'MD'
A dedicated billing view for master admin showing MRR by tier, dunning queue (cards that failed, awaiting retry), recent churns with reason codes, and a per-tenant subscription snapshot. Most of the data is already on Stripe — this surfaces it inside Intake without needing to context-switch.

Estimated build: 2 days.
MD,
            ],
            [
                'title'           => 'In-app plan change — Change your plan from inside Intake (no Stripe portal hop)',
                'status'          => 'next_up',
                'category'        => 'Stripe',
                'rough_timeframe' => '3 weeks',
                'display_order'   => 60,
                'body'            => <<<'MD'
Today plan changes go through Stripe's customer portal, which means leaving Intake. We're moving plan changes back inside the admin — when you upgrade from Starter to Pro, you see the comparison, the prorated charge preview, and confirm without leaving your shop.

Estimated build: 2 days.
MD,
            ],
            [
                'title'           => 'Customer cancellation — Cancel via signed-URL link, no login required',
                'status'          => 'next_up',
                'category'        => 'Customer',
                'rough_timeframe' => '2 weeks',
                'display_order'   => 70,
                'body'            => <<<'MD'
Class registration cancellation is already shipped via the customer portal. Appointment-side cancellation needs the same treatment: a signed-URL "Manage appointment" link in the confirmation email, a two-state landing page (still cancellable / past cutoff), confirm modal, and tenant admin settings for the cancellation window.

Estimated build: 2 days.
MD,
            ],
            [
                'title'           => 'AI Quick Setup hardening — Anthropic tool-use API for guaranteed JSON shape',
                'status'          => 'next_up',
                'category'        => 'Onboarding', // NEW — remap to 'Workflow' if hard enum
                'rough_timeframe' => 'Next session',
                'display_order'   => 80,
                'body'            => <<<'MD'
The current AI Quick Setup uses a system prompt that describes the JSON schema and a defensive parsing layer to catch the occasional model typo. Anthropic's tool-use mode enforces JSON shape at the protocol level — the model literally cannot return malformed output.

Estimated build: 30 minutes. No user-visible change; eliminates a class of edge-case failures.
MD,
            ],
            [
                'title'           => 'Mode-aware attention card labels',
                'status'          => 'next_up',
                'category'        => 'Workflow',
                'rough_timeframe' => 'Next session',
                'display_order'   => 90,
                'body'            => <<<'MD'
Today's "Pending bookings" card on the dashboard reads as appointment-shop language. For drop-off shops the booking is confirmed — the *item* hasn't arrived yet. We'll split labels per mode: "Awaiting drop-off" for drop-off shops, "Pending bookings" for time-slot shops. Same data, accurate framing.

Same audit applies to "Ready for pickup" and "Stale pickups" — phrasing should match how each shop type actually thinks about the work.

Estimated build: 1 hour.
MD,
            ],
            [
                'title'           => 'Test coverage — enum boundaries, status transitions, payment paths',
                'status'          => 'next_up',
                'category'        => 'Infrastructure', // NEW — remap to 'Workflow' if hard enum
                'rough_timeframe' => '2 weeks',
                'display_order'   => 100,
                'body'            => <<<'MD'
April 30 shipped a one-letter typo in the onboarding wizard that blocked two tenants from logging in. A single integration test asserting that the wizard's booking-step save persists a value the schema accepts would have caught it pre-deploy.

We're not building a full suite — just the high-blast-radius paths: wizard step writes, status transitions on appointments and class registrations, payment_status flips. The contract assertions that catch typo-class bugs.

Estimated build: 1 day.
MD,
            ],
        ];

        foreach ($entries as $entry) {
            DB::table('roadmap_entries')->updateOrInsert(
                ['title' => $entry['title']],
                array_merge($entry, [
                    'is_published' => true,
                    'updated_at'   => Carbon::now(),
                    'created_at'   => DB::raw('COALESCE(created_at, NOW())'),
                ])
            );
        }
    }
}
