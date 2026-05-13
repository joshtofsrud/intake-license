#!/bin/bash
# ============================================================================
# patch-52-walkin-bottom-bar-zindex-fix.sh
# ----------------------------------------------------------------------------
# Bug: walk-in Continue / Confirm buttons live in a `position: fixed`
# `.wi-bottom` strip at z-index 80. The tenant mobile-nav (Home/Schedule/
# Customers/More tab bar) is also position:fixed at the bottom with z-index
# 100 and ~72px height. So the mobile-nav was sitting ON TOP of the walk-in
# button, hiding it entirely.
#
# Symptoms: resource picker step (and time step) had no visible Continue
# button. Tapping a resource highlighted it but no way to proceed.
#
# Fix:
#   1. Raise .wi-bottom z-index to 110 (above mobile-nav's 100).
#   2. Lift .wi-bottom bottom-offset by mobile-nav height (72px) on mobile,
#      so the button sits above the tab bar rather than behind it.
# ============================================================================

set -euo pipefail
cd "${REPO_ROOT:-$(pwd)}"

python3 <<'PYEOF'
from pathlib import Path
p = Path("resources/views/tenant/walkin/index.blade.php")
s = p.read_text()

old = """  /* Sticky bottom action */
  .wi-bottom {
    position: fixed;
    bottom: env(safe-area-inset-bottom, 0px);
    left: 0; right: 0;
    padding: 14px 16px calc(14px + env(safe-area-inset-bottom, 0px));
    background: rgba(10,10,10,.95);
    backdrop-filter: blur(10px);
    border-top: 1px solid var(--ia-border, rgba(255,255,255,.08));
    z-index: 80;
  }
  @media (min-width: 1024px) {
    .wi-bottom {
      position: sticky;
      bottom: 16px;
      max-width: 560px;
      margin: 0 auto;
      background: transparent;
      backdrop-filter: none;
      border: 0;
    }
  }"""

new = """  /* Sticky bottom action
     z-index must be > 100 to clear the tenant mobile-nav tab bar.
     bottom offset includes the mobile-nav height (72px) so the button
     sits above the tab bar rather than behind it. */
  .wi-bottom {
    position: fixed;
    bottom: calc(72px + env(safe-area-inset-bottom, 0px));
    left: 0; right: 0;
    padding: 14px 16px;
    background: rgba(10,10,10,.95);
    backdrop-filter: blur(10px);
    border-top: 1px solid var(--ia-border, rgba(255,255,255,.08));
    z-index: 110;
  }
  @media (min-width: 1024px) {
    .wi-bottom {
      position: sticky;
      bottom: 16px;
      max-width: 560px;
      margin: 0 auto;
      background: transparent;
      backdrop-filter: none;
      border: 0;
      padding: 14px 16px;
    }
  }"""

if "z-index: 110" in s:
    print("    SKIP — z-index fix already applied")
elif old not in s:
    raise SystemExit("ABORT: .wi-bottom block anchor not found")
elif s.count(old) != 1:
    raise SystemExit(f"ABORT: anchor count = {s.count(old)}")
else:
    s = s.replace(old, new, 1)
    p.write_text(s)
    print("    UPDATED .wi-bottom — z-index 110 + bottom offset 72px")
PYEOF

cat <<EONOTE

==> Patch 52 applied locally.

Deploy:
  git add resources/views/tenant/walkin/index.blade.php patch-52-walkin-bottom-bar-zindex-fix.sh
  git commit -m "fix: walk-in bottom bar z-index above mobile-nav (patch 52)"
  git push

On server:
  cd /var/www/intake
  git pull
  php artisan view:clear
  sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm

Verify on phone:
  1. Tap FAB → pick customer → Book → pick service → resource step shows
  2. EXPECTED: Continue button visible at the bottom, ABOVE the
     Home/Schedule/Customers/More tab bar
  3. Tap a resource → Continue brightens (was opacity .45, now opacity 1)
  4. Tap Continue → time step shows
  5. Confirm booking button on time step should also be visible above the nav

If the bottom bar overlap was also affecting other steps (new customer step
has a Continue button too), this fixes all of them at once.
EONOTE
