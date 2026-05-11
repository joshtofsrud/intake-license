#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Mobile bottom nav — Chrome iOS drift fix.
#
# Symptom: bottom nav drifts vertically (sometimes all the way up the page)
# in Chrome on iOS.
#
# Cause: the existing rule used `transform: translateZ(0)` + `will-change:
# transform` + `backface-visibility: hidden` to pin the nav to a separate
# compositor layer. This was a known iOS 13/14 Safari workaround for address-
# bar transition bounce. On modern iOS WebKit (15+, which Chrome iOS also
# uses), those properties create a new containing block for descendants and
# can re-anchor `position: fixed` to that block — which then scrolls with
# the page, causing the drift.
#
# Fix: drop the three GPU-pin properties entirely. Modern iOS WebKit handles
# `position: fixed` + `bottom: 0` correctly without help. Result: nav stays
# stable on both Safari iOS and Chrome iOS.
#
# Also dropped: the now-misleading comment. New comment explains the modern
# approach.
# ─────────────────────────────────────────────────────────────────────────────

set -e
[ -f artisan ] || { echo "ABORT: not in intake-license repo root"; exit 1; }

echo "=== chrome ios nav drift fix starting ==="

python3 <<'PY'
from pathlib import Path
p = Path('public/css/tenant/mobile-nav.css')
s = p.read_text()
marker = "CHROME-IOS-NAV-FIX v1"
if marker in s:
    print("SKIP (already fixed)")
else:
    old = '''  /* Bottom tab bar — default (light theme).
     iOS WebKit (Safari + Chrome on iOS) repaints fixed-position elements
     during address-bar transitions, causing them to "bobble" or briefly
     lift. Pinning to a compositor layer with transform: translateZ(0)
     and will-change: transform fixes the most common symptoms. */
  .ia-mobile-nav {
    display: flex;
    justify-content: space-around;
    align-items: stretch;
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    background: #ffffff;
    border-top: 0.5px solid rgba(0, 0, 0, .1);
    padding: 6px 2px calc(6px + env(safe-area-inset-bottom, 0px));
    z-index: 100;
    box-shadow: 0 -4px 12px rgba(0, 0, 0, .06);
    transform: translateZ(0);
    backface-visibility: hidden;
    will-change: transform;
  }'''
    new = '''  /* CHROME-IOS-NAV-FIX v1 — Bottom tab bar.
     Modern iOS WebKit (15+, used by both Safari iOS and Chrome iOS) handles
     `position: fixed; bottom: 0` correctly during URL bar transitions.
     The older `transform: translateZ(0)` + `will-change` GPU pinning was a
     workaround for iOS 13/14 bobble but creates a containing block that can
     re-anchor `position: fixed` descendants to the wrong ancestor, causing
     the nav to drift up the page on Chrome iOS. Removed. */
  .ia-mobile-nav {
    display: flex;
    justify-content: space-around;
    align-items: stretch;
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    background: #ffffff;
    border-top: 0.5px solid rgba(0, 0, 0, .1);
    padding: 6px 2px calc(6px + env(safe-area-inset-bottom, 0px));
    z-index: 100;
    box-shadow: 0 -4px 12px rgba(0, 0, 0, .06);
  }'''
    assert s.count(old) == 1, f"anchor count={s.count(old)}, expected 1"
    p.write_text(s.replace(old, new))
    print("OK (compositor hacks removed)")
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

# Scope checks to the .ia-mobile-nav rule (only that rule had the GPU hacks).
verify        "public/css/tenant/mobile-nav.css"  "CHROME-IOS-NAV-FIX v1"  "fix marker"

# Verify the GPU hacks are no longer in .ia-mobile-nav. Count them in the
# whole file — the only other place they could legitimately live is on
# .ia-mobile-fab, which we did NOT touch. Check that the .ia-mobile-nav
# rule body itself doesn't have them.
python3 <<'PY'
import re, sys
s = open('public/css/tenant/mobile-nav.css').read()
m = re.search(r'\.ia-mobile-nav \{([^}]*)\}', s, re.DOTALL)
if not m:
    print("  ✗ couldn't locate .ia-mobile-nav rule")
    sys.exit(1)
body = m.group(1)
bad = [t for t in ['transform: translateZ', 'will-change: transform', 'backface-visibility'] if t in body]
if bad:
    print(f"  ✗ .ia-mobile-nav STILL contains: {bad}")
    sys.exit(1)
print("  ✓ .ia-mobile-nav has no GPU-pin properties")
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
echo "  git add -A && git commit -m 'fix: bottom nav drift on Chrome iOS — remove GPU compositor hacks'"
echo "  git push"
echo "  Server: git pull && php artisan view:clear && \\"
echo "    sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm"
echo ""
echo "=== fix complete ==="
