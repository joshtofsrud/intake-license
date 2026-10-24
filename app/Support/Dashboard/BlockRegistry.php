<?php

namespace App\Support\Dashboard;

/**
 * The dashboard blocks that come from code.
 *
 * Each one is a Blade partial that draws one part of a dashboard, plus the
 * extra data it needs beyond what every dashboard already loads (greeting,
 * today, attention, growth, launcher, progress). Templates arrange these by
 * key; the dashboard_blocks table holds the master-admin side of each one
 * (its name, and whether it's hidden everywhere).
 *
 * Adding a built-in block: add an entry here and a row in dashboard_blocks
 * (see DashboardTemplates::seedBuiltIns()).
 */
class BlockRegistry
{
    /** Extra data a block can ask for, and what loads it. */
    public const NEEDS = ['week', 'jobs', 'tiles'];

    public static function builtIns(): array
    {
        return [
            // Overview
            'attention' => ['name' => 'Needs attention', 'origin' => 'overview', 'view' => 'tenant.dashboard._zone_triage_tiles',
                'reads' => 'Appointments, work orders, special orders, stock', 'desc' => 'The attention counts as a row of tiles.', 'needs' => []],
            'today'     => ['name' => 'Today', 'origin' => 'overview', 'view' => 'tenant.dashboard._zone_today_tile',
                'reads' => 'Appointments', 'desc' => "Today's bookings and the week strip.", 'needs' => []],
            'growth'    => ['name' => 'Growth', 'origin' => 'overview', 'view' => 'tenant.dashboard._zone_growth_tiles',
                'reads' => 'Sales, customers', 'desc' => 'Revenue and customer trends.', 'needs' => []],
            'launcher'  => ['name' => 'App launcher', 'origin' => 'overview', 'view' => 'tenant.dashboard._zone_launcher',
                'reads' => 'Add-ons the shop has', 'desc' => 'Shortcuts to the main areas.', 'needs' => []],

            // Tiles
            'needs_you' => ['name' => 'Needs you', 'origin' => 'tiles', 'view' => 'tenant.dashboard.blocks.needs-you',
                'reads' => 'Appointments, work orders, special orders, stock', 'desc' => 'Attention counts as tiles, collapsible.', 'needs' => []],
            'tiles'     => ['name' => 'Tiles', 'origin' => 'tiles', 'view' => 'tenant.dashboard.blocks.tiles',
                'reads' => 'Add-ons the shop has', 'desc' => 'Shortcut tiles each person can reorder and hide.', 'needs' => ['tiles']],

            // Brief
            'greeting'  => ['name' => 'Greeting and summary', 'origin' => 'brief', 'view' => 'tenant.dashboard.blocks.greeting',
                'reads' => 'Appointments, the queue', 'desc' => 'Greeting, then one line on what is late, due and booked.', 'needs' => []],
            'actions'   => ['name' => 'Quick actions', 'origin' => 'brief', 'view' => 'tenant.dashboard.blocks.actions',
                'reads' => 'Shop settings', 'desc' => 'New appointment, plus Open register when Retail is on.', 'needs' => []],
            'setup'     => ['name' => 'Finish setup', 'origin' => 'brief', 'view' => 'tenant.dashboard.blocks.setup',
                'reads' => 'Setup progress', 'desc' => 'Shows until services, hours and branding are done.', 'needs' => []],
            'queue'     => ['name' => 'The queue', 'origin' => 'brief', 'view' => 'tenant.dashboard.blocks.queue',
                'reads' => 'Appointments, work orders, special orders, stock', 'desc' => 'Late, Today and When you can. Each row opens its list.', 'needs' => []],
            'week'      => ['name' => 'This week', 'origin' => 'brief', 'view' => 'tenant.dashboard.blocks.week',
                'reads' => 'Appointments, opening hours', 'desc' => 'Day strip and timeline. Free time links to a new appointment.', 'needs' => ['week']],
            'last30'    => ['name' => 'Last 30 days', 'origin' => 'brief', 'view' => 'tenant.dashboard.blocks.last30',
                'reads' => 'Sales, work orders, customers', 'desc' => 'Sales, jobs completed and new customers against the 30 days before.', 'needs' => ['jobs']],
        ];
    }

    public static function view(string $key): ?string
    {
        return self::builtIns()[$key]['view'] ?? null;
    }

    /** Every extra data set the blocks in these rows ask for. */
    public static function needs(array $rows): array
    {
        $defs = self::builtIns();
        $needs = [];
        foreach (Layout::blockKeys($rows) as $key) {
            foreach ($defs[$key]['needs'] ?? [] as $n) {
                $needs[$n] = true;
            }
        }

        return array_keys($needs);
    }
}
