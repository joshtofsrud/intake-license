#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Mobile nav patches — Day 1 of mobile MVP.
#
# Five changes:
#   1. FAB (+) floating button on Today + Schedule, stubbed to v1.1 toast.
#   2. Back button slot — pages set @section('mobile-back') to declare a destination.
#      Mobile header reads it; if set, shows ‹ Back chevron instead of brand.
#   3. "Open on desktop" utility class — for pages too complex for phones
#      (intake form editor, block builder). Existing pattern at lines 419-429
#      of mobile-nav.css for theme cards, generalized to a reusable class.
#   4. Drawer sign-out fix — separate "tap user row to sign out" hostile UX
#      into two rows: user info (no action) and explicit "Sign out" button.
#   5. Mobile-content padding audit — drop the redundant top-padding on
#      .ia-content when mobile header is present (header already separates).
#
# All changes scoped to mobile (≤1023px). Desktop unaffected.
# ─────────────────────────────────────────────────────────────────────────────

set -e
[ -f artisan ] || { echo "ABORT: not in intake-license repo root"; exit 1; }

echo "=== mobile nav patches starting ==="

# ─────────────────────────────────────────────────────────────────────────────
# 1. FAB component — new partial included in layout, only renders on
#    routes that want it (controlled by @section('mobile-fab')).
# ─────────────────────────────────────────────────────────────────────────────
mkdir -p resources/views/layouts/tenant
cat > resources/views/layouts/tenant/_mobile-fab.blade.php <<'EOF'
{{-- ================================================================
     Mobile FAB — floating action button shown bottom-right on certain
     pages. Page declares @section('mobile-fab') => 'walk-in' to enable.

     v1: only the 'walk-in' variant exists, stubbed to a v1.1 toast.
     Future: more FAB types as features ship.
     ================================================================ --}}
@hasSection('mobile-fab')
  @php $fabType = trim(View::yieldContent('mobile-fab')); @endphp
  @if($fabType === 'walk-in')
    <button type="button"
            class="ia-mobile-fab ia-mobile-fab--walkin"
            aria-label="Start walk-in"
            onclick="window.IntakeToast && window.IntakeToast.info('Walk-in flow ships in v1.1. For now, use New appointment.')">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
        <line x1="12" y1="5" x2="12" y2="19"/>
        <line x1="5" y1="12" x2="19" y2="12"/>
      </svg>
    </button>
  @endif
@endif
EOF
echo "OK 1a (FAB partial created)"

# Add FAB CSS — appended to mobile-nav.css inside the existing 1023px media query
python3 <<'PY'
from pathlib import Path
p = Path('public/css/tenant/mobile-nav.css')
s = p.read_text()
marker = '/* MOBILE-FAB v1 */'
if marker in s:
    print("SKIP 1b (FAB CSS already present)")
else:
    # Append at end of file as its own scoped block.
    addition = '''

/* MOBILE-FAB v1 — floating action button (≤1023px only) */
@media (max-width: 1023px) {
  .ia-mobile-fab {
    position: fixed;
    right: 16px;
    bottom: calc(82px + env(safe-area-inset-bottom, 0px)); /* clear the bottom nav */
    width: 52px;
    height: 52px;
    border-radius: 50%;
    background: var(--ia-accent, #BEF264);
    color: var(--ia-accent-text, #0a0a0a);
    border: none;
    box-shadow: 0 6px 20px rgba(190,242,100,.3), 0 2px 6px rgba(0,0,0,.2);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 95;
    font-family: inherit;
    -webkit-tap-highlight-color: transparent;
    transition: transform 100ms ease;
  }
  .ia-mobile-fab:active { transform: scale(0.94); }
}
@media (min-width: 1024px) {
  .ia-mobile-fab { display: none !important; }
}
'''
    p.write_text(s + addition)
    print("OK 1b (FAB CSS appended)")
PY

# Include the FAB partial in app.blade.php, right after the more-drawer include.
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/layouts/tenant/app.blade.php')
s = p.read_text()
if "_mobile-fab" in s:
    print("SKIP 1c (FAB include already present)")
