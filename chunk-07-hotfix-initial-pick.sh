#!/usr/bin/env bash
# ============================================================================
# Chunk 7 hotfix — skip switch_location gate on initial post-sign-in picker
#
# BUG
#   /admin/select-location (the full-page post-sign-in picker for multi-loc
#   users) was hitting the chunk 7 PIN gate. User signs in via PIN, picks
#   their location, gets 403 JSON dumped as raw page content because the
#   picker form does a plain HTML POST and doesn't intercept the response.
#
# ROOT CAUSE
#   The gate fires whenever pin_tier_active + the action isn't sticky-fresh.
#   It didn't distinguish "first time choosing a location after PIN auth"
#   from "swapping locations mid-session." Both POST to the same endpoint.
#
#   The conceptual fix: a post-sign-in picker has no current_location_id
#   in session yet. A mid-session switch always does. That's the
#   discriminator.
#
# FIX
#   Skip the gate when there's no existing current_location_id in session.
#   Setting the first location after sign-in isn't a switch, it's just
#   completing the sign-in flow.
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
echo "Chunk 7 hotfix — skip gate on initial picker"
echo "Running in: $(pwd)"
echo "=========================================="

python3 <<'PY'
from pathlib import Path
p = Path('app/Http/Controllers/Tenant/AuthController.php')
s = p.read_text()

if "CHUNK-7H initial-pick-skip" in s:
    print("STEP 1: SKIP (hotfix already applied)")
else:
    old = """        // CHUNK-7 switch_location action gate.
        // If pin_tier_active and no recent PIN confirmation for switch_location,
        // require one. The client-side fetch() handler catches the 403 and
        // re-submits with the pin field after the user enters it.
        $gate = app(\\App\\Services\\PinGateService::class);
        if ($gate->requirePin($request, 'switch_location')) {"""

    new = """        // CHUNK-7 switch_location action gate.
        // If pin_tier_active and no recent PIN confirmation for switch_location,
        // require one. The client-side fetch() handler catches the 403 and
        // re-submits with the pin field after the user enters it.
        //
        // CHUNK-7H initial-pick-skip — but NOT on the post-sign-in picker.
        // If there's no current_location_id in session yet, this is the
        // first location selection after sign-in (the user just PIN'd in
        // seconds ago). Asking them to re-PIN immediately is theater.
        // A real mid-session switch always has an existing location.
        $isInitialPick = ! $request->session()->has('current_location_id');
        $gate = app(\\App\\Services\\PinGateService::class);
        if (! $isInitialPick && $gate->requirePin($request, 'switch_location')) {"""

    if s.count(old) != 1:
        print(f"STEP 1: ABORT (anchor matches {s.count(old)} times)")
        raise SystemExit(1)

    s = s.replace(old, new)
    p.write_text(s)
    print("STEP 1: OK (gate now skips when no current_location_id in session)")
PY

echo ""
echo "----------------------------------------"
echo "VERIFY"
echo "----------------------------------------"
grep -n "CHUNK-7H\|isInitialPick" app/Http/Controllers/Tenant/AuthController.php

echo ""
echo "=========================================="
echo "Hotfix complete."
echo ""
echo "Server steps:"
echo "  git pull && \\"
echo "  php artisan optimize:clear && \\"
echo "  systemctl stop php8.3-fpm && sleep 2 && systemctl start php8.3-fpm"
echo ""
echo "Verify on thebikehub.intake.works:"
echo "  1. Sign out fully."
echo "  2. Sign back in via /admin/switch with your PIN."
echo "  3. Land on /admin/select-location (because you have 2+ locations)."
echo "  4. Pick a location — should redirect straight to dashboard. NO modal,"
echo "     NO JSON dump."
echo "  5. From the dashboard, click the sidebar location pill, pick"
echo "     the other location — THIS should show the PIN modal."
echo "=========================================="
