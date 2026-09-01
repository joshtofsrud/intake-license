@php
  $current = request()->route()?->getName() ?? '';
  $navItems = [
    [
      'route'  => 'tenant.dashboard',
      'label'  => 'Dashboard',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><rect x="1" y="1" width="5" height="5" rx="1" fill="currentColor"/><rect x="8" y="1" width="5" height="5" rx="1" fill="currentColor"/><rect x="1" y="8" width="5" height="5" rx="1" fill="currentColor"/><rect x="8" y="8" width="5" height="5" rx="1" fill="currentColor"/></svg>',
      'group'  => null,
    ],
    [
      'route'  => 'tenant.register.index',
      'label'  => 'Register',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><rect x="1.5" y="3.5" width="11" height="8" rx="1" stroke="currentColor" stroke-width="1.2"/><path d="M3.5 3.5V2.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/><path d="M10.5 3.5V2.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/><path d="M4 6h6M4 8h4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>',
      'group'  => null,
      'gate'   => 'retail_enabled',
    ],
    [
      'route'  => 'tenant.calendar.index',
      'label'  => 'Schedule',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><rect x="1" y="2.5" width="12" height="10.5" rx="1.2" stroke="currentColor" stroke-width="1.2"/><path d="M4 1.5V3.5M10 1.5V3.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/><path d="M1 5.5h12" stroke="currentColor" stroke-width="1.2"/><circle cx="4.5" cy="9" r="0.7" fill="currentColor"/><circle cx="7" cy="9" r="0.7" fill="currentColor"/><circle cx="9.5" cy="9" r="0.7" fill="currentColor"/></svg>',
      'group'  => null,
      'match_alt' => 'tenant.appointments',
    ],
    [
      // MARKER-PATCH-217 — Rentals desk. Gated on the rentals addon
      // (a la carte, tier floor branded). match covers future
      // tenant.rentals.* surfaces automatically via route-name prefix.
      'route'  => 'tenant.rentals.desk',
      'label'  => 'Rentals',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><circle cx="3.4" cy="9.8" r="2.1" stroke="currentColor" stroke-width="1.2"/><circle cx="10.6" cy="9.8" r="2.1" stroke="currentColor" stroke-width="1.2"/><path d="M3.4 9.8L5.6 5.2h3.2M10.6 9.8L8.6 4.4M5 3.2h2.4M8.6 4.4l-.4-1.2" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
      'group'  => null,
      'gate'   => 'rentals_visible', // MARKER-PATCH-228B — visibility toggle
    ],
    [
      'route'  => 'tenant.classes.sessions',
      'label'  => 'Classes',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><rect x="1" y="3" width="12" height="8" rx="1.2" stroke="currentColor" stroke-width="1.2"/><path d="M5.5 5.5l3 1.5-3 1.5V5.5z" fill="currentColor"/></svg>',
      'group'  => null,
      'gate'   => 'classes_enabled',
      'match_alt' => 'tenant.classes',
    ],
    [
      'route'  => 'tenant.customers.index',
      'label'  => 'Customers',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><circle cx="7" cy="5" r="3" stroke="currentColor" stroke-width="1.2"/><path d="M1.5 12.5c0-2.5 2.5-4 5.5-4s5.5 1.5 5.5 4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>',
      'group'  => null,
    ],
    [
      'route'  => 'tenant.inventory.index',
      'label'  => 'Inventory',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><rect x="2" y="4" width="10" height="8" rx="1" stroke="currentColor" stroke-width="1.2"/><path d="M2 6h10" stroke="currentColor" stroke-width="1.2"/><path d="M5 2v3M9 2v3" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>',
      'group'  => null,
      'match_alt' => 'tenant.distributors',
      'gate'   => 'retail_enabled',
    ],
    // MARKER-PATCH-HLC22 HLC22-REMOVED-DISTRIBUTORS: distributor surfaces now
    // live as tabs under Inventory (see _inventory-tabs).
    [
      // patch-94 SO nav entry — added in Stage 9. Retail-gated, top-level.
      // Drawer trigger lives on the index page itself.
      'route'  => 'tenant.special-orders.index',
      'label'  => 'Special Orders',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M2.5 4l4.5-2 4.5 2v6l-4.5 2-4.5-2V4z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/><path d="M2.5 4L7 6l4.5-2M7 6v6" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/></svg>',
      'group'  => null,
      'gate'   => 'retail_enabled',
    ],
    // MARKER-PATCH-567/568 — online orders queue
    [
      'route'  => 'tenant.orders.index',
      'label'  => 'Orders',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M2 3.5h1.4l1.2 6.2a1 1 0 0 0 1 .8h4.9a1 1 0 0 0 1-.8l.9-4.2H4.1" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="6" cy="12" r="0.9" fill="currentColor"/><circle cx="10.6" cy="12" r="0.9" fill="currentColor"/></svg>',
      'group'  => null,
      'gate'   => 'online_store_enabled',
    ],
    // MARKER-GIFTCARDS-ADMIN
    [
      'route'  => 'tenant.gift-cards.index',
      'label'  => 'Gift Cards',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><rect x="1.5" y="4" width="11" height="7.5" rx="1.2" stroke="currentColor" stroke-width="1.2"/><path d="M1.5 6.8h11M7 4v7.5M7 4c-.8-1.6-3.2-2.1-3.2-.6C3.8 4.6 5.8 4 7 4zm0 0c.8-1.6 3.2-2.1 3.2-.6C10.2 4.6 8.2 4 7 4z" stroke="currentColor" stroke-width="1.1"/></svg>',
      'group'  => null,
      'gate'   => 'gift_cards_visible', // MARKER-GIFTCARDS-GATE
    ],
    [
      // patch-100b transfer-requests nav — between SOs and Vendors
      // since transfer requests are operationally similar to SOs
      // (both are "we need stock somewhere else").
      // MARKER-PATCH-162 — gated on multi_location_active, not just retail.
      // Single-location tenants have nowhere to transfer from.
      'route'  => 'tenant.transfer-requests.index',
      'label'  => 'Transfer Requests',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M1.5 4.5h9l-2 -2M12.5 9.5h-9l2 2" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
      'group'  => null,
      'gate'   => 'multi_location_active',
    ],
    [
      // patch-94 Vendors nav entry — gap closure from Patch 86.
      // Retail-gated, top-level. Sits next to Special Orders for
      // staff-mental-model coherence (vendors are SO suppliers).
      'route'  => 'tenant.vendors.index',
      'label'  => 'Vendors',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><rect x="1.5" y="3" width="11" height="8" rx="1" stroke="currentColor" stroke-width="1.2"/><path d="M4 5.5h2M4 7.5h6M4 9.5h4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>',
      'group'  => null,
      'gate'   => 'retail_enabled',
    ],
    [
      'route'  => 'tenant.reports.index',
      'label'  => 'Reports',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><rect x="1.5" y="8" width="2.5" height="4.5" rx="0.5" fill="currentColor"/><rect x="5.75" y="5" width="2.5" height="7.5" rx="0.5" fill="currentColor"/><rect x="10" y="2" width="2.5" height="10.5" rx="0.5" fill="currentColor"/></svg>',
      'group'  => null,
    ],
    // MARKER-PATCH-610 — Time clock
    [
      'route'  => 'tenant.timeclock.index',
      'label'  => 'Time clock',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><circle cx="7" cy="7" r="5.5" stroke="currentColor" stroke-width="1.2"/><path d="M7 4v3l2 1.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>',
      'group'  => 'manage',
    ],
    // MARKER-PATCH-623 — Scheduling
    [
      'route'  => 'tenant.scheduling.mine',
      'label'  => 'Scheduling',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><rect x="1.5" y="2.5" width="11" height="10" rx="1.5" stroke="currentColor" stroke-width="1.2"/><path d="M1.5 5.5h11M4.5 1v3M9.5 1v3" stroke="currentColor" stroke-width="1.2"/></svg>',
      'group'  => 'manage',
    ],
    // MARKER-PATCH-129 — Team & Access (consolidated from Team + Security)
    [
      'route'  => 'tenant.team.index',
      'label'  => 'Team & access',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><circle cx="5" cy="5" r="2.2" stroke="currentColor" stroke-width="1.2"/><circle cx="10.5" cy="5.5" r="1.6" stroke="currentColor" stroke-width="1.2"/><path d="M1.5 12c0-1.8 1.5-3 3.5-3s3.5 1.2 3.5 3" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/><path d="M8.5 12c0-1.4 1-2.4 2.5-2.4s2.5 1 2.5 2.4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>',
      'group'  => 'manage',
      'gate'   => 'additional_users_enabled',
    ],
    [
      'route'  => 'tenant.services.index',
      'label'  => 'Services',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M2 4h10M2 7h7M2 10h5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>',
      'group'  => 'manage',
    ],
    [
      'route'  => 'tenant.resources.index',
      'label'  => 'Resources',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><circle cx="4.5" cy="4" r="1.8" stroke="currentColor" stroke-width="1.2"/><circle cx="9.5" cy="4" r="1.8" stroke="currentColor" stroke-width="1.2"/><path d="M1.5 11.5c0-1.8 1.5-3 3-3s3 1.2 3 3M6.5 11.5c0-1.8 1.5-3 3-3s3 1.2 3 3" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>',
      'group'  => 'manage',
    ],
    [
      'route'  => 'tenant.work-order-fields.index',
      'label'  => 'Work Order Fields',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><rect x="1.5" y="2" width="11" height="10" rx="1.2" stroke="currentColor" stroke-width="1.2"/><path d="M4 5h6M4 7.5h4M4 10h3" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/><circle cx="11.5" cy="5" r="1" fill="currentColor"/></svg>',
      'group'  => 'manage',
    ],
        [
      'route'  => 'tenant.booking-editor.index',
      'label'  => 'Intake Form Editor',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><rect x="1" y="1" width="12" height="12" rx="1.5" stroke="currentColor" stroke-width="1.2"/><path d="M4 5h6M4 7.5h4M4 10h2" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>',
      'group'  => 'manage',
    ],
    [
      'route'  => 'tenant.capacity.index',
      'label'  => 'Capacity',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><rect x="1" y="1" width="12" height="12" rx="1.5" stroke="currentColor" stroke-width="1.2"/><path d="M1 5h12" stroke="currentColor" stroke-width="1.2"/><path d="M5 1v4M9 1v4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>',
      'group'  => 'manage',
    ],
    [
      'route'  => 'tenant.media.index',
      'label'  => 'Media',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><rect x="1" y="2" width="12" height="10" rx="1.5" stroke="currentColor" stroke-width="1.2"/><circle cx="4.5" cy="5.5" r="1.2" stroke="currentColor" stroke-width="1.2"/><path d="M2 10l3-2.5 2.5 2 2-1.5L12 10" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/></svg>',
      'group'  => 'website',
    ],
    [
      'route'  => 'tenant.pages.index',
      'label'  => 'Pages',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><rect x="1" y="2" width="12" height="10" rx="1.5" stroke="currentColor" stroke-width="1.2"/><path d="M4 6h6M4 8.5h4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>',
      'group'  => 'website',
    ],
    // MARKER-PATCH-261 — site template gallery
    [
      'route'  => 'tenant.templates.index',
      'label'  => 'Templates',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><rect x="1.5" y="1.5" width="11" height="11" rx="1.5" stroke="currentColor" stroke-width="1.2"/><path d="M1.5 5h11M5 5v7.5" stroke="currentColor" stroke-width="1.2"/></svg>',
      'group'  => 'website',
    ],
    // MARKER-PATCH-404 — Communication Center
    [
      'route'  => 'tenant.communication.index',
      'label'  => 'Communication',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M2 3.5h10a1 1 0 0 1 1 1V9a1 1 0 0 1-1 1H6l-3 2.5V10H2a1 1 0 0 1-1-1V4.5a1 1 0 0 1 1-1z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/><path d="M4.5 6h5M4.5 7.8h3" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>',
      'group'  => 'messages',
    ],
    // MARKER-PATCH-147 — tenant-facing suppression list
    [
      'route'  => 'tenant.waitlist.index',
      'label'  => 'Waitlist',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M4 2v2l-2 2v5h10V6l-2-2V2" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/><path d="M4 2h6" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/><path d="M6 7.5h2M5 9.5h4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>',
      'group'  => 'marketing',
    ],
        [
      // MARKER-DISCOUNTS-ADMIN
      'route'  => 'tenant.discounts.index',
      'label'  => 'Discounts',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M10.5 3.5l-7 7" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/><circle cx="4.75" cy="4.75" r="1.4" stroke="currentColor" stroke-width="1.2"/><circle cx="9.25" cy="9.25" r="1.4" stroke="currentColor" stroke-width="1.2"/></svg>',
      'group'  => 'marketing',
    ],
        [
      'route'  => 'tenant.campaigns.index',
      'label'  => 'Campaigns',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M2 7h10M9 4l3 3-3 3" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
      'group'  => 'marketing',
    ],
    // MARKER-PATCH-450 — Engage -> Recovery (abandoned-booking follow-up)
    [
      'route'  => 'tenant.recovery.index',
      'label'  => 'Recovery',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M2.5 7a4.5 4.5 0 1 1 1.3 3.2" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/><path d="M2 7.5V10h2.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
      'group'  => 'marketing',
    ],
    [
      'route'  => 'tenant.help.index',
      'label'  => 'Help & Guides',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><circle cx="7" cy="7" r="5.5" stroke="currentColor" stroke-width="1.2"/><path d="M5.5 5.5a1.5 1.5 0 1 1 1.5 1.5v1" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/><circle cx="7" cy="10" r=".6" fill="currentColor"/></svg>',
      'group'  => 'settings',
    ],
    [
      'route'  => 'tenant.whats_new.changelog',
      'label'  => "What's New",
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M7 1.5l1.5 3.5 3.5.5-2.5 2.5.5 3.5L7 9.5 4 11.5l.5-3.5L2 5.5l3.5-.5z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/></svg>',
      'group'  => 'settings',
    ],
    [
      'route'  => 'tenant.whats_new.roadmap',
      'label'  => "What's Coming",
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M2 11.5L7 2l5 9.5M4.5 8.5h5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
      'group'  => 'settings',
    ],
    [
      'route'  => 'tenant.locations.index',
      'label'  => 'Locations',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M7 1.5C4.8 1.5 3 3.3 3 5.5c0 3 4 7 4 7s4-4 4-7c0-2.2-1.8-4-4-4z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/><circle cx="7" cy="5.5" r="1.3" stroke="currentColor" stroke-width="1.2"/></svg>',
      'group'  => 'settings',
    ],
    // MARKER-IMPORT1 — CSV import lives with the other setup surfaces
    [
      'route'  => 'tenant.imports.index',
      'label'  => 'Import',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M7 1.5v7M4.5 6L7 8.5 9.5 6" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/><path d="M2 10.5v1a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1v-1" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>',
      'group'  => 'settings',
    ],
    // MARKER-PATCH-570 — storefront settings (online store control panel)
    [
      'route'  => 'tenant.storefront.settings',
      'label'  => 'Storefront',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M2 5.5L2.8 2.5h8.4L12 5.5M2 5.5v6a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1v-6M2 5.5h10M5.5 12.5V9h3v3.5" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/></svg>',
      'group'  => 'website',
      'gate'   => 'online_store_enabled',
    ],
    [
      'route'  => 'tenant.booking_modes.index',
      'label'  => 'Booking Mode',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M3 2v3a2 2 0 0 0 2 2h6M11 4.5L8.5 7 11 9.5M3 12V7" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
      'group'  => 'website',
    ],
    [
      'route'  => 'tenant.settings.index',
      'label'  => 'Settings',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><circle cx="7" cy="7" r="2" stroke="currentColor" stroke-width="1.2"/><path d="M7 1v1.5M7 11.5V13M1 7h1.5M11.5 7H13M2.9 2.9l1.1 1.1M10 10l1.1 1.1M2.9 11.1l1.1-1.1M10 4l1.1-1.1" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>',
      'group'  => 'settings',
    ],
    [
      'route'  => 'tenant.feature_addons.index',
      'label'  => 'Add-ons',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><rect x="1.5" y="1.5" width="4.5" height="4.5" rx="0.8" stroke="currentColor" stroke-width="1.2"/><rect x="8" y="1.5" width="4.5" height="4.5" rx="0.8" stroke="currentColor" stroke-width="1.2"/><rect x="1.5" y="8" width="4.5" height="4.5" rx="0.8" stroke="currentColor" stroke-width="1.2"/><path d="M10.25 8v4.5M8 10.25h4.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>',
      'group'  => 'settings',
    ],
  ];

  // MARKER-PATCH-549 — nav restructure (547 hotfix): runs inside the existing
  // @php block; Email+Suppressions live in Communication, What's New/Coming
  // in Help & Guides, Capacity -> Settings.
    $navOrder547 = [
      // main
      'tenant.dashboard', 'tenant.calendar.index', 'tenant.register.index',
      'tenant.customers.index', 'tenant.inventory.index', 'tenant.special-orders.index', 'tenant.orders.index',
      'tenant.transfer-requests.index', 'tenant.vendors.index',
      'tenant.rentals.desk', 'tenant.classes.sessions', 'tenant.reports.index',
      // manage
      'tenant.team.index', 'tenant.services.index', 'tenant.resources.index',
      'tenant.work-order-fields.index', 'tenant.booking-editor.index',
      // MARKER-NAV-REGROUP — website, then marketing, then messages
      'tenant.pages.index', 'tenant.templates.index', 'tenant.media.index',
      'tenant.storefront.settings', 'tenant.booking_modes.index', // MARKER-NAV-WEBSITE-MOVES
      'tenant.campaigns.index', 'tenant.discounts.index', 'tenant.recovery.index',
      'tenant.waitlist.index',
      'tenant.communication.index',
      // settings
      'tenant.help.index', 'tenant.locations.index', 'tenant.storefront.settings', 'tenant.booking_modes.index',
      'tenant.capacity.index', 'tenant.settings.index', 'tenant.feature_addons.index',
    ];
    $navDrop547  = ['tenant.whats_new.changelog', 'tenant.whats_new.roadmap'];
    // MARKER-NAV-REGROUP — sort weights follow the new group order.
    $gw547       = ['manage' => 1, 'website' => 2, 'marketing' => 3, 'messages' => 4, 'settings' => 5];
    $navItems = collect($navItems)
        ->reject(fn ($i) => in_array($i['route'], $navDrop547))
        ->map(function ($i) {
            if ($i['route'] === 'tenant.capacity.index') $i['group'] = 'settings';
            return $i;
        })
        ->sortBy(function ($i, $idx) use ($navOrder547, $gw547) {
            $g = $gw547[$i['group'] ?? ''] ?? 0;
            $p = array_search($i['route'], $navOrder547);
            return $g * 1000 + ($p === false ? 500 + $idx : $p);
        })
        ->values()->all();

  // MARKER-NAV-REGROUP — Engage split into the three jobs it was doing.
  $groups = [
    'manage'    => 'Manage',
    'website'   => 'Website',
    'marketing' => 'Marketing',
    'messages'  => 'Messages',
    'settings'  => 'Settings',
  ];
  $lastGroup = null;
