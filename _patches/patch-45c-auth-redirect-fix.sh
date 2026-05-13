#!/bin/bash
# ============================================================================
# patch-45c-auth-redirect-fix.sh
# ----------------------------------------------------------------------------
# Bug: patch #43's render hook catches AuthenticationException because it
# doesn't have getStatusCode() so defaults to status=500. Result: visiting
# /admin while logged-out shows a 500 page instead of redirecting to login.
#
# Fix: add early bail-out for AuthenticationException + a few other framework
# exceptions that should redirect, not error.
# ============================================================================

set -euo pipefail
cd "${REPO_ROOT:-$(pwd)}"

if [ ! -f "bootstrap/app.php" ]; then
  echo "ERROR: not in project root" >&2
  exit 1
fi

python3 <<'PYEOF'
from pathlib import Path
p = Path("bootstrap/app.php")
s = p.read_text()

marker = "// 500 error reference ID (patch #43)"
if "patch #45c" in s:
    print("    ✓ already patched")
    raise SystemExit(0)
if marker not in s:
    raise SystemExit("ABORT: render hook anchor not found")

# Replace the status-check block with a version that also bails for
# AuthenticationException + AuthorizationException + ValidationException.
old = """            // Only intercept 5xx-class errors. Symfony HttpException carries
            // its own status code; other Throwables default to 500.
            $status = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
            if ($status < 500 || $status > 599) {
                return null; // let Laravel render normally (404, 419, etc.)
            }"""

new = """            // patch #45c: bail early for exceptions that Laravel handles
            // natively with redirects (auth, validation). Without this, the
            // render hook below would catch AuthenticationException and show
            // a 500 page instead of redirecting to login.
            if ($e instanceof \\Illuminate\\Auth\\AuthenticationException) return null;
            if ($e instanceof \\Illuminate\\Auth\\Access\\AuthorizationException) return null;
            if ($e instanceof \\Illuminate\\Validation\\ValidationException) return null;
            if ($e instanceof \\Illuminate\\Session\\TokenMismatchException) return null;

            // Only intercept 5xx-class errors. Symfony HttpException carries
            // its own status code; other Throwables default to 500.
            $status = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
            if ($status < 500 || $status > 599) {
                return null; // let Laravel render normally (404, 419, etc.)
            }"""

if s.count(old) != 1:
    raise SystemExit(f"ABORT: status-check anchor count = {s.count(old)}")

s = s.replace(old, new, 1)
p.write_text(s)
print("    UPDATED bootstrap/app.php — auth/validation exceptions now redirect")
PYEOF

cat <<EONOTE

==> Patch 45c applied locally.

Deploy:
  git add bootstrap/app.php
  git commit -m "fix: let auth/validation exceptions redirect instead of 500 (patch 45c)"
  git push

On server:
  cd /var/www/intake
  git pull
  php artisan optimize:clear
  sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm

Smoke test:
  - intake.works/admin (logged out) → should redirect to login, NOT show 500
  - intake.works/this-does-not-exist → should still show 404 page
  - intake.works/admin (logged in) → should load Filament admin
EONOTE
