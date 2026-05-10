#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Intake — Back-button + bfcache spinner + service-label duplicate duration fix
#
# Three small fixes:
#
#   1. Detail-page "← Back" button: drop the history.back() optimization that
#      restores a stale (frozen-spinner) modal state via bfcache. Just hard-
#      navigate to the appointments list every time. Predictable > clever.
#
#   2. List-page modal: add a pageshow listener that detects bfcache restore
#      (event.persisted === true) and closes any open modal + resets submit
#      button state. Defense in depth — also fixes browser-back from any
#      future detail page, not just appointments.
#
#   3. Service dropdown labels: don't append "(N min)" if the service name
#      already contains a "(N min)" pattern. Fixes the "(60 min) (60 min)"
#      duplicate-duration display in the new sequential picker.
# ─────────────────────────────────────────────────────────────────────────────

set -e
[ -f artisan ] || { echo "ABORT: must run from intake-license repo root (no artisan found in $(pwd))"; exit 1; }

echo "=== combined fix patch starting ==="

# ─────────────────────────────────────────────────────────────────────────────
# 1. Detail page Back button — hard nav, no history.back()
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/show.blade.php')
s = p.read_text()
old = '''<a href="{{ route('tenant.appointments.index') }}" class="ia-btn ia-btn--ghost" onclick="if (document.referrer && document.referrer.indexOf(window.location.host) !== -1) { event.preventDefault(); history.back(); }">← Back</a>'''
new = '''<a href="{{ route('tenant.appointments.index') }}" class="ia-btn ia-btn--ghost">← Back</a>'''
assert s.count(old) == 1, f"back-btn count={s.count(old)}, expected 1"
p.write_text(s.replace(old, new))
print("OK 1 (detail Back button → hard nav)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# 2. bfcache reset on list-page modal restore.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/_create_modal.blade.php')
s = p.read_text()
old = '''window.openApptModal  = function () { ApptModal.open(); };
window.closeApptModal = function () { ApptModal.close(); };'''
new = '''window.openApptModal  = function () { ApptModal.open(); };
window.closeApptModal = function () { ApptModal.close(); };

// BFCACHE-MODAL-RESET v1
// When the user navigates back to a page where this modal lives, the browser
// may bfcache-restore the page mid-submit (frozen spinner, modal still open).
// Detect persisted-restore and reset modal + submit button state.
window.addEventListener('pageshow', function (e) {
  if (!e.persisted) return;
  var modal = document.getElementById('new-appt-modal');
  if (modal) modal.style.display = 'none';
  var btn = document.getElementById('appt-submit');
  if (btn) {
    btn.disabled = false;
    btn.innerHTML = 'Create Appointment';
  }
});'''
assert s.count(old) == 1, f"bfcache-reset count={s.count(old)}, expected 1"
p.write_text(s.replace(old, new))
print("OK 2 (bfcache pageshow reset)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# 3. Service dropdown labels — skip duration suffix if name already has one.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/_create_modal.blade.php')
s = p.read_text()
old = '''    state.services.forEach(function (svc) {
      var opt = document.createElement('option');
      opt.value = svc.id;
      var dur = svc.duration_minutes ? ' (' + svc.duration_minutes + ' min)' : '';
      var price = (svc.price_cents != null) ? ' · ' + fmt(svc.price_cents) : '';
      opt.textContent = svc.name + dur + price;
      sel.appendChild(opt);
    });'''
new = '''    state.services.forEach(function (svc) {
      var opt = document.createElement('option');
      opt.value = svc.id;
      // SERVICE-LABEL-DEDUPE v1: skip "(N min)" suffix if the name already has one.
      var nameHasDuration = /\\(\\s*\\d+\\s*min\\s*\\)/i.test(svc.name);
      var dur = (svc.duration_minutes && !nameHasDuration) ? ' (' + svc.duration_minutes + ' min)' : '';
      var price = (svc.price_cents != null) ? ' · ' + fmt(svc.price_cents) : '';
      opt.textContent = svc.name + dur + price;
      sel.appendChild(opt);
    });'''
assert s.count(old) == 1, f"3 dedupe count={s.count(old)}, expected 1"
p.write_text(s.replace(old, new))
print("OK 3 (service label dedupe)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# Verification
# ─────────────────────────────────────────────────────────────────────────────
echo ""
echo "=== verifying patches ==="
fail=0
verify() {
  local file="$1" needle="$2" label="$3"
  local n
  n=$(grep -c -F -- "$needle" "$file" 2>/dev/null | tr -d '\n' || true)
  : "${n:=0}"
  if [ "${n:-0}" -ge 1 ] 2>/dev/null; then
    echo "  ✓ $label  (${n}× in $file)"
  else
    echo "  ✗ MISSING: $label  in $file"
    fail=1
  fi
}
verify_absent() {
  local file="$1" needle="$2" label="$3"
  local n
  n=$(grep -c -F -- "$needle" "$file" 2>/dev/null | tr -d '\n' || true)
  : "${n:=0}"
  if [ "${n:-0}" -eq 0 ] 2>/dev/null; then
    echo "  ✓ ABSENT: $label  (in $file)"
  else
    echo "  ✗ STILL PRESENT: $label  (${n}× in $file)"
    fail=1
  fi
}

verify_absent "resources/views/tenant/appointments/show.blade.php"        "history.back()" "1 history.back() removed"
verify        "resources/views/tenant/appointments/_create_modal.blade.php" "BFCACHE-MODAL-RESET v1" "2 bfcache reset"
verify        "resources/views/tenant/appointments/_create_modal.blade.php" "SERVICE-LABEL-DEDUPE v1" "3 service label dedupe"

if [ "$fail" -ne 0 ]; then
  echo ""
  echo "✗ FAIL"
  exit 1
fi

echo ""
echo "✓ patches verified."
echo ""
echo "Next:"
echo "  git add -A && git commit -m 'fix: detail-back hard-nav + bfcache modal reset + service label dedupe'"
echo "  git push"
echo "  Server: git pull && php artisan view:clear && \\"
echo "    sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm"
echo ""
echo "=== fix complete ==="
