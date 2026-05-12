#!/bin/bash
# ============================================================================
# patch-49-walkin-endpoint-and-redirect-fix.sh
# ----------------------------------------------------------------------------
# Two bugs ship together because they're tiny and both block end-to-end:
#
# 1. ROUTE_AVAILABILITY pointed at /calendar/quick-book/availability — a route
#    that doesn't exist. fetch() got Laravel's 404 HTML, !res.ok fired, and
#    the flow silently fell through to the synthesized-slots fallback. The
#    fallback ignores tenant hours and capacity, so any booking made this way
#    could land outside business hours or double-book existing appointments.
#
#    Fix: point at /appointments/week-times (which already exists, takes
#    service_id + resource_id + start_date, and returns the right slot shape).
#    Rename the param from service_item_id to service_id and pass today as
#    start_date.
#
# 2. Booking-success redirect read json.appointment_id || json.id from
#    QuickBookController's response. The controller actually returns
#    { success: true, appointment: { id, ra_number } }, so apptId resolved
#    to undefined and the flow fell through to /admin/calendar instead of
#    the new appointment's detail page.
#
#    Fix: read json.appointment?.id || json.id.
#
# Both fixes are one-line. After this, end-to-end booking should land in
# the DB on the right resource with a real time slot and redirect to the
# appointment detail. Resource picker UX is patch 50.
# ============================================================================

set -euo pipefail
cd "${REPO_ROOT:-$(pwd)}"

if [ ! -f "resources/views/tenant/walkin/index.blade.php" ]; then
  echo "ERROR: walk-in blade not found." >&2
  exit 1
fi

python3 <<'PYEOF'
from pathlib import Path
p = Path("resources/views/tenant/walkin/index.blade.php")
s = p.read_text()

# ─── Fix 1a: ROUTE_AVAILABILITY URL ──────────────────────────────────────
old_route = "const ROUTE_AVAILABILITY = `/calendar/quick-book/availability`;"
new_route = "const ROUTE_AVAILABILITY = `/appointments/week-times`;"
if old_route not in s:
    if new_route in s:
        print("    SKIP fix-1a — ROUTE_AVAILABILITY already updated")
    else:
        raise SystemExit("ABORT fix-1a: ROUTE_AVAILABILITY anchor not found")
elif s.count(old_route) != 1:
    raise SystemExit(f"ABORT fix-1a: anchor count = {s.count(old_route)}")
else:
    s = s.replace(old_route, new_route, 1)
    print("    UPDATED ROUTE_AVAILABILITY → /appointments/week-times")

# ─── Fix 1b: param name service_item_id → service_id, add start_date ─────
old_params = """      const params = new URLSearchParams({
        service_item_id: state.service.id,
        resource_id: $('#wiResourceSelectReal').value || '',
      });"""
new_params = """      const params = new URLSearchParams({
        service_id: state.service.id,
        resource_id: $('#wiResourceSelectReal').value || '',
        start_date: new Date().toISOString().slice(0, 10),
      });"""
if old_params not in s:
    if "service_id: state.service.id" in s and "start_date:" in s:
        print("    SKIP fix-1b — params already updated")
    else:
        raise SystemExit("ABORT fix-1b: params anchor not found")
elif s.count(old_params) != 1:
    raise SystemExit(f"ABORT fix-1b: anchor count = {s.count(old_params)}")
else:
    s = s.replace(old_params, new_params, 1)
    print("    UPDATED availability params (service_item_id → service_id, +start_date)")

# ─── Fix 2: booking redirect reads json.appointment.id ───────────────────
old_redirect = "const apptId = json.appointment_id || json.id;"
new_redirect = "const apptId = (json.appointment && json.appointment.id) || json.appointment_id || json.id;"
if old_redirect not in s:
    if "json.appointment && json.appointment.id" in s:
        print("    SKIP fix-2 — redirect already updated")
    else:
        raise SystemExit("ABORT fix-2: redirect anchor not found")
elif s.count(old_redirect) != 1:
    raise SystemExit(f"ABORT fix-2: anchor count = {s.count(old_redirect)}")
else:
    s = s.replace(old_redirect, new_redirect, 1)
    print("    UPDATED booking redirect → reads json.appointment.id first")

p.write_text(s)
print("    WROTE resources/views/tenant/walkin/index.blade.php")
PYEOF

cat <<EONOTE

==> Patch 49 applied locally.

Deploy:
  git add resources/views/tenant/walkin/index.blade.php patch-49-walkin-endpoint-and-redirect-fix.sh
  git commit -m "fix: walk-in points at real availability endpoint + reads appointment.id from booking response (patch 49)"
  git push

On server:
  cd /var/www/intake
  git pull
  php artisan view:clear
  sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm

Verify end-to-end on phone:
  1. Tap FAB → walk-in page renders
  2. Tap a recent customer (or search → pick result)
  3. Choose "Book appointment"
  4. Pick a service
  5. Time picker: confirm slots are REAL (look like tenant hours, not "every 30 min from now")
     - If the times look fake (every 30 min, ignoring shop hours), the fallback fired —
       open devtools network tab and check /appointments/week-times response
  6. Pick a time → Confirm booking
  7. Verify you land on /admin/appointments/{id} — NOT /admin/calendar
  8. Verify the appointment exists in DB with the right customer + service + time

Known remaining issue (patch 50): if the shop has multiple resources eligible for
the service, the hidden <select> picks the first one silently. No UI to change it.
That's the next patch.
EONOTE
