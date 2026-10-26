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

    // 'title' (MARKER-DASH-BUILDER): the block's own heading, which a template
    // can rename (Layout placement 'title'). Blocks without one have no heading.

    /**
     * MARKER-DASH-ACCESS: the areas (SectionRegistry keys) a block's data
     * comes from — see BlockAccess. Blocks made of links and counts from
     * several areas show if the person can open any of them, then leave out
     * the parts they can't.
     */
    private const QUEUE_AREAS = ['any' => ['schedule', 'waitlist', 'special_orders', 'transfer_requests', 'inventory', 'customers', 'register']];
    private const LINK_AREAS  = ['any' => ['schedule', 'register', 'customers', 'waitlist', 'inventory', 'special_orders', 'reports', 'services', 'resources', 'pages', 'settings', 'rentals', 'inbox', 'team', 'gift_cards']];

    public static function builtIns(): array
    {
        return [
            // Overview
            'attention' => ['name' => 'Needs attention', 'origin' => 'overview', 'view' => 'tenant.dashboard._zone_triage_tiles',
                'reads' => 'Appointments, work orders, special orders, stock', 'desc' => 'The attention counts as a row of tiles.', 'access' => self::QUEUE_AREAS, 'title' => 'Needs you today', 'needs' => []],
            'today'     => ['name' => 'Today', 'origin' => 'overview', 'view' => 'tenant.dashboard._zone_today_tile',
                'reads' => 'Appointments', 'desc' => "Today's bookings and deliveries, or the next day that has some.", 'access' => ['any' => ['schedule']], 'title' => 'Today', 'needs' => []],
            'growth'    => ['name' => 'Growth', 'origin' => 'overview', 'view' => 'tenant.dashboard._zone_growth_tiles',
                'reads' => 'Sales, customers', 'desc' => 'Revenue and customer trends.', 'access' => ['any' => ['reports', 'customers', 'settings']], 'title' => 'How the business is doing', 'needs' => []],
            'launcher'  => ['name' => 'App launcher', 'origin' => 'overview', 'view' => 'tenant.dashboard._zone_launcher',
                'reads' => 'Add-ons the shop has', 'desc' => 'Shortcuts to the main areas.', 'access' => self::LINK_AREAS, 'title' => 'Jump to', 'needs' => []],

            // Tiles
            'needs_you' => ['name' => 'Needs you', 'origin' => 'tiles', 'view' => 'tenant.dashboard.blocks.needs-you',
                'reads' => 'Appointments, work orders, special orders, stock', 'desc' => 'Attention counts as tiles, collapsible.', 'access' => self::QUEUE_AREAS, 'needs' => []],
            'tiles'     => ['name' => 'Tiles', 'origin' => 'tiles', 'view' => 'tenant.dashboard.blocks.tiles',
                'reads' => 'Add-ons the shop has', 'desc' => 'Shortcut tiles each person can reorder and hide.', 'access' => self::LINK_AREAS, 'needs' => ['tiles']],

            // Brief
            'greeting'  => ['name' => 'Greeting and summary', 'origin' => 'brief', 'view' => 'tenant.dashboard.blocks.greeting',
                'reads' => 'Appointments, the queue', 'desc' => 'Greeting, then one line on what is late, due and booked.', 'access' => [], 'needs' => []],
            'actions'   => ['name' => 'Quick actions', 'origin' => 'brief', 'view' => 'tenant.dashboard.blocks.actions',
                'reads' => 'Shop settings', 'desc' => 'New appointment, plus Open register when Retail is on.', 'access' => ['any' => ['schedule', 'register']], 'needs' => []],
            'setup'     => ['name' => 'Finish setup', 'origin' => 'brief', 'view' => 'tenant.dashboard.blocks.setup',
                'reads' => 'Setup progress', 'desc' => 'Shows until services, hours and branding are done.', 'access' => ['all' => ['services', 'capacity', 'settings']], 'needs' => []],
            'queue'     => ['name' => 'The queue', 'origin' => 'brief', 'view' => 'tenant.dashboard.blocks.queue',
                'reads' => 'Appointments, work orders, special orders, stock', 'desc' => 'Late, Today and When you can. Each row opens its list.', 'access' => self::QUEUE_AREAS, 'title' => 'The queue', 'needs' => []],
            'week'      => ['name' => 'This week', 'origin' => 'brief', 'view' => 'tenant.dashboard.blocks.week',
                'reads' => 'Appointments, opening hours', 'desc' => 'Day strip and timeline. Free time links to a new appointment.', 'access' => ['any' => ['schedule']], 'title' => 'This week', 'needs' => ['week']],
            'last30'    => ['name' => 'Last 30 days', 'origin' => 'brief', 'view' => 'tenant.dashboard.blocks.last30',
                'reads' => 'Sales, work orders, customers', 'desc' => 'Sales, jobs completed and new customers against the 30 days before.', 'access' => ['any' => ['reports', 'schedule', 'customers']], 'title' => 'Last 30 days', 'needs' => ['jobs']],
        ];
    }

    /**
     * MARKER-DASH-BUILDER: every block a template can place — the built-ins
     * above, then the ones made in master admin (CustomBlocks).
     */
    public static function all(): array
    {
        return self::builtIns() + CustomBlocks::definitions();
    }

    public static function view(string $key): ?string
    {
        return self::all()[$key]['view'] ?? null;
    }

    /** Every extra data set the blocks in these rows ask for. */
    public static function needs(array $rows): array
    {
        $defs = self::all();
        $needs = [];
        foreach (Layout::blockKeys($rows) as $key) {
            foreach ($defs[$key]['needs'] ?? [] as $n) {
                $needs[$n] = true;
            }
        }

        return array_keys($needs);
    }
}
