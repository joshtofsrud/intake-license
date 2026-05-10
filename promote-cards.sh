#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Layout B — promote relocated cards in main column.
#
# Currently, Resource picker / Capacity slots / Payment ledger sit at the
# BOTTOM of main, below Notes. They should be higher in priority.
#
# Approach: don't move DOM (existing JS hooks bind to specific positions/IDs).
# Instead:
#   1. Unwrap the moved-block flex wrapper so its children become direct
#      children of .appt-b-main (which is itself flex column, so gap is now
#      uniform 20px).
#   2. Apply CSS order: N to each card with explicit visual ordering.
#
# Final visual order in main:
#   10  Services
#   20  Resource picker        ← promoted
#   30  Capacity slots         ← promoted
#   40  Products & add-ons
#   50  Work order
#   60  Customer details (intake form)
#   70  Additional charges
#   80  Payment ledger          ← promoted
#   90  Notes
#  9999  Hidden customer / hidden Cancel (display:none anyway, order moot)
# ─────────────────────────────────────────────────────────────────────────────

set -e
[ -f artisan ] || { echo "ABORT: must run from intake-license repo root (no artisan in $(pwd))"; exit 1; }

echo "=== promote relocated cards starting ==="

# ─────────────────────────────────────────────────────────────────────────────
# Step 1 — unwrap the moved-block. Delete the opening wrapper <div> and its
# matching closing </div>{{-- /moved-block --}}.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/show.blade.php')
s = p.read_text()

# Open
old1 = '''    {{-- LAYOUT-B-MOVED-FIX v1 — relocated cards are now children of .appt-b-main --}}
    <div style="display:flex;flex-direction:column;gap:16px;width:100%">

    {{-- Customer card (kept in DOM to avoid breaking any potential JS, hidden in B layout) --}}'''
new1 = '''    {{-- LAYOUT-B-PROMOTE v1 — moved-block unwrapped; cards are direct children of .appt-b-main and ordered via CSS order:N --}}

    {{-- Customer card (kept in DOM to avoid breaking any potential JS, hidden in B layout) --}}'''
assert s.count(old1) == 1, f"unwrap-open count={s.count(old1)}, expected 1"
s = s.replace(old1, new1)

# Close
old2 = '''    @endunless

    </div>{{-- /moved-block --}}

  </div>{{-- /.appt-b-main --}}'''
new2 = '''    @endunless

  </div>{{-- /.appt-b-main --}}'''
assert s.count(old2) == 1, f"unwrap-close count={s.count(old2)}, expected 1"
s = s.replace(old2, new2)

