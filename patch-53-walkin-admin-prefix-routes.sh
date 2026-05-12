#!/bin/bash
# ============================================================================
# patch-53-walkin-admin-prefix-routes.sh
# ----------------------------------------------------------------------------
# Root cause of why no times were showing, why eligible-resources silently
# failed (fallback hid it), and likely why customer search would have failed
# too if anyone tried it.
#
# All tenant admin routes are defined under Route::prefix('admin'). The
# walk-in SPA was calling them without the /admin prefix, so every fetch
# returned a 404 "route not found" HTML page (or JSON), the SPA's not-ok
# branch fired, and various fallbacks masked the underlying issue.
#
# Routes the SPA fetches:
#   /customers/search             → /admin/customers/search
#   /customers (POST create)      → /admin/customers
#   /appointments/eligible-resources → /admin/appointments/eligible-resources
#   /appointments/week-times      → /admin/appointments/week-times
#   /calendar/quick-book (POST)   → /admin/calendar/quick-book
#
# Already correct: ROUTE_REGISTER = /admin/register
#                  ROUTE_APPT_BASE = /admin/appointments
# ============================================================================

set -euo pipefail
cd "${REPO_ROOT:-$(pwd)}"

python3 <<'PYEOF'
from pathlib import Path
p = Path("resources/views/tenant/walkin/index.blade.php")
s = p.read_text()

old = """  // Routes
  const ROUTE_SEARCH      = `/customers/search`;
  const ROUTE_CREATE_CUST = `/customers`;
  const ROUTE_AVAILABILITY = `/appointments/week-times`;
  const ROUTE_ELIGIBLE    = `/appointments/eligible-resources`;
  const ROUTE_BOOK        = `/calendar/quick-book`;
  const ROUTE_REGISTER    = `/admin/register`;
  const ROUTE_APPT_BASE   = `/admin/appointments`;"""

new = """  // Routes — all under /admin prefix (matches Route::prefix('admin') in web.php)
  const ROUTE_SEARCH      = `/admin/customers/search`;
  const ROUTE_CREATE_CUST = `/admin/customers`;
  const ROUTE_AVAILABILITY = `/admin/appointments/week-times`;
  const ROUTE_ELIGIBLE    = `/admin/appointments/eligible-resources`;
  const ROUTE_BOOK        = `/admin/calendar/quick-book`;
  const ROUTE_REGISTER    = `/admin/register`;
  const ROUTE_APPT_BASE   = `/admin/appointments`;"""

if "ROUTE_AVAILABILITY = `/admin/appointments/week-times`" in s:
    print("    SKIP — routes already prefixed with /admin")
elif old not in s:
    raise SystemExit("ABORT: routes block anchor not found")
elif s.count(old) != 1:
    raise SystemExit(f"ABORT: anchor count = {s.count(old)}")
else:
    s = s.replace(old, new, 1)
    p.write_text(s)
    print("    UPDATED — 4 routes prefixed with /admin (search, create-cust, availability, eligible, book)")
PYEOF

cat <<EONOTE

==> Patch 53 applied locally.

Deploy:
  git add resources/views/tenant/walkin/index.blade.php patch-53-walkin-admin-prefix-routes.sh
  git commit -m "fix: walk-in SPA routes need /admin prefix (patch 53)"
  git push

On server:
  cd /var/www/intake
  git pull
  php artisan view:clear
  sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm

Verify on phone — should now see real time slots from the server, not just
debug boxes about 404s. The debug output (patch 51) is still in place; once
times appear, run patch 54 (next) to revert the debug.

After patch 53, the full walk-in flow should land an appointment end-to-end.
EONOTE
