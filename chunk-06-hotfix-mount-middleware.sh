#!/usr/bin/env bash
# ============================================================================
# Chunk 6 hotfix — mount EnsurePinFresh (skipped due to idempotency bug)
#
# BUG
#   chunk-06-idle-lock.sh STEP 5 used a substring check for "EnsurePinFresh"
#   in routes/web.php to decide whether to mount the middleware. STEP 4
#   (which ran first) added a *comment* in the file mentioning
#   "EnsurePinFresh". STEP 5's check then matched the comment and skipped.
#   The middleware was never actually mounted.
#
# FIX
#   Use a more specific anchor — the exact string of the middleware FQCN
#   in the array — which only appears when the middleware is real.
#
# This is the "substring matching betrays idempotency" pattern from the
# gating handoff, biting again. Banking that as a habit reminder: when
# checking whether a middleware/import is mounted, match the literal
# mount syntax, not the bare class name.
# ============================================================================

set -euo pipefail

APP_ROOT="${INTAKE_APP_ROOT:-/var/www/intake}"
if [ ! -d "$APP_ROOT" ]; then
    if [ -f "./artisan" ] && [ -d "./app/Models" ]; then
        APP_ROOT="$(pwd)"
    else
        echo "ERROR: APP_ROOT '$APP_ROOT' does not exist." >&2
        exit 1
    fi
fi
cd "$APP_ROOT"

echo "=========================================="
echo "Chunk 6 hotfix — mount EnsurePinFresh"
echo "Running in: $(pwd)"
echo "=========================================="

python3 <<'PY'
from pathlib import Path
p = Path('routes/web.php')
s = p.read_text()

mount_line = "'App\\Http\\Middleware\\EnsurePinFresh',"
if mount_line in s:
    print("STEP 1: SKIP (EnsurePinFresh is already mounted)")
else:
    old = """        Route::middleware([
            'App\\Http\\Middleware\\ConsumeOnboardingToken',
            'App\\Http\\Middleware\\EnsureTrustedDevice',
            'App\\Http\\Middleware\\RequireTenantAuth',
            'App\\Http\\Middleware\\ApplyTenantTheme',
        ])->group(function () {"""

    new = """        Route::middleware([
            'App\\Http\\Middleware\\ConsumeOnboardingToken',
            'App\\Http\\Middleware\\EnsureTrustedDevice',
            'App\\Http\\Middleware\\RequireTenantAuth',
            'App\\Http\\Middleware\\EnsurePinFresh',
            'App\\Http\\Middleware\\ApplyTenantTheme',
        ])->group(function () {"""

    if s.count(old) != 1:
        print(f"STEP 1: ABORT (anchor matches {s.count(old)} times)")
        raise SystemExit(1)
    s = s.replace(old, new)
    p.write_text(s)
    print("STEP 1: OK (EnsurePinFresh now mounted in middleware stack)")
PY

echo ""
echo "----------------------------------------"
echo "VERIFY: middleware stack now correct"
echo "----------------------------------------"
grep -n "EnsurePinFresh\|RequireTenantAuth" routes/web.php | head -5

echo ""
echo "=========================================="
echo "Hotfix complete."
echo ""
echo "Server steps:"
echo "  git pull && \\"
echo "  php artisan optimize:clear && \\"
echo "  systemctl stop php8.3-fpm && sleep 2 && systemctl start php8.3-fpm"
echo "=========================================="
