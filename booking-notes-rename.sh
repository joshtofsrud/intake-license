#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Layout B — rename "Customer details" → "Booking notes"
# (the section actually contains intake-form responses customers answered at
# booking time, not staff-editable customer info; the old label was misleading).
#
# Also: change rail "View booking notes →" button from ghost (low-contrast) to
# primary (lime) so it stands out and signals "there's content here to see".
# ─────────────────────────────────────────────────────────────────────────────

set -e
[ -f artisan ] || { echo "ABORT: must run from intake-license repo root (no artisan in $(pwd))"; exit 1; }

echo "=== rename + button restyle starting ==="

# ─────────────────────────────────────────────────────────────────────────────
# 1. Rail button label: "View customer details →" → "View booking notes →"
#    Style: ghost (low contrast) → primary (lime)
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/show.blade.php')
s = p.read_text()
old = '''        @if($appointment->responses->isNotEmpty())
          <button type="button"
                  class="ia-btn ia-btn--ghost ia-btn--sm appt-b-cust-details-btn"
                  style="width:100%;justify-content:center;margin-top:6px">
            View customer details →
          </button>
        @endif'''
new = '''        @if($appointment->responses->isNotEmpty())
          <button type="button"
                  class="ia-btn ia-btn--primary ia-btn--sm appt-b-cust-details-btn"
                  style="width:100%;justify-content:center;margin-top:6px">
            View booking notes →
          </button>
        @endif'''
assert s.count(old) == 1, f"button count={s.count(old)}, expected 1"
p.write_text(s.replace(old, new))
print("OK 1 (rail button: label + primary style)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# 2. Modal title: "Customer details" → "Booking notes"
#    aria-label stays referenced by the existing aria-labelledby attribute.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/show.blade.php')
s = p.read_text()
old = '''      <h2 class="appt-b-cust-modal-title" id="appt-b-cust-modal-title">Customer details</h2>'''
new = '''      <h2 class="appt-b-cust-modal-title" id="appt-b-cust-modal-title">Booking notes</h2>'''
assert s.count(old) == 1, f"modal-title count={s.count(old)}, expected 1"
p.write_text(s.replace(old, new))
print("OK 2 (modal title)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# 3. Update the relocate-marker comment in the main column for traceability.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/show.blade.php')
s = p.read_text()
old = '''    {{-- LAYOUT-B-CUSTDETAIL-MOVED v1: Customer details now render in the modal at end of page --}}'''
new = '''    {{-- LAYOUT-B-CUSTDETAIL-MOVED v1: Booking notes (intake responses) now render in the modal at end of page --}}'''
assert s.count(old) == 1, f"marker-comment count={s.count(old)}, expected 1"
p.write_text(s.replace(old, new))
print("OK 3 (relocate-marker comment updated)")
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

verify        "resources/views/tenant/appointments/show.blade.php" "View booking notes →"          "rail button label"
verify        "resources/views/tenant/appointments/show.blade.php" "ia-btn--primary ia-btn--sm appt-b-cust-details-btn" "primary class"
verify        "resources/views/tenant/appointments/show.blade.php" ">Booking notes</h2>"           "modal title"
verify_absent "resources/views/tenant/appointments/show.blade.php" "View customer details →"       "old button label gone"
verify_absent "resources/views/tenant/appointments/show.blade.php" "ia-btn--ghost ia-btn--sm appt-b-cust-details-btn" "old ghost class gone"
verify_absent "resources/views/tenant/appointments/show.blade.php" ">Customer details</h2>"       "old modal title gone"

if [ "$fail" -ne 0 ]; then
  echo ""
  echo "✗ FAIL — STOP, do not commit"
  exit 1
fi

echo ""
echo "✓ all markers green"
echo ""
echo "Next:"
echo "  git add -A && git commit -m 'rename Customer details → Booking notes; promote button to primary'"
echo "  git push"
echo "  Server: git pull && php artisan view:clear && \\"
echo "    sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm"
echo ""
echo "=== rename complete ==="
