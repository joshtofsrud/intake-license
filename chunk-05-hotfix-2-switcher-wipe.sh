#!/usr/bin/env bash
# ============================================================================
# Chunk 5 hotfix 2 — /admin/switch redirects to dashboard
#
# BUG
#   Visiting /admin/switch while signed in bounces straight to dashboard.
#   StaffSwitchController::index has an early-return that treats "already
#   authenticated" as "no need for the switcher."
#
# ROOT CAUSE
#   Wrong mental model. The switcher isn't "auth fallback when nobody is
#   signed in" — it's the surface staff use to CHANGE who is signed in.
#   Maya hands the iPad to Josh; both are real, valid scenarios that need
#   the switcher.
#
# FIX
#   When /admin/switch is hit while a user is signed in:
#     - Log out the current user (clear the session)
#     - Keep the device-trust cookie intact (NOT logout proper — that
#       revokes the device)
#     - Render the switcher
#
#   This means /admin/switch is now also the "switch staff" endpoint. The
#   sidebar identity block's future "Switch staff" link can just be a
#   plain anchor to /admin/switch.
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
echo "Chunk 5 hotfix 2 — switcher wipes session"
echo "Running in: $(pwd)"
echo "=========================================="

python3 <<'PY'
from pathlib import Path
p = Path('app/Http/Controllers/Tenant/StaffSwitchController.php')
s = p.read_text()

if "PATCH-CHUNK-5H2 wipe-session" in s:
    print("STEP 1: SKIP (already patched)")
else:
    old = """        // If user is already auth'd and PIN is fresh, just go to dashboard.
        // (For now we don't track PIN freshness — chunk 6 adds the idle
        // lock. Today, an auth'd user is auth'd.)
        if (Auth::guard('tenant')->check()) {
            return redirect()->route('tenant.dashboard');
        }"""

    new = """        // PATCH-CHUNK-5H2 wipe-session — /admin/switch is both the
        // initial-PIN-entry surface AND the "switch staff" surface.
        // When someone is already signed in and visits here, they want
        // to hand the device to another staff member, NOT bounce home.
        // Wipe the user session (keep the device-trust cookie intact),
        // then render the switcher cards.
        if (Auth::guard('tenant')->check()) {
            Auth::guard('tenant')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }"""

    if s.count(old) != 1:
        print(f"STEP 1: ABORT (anchor matches {s.count(old)} times)")
        raise SystemExit(1)

    s = s.replace(old, new)
    p.write_text(s)
    print("STEP 1: OK (/admin/switch now wipes session instead of redirecting)")
PY

echo ""
echo "----------------------------------------"
echo "VERIFY: switcher now wipes session"
echo "----------------------------------------"
grep -n "PATCH-CHUNK-5H2\|wipe-session\|->logout()" app/Http/Controllers/Tenant/StaffSwitchController.php | head -5

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
echo "  1. Visit /admin/switch (while signed in)."
echo "  2. You should now see the staff card grid with both users."
echo "  3. Click your card → since you have no pin_hash yet, you'll get"
echo "     the set-initial-PIN form."
echo "  4. Set a PIN, confirm, enter your account password → dashboard."
echo "=========================================="
