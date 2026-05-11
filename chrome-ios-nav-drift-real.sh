#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Chrome iOS nav drift — root cause fix.
#
# Previous attempt (chrome-ios-nav-fix.sh) removed transform/will-change from
# the nav. That was correct hygiene but not the root cause.
#
# The real bug: .ia-shell has min-height: 100vh (set by all three themes).
# In Chrome iOS, 100vh = the LARGEST possible viewport (URL bar hidden).
# When the URL bar is visible, the visual viewport is smaller, but the body
# still measures 100vh — meaning the body extends past the visible viewport.
# Scrolling causes the URL bar to hide/show, the visual viewport to resize,
# and `position: fixed; bottom: 0` re-anchors during the animation.
#
# On Chrome iOS specifically, the re-anchor isn't snapped — it's interpolated
# during the transition. With repeated scrolling, the "fixed" nav appears to
# drift, eventually ending up arbitrarily far from the actual viewport bottom.
#
# Fix: override .ia-shell min-height to 100dvh (dynamic viewport height) on
# mobile. 100dvh always matches the CURRENT visual viewport — when the URL
# bar shows, dvh shrinks; when it hides, dvh grows. The body never extends
# past the visible viewport, no scroll past content, no URL bar transition,
# no nav drift.
#
# Browser support: iOS 15.4+ (Apr 2022), Chrome iOS inherits from WebKit so
# also 15.4+. Vast majority of mobile devices. Fallback handled by leaving
# the existing 100vh rule in place; dvh override only kicks in where supported.
# ─────────────────────────────────────────────────────────────────────────────

set -e
[ -f artisan ] || { echo "ABORT: not in intake-license repo root"; exit 1; }

echo "=== chrome ios nav drift root-cause fix starting ==="

# Append the dvh override at the END of mobile-nav.css so it wins over the
# theme files (which load before mobile-nav.css per app.blade.php).
python3 <<'PY'
from pathlib import Path
p = Path('public/css/tenant/mobile-nav.css')
s = p.read_text()
marker = '/* MOBILE-VIEWPORT-DVH v1 */'
if marker in s:
    print("SKIP (already patched)")
else:
    addition = '''

/* MOBILE-VIEWPORT-DVH v1 — fix Chrome iOS nav drift.
   Override theme files' `min-height: 100vh` on mobile to use 100dvh
   (dynamic viewport height). On Chrome iOS, 100vh = largest possible
   viewport (URL bar hidden), so the body is taller than the visual
   viewport, causing position: fixed; bottom: 0 to drift during URL bar
   transitions. 100dvh tracks the current visual viewport, so the page
   doesn't extend past it. */
@media (max-width: 1023px) {
  .ia-shell { min-height: 100dvh; }
  body { min-height: 100dvh; }
}
'''
    p.write_text(s + addition)
    print("OK (dvh override appended)")
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
verify "public/css/tenant/mobile-nav.css"  "MOBILE-VIEWPORT-DVH v1"           "marker"
verify "public/css/tenant/mobile-nav.css"  ".ia-shell { min-height: 100dvh; }" "shell dvh rule"
verify "public/css/tenant/mobile-nav.css"  "body { min-height: 100dvh; }"      "body dvh rule"

if [ "$fail" -ne 0 ]; then
  echo ""
  echo "✗ FAIL"
  exit 1
fi

echo ""
echo "✓ all green"
echo ""
echo "Next:"
echo "  git add -A && git commit -m 'fix: Chrome iOS nav drift via 100dvh — root cause not compositor hacks'"
echo "  git push"
echo "  Server: git pull && php artisan view:clear && \\"
echo "    sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm"
echo ""
echo "=== fix complete ==="
