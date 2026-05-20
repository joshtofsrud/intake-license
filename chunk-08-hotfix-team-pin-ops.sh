#!/usr/bin/env bash
# ============================================================================
# Chunk 8 hotfix — add PIN ops to TeamController::update
#
# BUG
#   chunk-08-admin-screens.sh STEP 7 used a `case 'toggle_active':` anchor
#   on the assumption that update() was a switch/match. The actual
#   implementation is an `if`-cascade, so STEP 7 aborted and STEP 8
#   (validation rule update) was skipped — which actually was correct,
#   since this codebase doesn't formally validate the `op` field.
#
# FIX
#   Insert pin_unlock + pin_force_reset as new `if` blocks after the
#   toggle_active block, matching the existing style.
#
# IDEMPOTENT.
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
echo "Chunk 8 hotfix — TeamController PIN ops"
echo "Running in: $(pwd)"
echo "=========================================="

python3 <<'PY'
from pathlib import Path
p = Path('app/Http/Controllers/Tenant/TeamController.php')
s = p.read_text()

if "'pin_force_reset'" in s:
    print("STEP 1: SKIP (PIN ops already in update())")
else:
    old = """        if ($op === 'toggle_active') {
            $member->update(['is_active' => ! $member->is_active]);
            return back()->with('success', $member->is_active ? 'Member reactivated.' : 'Member deactivated.');
        }

        return back();
    }"""

    new = """        if ($op === 'toggle_active') {
            $member->update(['is_active' => ! $member->is_active]);
            return back()->with('success', $member->is_active ? 'Member reactivated.' : 'Member deactivated.');
        }

        if ($op === 'pin_unlock') {
            app(\\App\\Services\\PinService::class)->unlockUser($member, $me);
            return back()->with('success', $member->name . \"'s PIN unlocked.\");
        }

        if ($op === 'pin_force_reset') {
            app(\\App\\Services\\PinService::class)->forceReset($member, $me);
            return back()->with('success', $member->name . ' will set a new PIN on next sign-in.');
        }

        return back();
    }"""

    if s.count(old) != 1:
        print(f"STEP 1: ABORT (anchor matches {s.count(old)} times)")
        raise SystemExit(1)

    s = s.replace(old, new)
    p.write_text(s)
    print("STEP 1: OK (added pin_unlock + pin_force_reset handlers)")
PY

echo ""
echo "----------------------------------------"
echo "VERIFY: PIN ops present"
echo "----------------------------------------"
grep -n "pin_unlock\|pin_force_reset\|PinService" app/Http/Controllers/Tenant/TeamController.php

echo ""
echo "=========================================="
echo "Hotfix complete."
echo ""
echo "Server steps:"
echo "  git pull && \\"
echo "  php artisan optimize:clear && \\"
echo "  systemctl stop php8.3-fpm && sleep 2 && systemctl start php8.3-fpm"
echo "=========================================="
