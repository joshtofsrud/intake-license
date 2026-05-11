#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Appointment detail — fix horizontal overflow on mobile.
#
# Root cause: in the APPT-DETAIL-MOBILE patch, the status pipeline rule
#   .appt-b-rail .appt-progress-bar { min-width: max-content; }
# tells the pipeline to expand to its content width. That's correct INSIDE
# .appt-progress-card (which has overflow-x: auto so it can horizontally
# scroll). But the card doesn't have width:100% / max-width:100% constraint,
# so it stretches with its child instead of containing it. Pipeline pushes
# card pushes rail pushes page wider than viewport = horizontal page scroll.
#
# Fix: constrain .appt-progress-card to viewport-width on mobile, and ensure
# .appt-b-shell and .appt-b-rail also have max-width: 100% as a defensive
# measure against any other element trying to push the page wide.
# ─────────────────────────────────────────────────────────────────────────────

set -e
[ -f artisan ] || { echo "ABORT: not in intake-license repo root"; exit 1; }

echo "=== appt detail overflow fix starting ==="

python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/show.blade.php')
s = p.read_text()
marker = "APPT-MOBILE-OVERFLOW-FIX v1"
if marker in s:
    print("SKIP (already patched)")
else:
    # Anchor at the start of the existing APPT-DETAIL-MOBILE block so the
    # overflow constraints come BEFORE the min-width: max-content rule, but
    # then we add overflow: hidden on the card AFTER the min-width rule.
    # Easiest: just add a new dedicated block right after the existing one.
    old = "/* APPT-DETAIL-MOBILE v1 — phone polish at ≤700px */"
    new = """/* APPT-MOBILE-OVERFLOW-FIX v1 — keep the page from exceeding viewport width.
   The status pipeline's `min-width: max-content` rule in the block below
   makes the bar take its content width. Without these constraints, that
   width propagates up through .appt-progress-card → .appt-b-rail →
   .appt-b-shell → page, causing horizontal scroll. We constrain the chain
   so the bar can scroll horizontally inside its card while the card stays
   inside the rail stays inside the page. */
@media (max-width: 900px) {
  .appt-b-shell, .appt-b-rail, .appt-b-main { max-width: 100%; min-width: 0; }
  .appt-b-rail > * { max-width: 100%; min-width: 0; }
  .appt-b-rail .appt-progress-card {
    max-width: 100%;
    min-width: 0;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
  }
  /* Body block too — services/work order/payment cards shouldn't push width */
  .appt-b-main > * { max-width: 100%; min-width: 0; overflow-x: hidden; }
}

/* APPT-DETAIL-MOBILE v1 — phone polish at ≤700px */"""
    assert s.count(old) == 1, f"anchor count={s.count(old)}, expected 1"
    p.write_text(s.replace(old, new))
    print("OK (overflow fix appended before existing mobile block)")
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
verify "resources/views/tenant/appointments/show.blade.php"  "APPT-MOBILE-OVERFLOW-FIX v1"   "overflow fix marker"
verify "resources/views/tenant/appointments/show.blade.php"  ".appt-b-shell, .appt-b-rail, .appt-b-main { max-width: 100%; min-width: 0; }" "shell/rail/main max-width"

if [ "$fail" -ne 0 ]; then
  echo ""
  echo "✗ FAIL"
  exit 1
fi

echo ""
echo "✓ all green"
echo ""
echo "Next:"
echo "  git add -A && git commit -m 'fix: appt detail horizontal overflow on mobile'"
echo "  git push"
echo "  Server: git pull && php artisan view:clear && \\"
echo "    sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm"
echo ""
echo "=== overflow fix complete ==="
