<?php

namespace Database\Seeders;

use App\Models\ChangelogEntry;
use App\Models\RoadmapEntry;
use Illuminate\Database\Seeder;

/**
 * Initial public content for /changelog and /roadmap, drafted from the
 * v5 internal roadmap and shipped timeline. Run once via:
 *   php artisan db:seed --class=ChangelogAndRoadmapSeeder
 *
 * Idempotent on title — re-running updates existing rows by title match.
 */
class ChangelogAndRoadmapSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedChangelog();
        $this->seedRoadmap();
    }

    protected function seedChangelog(): void
    {
        $entries = [
            [
                'shipped_on' => '2026-04-26',
                'category'   => 'Calendar',
                'title'      => 'Week and month calendar views',
                'body'       => "Two new ways to see your schedule. Week view shows each resource as a swimlane across seven days, so you can spot who is slammed at a glance. Month view is a density grid with color-coded bars per day. Click any cell to drill into day view.",
                'is_published' => true,
                'is_highlighted' => true,
            ],
            [
                'shipped_on' => '2026-04-26',
                'category'   => 'Calendar',
                'title'      => 'Side-by-side rendering for overlapping appointments',
                'body'       => "When two or more appointments overlap on the same resource, the calendar now splits the column horizontally so you can see all of them. Previously, overlapping appointments would stack on top of each other and only the last one drawn was visible. A small badge in the corner shows how many appointments are sharing that time slot.",
                'is_published' => true,
            ],
            [
                'shipped_on' => '2026-04-26',
                'category'   => 'Calendar',
                'title'      => 'Change the resource on an appointment',
                'body'       => "Reassign a booking to a different staff member or station without canceling and rebuilding it. If the new resource is busy at that time, you get a clear warning with the conflicting appointment shown — so you can choose to override or pick a different slot. Every change is recorded in the appointment notes.",
                'is_published' => true,
            ],
            [
                'shipped_on' => '2026-04-26',
                'category'   => 'Calendar',
                'title'      => 'New appointment modal with date, time, and resource',
                'body'       => "Quick-book now lets you set the date, time, resource, and customer all in one place. Click + New from anywhere — calendar cell, customer page, top nav — and the modal opens with smart defaults you can change.",
                'is_published' => true,
            ],
            [
                'shipped_on' => '2026-04-26',
                'category'   => 'Calendar',
                'title'      => 'Calendar legend',
                'body'       => "A new Legend button in the calendar toolbar opens a panel that explains what every visual signal means — appointment status, prep and cleanup time, walk-in holds, breaks, and resource colors. Open it once, then close it for good once you have the hang of things.",
                'is_published' => true,
            ],
            [
                'shipped_on' => '2026-04-25',
                'category'   => 'Polish',
                'title'      => 'Click-and-go appointment status',
                'body'       => "The status pipeline got a real overhaul. Click any step in the progress bar to move there. Forward moves are silent; backward moves trigger a confirmation. Cancelled appointments now show a clean reopen card. Every action gives you toast feedback, so you always know it took.",
                'is_published' => true,
            ],
            [
                'shipped_on' => '2026-04-25',
                'category'   => 'Polish',
                'title'      => 'Edit services and add-ons inline on appointments',
                'body'       => "Add a service, change a price, override a duration — all directly on the appointment page. Totals and timing update live. The calendar block grows or shrinks in real time as you make changes.",
                'is_published' => true,
            ],
            [
                'shipped_on' => '2026-04-25',
                'category'   => 'Polish',
                'title'      => 'Tenant timezone support',
                'body'       => "Your shop's local time is now the default everywhere — calendar, dashboard, capacity. No more 'why does my schedule say tomorrow at 5pm?' moments. Set your timezone in Settings and the rest of the app honors it.",
                'is_published' => true,
            ],
            [
                'shipped_on' => '2026-04-24',
                'category'   => 'Booking',
                'title'      => 'Race-safe booking',
                'body'       => "When two customers try to book the same time slot at the same instant, the system now reliably gives the slot to one of them and tells the other it's taken. Tested at production-grade concurrency. The kind of fix you don't notice working — but you'd notice if it weren't.",
                'is_published' => true,
            ],
            [
                'shipped_on' => '2026-04-24',
                'category'   => 'Calendar',
                'title'      => 'Resource columns on the day view',
                'body'       => "Each staff member or work station now gets its own column on the day view. Filter to just yours, share the screen with your team, or see who has space when a walk-in shows up.",
                'is_published' => true,
            ],
        ];

        foreach ($entries as $entry) {
            ChangelogEntry::updateOrCreate(
                ['title' => $entry['title']],
                $entry
            );
        }
    }

    protected function seedRoadmap(): void
    {
        $entries = [
            // SHIPPED bucket — recent visible wins
            [
                'status' => 'shipped', 'category' => 'Calendar', 'display_order' => 10,
                'title' => 'Week and month calendar views',
                'body'  => 'Sunday-anchored week with per-resource swimlanes; month view as a density grid. Click to drill into day view.',
                'rough_timeframe' => 'Shipped Apr 26', 'is_published' => true,
            ],
            [
                'status' => 'shipped', 'category' => 'Booking', 'display_order' => 20,
                'title' => 'Race-safe booking engine',
                'body'  => 'When two customers try to book the same time at the same instant, exactly one wins and the other gets told the slot is taken.',
                'rough_timeframe' => 'Shipped Apr 24', 'is_published' => true,
            ],

            // IN PROGRESS bucket
            [
                'status' => 'in_progress', 'category' => 'Stripe', 'display_order' => 10,
                'title' => 'Take payments from your customers',
                'body'  => 'Stripe-powered checkout right inside Intake. Charge at booking or at pickup, refund with one click, no separate POS or invoicing tool needed.',
                'rough_timeframe' => 'In progress', 'is_published' => true,
            ],

            // NEXT UP bucket
            [
                'status' => 'next_up', 'category' => 'Calendar', 'display_order' => 10,
                'title' => 'Drag to reschedule',
                'body'  => 'Move an appointment by dragging it to a new time slot. Conflict warnings if you drag onto something busy.',
                'rough_timeframe' => 'Next up', 'is_published' => true,
            ],
            [
                'status' => 'next_up', 'category' => 'Calendar', 'display_order' => 20,
                'title' => 'Capacity page redesign',
                'body'  => 'Cleaner closed-day handling, per-resource capacity overrides, smarter defaults that match how shops actually run.',
                'rough_timeframe' => 'Next up', 'is_published' => true,
            ],
            [
                'status' => 'next_up', 'category' => 'Customer', 'display_order' => 30,
                'title' => 'Customer cancellation flow',
                'body'  => 'Customers cancel via a signed link in their confirmation email — no login required, one tap on mobile.',
                'rough_timeframe' => 'Next up', 'is_published' => true,
            ],
            [
                'status' => 'next_up', 'category' => 'Workflow', 'display_order' => 40,
                'title' => 'Reports',
                'body'  => 'Real reports for revenue, repeat customers, and resource utilization. Built around the questions shop owners actually ask.',
                'rough_timeframe' => 'Next up', 'is_published' => true,
            ],

            // CONSIDERING bucket
            [
                'status' => 'considering', 'category' => 'Customer', 'display_order' => 10,
                'title' => 'Lead and abandoned-booking recovery',
                'body'  => 'Capture customers who started a booking but did not finish, plus a unified lead inbox for inquiries that come from outside the booking flow.',
                'is_published' => true,
            ],
            [
                'status' => 'considering', 'category' => 'Customer', 'display_order' => 20,
                'title' => 'Unified messaging inbox',
                'body'  => 'SMS and email conversations with customers in one threaded view. Twilio-backed.',
                'is_published' => true,
            ],
            [
                'status' => 'considering', 'category' => 'Workflow', 'display_order' => 30,
                'title' => 'Migration helpers',
                'body'  => 'Import customers and history from Acuity, Square, Mindbody, Vagaro, and CSVs. Wave-based imports so you can preview before committing.',
                'is_published' => true,
            ],
        ];

        foreach ($entries as $entry) {
            RoadmapEntry::updateOrCreate(
                ['title' => $entry['title']],
                $entry
            );
        }
    }
}
