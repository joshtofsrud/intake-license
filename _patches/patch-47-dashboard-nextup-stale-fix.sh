#!/bin/bash
# ============================================================================
# patch-47-dashboard-nextup-stale-fix.sh
# ----------------------------------------------------------------------------
# Bug: Dashboard "Next up" card shows the morning's first appointment after
# it's already completed. DashboardDataService had a fallback that, when no
# future appointments remained today, defaulted to the first appointment of
# the day — which is past and complete by evening.
#
# Fix: Drop the fallback. If no future appointment today, return null. The
# Blade already guards with @if($nu) so the card simply hides.
# ============================================================================

set -euo pipefail
cd "${REPO_ROOT:-$(pwd)}"

if [ ! -f "app/Services/Tenant/DashboardDataService.php" ]; then
  echo "ERROR: not in project root" >&2
  exit 1
fi

python3 <<'PYEOF'
from pathlib import Path
p = Path("app/Services/Tenant/DashboardDataService.php")
s = p.read_text()

old = """        if (!$nextUp) {
            $nextUp = $todayAppointments->first();
        }"""

new = """        // Patch 47: no fallback to first-of-day. If today's appointments are all
        // in the past, $nextUp stays null and the Blade hides the card. Showing
        // a completed 8am appointment as "Next up" at 9pm is worse than hiding
        // the card entirely. Future: fall through to tomorrow's first appointment.
        // (No fallback assignment — $nextUp may legitimately be null.)"""

if s.count(old) != 1:
    if "Patch 47" in s:
        print("    SKIP — already patched")
        raise SystemExit(0)
    raise SystemExit(f"ABORT: anchor count = {s.count(old)}")

s = s.replace(old, new, 1)
p.write_text(s)
print("    UPDATED DashboardDataService.php — removed stale first-of-day fallback")
PYEOF

cat <<EONOTE

==> Patch 47 applied locally.

Deploy:
  git add app/Services/Tenant/DashboardDataService.php
  git commit -m "fix: hide Next-up card when no future appointments today (patch 47)"
  git push

On server:
  cd /var/www/intake
  git pull
  php artisan optimize:clear
  sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm

Verify:
  - dashboard at 9pm with no future appointments today → Next-up card is hidden
  - dashboard at 8am with later appointments today → Next-up shows the next one
EONOTE
