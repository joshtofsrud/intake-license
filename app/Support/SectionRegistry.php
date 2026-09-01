<?php
namespace App\Support;

// MARKER-PATCH-490 — single source of truth for admin sections.
// Consumed by: nav rendering (_nav-items), EnforceSectionAccess
// middleware, and the Roles & access editor. Keys are stored in
// tenant_roles.sections, so treat them as durable identifiers —
// never rename a key without a data migration.
class SectionRegistry
{
    /**
     * key => [label, group, prefixes (route-name prefixes), gate (tenant
     * attribute that must be truthy for the section to exist at all)]
     *
     * Groups mirror the nav: main / manage / engage / settings.
     */
    public static function all(): array
    {
        return [
            'dashboard'         => ['label' => 'Dashboard',          'group' => 'main',     'prefixes' => ['tenant.dashboard'],                            'gate' => null],
            'timeclock'         => ['label' => 'Time clock',         'group' => 'manage',   'prefixes' => ['tenant.timeclock'],                            'gate' => null], // MARKER-PATCH-610
            'scheduling'        => ['label' => 'Scheduling',         'group' => 'manage',   'prefixes' => ['tenant.scheduling'],                           'gate' => null], // MARKER-PATCH-611 (nav lands with the scheduling build)
            'register'          => ['label' => 'Register',           'group' => 'main',     'prefixes' => ['tenant.register'],                             'gate' => 'retail_enabled'],
            'schedule'          => ['label' => 'Schedule',           'group' => 'main',     'prefixes' => ['tenant.calendar', 'tenant.appointments'],      'gate' => null],
            'rentals'           => ['label' => 'Rentals',            'group' => 'main',     'prefixes' => ['tenant.rentals'],                              'gate' => 'rentals_visible'],
            'classes'           => ['label' => 'Classes',            'group' => 'main',     'prefixes' => ['tenant.classes'],                              'gate' => 'classes_enabled'],
            'customers'         => ['label' => 'Customers',          'group' => 'main',     'prefixes' => ['tenant.customers'],                            'gate' => null],
            'inventory'         => ['label' => 'Inventory',          'group' => 'main',     'prefixes' => ['tenant.inventory', 'tenant.distributors'],     'gate' => 'retail_enabled'],
            'special_orders'    => ['label' => 'Special Orders',     'group' => 'main',     'prefixes' => ['tenant.special-orders'],                       'gate' => 'retail_enabled'],
            'transfer_requests' => ['label' => 'Transfer Requests',  'group' => 'main',     'prefixes' => ['tenant.transfer-requests'],                    'gate' => 'multi_location_active'],
            'vendors'           => ['label' => 'Vendors',            'group' => 'main',     'prefixes' => ['tenant.vendors'],                              'gate' => 'retail_enabled'],
            'reports'           => ['label' => 'Reports',            'group' => 'main',     'prefixes' => ['tenant.reports'],                              'gate' => null],

            'team'              => ['label' => 'Team & access',      'group' => 'manage',   'prefixes' => ['tenant.team'],                                 'gate' => 'additional_users_enabled'],
            // MARKER-PATCH-553 — capability, not a page: gates cost/margin
            // visibility (register item modal, future report columns).
            // Empty prefixes = never route-enforced, never in nav.
            'cost_margins'      => ['label' => 'Costs & margins',    'group' => 'manage',   'prefixes' => [],                                              'gate' => 'retail_enabled'],
            'services'          => ['label' => 'Services',           'group' => 'manage',   'prefixes' => ['tenant.services'],                             'gate' => null],
            'resources'         => ['label' => 'Resources',          'group' => 'manage',   'prefixes' => ['tenant.resources'],                            'gate' => null],
            'work_order_fields' => ['label' => 'Work Order Fields',  'group' => 'manage',   'prefixes' => ['tenant.work-order-fields'],                    'gate' => null],
            'intake_form_editor'=> ['label' => 'Intake Form Editor', 'group' => 'manage',   'prefixes' => ['tenant.booking-editor'],                       'gate' => null],
            'capacity'          => ['label' => 'Capacity',           'group' => 'manage',   'prefixes' => ['tenant.capacity'],                             'gate' => null],

            'media'             => ['label' => 'Media',              'group' => 'website',   'prefixes' => ['tenant.media'],                                'gate' => null],
            'pages'             => ['label' => 'Pages',              'group' => 'website',   'prefixes' => ['tenant.pages'],                                'gate' => null],
            'templates'         => ['label' => 'Templates',          'group' => 'website',   'prefixes' => ['tenant.templates'],                            'gate' => null],
            'communication'     => ['label' => 'Communication',      'group' => 'messages',   'prefixes' => ['tenant.communication'],                        'gate' => null],
            'suppressions'      => ['label' => 'Suppressions',       'group' => 'messages',   'prefixes' => ['tenant.suppressions'],                         'gate' => null],
            'waitlist'          => ['label' => 'Waitlist',           'group' => 'marketing',   'prefixes' => ['tenant.waitlist'],                             'gate' => null],
            'campaigns'         => ['label' => 'Campaigns',          'group' => 'marketing',   'prefixes' => ['tenant.campaigns'],                            'gate' => null],
            'recovery'          => ['label' => 'Recovery',           'group' => 'marketing',   'prefixes' => ['tenant.recovery'],                             'gate' => null],

            'help'              => ['label' => 'Help & Guides',      'group' => 'settings', 'prefixes' => ['tenant.help'],                                 'gate' => null],
            'whats_new'         => ['label' => "What's New / Coming",'group' => 'settings', 'prefixes' => ['tenant.whats_new'],                            'gate' => null],
            'locations'         => ['label' => 'Locations',          'group' => 'settings', 'prefixes' => ['tenant.locations'],                            'gate' => null],
            'booking_mode'      => ['label' => 'Booking Mode',       'group' => 'settings', 'prefixes' => ['tenant.booking_modes'],                        'gate' => null],
            'settings'          => ['label' => 'Settings',           'group' => 'settings', 'prefixes' => ['tenant.settings'],                             'gate' => null],
            'addons'            => ['label' => 'Add-ons',            'group' => 'settings', 'prefixes' => ['tenant.feature_addons'],                       'gate' => null],
        ];
    }

    public static function groups(): array
    {
        // MARKER-NAV-REGROUP — must match the sidebar, or editing a role shows
        // groupings that no longer exist in the nav.
        return ['main' => 'Main', 'manage' => 'Manage', 'website' => 'Website', 'marketing' => 'Marketing', 'messages' => 'Messages', 'settings' => 'Settings'];
    }

    /**
     * Resolve a route name to a section key, or null if the route
     * isn't section-governed (login, pin, location picker, etc.).
     * Longest-prefix wins so tenant.settings never swallows a future
     * tenant.settings-adjacent prefix owned by another section.
     */
    public static function sectionForRoute(?string $routeName): ?string
    {
        if (!$routeName) return null;
        $best = null; $bestLen = -1;
        foreach (static::all() as $key => $def) {
            foreach ($def['prefixes'] as $prefix) {
                if (str_starts_with($routeName, $prefix) && strlen($prefix) > $bestLen) {
                    $best = $key; $bestLen = strlen($prefix);
                }
            }
        }
        return $best;
    }
}

