#!/bin/bash
# ============================================================================
# patch-46c-walkin-stack-fix.sh
# ----------------------------------------------------------------------------
# Bug: walk-in view used @push('head') but tenant layout exposes
# @stack('styles'). Result: walk-in CSS wasn't loading, page looked
# unstyled (huge icons, no card chrome).
#
# Fix: rename the push from 'head' to 'styles'.
# ============================================================================

set -euo pipefail
cd "${REPO_ROOT:-$(pwd)}"

if [ ! -f "resources/views/tenant/walkin/index.blade.php" ]; then
  echo "ERROR: walk-in blade not found. Run patch 46 first." >&2
  exit 1
fi

python3 <<'PYEOF'
from pathlib import Path
p = Path("resources/views/tenant/walkin/index.blade.php")
s = p.read_text()

if "@push('styles')" in s:
    print("    SKIP — already using 'styles' stack")
    raise SystemExit(0)

old = "@push('head')"
new = "@push('styles')"

if s.count(old) != 1:
    raise SystemExit(f"ABORT: @push('head') anchor count = {s.count(old)}")

s = s.replace(old, new, 1)
p.write_text(s)
print("    UPDATED walkin/index.blade.php — @push('head') -> @push('styles')")
PYEOF

cat <<EONOTE

==> Patch 46c applied locally.

Deploy:
  git add resources/views/tenant/walkin/index.blade.php
  git commit -m "fix: walk-in CSS push to 'styles' stack (patch 46c)"
  git push

On server:
  cd /var/www/intake
  git pull
  php artisan view:clear
  sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm

Verify:
  - tap FAB → walk-in page renders with styling (search bar in card, choice tiles, recent customers list)
EONOTE
