#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Rollback drawer-nav-sync + apply minimal fix.
#
# The prior patch extracted the nav items array to a shared partial. Caused
# a 500 error in production — root cause not fully diagnosed, but the safer
# move is to NOT restructure files mid-launch-week.
#
# This patch:
#   1. Restores _nav-items.blade.php to its original inline-array form
#   2. Removes _nav-items-data.blade.php
#   3. Adds the missing items (Waitlist, Inventory, Reports, Work Order
#      Fields, Add-ons) directly to _more-drawer.blade.php's hardcoded
#      $moreItems array
#
# Trade-off: drawer + sidebar are still separate sources of truth. Adding a
# new nav item still requires editing two files. We accept that for now;
# the sync refactor can ship after launch.
# ─────────────────────────────────────────────────────────────────────────────

set -e
[ -f artisan ] || { echo "ABORT: not in intake-license repo root"; exit 1; }

echo "=== rollback drawer-nav-sync + simple fix ==="

# 1. Delete _nav-items-data.blade.php if it exists.
if [ -f resources/views/layouts/tenant/_nav-items-data.blade.php ]; then
  rm resources/views/layouts/tenant/_nav-items-data.blade.php
  echo "OK 1 (removed _nav-items-data.blade.php)"
else
  echo "SKIP 1 (data partial already absent)"
fi

