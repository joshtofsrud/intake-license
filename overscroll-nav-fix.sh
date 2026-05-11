#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Nav drift root cause — iOS rubber-band overscroll.
#
# Symptom: bottom nav drifts up when you swipe up past the bottom of the
# page content. NOT the URL bar transition (the 100dvh fix didn't help
# because that wasn't the issue).
#
# Real cause: iOS WebKit rubber-bands the entire viewport during overscroll,
# including `position: fixed` elements. When the user swipes up and the page
# hits the content edge, the whole viewport (with the "fixed" nav on it)
# elastic-bounces up.
#
# Standard fix: overscroll-behavior-y: none on the body. Disables rubber-band
# at the body scroll boundaries. Fixed elements stay genuinely fixed because
# the viewport itself no longer bounces.
#
# Side effect: page no longer has the iOS elastic feel at top/bottom. That's
# the right tradeoff for an app interface — the elastic bounce is appropriate
# for a webpage, not for an app with persistent chrome.
#
# Browser support: Safari 16+, Chrome iOS 16+ (which is iOS 16+). Earlier
# versions silently ignore. Most active devices.
#
# Also adds `overflow-anchor: none` to prevent another iOS quirk where the
# browser tries to maintain scroll position relative to a "anchor" element,
# which can fight with fixed positioning.
# ─────────────────────────────────────────────────────────────────────────────

set -e
[ -f artisan ] || { echo "ABORT: not in intake-license repo root"; exit 1; }

echo "=== overscroll fix starting ==="

python3 <<'PY'
from pathlib import Path
p = Path('public/css/tenant/mobile-nav.css')
s = p.read_text()
marker = '/* OVERSCROLL-NAV-FIX v1 */'
if marker in s:
    print("SKIP (already patched)")
else:
    addition = '''

/* OVERSCROLL-NAV-FIX v1 — prevent iOS rubber-band from dragging the bottom nav.
   `position: fixed` is honored by the visual viewport, but iOS rubber-band
   bounces the visual viewport itself during overscroll. Disabling the
   rubber-band at the body's scroll boundaries keeps the nav genuinely fixed. */
@media (max-width: 1023px) {
  html, body {
    overscroll-behavior-y: none;
    overflow-anchor: none;
  }
}
'''
    p.write_text(s + addition)
    print("OK (overscroll-behavior rule appended)")
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

verify "public/css/tenant/mobile-nav.css"  "OVERSCROLL-NAV-FIX v1"          "marker"
verify "public/css/tenant/mobile-nav.css"  "overscroll-behavior-y: none"    "overscroll rule"

if [ "$fail" -ne 0 ]; then
  echo ""
  echo "✗ FAIL"
  exit 1
fi

echo ""
echo "✓ all green"
echo ""
echo "Next:"
echo "  bash chrome-ios-nav-fix-real-2.sh && \\"
echo "    git add -A && \\"
echo "    git commit -m 'fix: bottom nav drift caused by iOS rubber-band overscroll, not URL bar' && \\"
echo "    git push"
echo "  Server: git pull && php artisan view:clear && \\"
echo "    sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm"
echo ""
echo "ALSO REMEMBER: remove the nav-debug-overlay.sh before launch (it ships a red diagnostic banner)."
echo "=== fix complete ==="
