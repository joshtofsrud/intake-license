#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Mobile fixes from the 2026-05-11 phone screenshots.
#
# Two issues addressed:
#
#   1. Appointment detail page on mobile:
#      - Duplicate back button: the desktop page-head has a "← Back" button
#        inside .ia-page-actions. On mobile we already added a "‹ Schedule"
#        chevron to the top-bar via @section('mobile-back'). So the body-level
#        Back is redundant — hide it on mobile.
#      - FAB overlap: the lime + button covers the bottom of content. Add
#        bottom padding to .ia-content on mobile so users can scroll past it.
#
#   2. /admin/appointments page on mobile:
#      - The three attention cards (Pending bookings / Unpaid completed jobs /
#        Ready for pickup) eat the whole viewport before the user sees any
#        appointments. Compact them on mobile into a horizontal 3-tile strip
#        like the Today dashboard 3-stat row — number prominent, single-line
#        title, hide the longer descriptions.
# ─────────────────────────────────────────────────────────────────────────────

set -e
[ -f artisan ] || { echo "ABORT: not in intake-license repo root"; exit 1; }

echo "=== mobile fixes starting ==="

# ─────────────────────────────────────────────────────────────────────────────
# 1. Appointment detail — hide page-head back button + add FAB padding
#    Both rules in the APPT-DETAIL-MOBILE block.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/show.blade.php')
s = p.read_text()
marker = "APPT-MOBILE-FIX v1"
if marker in s:
    print("SKIP 1 (already patched)")
else:
    # Anchor: end of the APPT-DETAIL-MOBILE @media block.
    # Find the closing brace + newline of that media query.
    # Easiest reliable anchor: the @keyframes appt-sheet-up declaration that
    # immediately follows it (added in the same patch).
    old = "@keyframes appt-sheet-up {"
    new = """/* APPT-MOBILE-FIX v1 — duplicate back button + FAB padding */
@media (max-width: 700px) {
  /* The mobile top-bar already shows "‹ Schedule"; hide the page-head Back. */
  .ia-page-actions .ia-btn--ghost { display: none; }
  /* So .ia-page-actions doesn't render as an empty box, hide it when empty. */
  .ia-page-actions:empty { display: none; }
}
@media (max-width: 1023px) {
  /* Push content above the FAB so the last card isn't covered. */
  .ia-content { padding-bottom: calc(160px + env(safe-area-inset-bottom, 0px)) !important; }
}

@keyframes appt-sheet-up {"""
    assert s.count(old) == 1, f"keyframes anchor count={s.count(old)}, expected 1"
    p.write_text(s.replace(old, new))
    print("OK 1 (appt detail mobile fixes added)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# 2. Attention cards — compact 3-tile row on mobile.
#    Targets the .ia-dash-attention-grid + cards inside dashboard.css.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('public/css/tenant/dashboard.css')
s = p.read_text()
marker = '/* ATTENTION-CARDS-MOBILE v1 */'
if marker in s:
    print("SKIP 2 (attention CSS already present)")
else:
    addition = '''

/* ATTENTION-CARDS-MOBILE v1 — compact tiles on phones */
@media (max-width: 600px) {
  .ia-dash-attention-grid {
    grid-template-columns: repeat(3, 1fr);
    gap: 6px;
  }
  .ia-dash-attention-card {
    padding: 10px 8px 12px;
    border-left-width: 2px;
    text-align: left;
    min-width: 0;
  }
  .ia-dash-attention-count {
    font-size: 20px;
    margin-bottom: 4px;
    line-height: 1.1;
  }
  .ia-dash-attention-title {
    font-size: 11px;
    font-weight: 500;
    margin-bottom: 0;
    line-height: 1.25;
    overflow: hidden;
    text-overflow: ellipsis;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
  }
  /* Hide the longer description on phones to keep the card compact */
  .ia-dash-attention-desc {
    display: none;
  }
  .ia-dash-attention-card:hover {
    transform: none; /* tighter tiles shouldn't bob on tap */
  }
}
'''
    p.write_text(s + addition)
    print("OK 2 (attention cards compact CSS appended)")
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

verify "resources/views/tenant/appointments/show.blade.php"  "APPT-MOBILE-FIX v1"            "appt detail fix"
verify "resources/views/tenant/appointments/show.blade.php"  ".ia-page-actions .ia-btn--ghost { display: none; }" "back btn hidden rule"
verify "resources/views/tenant/appointments/show.blade.php"  "padding-bottom: calc(160px"    "FAB padding rule"
verify "public/css/tenant/dashboard.css"                     "ATTENTION-CARDS-MOBILE v1"     "attention cards fix"

if [ "$fail" -ne 0 ]; then
  echo ""
  echo "✗ FAIL"
  exit 1
fi

echo ""
echo "✓ all green"
echo ""
echo "Next:"
echo "  git add -A && git commit -m 'mobile fixes: duplicate back button, FAB overlap, compact attention cards'"
echo "  git push"
echo "  Server: git pull && php artisan view:clear && \\"
echo "    sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm"
echo ""
echo "=== mobile fixes complete ==="