# 2. Restore _nav-items.blade.php to its original inline-array form.
cat > resources/views/layouts/tenant/_nav-items.blade.php <<'BLADE'
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
    ],
    [
      'route'  => 'tenant.calendar.index',
      'label'  => 'Schedule',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><rect x="1" y="2.5" width="12" height="10.5" rx="1.2" stroke="currentColor" stroke-width="1.2"/><path d="M4 1.5V3.5M10 1.5V3.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/><path d="M1 5.5h12" stroke="currentColor" stroke-width="1.2"/><circle cx="4.5" cy="9" r="0.7" fill="currentColor"/><circle cx="7" cy="9" r="0.7" fill="currentColor"/><circle cx="9.5" cy="9" r="0.7" fill="currentColor"/></svg>',
      'group'  => null,
      'match_alt' => 'tenant.appointments',
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
      'gate'   => 'retail_enabled',
    ],
    [
      'route'  => 'tenant.reports.index',
      'label'  => 'Reports',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><rect x="1.5" y="8" width="2.5" height="4.5" rx="0.5" fill="currentColor"/><rect x="5.75" y="5" width="2.5" height="7.5" rx="0.5" fill="currentColor"/><rect x="10" y="2" width="2.5" height="10.5" rx="0.5" fill="currentColor"/></svg>',
      'group'  => null,
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
      'route'  => 'tenant.pages.index',
      'label'  => 'Pages',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><rect x="1" y="2" width="12" height="10" rx="1.5" stroke="currentColor" stroke-width="1.2"/><path d="M4 6h6M4 8.5h4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>',
      'group'  => 'manage',
    ],
    [
      'route'  => 'tenant.emails.index',
      'label'  => 'Email',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><rect x="1" y="3" width="12" height="8" rx="1.5" stroke="currentColor" stroke-width="1.2"/><path d="M1.5 4l5.5 4 5.5-4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>',
      'group'  => 'manage',
    ],
    [
      'route'  => 'tenant.waitlist.index',
      'label'  => 'Waitlist',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M4 2v2l-2 2v5h10V6l-2-2V2" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/><path d="M4 2h6" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/><path d="M6 7.5h2M5 9.5h4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>',
      'group'  => 'manage',
    ],
        [
      'route'  => 'tenant.campaigns.index',
      'label'  => 'Campaigns',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M2 7h10M9 4l3 3-3 3" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
      'group'  => 'manage',
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

  $groups = ['manage' => 'Manage', 'settings' => 'Settings'];
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

  @if($item['group'] !== $lastGroup && $item['group'])
    @if($lastGroup !== null)
      <div class="ia-sidebar-divider"></div>
    @endif
    <div class="ia-nav-section">{{ $groups[$item['group']] }}</div>
    @php $lastGroup = $item['group']; @endphp
  @endif

  <a href="{{ $url }}" class="ia-nav-item {{ $isActive ? 'active' : '' }}">
    {!! $item['icon'] !!}
    {{ $item['label'] }}
  </a>

@endforeach
BLADE
echo "OK 2 (nav-items restored to original)"

# 3. Update _more-drawer.blade.php's hardcoded list to add the missing items
#    AND apply gate checks consistently.
cat > resources/views/layouts/tenant/_more-drawer.blade.php <<'BLADE'
@php
  $current = request()->route()?->getName() ?? '';

  // Items shown in the More drawer. Excludes the primary bottom-nav tabs
  // (Dashboard, Schedule, Customers). Mirrors the sidebar nav order.
  // Gate checks apply: items with a `gate` only render if the tenant has
  // that feature flag enabled. Keep this list in sync with _nav-items.blade.php.
  $moreItems = [
    ['route' => 'tenant.register.index',           'label' => 'Register'],
    ['route' => 'tenant.classes.sessions',         'label' => 'Classes',      'gate' => 'classes_enabled'],
    ['route' => 'tenant.inventory.index',          'label' => 'Inventory',    'gate' => 'retail_enabled'],
    ['route' => 'tenant.reports.index',            'label' => 'Reports'],
    ['route' => 'tenant.services.index',           'label' => 'Services'],
    ['route' => 'tenant.resources.index',          'label' => 'Resources'],
    ['route' => 'tenant.work-order-fields.index',  'label' => 'Work Order Fields'],
    ['route' => 'tenant.booking-editor.index',     'label' => 'Intake Form Editor'],
    ['route' => 'tenant.capacity.index',           'label' => 'Capacity'],
    ['route' => 'tenant.pages.index',              'label' => 'Pages'],
    ['route' => 'tenant.emails.index',             'label' => 'Email'],
    ['route' => 'tenant.waitlist.index',           'label' => 'Waitlist'],
    ['route' => 'tenant.campaigns.index',          'label' => 'Campaigns'],
    ['route' => 'tenant.help.index',               'label' => 'Help & Guides'],
    ['route' => 'tenant.whats_new.changelog',      'label' => "What's New"],
    ['route' => 'tenant.whats_new.roadmap',        'label' => "What's Coming"],
    ['route' => 'tenant.settings.index',           'label' => 'Settings'],
    ['route' => 'tenant.feature_addons.index',     'label' => 'Add-ons'],
  ];
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

    <div class="ia-drawer-items">
      @foreach($moreItems as $item)
        @if(!empty($item['gate']) && !$currentTenant->{$item['gate']})
          @continue
        @endif
        @if(\Illuminate\Support\Facades\Route::has($item['route']))
          @php
            $isActive = str_starts_with($current, str_replace('.index', '', $item['route']));
          @endphp
          <a href="{{ route($item['route']) }}" class="ia-drawer-item {{ $isActive ? 'active' : '' }}">
            {{ $item['label'] }}
          </a>
        @endif
      @endforeach
    </div>

    {{-- DRAWER-USER v2 — split user info from sign-out to prevent accidental logouts --}}
    <div class="ia-drawer-user ia-drawer-user--readonly">
      <div class="ia-user-avatar">{{ strtoupper(substr($authUser->name, 0, 2)) }}</div>
      <div>
        <div class="ia-user-name">{{ $authUser->name }}</div>
        <div class="ia-user-role">{{ ucfirst($authUser->role ?? 'Member') }}</div>
      </div>
    </div>
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
BLADE
echo "OK 3 (drawer rebuilt with all items + gates)"

echo ""
echo "=== verifying ==="
fail=0
verify() {
  local file="$1" needle="$2" label="$3"
  local n
  n=$(grep -c -F -- "$needle" "$file" 2>/dev/null | tr -d '\n' || true)
  : "${n:=0}"
  if [ "${n:-0}" -ge 1 ] 2>/dev/null; then
    echo "  ✓ $label  (${n}×)"
  else
    echo "  ✗ MISSING: $label"
    fail=1
  fi
}

if [ -f resources/views/layouts/tenant/_nav-items-data.blade.php ]; then
  echo "  ✗ _nav-items-data.blade.php still exists"
  fail=1
else
  echo "  ✓ _nav-items-data.blade.php removed"
fi

verify "resources/views/layouts/tenant/_nav-items.blade.php"  "navItems = ["         "nav-items has inline array"
verify "resources/views/layouts/tenant/_more-drawer.blade.php" "tenant.waitlist.index" "drawer has waitlist"
verify "resources/views/layouts/tenant/_more-drawer.blade.php" "tenant.inventory.index" "drawer has inventory"
verify "resources/views/layouts/tenant/_more-drawer.blade.php" "tenant.reports.index"  "drawer has reports"
verify "resources/views/layouts/tenant/_more-drawer.blade.php" "tenant.work-order-fields.index" "drawer has work order fields"
verify "resources/views/layouts/tenant/_more-drawer.blade.php" "tenant.feature_addons.index" "drawer has add-ons"

if [ "$fail" -ne 0 ]; then
  echo ""
  echo "✗ FAIL"
  exit 1
fi

echo ""
echo "✓ all green"
echo ""
echo "DEPLOY THIS IMMEDIATELY to clear the 500 error:"
echo "  git add -A && git commit -m 'fix: rollback drawer extraction, add missing items inline'"
echo "  git push"
echo "  Server: git pull && php artisan view:clear && \\"
echo "    sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm"
echo ""
echo "=== rollback + fix complete ==="
