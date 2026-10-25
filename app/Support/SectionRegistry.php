<?php
namespace App\Support;

// single source of truth for admin sections.
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
     * Groups mirror the nav (see groups()); within a group, keep the menu's order.
     */
    public static function all(): array
    {
        return [
            'dashboard'         => ['label' => 'Dashboard',          'group' => 'main',     'prefixes' => ['tenant.dashboard'],                            'gate' => null],
            'timeclock'         => ['label' => 'Time clock',         'group' => 'manage',   'prefixes' => ['tenant.timeclock'],                            'gate' => null],
            'scheduling'        => ['label' => 'Scheduling',         'group' => 'manage',   'prefixes' => ['tenant.scheduling'],                           'gate' => null], // (nav lands with the scheduling build)
            'register'          => ['label' => 'Register',           'group' => 'main',     'prefixes' => ['tenant.register', 'tenant.sales'],             'gate' => 'retail_enabled'],
            'schedule'          => ['label' => 'Schedule',           'group' => 'main',     'prefixes' => ['tenant.calendar', 'tenant.appointments', 'tenant.deliveries'], 'gate' => null],
            'rentals'           => ['label' => 'Rentals',            'group' => 'main',     'prefixes' => ['tenant.rentals'],                              'gate' => 'rentals_visible'],
            'classes'           => ['label' => 'Classes',            'group' => 'main',     'prefixes' => ['tenant.classes'],                              'gate' => 'classes_active'],
            // MARKER-CLASSES-P10: check people in and teach, without prices, passes or settings. Roles with Classes have this too.
            'class_desk'        => ['label' => 'Class check-in',     'group' => 'main',     'prefixes' => ['tenant.classes.checkin', 'tenant.classes.mine', 'tenant.classes.registrations.checkin', 'tenant.classes.kiosk'], 'gate' => 'classes_active'],
            'customers'         => ['label' => 'Customers',          'group' => 'main',     'prefixes' => ['tenant.customers'],                            'gate' => null],
            'inventory'         => ['label' => 'Inventory',          'group' => 'main',     'prefixes' => ['tenant.inventory', 'tenant.distributors'],     'gate' => 'retail_enabled'],
            'special_orders'    => ['label' => 'Special Orders',     'group' => 'main',     'prefixes' => ['tenant.special-orders'],                       'gate' => 'retail_enabled'],
            // MARKER-SEC-SECTIONS: online orders, gift cards, storefront and
            // discounts used to sit outside every area, so no role could be
            // kept out of them. Existing roles were given them (see the
            // 2026_10_25 migration) so nobody lost access on the day.
            'orders'            => ['label' => 'Orders',             'group' => 'main',     'prefixes' => ['tenant.orders'],                               'gate' => 'online_store_enabled'],
            'transfer_requests' => ['label' => 'Transfer Requests',  'group' => 'main',     'prefixes' => ['tenant.transfer-requests'],                    'gate' => 'multi_location_active'],
            'vendors'           => ['label' => 'Vendors',            'group' => 'main',     'prefixes' => ['tenant.vendors'],                              'gate' => 'retail_enabled'],
            'reports'           => ['label' => 'Reports',            'group' => 'main',     'prefixes' => ['tenant.reports'],                              'gate' => null],
            // the staff pages only: the public tenant.gift-cards.public.* pages aren't an area
            'gift_cards'        => ['label' => 'Gift Cards',         'group' => 'main',     'prefixes' => ['tenant.gift-cards.index', 'tenant.gift-cards.store', 'tenant.gift-cards.show', 'tenant.gift-cards.adjust', 'tenant.gift-cards.deactivate', 'tenant.gift-cards.bind-code'], 'gate' => 'gift_cards_visible'],

            'team'              => ['label' => 'Team & access',      'group' => 'manage',   'prefixes' => ['tenant.team'],                                 'gate' => 'additional_users_enabled'],
            // capability, not a page: gates cost/margin
            // visibility (register item modal, future report columns).
            // Empty prefixes = never route-enforced, never in nav.
            'cost_margins'      => ['label' => 'Costs & margins',    'group' => 'manage',   'prefixes' => [],                                              'gate' => 'retail_enabled'],
            'services'          => ['label' => 'Services',           'group' => 'manage',   'prefixes' => ['tenant.services', 'tenant.addons'],            'gate' => null],
            'resources'         => ['label' => 'Resources',          'group' => 'manage',   'prefixes' => ['tenant.resources'],                            'gate' => null],
            'work_order_fields' => ['label' => 'Work Order Fields',  'group' => 'manage',   'prefixes' => ['tenant.work-order-fields'],                    'gate' => null],
            'intake_form_editor'=> ['label' => 'Intake Form Editor', 'group' => 'manage',   'prefixes' => ['tenant.booking-editor'],                       'gate' => null],

            // website, marketing, messages and settings follow the menu's order
            'pages'             => ['label' => 'Pages',              'group' => 'website',  'prefixes' => ['tenant.pages'],                                'gate' => null],
            'templates'         => ['label' => 'Templates',          'group' => 'website',  'prefixes' => ['tenant.templates'],                            'gate' => null],
            'media'             => ['label' => 'Media',              'group' => 'website',  'prefixes' => ['tenant.media'],                                'gate' => null],
            'storefront'        => ['label' => 'Storefront',         'group' => 'website',  'prefixes' => ['tenant.storefront'],                           'gate' => 'online_store_enabled'],
            'booking_mode'      => ['label' => 'Booking Mode',       'group' => 'website',  'prefixes' => ['tenant.booking_modes', 'tenant.route_windows'], 'gate' => null],
            'inbox'             => ['label' => 'Inbox',              'group' => 'messages', 'prefixes' => ['tenant.inbox'],                                'gate' => 'unified_inbox_enabled'],
            'communication'     => ['label' => 'Communication',      'group' => 'messages',   'prefixes' => ['tenant.communication'],                        'gate' => null],
            'suppressions'      => ['label' => 'Suppressions',       'group' => 'messages',   'prefixes' => ['tenant.suppressions'],                         'gate' => null],
            'campaigns'         => ['label' => 'Campaigns',          'group' => 'marketing', 'prefixes' => ['tenant.campaigns', 'tenant.campaign-images', 'tenant.consent'], 'gate' => null],
            'discounts'         => ['label' => 'Discounts',          'group' => 'marketing', 'prefixes' => ['tenant.discounts'],                            'gate' => null],
            'recovery'          => ['label' => 'Recovery',           'group' => 'marketing', 'prefixes' => ['tenant.recovery'],                             'gate' => null],
            'waitlist'          => ['label' => 'Waitlist',           'group' => 'marketing', 'prefixes' => ['tenant.waitlist'],                             'gate' => null],

            'help'              => ['label' => 'Help & Guides',      'group' => 'settings', 'prefixes' => ['tenant.help'],                                 'gate' => null],
            'whats_new'         => ['label' => "What's New / Coming",'group' => 'settings', 'prefixes' => ['tenant.whats_new'],                            'gate' => null],
            'locations'         => ['label' => 'Locations',          'group' => 'settings', 'prefixes' => ['tenant.locations'],                            'gate' => null],
            'capacity'          => ['label' => 'Capacity',           'group' => 'settings', 'prefixes' => ['tenant.capacity'],                             'gate' => null],
            // Business covers billing, custom domains and receiving methods, which live on its page
            'settings'          => ['label' => 'Business',           'group' => 'settings', 'prefixes' => ['tenant.settings', 'tenant.billing', 'tenant.domains', 'tenant.receiving-methods'], 'gate' => null],
            'addons'            => ['label' => 'Add-ons',            'group' => 'settings', 'prefixes' => ['tenant.feature_addons'],                       'gate' => null],
        ];
    }

    public static function groups(): array
    {
        // must match the sidebar, or editing a role shows
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

