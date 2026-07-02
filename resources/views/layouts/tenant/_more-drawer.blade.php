@php
  // MARKER-PATCH-360 — the More drawer renders the SAME nav item set as the
  // desktop sidebar (_nav-items.blade.php), grouped into sections. Sharing the
  // full list guarantees nothing is dropped and that plan tiers (Starter vs
  // Scale) surface exactly the items their feature gates allow.
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
      // MARKER-PATCH-442 — Communication in the mobile More drawer (engage cluster, ungated)
      'route'  => 'tenant.communication.index',
      'label'  => 'Communication',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M2.5 2.5h9a1 1 0 0 1 1 1v4.5a1 1 0 0 1-1 1H5.5l-2.5 2v-2h-.5a1 1 0 0 1-1-1V3.5a1 1 0 0 1 1-1z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/></svg>',
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
      'group'  => 'engage',
    ],
    [
      'route'  => 'tenant.pages.index',
      'label'  => 'Pages',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><rect x="1" y="2" width="12" height="10" rx="1.5" stroke="currentColor" stroke-width="1.2"/><path d="M4 6h6M4 8.5h4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>',
      'group'  => 'engage',
    ],
    // MARKER-PATCH-261 — site template gallery
    [
      'route'  => 'tenant.templates.index',
      'label'  => 'Templates',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><rect x="1.5" y="1.5" width="11" height="11" rx="1.5" stroke="currentColor" stroke-width="1.2"/><path d="M1.5 5h11M5 5v7.5" stroke="currentColor" stroke-width="1.2"/></svg>',
      'group'  => 'engage',
    ],
    [
      'route'  => 'tenant.emails.index',
      'label'  => 'Email',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><rect x="1" y="3" width="12" height="8" rx="1.5" stroke="currentColor" stroke-width="1.2"/><path d="M1.5 4l5.5 4 5.5-4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>',
      'group'  => 'engage',
    ],
    // MARKER-PATCH-147 — tenant-facing suppression list
    [
      'route'  => 'tenant.suppressions.index',
      'label'  => 'Suppressions',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><circle cx="7" cy="7" r="5.5" stroke="currentColor" stroke-width="1.2"/><path d="M3.5 3.5l7 7" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>',
      'group'  => 'engage',
    ],
    [
      'route'  => 'tenant.waitlist.index',
      'label'  => 'Waitlist',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M4 2v2l-2 2v5h10V6l-2-2V2" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/><path d="M4 2h6" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/><path d="M6 7.5h2M5 9.5h4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>',
      'group'  => 'engage',
    ],
        [
      'route'  => 'tenant.campaigns.index',
      'label'  => 'Campaigns',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M2 7h10M9 4l3 3-3 3" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
      'group'  => 'engage',
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

  $drawerSections = ['workspace' => 'Workspace', 'manage' => 'Manage', 'engage' => 'Engage', 'settings' => 'Settings'];
  // Already in the bottom tab bar — don't repeat them in the drawer:
  $drawerSkip = ['tenant.dashboard', 'tenant.calendar.index', 'tenant.customers.index', 'tenant.inbox.index'];
  $lastSect = null;
@endphp

<div class="ia-drawer-overlay" id="ia-more-drawer" aria-hidden="true" onclick="IntakeMobileNav.closeDrawerFromOverlay(event)">
  <div class="ia-drawer" role="dialog" aria-modal="true" aria-labelledby="ia-drawer-title">
    <div class="ia-drawer-handle" aria-hidden="true"></div>
    <div class="ia-drawer-header">
      <h2 id="ia-drawer-title" class="ia-drawer-title">More</h2>
      <button type="button" class="ia-drawer-close" onclick="IntakeMobileNav.closeDrawer()" aria-label="Close">
        <svg width="18" height="18" viewBox="0 0 18 18" fill="none">
          <path d="M4 4l10 10M14 4L4 14" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
        </svg>
      </button>
    </div>

    <nav class="ia-drawer-nav" aria-label="All sections">
      @foreach($navItems as $navItem)
        @continue(in_array($navItem['route'], $drawerSkip, true))
        @continue(!empty($navItem['gate']) && !$currentTenant->{$navItem['gate']})
        @continue(!\Illuminate\Support\Facades\Route::has($navItem['route']))
        {{-- MARKER-PATCH-493 — role section visibility --}}
        @php $dSec = \App\Support\SectionRegistry::sectionForRoute($navItem['route']); @endphp
        @continue($dSec && !empty($authUser) && !$authUser->canAccessSection($dSec))
        @php $sect = $navItem['group'] ?? 'workspace'; @endphp
        @if($sect !== $lastSect)
          @if($lastSect !== null)<div class="ia-drawer-rule"></div>@endif
          <div class="ia-drawer-sect">{{ $drawerSections[$sect] ?? \Illuminate\Support\Str::title($sect) }}</div>
          @php $lastSect = $sect; @endphp
        @endif
        @php $isActive = str_starts_with($current, str_replace('.index', '', $navItem['route'])); @endphp
        <a href="{{ route($navItem['route']) }}" class="ia-drawer-link {{ $isActive ? 'active' : '' }}">
          <span class="ia-drawer-link-ic">{!! $navItem['icon'] !!}</span>
          <span class="ia-drawer-link-lb">{{ $navItem['label'] }}</span>
          <span class="ia-drawer-link-ch" aria-hidden="true">&rsaquo;</span>
        </a>
      @endforeach
    </nav>

    {{-- DRAWER-USER v2 — split user info from sign-out to prevent accidental logouts --}}
    <div class="ia-drawer-user ia-drawer-user--readonly">
      <div class="ia-user-avatar">{{ strtoupper(substr($authUser->name, 0, 2)) }}</div>
      <div>
        <div class="ia-user-name">{{ $authUser->name }}</div>
        <div class="ia-user-role">{{ ucfirst($authUser->role ?? 'Member') }}</div>
      </div>
    </div>
    {{-- MARKER-PATCH-496 — switch user (PIN tier only) --}}
    @if($currentTenant->pin_tier_active)
    <a href="{{ route('tenant.switch') }}" class="ia-drawer-signout" style="text-decoration:none">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M16 3h5v5"/><path d="M21 3l-7 7"/>
        <path d="M8 21H3v-5"/><path d="M3 21l7-7"/>
      </svg>
      Switch user
    </a>
    @endif
    <button type="button"
            class="ia-drawer-signout"
            onclick="if(confirm('Sign out of {{ addslashes($currentTenant->name) }}?')) document.getElementById('logout-form-mobile').submit()">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
        <polyline points="16 17 21 12 16 7"/>
        <line x1="21" y1="12" x2="9" y2="12"/>
      </svg>
      Sign out
    </button>

    @include('layouts.tenant._brand-footer')

    <form id="logout-form-mobile" method="POST" action="{{ route('tenant.logout') }}" style="display:none">
      @csrf
    </form>
  </div>
</div>