@endphp

@foreach($navItems as $item)
  @php
    $primaryMatch = str_replace('.index', '', $item['route']);
    $isActive = str_starts_with($current, $primaryMatch);
    if (!$isActive && !empty($item['match_alt'])) {
      $isActive = str_starts_with($current, $item['match_alt']);
    }
    $url      = route($item['route']);
  @endphp

  @if(!empty($item['gate']) && !$currentTenant->{$item['gate']})
    @continue
  @endif

  {{-- MARKER-PATCH-493 — Roles & access: hide sections outside the user's role --}}
  @php $navSec = \App\Support\SectionRegistry::sectionForRoute($item['route']); @endphp
  @if($navSec && !empty($authUser) && !$authUser->canAccessSection($navSec))
    @continue
  @endif

  {{-- MARKER-NAV-SPACING — the uppercase header already separates groups;
       a divider on top of it stacked ~36px of dead space per boundary, and
       with five groups that reads as gappy rather than organised. --}}
  @if($item['group'] !== $lastGroup && $item['group'])
    <div class="ia-nav-section">{{ $groups[$item['group']] }}</div>
    @php $lastGroup = $item['group']; @endphp
  @endif

  {{-- MARKER-SIDEBAR-COLLAPSE — title carries the label when collapsed; the
       text itself stays in the DOM for screen readers rather than being
       display:none'd away. --}}
  <a href="{{ $url }}" class="ia-nav-item {{ $isActive ? 'active' : '' }}" title="{{ $item['label'] }}">
    {!! $item['icon'] !!}
    <span class="ia-nav-label">{{ $item['label'] }}</span>
  </a>

@endforeach

