#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Mobile header — pick correct logo variant for the current theme.
#
# Bug: _mobile-header.blade.php uses $currentTenant->logo_url directly, which
# is the DARK variant (intended for light backgrounds). On dark themes (a, c)
# the mobile header background is #0c0c0c, so the dark logo appears nearly
# invisible / very low-contrast.
#
# Fix: use the same ColorHelper::pickLogo() helper the desktop sidebar uses,
# with the matching background color per theme. pickLogo() returns the LIGHT
# variant on dark backgrounds when one is uploaded, else falls back to the
# single uploaded variant. Same logo_size_admin clamping as the sidebar.
#
# Side benefits:
#   - Tenants that uploaded only one logo continue to work (graceful fallback)
#   - Tenants that uploaded both variants get the correct one per theme
#   - Logo height respects the per-tenant `logo_size_admin` setting (16-80px),
#     same as the sidebar — this was previously a fixed 32px max-height.
# ─────────────────────────────────────────────────────────────────────────────

set -e
[ -f artisan ] || { echo "ABORT: not in intake-license repo root"; exit 1; }

echo "=== mobile header logo fix starting ==="

python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/calendar/_mobile-schedule.blade.php')  # placeholder, real target below
target = Path('resources/views/layouts/tenant/_mobile-header.blade.php')
s = target.read_text()
marker = "MOBILE-HEADER-LOGO-PICK v1"
if marker in s:
    print("SKIP (already patched)")
else:
    new = '''{{-- ================================================================
     Mobile admin header (≤1023px) — MOBILE-BACK v1 + MOBILE-HEADER-LOGO-PICK v1
     Shows ‹ Back chevron when @section('mobile-back', 'Label|/url') is set,
     otherwise shows tenant logo or wordmark.
     Uses ColorHelper::pickLogo so dark themes get the light logo variant
     (matching the desktop sidebar's behavior).
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

  // Pick the right logo variant for the mobile header surface.
  // Background matches the CSS rule in mobile-nav.css: dark themes #0c0c0c,
  // light theme (b) #ffffff. Match those exact values so pickLogo's
  // dark-detection lines up with the painted surface.
  $mhdrBg = ($adminTheme === 'b') ? '#ffffff' : '#0c0c0c';
  $mhdrLogo = \App\Support\ColorHelper::pickLogo($currentTenant, $mhdrBg);
  $mhdrLogoHeight = (int) ($currentTenant->logo_size_admin ?? 26);
  $mhdrLogoHeight = max(16, min(40, $mhdrLogoHeight)); // clamp to mobile-friendly size
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
    @elseif($mhdrLogo)
      <a href="{{ route('tenant.dashboard') }}" class="ia-mobile-header-brand" aria-label="{{ $currentTenant->name }} — Dashboard">
        <img src="{{ $mhdrLogo }}" alt="{{ $currentTenant->name }}" class="ia-mobile-header-logo" style="height:{{ $mhdrLogoHeight }}px">
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
    target.write_text(new)
    print("OK (logo pick + theme-aware sizing)")
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

verify        "resources/views/layouts/tenant/_mobile-header.blade.php"  "MOBILE-HEADER-LOGO-PICK v1"          "marker"
verify        "resources/views/layouts/tenant/_mobile-header.blade.php"  "ColorHelper::pickLogo"               "pickLogo call"
verify        "resources/views/layouts/tenant/_mobile-header.blade.php"  "mhdrLogoHeight"                      "logo height var"
verify_absent "resources/views/layouts/tenant/_mobile-header.blade.php"  "\$currentTenant->logo_url"           "old direct logo_url access gone"

# Verify Blade balance
python3 <<'PY'
src = open('resources/views/layouts/tenant/_mobile-header.blade.php').read()
checks = [('@if','@endif'), ('@php','@endphp')]
import sys
ok = True
for o, c in checks:
    no = src.count(o)
    nc = src.count(c)
    if o == '@if':
        # @if shares @endif with @elseif (one closer per @if)
        if no != nc:
            print(f'  ✗ {o}({no}) != {c}({nc})')
            ok = False
        else:
            print(f'  ✓ {o}/{c}: {no}')
    else:
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
echo "  git add -A && git commit -m 'fix: mobile header uses pickLogo for theme-aware logo variant + respects logo_size_admin'"
echo "  git push"
echo "  Server: git pull && php artisan view:clear && \\"
echo "    sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm"
echo ""
echo "=== mobile header logo fix complete ==="
