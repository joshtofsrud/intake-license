#!/bin/bash
# ============================================================================
# patch-46b-walkin-blade-fix.sh
# ----------------------------------------------------------------------------
# Bug: Multi-line PHP array literal inside @json() inside HTML attribute
# breaks Blade's compiler — "Unclosed '[' on line 382".
#
# Fix: Build the array in a @php block first, then @json the variable.
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

if "$custData" in s:
    print("    SKIP — already patched")
    raise SystemExit(0)

old = """      @foreach($recentCustomers as $cust)
        <div class="wi-cust-row"
             data-cust='@json([
               "id" => $cust["id"],
               "name" => $cust["name"] ?: "(no name)",
               "email" => $cust["email"],
               "phone" => $cust["phone"],
             ])'>"""

new = """      @foreach($recentCustomers as $cust)
        @php
          $custData = [
              "id"    => $cust["id"],
              "name"  => $cust["name"] ?: "(no name)",
              "email" => $cust["email"],
              "phone" => $cust["phone"],
          ];
        @endphp
        <div class="wi-cust-row"
             data-cust='{{ json_encode($custData) }}'>"""

if s.count(old) != 1:
    raise SystemExit(f"ABORT: anchor count = {s.count(old)}")

s = s.replace(old, new, 1)
p.write_text(s)
print("    UPDATED walkin/index.blade.php — multi-line @json refactored to @php + json_encode")
PYEOF

cat <<EONOTE

==> Patch 46b applied locally.

Deploy:
  git add resources/views/tenant/walkin/index.blade.php
  git commit -m "fix: walk-in blade parse error (patch 46b)"
  git push

On server:
  cd /var/www/intake
  git pull
  php artisan view:clear
  sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm

Verify:
  - tap FAB on mobile → walk-in start screen loads (no 500)
EONOTE