else:
    old = "@include('layouts.tenant._more-drawer')"
    new = "@include('layouts.tenant._more-drawer')\n@include('layouts.tenant._mobile-fab')"
    assert s.count(old) == 1, f"more-drawer include count={s.count(old)}, expected 1"
    p.write_text(s.replace(old, new))
    print("OK 1c (FAB included in layout)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# 2. Back button slot in mobile header.
#    Pages set @section('mobile-back', route('tenant.calendar.index'))
#    or @section('mobile-back', 'Schedule|/admin/calendar')
#    Format: "Label|URL". Header renders ‹ Label chevron when set.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/layouts/tenant/_mobile-header.blade.php')
s = p.read_text()
marker = "MOBILE-BACK v1"
if marker in s:
    print("SKIP 2a (mobile header already updated)")
else:
    new = '''{{-- ================================================================
     Mobile admin header (≤1023px) — MOBILE-BACK v1
     Shows ‹ Back chevron when @section('mobile-back', 'Label|/url') is set,
     otherwise shows tenant logo or wordmark.
     Desktop hides this entirely via CSS.
     ================================================================ --}}
@php
  $mobileBackRaw = trim(View::yieldContent('mobile-back', ''));
  $mobileBackLabel = null;
  $mobileBackUrl = null;
  if ($mobileBackRaw !== '') {
    $parts = explode('|', $mobileBackRaw, 2);
    if (count($parts) === 2) {
      $mobileBackLabel = trim($parts[0]);
      $mobileBackUrl   = trim($parts[1]);
    }
  }
@endphp
<header class="ia-mobile-header" role="banner">
  <div class="ia-mobile-header-inner">
    @if($mobileBackLabel && $mobileBackUrl)
      <a href="{{ $mobileBackUrl }}" class="ia-mobile-header-back" aria-label="Back to {{ $mobileBackLabel }}">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <polyline points="15 18 9 12 15 6"/>
        </svg>
        <span>{{ $mobileBackLabel }}</span>
      </a>
    @elseif($currentTenant->logo_url)
      <a href="{{ route('tenant.dashboard') }}" class="ia-mobile-header-brand" aria-label="{{ $currentTenant->name }} — Dashboard">
        <img src="{{ $currentTenant->logo_url }}" alt="{{ $currentTenant->name }}" class="ia-mobile-header-logo">
      </a>
    @else
      <a href="{{ route('tenant.dashboard') }}" class="ia-mobile-header-brand ia-mobile-header-brand-text" aria-label="{{ $currentTenant->name }} — Dashboard">
        <span class="ia-mobile-header-mark">{{ strtoupper(substr($currentTenant->name, 0, 1)) }}</span>
        <span class="ia-mobile-header-name">{{ $currentTenant->name }}</span>
      </a>
    @endif
  </div>
</header>
'''
    p.write_text(new)
    print("OK 2a (mobile header rewritten with back slot)")
PY

# CSS for the back button — appended to mobile-nav.css
python3 <<'PY'
from pathlib import Path
p = Path('public/css/tenant/mobile-nav.css')
s = p.read_text()
marker = '/* MOBILE-BACK-BTN v1 */'
if marker in s:
    print("SKIP 2b (back-btn CSS already present)")
else:
    addition = '''

/* MOBILE-BACK-BTN v1 — back chevron in mobile header */
@media (max-width: 1023px) {
  .ia-mobile-header-inner:has(.ia-mobile-header-back) {
    justify-content: flex-start;
  }
  .ia-mobile-header-back {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    color: var(--ia-accent, #BEF264);
    text-decoration: none;
    font-size: 15px;
    font-weight: 500;
    padding: 6px 4px 6px 0;
    margin-left: -4px;
    border-radius: 6px;
    -webkit-tap-highlight-color: transparent;
  }
  .ia-mobile-header-back:active { background: rgba(190,242,100,.08); }
  .ia-mobile-header-back svg { flex-shrink: 0; }
}
'''
    p.write_text(s + addition)
    print("OK 2b (back-btn CSS appended)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# 3. "Open on desktop" affordance — utility class + tiny helper partial.
# ─────────────────────────────────────────────────────────────────────────────
cat > resources/views/layouts/tenant/_desktop-only-notice.blade.php <<'EOF'
{{-- ================================================================
     Renders a friendly notice on mobile that this page is best on
     desktop. Use at the top of pages too complex for phone editing.

     Usage:
       @include('layouts.tenant._desktop-only-notice', [
         'pageName' => 'Intake Form Editor',
       ])

     The actual page content can still render below — the notice is
     informational, not blocking. Pages may also choose to early-return
     a stripped-down read-only view on mobile.
     ================================================================ --}}
<div class="ia-desktop-only-notice">
  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <rect x="2" y="3" width="20" height="14" rx="2"/>
    <line x1="8" y1="21" x2="16" y2="21"/>
    <line x1="12" y1="17" x2="12" y2="21"/>
  </svg>
  <div>
    <strong>{{ $pageName ?? 'This page' }}</strong> works best on a larger screen.
    <span class="muted">Open this URL on your computer for the full editor.</span>
  </div>
</div>
EOF
echo "OK 3a (desktop-only notice partial created)"

python3 <<'PY'
from pathlib import Path
p = Path('public/css/tenant/mobile-nav.css')
s = p.read_text()
marker = '/* DESKTOP-ONLY-NOTICE v1 */'
if marker in s:
    print("SKIP 3b (notice CSS already present)")
else:
    addition = '''

/* DESKTOP-ONLY-NOTICE v1 — banner shown on mobile for desktop-best pages */
.ia-desktop-only-notice { display: none; }
@media (max-width: 1023px) {
  .ia-desktop-only-notice {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin: 12px 16px;
    padding: 12px 14px;
    background: rgba(245,158,11,.08);
    border: 1px solid rgba(245,158,11,.2);
    border-radius: 8px;
    font-size: 13px;
    line-height: 1.4;
    color: rgba(255,255,255,0.85);
  }
  body.ia-theme-b .ia-desktop-only-notice {
    color: #6B4E0B;
    background: rgba(245,158,11,.1);
  }
  .ia-desktop-only-notice svg {
    flex-shrink: 0;
    color: #F59E0B;
    margin-top: 1px;
  }
  .ia-desktop-only-notice .muted {
    display: block;
    margin-top: 2px;
    opacity: .65;
    font-size: 12px;
  }
}
'''
    p.write_text(s + addition)
    print("OK 3b (notice CSS appended)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# 4. Drawer sign-out fix — split the "tap user row to sign out" pattern
#    into a non-clickable user row + an explicit Sign out button.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/layouts/tenant/_more-drawer.blade.php')
s = p.read_text()
old = '''    <div class="ia-drawer-user" onclick="document.getElementById('logout-form-mobile').submit()">
      <div class="ia-user-avatar">{{ strtoupper(substr($authUser->name, 0, 2)) }}</div>
      <div>
        <div class="ia-user-name">{{ $authUser->name }}</div>
        <div class="ia-user-role">Tap to sign out</div>
      </div>
    </div>'''
new = '''    {{-- DRAWER-USER v2 — split user info from sign-out to prevent accidental logouts --}}
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
    </button>'''
assert s.count(old) == 1, f"drawer-user count={s.count(old)}, expected 1"
p.write_text(s.replace(old, new))
print("OK 4a (drawer user row + sign-out separated)")
PY

# CSS for the new sign-out button + readonly user row
python3 <<'PY'
from pathlib import Path
p = Path('public/css/tenant/mobile-nav.css')
s = p.read_text()
marker = '/* DRAWER-USER-V2 */'
if marker in s:
    print("SKIP 4b (drawer-user CSS already present)")
else:
    addition = '''

/* DRAWER-USER-V2 — user info row is no longer the sign-out hit area */
@media (max-width: 1023px) {
  .ia-drawer-user.ia-drawer-user--readonly {
    cursor: default;
  }
  .ia-drawer-signout {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    padding: 12px 20px;
    background: transparent;
    border: none;
    border-top: 0.5px solid rgba(0,0,0,.08);
    color: #B43030;
    font-size: 14px;
    font-weight: 500;
    font-family: inherit;
    cursor: pointer;
    text-align: left;
    -webkit-tap-highlight-color: transparent;
  }
  .ia-theme-a .ia-drawer-signout,
  .ia-theme-c .ia-drawer-signout {
    border-top-color: rgba(255,255,255,.08);
    color: #F59999;
  }
  .ia-drawer-signout:active {
    background: rgba(180,48,48,.08);
  }
  .ia-theme-a .ia-drawer-signout:active,
  .ia-theme-c .ia-drawer-signout:active {
    background: rgba(245,153,153,.06);
  }
  .ia-drawer-signout svg { flex-shrink: 0; }
}
'''
    p.write_text(s + addition)
    print("OK 4b (drawer sign-out CSS appended)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# 5. Mobile content padding audit — drop the redundant top padding on
#    .ia-content when in mobile mode. The sticky header already separates.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('public/css/tenant/mobile-nav.css')
s = p.read_text()
old = '''  /* Push main content down so it doesn't slide under the header */
  .ia-content {
    padding-top: 16px;
  }
}'''
new = '''  /* Header is sticky; content flows naturally underneath.
     Page-specific padding (per-page .ia-content rules) still applies. */
}'''
n = s.count(old)
if n == 0:
    print("SKIP 5 (already cleaned up)")
else:
    assert n == 1, f"padding-top count={n}, expected 1"
    p.write_text(s.replace(old, new))
    print("OK 5 (redundant top padding removed)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# Verification
# ─────────────────────────────────────────────────────────────────────────────
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

verify "resources/views/layouts/tenant/_mobile-fab.blade.php"        "ia-mobile-fab"             "FAB partial markup"
verify "public/css/tenant/mobile-nav.css"                            "MOBILE-FAB v1"             "FAB CSS marker"
verify "resources/views/layouts/tenant/app.blade.php"                "_mobile-fab"               "FAB included"
verify "resources/views/layouts/tenant/_mobile-header.blade.php"     "MOBILE-BACK v1"            "back slot in header"
verify "public/css/tenant/mobile-nav.css"                            "MOBILE-BACK-BTN v1"        "back-btn CSS"
verify "resources/views/layouts/tenant/_desktop-only-notice.blade.php" "ia-desktop-only-notice"  "notice partial"
verify "public/css/tenant/mobile-nav.css"                            "DESKTOP-ONLY-NOTICE v1"    "notice CSS"
verify "resources/views/layouts/tenant/_more-drawer.blade.php"       "DRAWER-USER v2"            "drawer user split"
verify "resources/views/layouts/tenant/_more-drawer.blade.php"       "ia-drawer-signout"         "sign-out button"
verify "public/css/tenant/mobile-nav.css"                            "DRAWER-USER-V2"            "drawer-user CSS"

# Sanity: no leftover broken Blade
python3 <<'PY'
import sys
for f in [
    'resources/views/layouts/tenant/_mobile-fab.blade.php',
    'resources/views/layouts/tenant/_mobile-header.blade.php',
    'resources/views/layouts/tenant/_more-drawer.blade.php',
    'resources/views/layouts/tenant/_desktop-only-notice.blade.php',
    'resources/views/layouts/tenant/app.blade.php',
]:
    s = open(f).read()
    checks = [('@if','@endif'), ('@php','@endphp'), ('@hasSection','@endif')]
    ok_file = True
    for o, c in checks:
        no = s.count(o)
        nc = s.count(c)
        # @endif is shared by @if and @hasSection, so just check @if + @hasSection <= @endif
        if o == '@if':
            haseif = s.count('@hasSection')
            if (no + haseif) != nc:
                print(f'  ✗ {f}: @if({no}) + @hasSection({haseif}) != @endif({nc})')
                ok_file = False
        elif o == '@hasSection':
            continue
        else:
            if no != nc:
                print(f'  ✗ {f}: {o}({no}) != {c}({nc})')
                ok_file = False
    if ok_file:
        print(f'  ✓ Blade balanced: {f}')
PY

if [ "$fail" -ne 0 ]; then
  echo ""
  echo "✗ FAIL — STOP, do not commit"
  exit 1
fi

echo ""
echo "✓ all green"
echo ""
echo "Next:"
echo "  git add -A && git commit -m 'mobile nav: FAB, back-button slot, desktop-only notice, drawer sign-out fix'"
echo "  git push"
echo "  Server: git pull && php artisan view:clear && \\"
echo "    sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm"
echo ""
echo "To use the FAB on a page, add to that page's Blade:"
echo "  @section('mobile-fab', 'walk-in')"
echo ""
echo "To use the back button on a page:"
echo "  @section('mobile-back', 'Schedule|' . route('tenant.calendar.index'))"
echo ""
echo "To show 'open on desktop' notice:"
echo "  @include('layouts.tenant._desktop-only-notice', ['pageName' => 'Intake Form Editor'])"
echo ""
echo "=== mobile nav patches complete ==="
