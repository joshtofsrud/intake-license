#!/bin/bash
# ============================================================================
# patch-64-sidebar-logo-theme-b-fix.sh
# ----------------------------------------------------------------------------
# Fixes the sidebar logo on theme-b (light theme).
#
# Bug: The Blade hint in _sidebar.blade.php tells ColorHelper::pickLogo() that
# theme-b's sidebar is white (#ffffff). pickLogo then returns the dark-on-
# light logo. But theme-b's actual sidebar is dark slate (#1E2A3A after patch
# 63; was #0f0f0f before). Result: dark logo on dark sidebar — invisible.
#
# Fix: Change the theme-b branch of $sidebarBg from '#ffffff' to '#1E2A3A'
# (matches theme-b.css --ia-side-bg). Themes a and c are unchanged.
#
# Files touched:
#   - resources/views/layouts/tenant/_sidebar.blade.php  (1 char-level change)
# ============================================================================

set -euo pipefail
cd "${REPO_ROOT:-$(pwd)}"

if [ ! -f "resources/views/layouts/tenant/_sidebar.blade.php" ]; then
  echo "ERROR: _sidebar.blade.php not found." >&2
  exit 1
fi

python3 <<'PYEOF'
from pathlib import Path
p = Path("resources/views/layouts/tenant/_sidebar.blade.php")
s = p.read_text()

old = "$sidebarBg = ($adminTheme === 'c') ? '#0c0c0c' : (($adminTheme === 'a') ? '#0f0f0f' : '#ffffff');"
new = "$sidebarBg = ($adminTheme === 'c') ? '#0c0c0c' : (($adminTheme === 'a') ? '#0f0f0f' : '#1E2A3A');"

if "'#1E2A3A'" in s and "sidebarBg" in s:
    print("    SKIP — sidebar logo already aware of slate theme-b")
elif old not in s:
    raise SystemExit("ABORT: sidebarBg anchor not found")
else:
    s = s.replace(old, new, 1)
    p.write_text(s)
    print("    UPDATED — theme-b sidebar bg hint: #ffffff → #1E2A3A")
PYEOF

cat <<EONOTE

==> Patch 64 applied locally.

Deploy:
  mv patch-64-sidebar-logo-theme-b-fix.sh _patches/
  git add resources/views/layouts/tenant/_sidebar.blade.php \\
          _patches/patch-64-sidebar-logo-theme-b-fix.sh
  git commit -m "fix: theme-b sidebar logo uses light variant (patch 64)"
  git push

On server:
  cd /var/www/intake
  git pull
  php artisan view:clear
  sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm

Verify on Mountainview Fitness (theme-b tenant with two uploaded logos):
  - Sidebar logo should now show the WHITE/light variant (the one with light
    bars + 'MOUNTAINVIEW FITNESS' in light text)
  - Previously showed the dark variant which was invisible on the dark slate
    sidebar

Tenants who only uploaded one logo (default/dark only): unchanged — pickLogo
falls back to whichever logo exists.
EONOTE