p.write_text(s)
print("OK 1 (moved-block unwrapped)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# Step 2 — apply CSS order to each direct child of .appt-b-main.
# We tag each card with a unique B-PROMOTE-ORDER-N marker so future patches can
# find the anchors. Order numbers chosen with gaps so we can squeeze new sections
# in later without renumbering.
# ─────────────────────────────────────────────────────────────────────────────

# Order 10 — Services (line 409 area)
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/show.blade.php')
s = p.read_text()
old = '''    {{-- Line items --}}
    <div class="ia-card">
      <div class="appt-section-label" style="display:flex;align-items:center;justify-content:space-between">
        <span>Services</span>'''
new = '''    {{-- Line items · LAYOUT-B-PROMOTE-ORDER 10 --}}
    <div class="ia-card" style="order:10">
      <div class="appt-section-label" style="display:flex;align-items:center;justify-content:space-between">
        <span>Services</span>'''
assert s.count(old) == 1, f"order-10 count={s.count(old)}, expected 1"
p.write_text(s.replace(old, new))
print("OK 10 Services")
PY

# Order 20 — Resource picker
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/show.blade.php')
s = p.read_text()
old = '''    <div class="ia-card ia-card--tight" data-appt-resource-card data-appt-id="{{ $appointment->id }}">'''
new = '''    {{-- LAYOUT-B-PROMOTE-ORDER 20 --}}
    <div class="ia-card ia-card--tight" style="order:20" data-appt-resource-card data-appt-id="{{ $appointment->id }}">'''
assert s.count(old) == 1, f"order-20 count={s.count(old)}, expected 1"
p.write_text(s.replace(old, new))
print("OK 20 Resource")
PY

# Order 30 — Capacity slots / slot weight card
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/show.blade.php')
s = p.read_text()
old = '''    {{-- Slot weight --}}
    <div class="ia-card ia-card--tight">
      <div style="font-size:11px;text-transform:uppercase;letter-spacing:.07em;font-weight:500;opacity:.4;margin-bottom:12px">
        Capacity slots
      </div>'''
new = '''    {{-- Slot weight · LAYOUT-B-PROMOTE-ORDER 30 --}}
    <div class="ia-card ia-card--tight" style="order:30">
      <div style="font-size:11px;text-transform:uppercase;letter-spacing:.07em;font-weight:500;opacity:.4;margin-bottom:12px">
        Capacity slots
      </div>'''
assert s.count(old) == 1, f"order-30 count={s.count(old)}, expected 1"
p.write_text(s.replace(old, new))
print("OK 30 Capacity")
PY

# Order 40 — Products & add-ons (parts-card)
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/show.blade.php')
s = p.read_text()
old = '''    <div class="ia-card" id="parts-card">'''
new = '''    {{-- LAYOUT-B-PROMOTE-ORDER 40 --}}
    <div class="ia-card" id="parts-card" style="order:40">'''
assert s.count(old) == 1, f"order-40 count={s.count(old)}, expected 1"
p.write_text(s.replace(old, new))
print("OK 40 Products")
PY

# Order 50 — Work order card
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/show.blade.php')
s = p.read_text()
old = '''    <div class="ia-card" id="work-order-card">'''
new = '''    {{-- LAYOUT-B-PROMOTE-ORDER 50 --}}
    <div class="ia-card" id="work-order-card" style="order:50">'''
assert s.count(old) == 1, f"order-50 count={s.count(old)}, expected 1"
p.write_text(s.replace(old, new))
print("OK 50 Work order")
PY

# Order 60 — Customer details (intake form responses)
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/show.blade.php')
s = p.read_text()
old = '''    <div class="ia-card">
      <div class="appt-section-label">Customer details</div>'''
new = '''    {{-- LAYOUT-B-PROMOTE-ORDER 60 --}}
    <div class="ia-card" style="order:60">
      <div class="appt-section-label">Customer details</div>'''
assert s.count(old) == 1, f"order-60 count={s.count(old)}, expected 1"
p.write_text(s.replace(old, new))
print("OK 60 Customer details")
PY

# Order 70 — Additional charges
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/show.blade.php')
s = p.read_text()
old = '''    <div class="ia-card">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
        <div class="appt-section-label" style="margin-bottom:0">Additional charges</div>'''
new = '''    {{-- LAYOUT-B-PROMOTE-ORDER 70 --}}
    <div class="ia-card" style="order:70">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
        <div class="appt-section-label" style="margin-bottom:0">Additional charges</div>'''
assert s.count(old) == 1, f"order-70 count={s.count(old)}, expected 1"
p.write_text(s.replace(old, new))
print("OK 70 Additional charges")
PY

# Order 80 — Payment ledger.
# Two-step in one heredoc: (1) tag the {{-- Payment ledger --}} comment with the marker,
# (2) walk backward from the "Payment" section-label to find the wrapping ia-card div
# and inject style="order:80".
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/show.blade.php')
s = p.read_text()

# (1) Tag the comment so we have a verifiable marker.
old1 = '    {{-- Payment ledger --}}'
new1 = '    {{-- Payment ledger · LAYOUT-B-PROMOTE-ORDER 80 (order applies to the wrapping ia-card div below) --}}'
if 'LAYOUT-B-PROMOTE-ORDER 80' not in s:
    assert s.count(old1) == 1, f"order-80 marker count={s.count(old1)}, expected 1"
    s = s.replace(old1, new1)

# (2) Find the wrapping <div class="ia-card ..."> for the Payment section.
needle = '<div class="appt-section-label">Payment</div>'
idx = s.find(needle)
assert idx != -1, "Payment label not found"
back = s[:idx].rfind('<div class="ia-card')
assert back != -1, "Wrapping ia-card not found"
end_of_tag = s.find('>', back)
old_tag = s[back:end_of_tag+1]
if 'order:80' in old_tag:
    print("SKIP 80 wrap (already has order:80)")
else:
    if 'style="' in old_tag:
        new_tag = old_tag.replace('style="', 'style="order:80;', 1)
    else:
        new_tag = old_tag.replace('<div class="ia-card', '<div style="order:80" class="ia-card', 1)
    # Use slice-based replacement (the tag string is not unique in the file).
    s = s[:back] + new_tag + s[end_of_tag+1:]
p.write_text(s)
print("OK 80 Payment")
PY

# Order 90 — Notes
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/show.blade.php')
s = p.read_text()
old = '''    <div class="ia-card">
      <div class="appt-section-label">Notes</div>'''
new = '''    {{-- LAYOUT-B-PROMOTE-ORDER 90 --}}
    <div class="ia-card" style="order:90">
      <div class="appt-section-label">Notes</div>'''
assert s.count(old) == 1, f"order-90 count={s.count(old)}, expected 1"
p.write_text(s.replace(old, new))
print("OK 90 Notes")
PY

# Order 9999 — hidden customer card and hidden cancel button
# These are display:none anyway; setting order pushes them to end so they don't
# accidentally inject between visible cards.
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/show.blade.php')
s = p.read_text()

old1 = '''    {{-- Customer card (kept in DOM to avoid breaking any potential JS, hidden in B layout) --}}
    <div class="ia-card ia-card--tight" style="display:none" aria-hidden="true">'''
new1 = '''    {{-- Customer card · LAYOUT-B-PROMOTE-ORDER 9999 (hidden) --}}
    <div class="ia-card ia-card--tight" style="display:none;order:9999" aria-hidden="true">'''
assert s.count(old1) == 1, f"hidden-cust count={s.count(old1)}, expected 1"
s = s.replace(old1, new1)

old2 = '''      <button type="button" class="ia-btn ia-btn--danger ia-btn--sm appt-cancel-btn appt-cancel-btn-original" style="width:100%">'''
new2 = '''      <button type="button" class="ia-btn ia-btn--danger ia-btn--sm appt-cancel-btn appt-cancel-btn-original" style="width:100%;order:9999">'''
assert s.count(old2) == 1, f"hidden-cancel count={s.count(old2)}, expected 1"
s = s.replace(old2, new2)

p.write_text(s)
print("OK 9999 hidden elements")
PY

# ─────────────────────────────────────────────────────────────────────────────
# Verification
# ─────────────────────────────────────────────────────────────────────────────
echo ""
echo "=== verifying ==="
fail=0
verify_count() {
  local file="$1" needle="$2" expect="$3" label="$4"
  local n
  n=$(grep -c -F -- "$needle" "$file" 2>/dev/null | tr -d '\n' || true)
  : "${n:=0}"
  if [ "${n:-0}" -eq "$expect" ] 2>/dev/null; then
    echo "  ✓ $label  (${n}× = $expect)"
  else
    echo "  ✗ $label MISMATCH (got ${n}, expected $expect)"
    fail=1
  fi
}
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

verify       "resources/views/tenant/appointments/show.blade.php" "LAYOUT-B-PROMOTE v1"  "1 unwrap marker"
verify_count "resources/views/tenant/appointments/show.blade.php" "LAYOUT-B-PROMOTE-ORDER 10" 1 "order 10"
verify_count "resources/views/tenant/appointments/show.blade.php" "LAYOUT-B-PROMOTE-ORDER 20" 1 "order 20"
verify_count "resources/views/tenant/appointments/show.blade.php" "LAYOUT-B-PROMOTE-ORDER 30" 1 "order 30"
verify_count "resources/views/tenant/appointments/show.blade.php" "LAYOUT-B-PROMOTE-ORDER 40" 1 "order 40"
verify_count "resources/views/tenant/appointments/show.blade.php" "LAYOUT-B-PROMOTE-ORDER 50" 1 "order 50"
verify_count "resources/views/tenant/appointments/show.blade.php" "LAYOUT-B-PROMOTE-ORDER 60" 1 "order 60"
verify_count "resources/views/tenant/appointments/show.blade.php" "LAYOUT-B-PROMOTE-ORDER 70" 1 "order 70"
verify_count "resources/views/tenant/appointments/show.blade.php" "LAYOUT-B-PROMOTE-ORDER 80" 1 "order 80"
verify_count "resources/views/tenant/appointments/show.blade.php" "LAYOUT-B-PROMOTE-ORDER 90" 1 "order 90"
verify_count "resources/views/tenant/appointments/show.blade.php" "LAYOUT-B-PROMOTE-ORDER 9999" 1 "order 9999 (hidden cust)"
verify       "resources/views/tenant/appointments/show.blade.php" 'order:80'  "order:80 inline style"

# Critical: <div balance preserved.
python3 <<'PY'
src = open('resources/views/tenant/appointments/show.blade.php').read()
opens = src.count('<div')
closes = src.count('</div')
import sys
if opens == closes:
    print(f'  ✓ <div balance: {opens}/{closes}')
else:
    print(f'  ✗ <div MISMATCH: {opens}/{closes}')
    sys.exit(1)
PY

if [ "$fail" -ne 0 ]; then
  echo ""
  echo "✗ FAIL — STOP, do not commit"
  exit 1
fi

echo ""
echo "✓ all markers green"
echo ""
echo "Next:"
echo "  git add -A && git commit -m 'layout B: promote Resource/Capacity/Payment in main column via CSS order'"
echo "  git push"
echo "  Server: git pull && php artisan view:clear && \\"
echo "    sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm"
echo ""
echo "=== promote complete ==="
