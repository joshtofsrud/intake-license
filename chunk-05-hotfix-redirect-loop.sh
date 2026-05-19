#!/usr/bin/env bash
# ============================================================================
# Chunk 5 hotfix — EnsureTrustedDevice redirect loop
#
# BUG
#   When a tenant has pin_tier_active and a user successfully signs in via
#   email+password (without checking "Trust this device"), they land on the
#   dashboard. EnsureTrustedDevice fires, sees no trust cookie, redirects to
#   login. Login screen passes auth, redirects to dashboard. Loop.
#
# ROOT CAUSE
#   EnsureTrustedDevice was demanding a device cookie as a hard prerequisite.
#   It should be one of TWO acceptable paths to identify the browser:
#     (a) trusted device cookie  → long-lived, opt-in
#     (b) active tenant session  → short-lived, every-visit re-auth
#
#   If either is present, pass through. Only redirect to login when BOTH
#   are missing.
#
# FIX
#   Add an early-return: if Auth::guard('tenant')->check() is true, pass
#   through. The downstream RequireTenantAuth middleware is the source of
#   truth for "is a user signed in"; EnsureTrustedDevice should only weigh
#   in for *unauthenticated* requests.
#
# Idempotent.
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
echo "Chunk 5 hotfix — redirect loop"
echo "Running in: $(pwd)"
echo "=========================================="

python3 <<'PY'
from pathlib import Path
p = Path('app/Http/Middleware/EnsureTrustedDevice.php')
s = p.read_text()

if "PATCH-CHUNK-5H session-bypass" in s:
    print("STEP 1: SKIP (already patched)")
else:
    old = """        // Starter + single-user Branded: PIN tier off. Keep existing flow.
        if (! $tenant->pin_tier_active) {
            return $next($request);
        }

        $cookieValue = $request->cookie(DeviceTrustService::COOKIE_NAME);"""

    new = """        // Starter + single-user Branded: PIN tier off. Keep existing flow.
        if (! $tenant->pin_tier_active) {
            return $next($request);
        }

        // PATCH-CHUNK-5H session-bypass — an authenticated tenant session
        // is itself proof of identity for this request. Only enforce the
        // device-trust gate when no session is in play. Without this, a
        // user who signs in via email+password (no trust opt-in) gets a
        // redirect loop: login succeeds → dashboard → middleware sees no
        // cookie → back to login.
        if (\\Illuminate\\Support\\Facades\\Auth::guard('tenant')->check()) {
            // Still touch the device row if a cookie IS present, so its
            // sliding-expiry stays current.
            $cookieValue = $request->cookie(DeviceTrustService::COOKIE_NAME);
            if ($cookieValue) {
                $device = $this->devices->validate($tenant, $cookieValue);
                if ($device) {
                    $this->devices->touch($device, $request);
                    $request->attributes->set('trusted_device', $device);
                }
            }
            return $next($request);
        }

        $cookieValue = $request->cookie(DeviceTrustService::COOKIE_NAME);"""

    if s.count(old) != 1:
        print(f"STEP 1: ABORT (anchor matches {s.count(old)} times)")
        raise SystemExit(1)

    s = s.replace(old, new)
    p.write_text(s)
    print("STEP 1: OK (EnsureTrustedDevice now bypasses for authenticated sessions)")
PY

echo ""
echo "----------------------------------------"
echo "VERIFY: middleware now has session-bypass"
echo "----------------------------------------"
grep -n "PATCH-CHUNK-5H\|Auth::guard('tenant')->check" app/Http/Middleware/EnsureTrustedDevice.php

echo ""
echo "=========================================="
echo "Hotfix complete."
echo ""
echo "Server steps:"
echo "  git pull && \\"
echo "  php artisan optimize:clear && \\"
echo "  systemctl stop php8.3-fpm && sleep 2 && systemctl start php8.3-fpm"
echo ""
echo "Then in your browser:"
echo "  1. Clear cookies for thebikehub.intake.works (the error page said to)"
echo "  2. Visit /admin/login → sign in with email + password"
echo "  3. Lands on dashboard. No loop."
echo "  4. To exercise the switcher: visit /admin/switch directly"
echo "=========================================="
