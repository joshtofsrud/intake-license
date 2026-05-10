#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Intake — calendar-first locked-slot bug fix
#
# Bug: when user places a slot via calendar-first, then adds a service in the
# modal, scheduleAvailabilityFetch() fires and fetchAvailability() clobbers
# state.selectedSlot with the server's "earliest available" (always Josh
# Tofsrud / first resource). The locked-time pill in the UI still shows the
# original placement, but the submit payload sends the server-overridden values.
#
# Fix: short-circuit scheduleAvailabilityFetch() when state.lockedPrefill is
# set. Single guard, single function, no other call sites need changes.
# ─────────────────────────────────────────────────────────────────────────────

set -e
[ -f artisan ] || { echo "ABORT: must run from intake-license repo root (no artisan found in $(pwd))"; exit 1; }

echo "=== locked-slot fix starting ==="

python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/_create_modal.blade.php')
s = p.read_text()
old = '''  // ── Availability ──
  function scheduleAvailabilityFetch() {
    clearTimeout(availTimer);
    if (state.cart.length === 0) {
      state.availability = null;
      state.selectedSlot = null;
      state.manualOverride = false;
      renderAvailability();
      return;
    }
    state.availLoading = true;
    state.manualOverride = false;
    renderAvailability();
    availTimer = setTimeout(fetchAvailability, 300);
  }'''
new = '''  // ── Availability ──
  function scheduleAvailabilityFetch() {
    clearTimeout(availTimer);
    // CALENDAR-FIRST-LOCK-GUARD v1: when a slot was placed via calendar-first,
    // skip availability lookup entirely. The placed slot is authoritative; we
    // must NOT overwrite state.selectedSlot with the server's "earliest".
    if (state.lockedPrefill) return;
    if (state.cart.length === 0) {
      state.availability = null;
      state.selectedSlot = null;
      state.manualOverride = false;
      renderAvailability();
      return;
    }
    state.availLoading = true;
    state.manualOverride = false;
    renderAvailability();
    availTimer = setTimeout(fetchAvailability, 300);
  }'''
assert s.count(old) == 1, f"anchor count={s.count(old)}, expected 1"
p.write_text(s.replace(old, new))
print("OK locked-slot guard")
PY

# Verification
echo ""
echo "=== verifying patch ==="
fail=0
verify() {
  local file="$1" needle="$2" label="$3"
  local n
  n=$(grep -c -F -- "$needle" "$file" 2>/dev/null || echo 0)
  if [ "$n" -ge 1 ]; then
    echo "  ✓ $label  ($n× in $file)"
  else
    echo "  ✗ MISSING: $label  in $file"
    fail=1
  fi
}

verify "resources/views/tenant/appointments/_create_modal.blade.php" 'CALENDAR-FIRST-LOCK-GUARD v1' "lock guard marker"
verify "resources/views/tenant/appointments/_create_modal.blade.php" 'if (state.lockedPrefill) return;' "lock guard logic"

if [ "$fail" -ne 0 ]; then
  echo ""
  echo "✗ FAIL — patch did not land. Stop."
  exit 1
fi

echo ""
echo "✓ patch verified."
echo ""
echo "Next:"
echo "  git add -A && git commit -m 'fix calendar-first slot clobber by availability fetch'"
echo "  git push"
echo "  On server: git pull && php artisan view:clear && \\"
echo "    sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm"
echo ""
echo "=== fix complete ==="
