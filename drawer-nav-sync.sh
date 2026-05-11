#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Mobile drawer — sync with sidebar nav.
#
# Bug: `_more-drawer.blade.php` has its own hardcoded $moreItems array,
# separate from `_nav-items.blade.php`'s $navItems. The two drift over time.
# Missing from drawer currently: Waitlist, Inventory, Reports, Work Order
# Fields, Add-ons. Means mobile users can't reach those pages from the nav.
#
# Fix: derive the drawer's items from a shared nav-items array. Both the
# sidebar and the drawer pull from the same definition, so adding a new nav
# item always shows up in both places.
#
# Approach: extract the nav items array to a partial helper that returns
# the array (via @include with section yielding). Cleaner alternative is a
# View Composer, but a shared Blade partial that emits PHP is the lowest-risk
# move at launch time. Both consumers @include it and use the populated array.
#
# Bottom-nav primary tabs (Dashboard, Calendar/Schedule, Customers, plus the
# Register/POS-future tab) are excluded from the drawer because they're
# already in the bottom bar.
# ─────────────────────────────────────────────────────────────────────────────

set -e
[ -f artisan ] || { echo "ABORT: not in intake-license repo root"; exit 1; }

echo "=== drawer nav sync starting ==="

# ─────────────────────────────────────────────────────────────────────────────
# 1. Extract the nav items definition to a shared PHP partial.
#    Currently the array is inline in _nav-items.blade.php. Move to a new
#    file that just defines the array. Both _nav-items.blade.php and
#    _more-drawer.blade.php @include this to populate $navItems.
# ─────────────────────────────────────────────────────────────────────────────

# Read existing _nav-items.blade.php and split it into:
#  a) _nav-items-data.blade.php (the @php array definition)
#  b) _nav-items.blade.php (the @foreach render — just keeps the rendering bits)

python3 <<'PY'
from pathlib import Path
p = Path('resources/views/layouts/tenant/_nav-items.blade.php')
s = p.read_text()
marker = "NAV-ITEMS-EXTRACTED v1"
if marker in s:
    print("SKIP 1 (already extracted)")
else:
    # Find the @php block at the top
    start = s.find('@php')
    end = s.find('@endphp', start) + len('@endphp')
    php_block = s[start:end]
    rest = s[end:]

    # Write the data partial — same @php block, plus a marker
    data_path = Path('resources/views/layouts/tenant/_nav-items-data.blade.php')
    data_path.write_text(
        '{{-- NAV-ITEMS-EXTRACTED v1 — shared data source for sidebar + mobile drawer.\n'
        '     Edit $navItems here; both consumers see the change. --}}\n'
        + php_block + '\n'
    )

    # Rewrite _nav-items.blade.php to @include the data partial then render
    new = (
        "{{-- NAV-ITEMS-EXTRACTED v1 — array data now lives in _nav-items-data.blade.php --}}\n"
        "@include('layouts.tenant._nav-items-data')\n"
        + rest
    )
    p.write_text(new)
    print("OK 1 (nav items extracted to shared partial)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# 2. Rewrite _more-drawer.blade.php to use $navItems from the shared partial.
#    Exclude bottom-nav primary tabs. Apply gate checks. Render in same
#    grid format the drawer already uses.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/layouts/tenant/_more-drawer.blade.php')
s = p.read_text()
marker = "DRAWER-NAV-SYNC v1"
if marker in s:
    print("SKIP 2 (drawer already synced)")
else:
    new = '''{{-- DRAWER-NAV-SYNC v1 — drawer items derived from shared $navItems.
     Excludes routes that already appear in the bottom nav bar.
     Anything added to _nav-items-data.blade.php shows up here automatically. --}}
@include('layouts.tenant._nav-items-data')
@php
  $current = request()->route()?->getName() ?? '';

  // Routes already shown in the bottom nav bar. Don't duplicate them in the drawer.
  $bottomNavRoutes = [
    'tenant.dashboard',
    'tenant.calendar.index',
    'tenant.customers.index',
  ];

  // Filter $navItems: drop bottom-nav primaries, drop gated items the tenant
  // doesn't have enabled, drop any with routes that don't exist.
  $drawerItems = [];
  foreach ($navItems as $item) {
    if (in_array($item['route'], $bottomNavRoutes, true)) continue;
    if (!empty($item['gate']) && !$currentTenant->{$item['gate']}) continue;
    if (!\\Illuminate\\Support\\Facades\\Route::has($item['route'])) continue;
    $drawerItems[] = $item;
  }
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
      @foreach($drawerItems as $item)
        @php
          $primaryMatch = str_replace('.index', '', $item['route']);
          $isActive = str_starts_with($current, $primaryMatch);
          if (!$isActive && !empty($item['match_alt'])) {
            $isActive = str_starts_with($current, $item['match_alt']);
          }
        @endphp
        <a href="{{ route($item['route']) }}" class="ia-drawer-item {{ $isActive ? 'active' : '' }}">
          {{ $item['label'] }}
        </a>
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
'''
    p.write_text(new)
    print("OK 2 (drawer rewritten to use shared nav)")
PY

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
verify_absent() {
  local file="$1" needle="$2" label="$3"
  local n
  n=$(grep -c -F -- "$needle" "$file" 2>/dev/null | tr -d '\n' || true)
  : "${n:=0}"
  if [ "${n:-0}" -eq 0 ] 2>/dev/null; then
    echo "  ✓ ABSENT: $label"
  else
    echo "  ✗ STILL PRESENT: $label  (${n}×)"
    fail=1
  fi
}

verify "resources/views/layouts/tenant/_nav-items-data.blade.php"  "navItems"                       "data partial has navItems"
verify "resources/views/layouts/tenant/_nav-items-data.blade.php"  "tenant.waitlist.index"          "waitlist in data"
verify "resources/views/layouts/tenant/_nav-items.blade.php"       "_nav-items-data"                "nav-items includes data"
verify "resources/views/layouts/tenant/_more-drawer.blade.php"     "DRAWER-NAV-SYNC v1"             "drawer marker"
verify "resources/views/layouts/tenant/_more-drawer.blade.php"     "_nav-items-data"                "drawer includes data"
verify "resources/views/layouts/tenant/_more-drawer.blade.php"     "drawerItems"                    "drawer uses filtered list"
verify_absent "resources/views/layouts/tenant/_more-drawer.blade.php"  "\$moreItems = ["          "old hardcoded list removed"

# Blade balance on the rewritten partial
python3 <<'PY'
src = open('resources/views/layouts/tenant/_more-drawer.blade.php').read()
checks = [('@if','@endif'), ('@foreach','@endforeach'), ('@php','@endphp')]
import sys
ok = True
for o, c in checks:
    no, nc = src.count(o), src.count(c)
    if no != nc:
        print(f'  ✗ {o}({no}) != {c}({nc})')
        ok = False
    else:
        print(f'  ✓ {o}/{c}: {no}')
if not ok: sys.exit(1)
PY

if [ "$fail" -ne 0 ]; then
  echo ""
  echo "✗ FAIL"
  exit 1
fi

echo ""
echo "✓ all green"
echo ""
echo "Next:"
echo "  git add -A && git commit -m 'mobile drawer: sync with sidebar nav — extract shared data partial'"
echo "  git push"
echo "  Server: git pull && php artisan view:clear && \\"
echo "    sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm"
echo ""
echo "=== drawer sync complete ==="
