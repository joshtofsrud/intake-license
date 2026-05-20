#!/usr/bin/env bash
# ============================================================================
# Auth Refactor — Chunk 8.1
# Wire per-tenant security policy reads
#
# CONTEXT
#   Chunk 8 added a Security admin page that saves per-tenant policy
#   overrides to tenant.settings.security. But the four read sites in
#   the auth flow (EnsurePinFresh, PinGateController, PinGateService,
#   DeviceTrustService) still read config('intake.auth.*'). So the
#   saved values appear in the form but aren't enforced.
#
#   This chunk introduces TenantAuthPolicy as the single source of truth.
#   All four read sites delegate to it. Falls back to config() when
#   the tenant has no override saved.
#
# WHAT THIS PATCH ADDS
#   1. TenantAuthPolicy helper class with four static methods:
#        idleThresholdSec($tenant)
#        deviceTrustExpiryDays($tenant)
#        actionStickySec($tenant, $action)
#        heartbeatIntervalSec($tenant)
#
#   2. EnsurePinFresh, PinGateController, PinGateService updated to use
#      TenantAuthPolicy instead of config() directly.
#
#   3. DeviceTrustService - both mint() and touch() now read the per-tenant
#      expiry. The COOKIE_MINUTES const stays (cookie lifetime is browser-
#      side; using the longest possible value is safe because the server
#      still validates against expires_at on every request).
#
#   4. Removes the "values save but aren't enforced" warning note from
#      the Security settings view.
#
# WHAT THIS PATCH DOES NOT DO
#   - Doesn't backfill expires_at on existing trusted_device rows when
#     a tenant tightens the expiry policy. New trust mints + touches use
#     the new value; old rows ride out their original expires_at. Owners
#     can use Revoke All to retroactively kill devices.
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
echo "Auth Refactor — Chunk 8.1 (per-tenant policy reads)"
echo "Running in: $(pwd)"
echo "=========================================="

# ----------------------------------------------------------------------------
# STEP 1 — TenantAuthPolicy helper
# ----------------------------------------------------------------------------

HELPER_FILE=app/Services/TenantAuthPolicy.php

if [ -f "$HELPER_FILE" ]; then
    echo "STEP 1: SKIP (TenantAuthPolicy already exists)"
else
    cat > "$HELPER_FILE" <<'PHP'
<?php

namespace App\Services;

use App\Models\Tenant;

/**
 * TenantAuthPolicy
 *
 * Single source of truth for auth policy values that can be overridden per
 * tenant via the Security admin page (chunk 8). Every read site in the auth
 * flow delegates to this helper instead of reading config() directly.
 *
 * Storage: tenant.settings.security.<key>. Falls back to config('intake.auth.*')
 * when the tenant has no override.
 *
 * Why a static helper instead of an instance service: these are simple lookups
 * with no state. A static helper is cheaper to call from hot-path middleware
 * (no container resolve per request) and the call site reads cleaner:
 *   TenantAuthPolicy::idleThresholdSec($tenant)  vs
 *   app(TenantAuthPolicy::class)->idleThresholdSec($tenant)
 */
class TenantAuthPolicy
{
    /**
     * Idle threshold in seconds before the lock overlay fires.
     */
    public static function idleThresholdSec(?Tenant $tenant): int
    {
        $default = (int) config('intake.auth.pin_idle_threshold_sec', 120);

        if (! $tenant) {
            return $default;
        }

        $value = data_get($tenant->settings, 'security.pin_idle_threshold_sec');
        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * How long a "Trust this device" cookie stays valid (in days), sliding
     * window. Used by DeviceTrustService::mint() and ::touch() to set
     * expires_at on the trusted_devices row.
     */
    public static function deviceTrustExpiryDays(?Tenant $tenant): int
    {
        $default = (int) config('intake.auth.device_trust_expiry_days', 90);

        if (! $tenant) {
            return $default;
        }

        $value = data_get($tenant->settings, 'security.device_trust_expiry_days');
        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Sticky window for a sensitive-action gate, in seconds. 0 means
     * "always prompt." Each action has its own value.
     *
     * Today only switch_location is wired and is stored under
     * security.switch_location_sticky_sec. Future actions add their own
     * settings keys.
     */
    public static function actionStickySec(?Tenant $tenant, string $action): int
    {
        $defaults = config('intake.auth.pin_action_sticky_sec', []);
        $default = (int) ($defaults[$action] ?? 0);

        if (! $tenant) {
            return $default;
        }

        // Per-action key in settings.security.<action>_sticky_sec.
        $settingsKey = 'security.' . $action . '_sticky_sec';
        $value = data_get($tenant->settings, $settingsKey);
        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Client heartbeat interval. Not currently tenant-tunable (the admin
     * page doesn't expose it); kept here for symmetry and future use.
     */
    public static function heartbeatIntervalSec(?Tenant $tenant): int
    {
        return (int) config('intake.auth.pin_heartbeat_interval_sec', 60);
    }
}
PHP
    echo "STEP 1: OK (created TenantAuthPolicy)"
fi

# ----------------------------------------------------------------------------
# STEP 2 — EnsurePinFresh reads from TenantAuthPolicy
# ----------------------------------------------------------------------------

python3 <<'PY'
from pathlib import Path
p = Path('app/Http/Middleware/EnsurePinFresh.php')
s = p.read_text()

if "TenantAuthPolicy" in s:
    print("STEP 2: SKIP (EnsurePinFresh already uses TenantAuthPolicy)")
else:
    old = "$thresholdSec = (int) config('intake.auth.pin_idle_threshold_sec', 120);"
    new = "$thresholdSec = \\App\\Services\\TenantAuthPolicy::idleThresholdSec($tenant);"

    if s.count(old) != 1:
        print(f"STEP 2: ABORT (anchor matches {s.count(old)} times)")
        raise SystemExit(1)
    s = s.replace(old, new)
    p.write_text(s)
    print("STEP 2: OK (EnsurePinFresh now uses TenantAuthPolicy)")
PY

# ----------------------------------------------------------------------------
# STEP 3 — PinGateController::heartbeat reads from TenantAuthPolicy
# ----------------------------------------------------------------------------

python3 <<'PY'
from pathlib import Path
p = Path('app/Http/Controllers/Tenant/PinGateController.php')
s = p.read_text()

if "TenantAuthPolicy" in s:
    print("STEP 3: SKIP (PinGateController already uses TenantAuthPolicy)")
else:
    old = "$thresholdSec = (int) config('intake.auth.pin_idle_threshold_sec', 120);"
    new = "$thresholdSec = \\App\\Services\\TenantAuthPolicy::idleThresholdSec(app('tenant') ?? null);"

    if s.count(old) != 1:
        print(f"STEP 3: ABORT (anchor matches {s.count(old)} times)")
        raise SystemExit(1)
    s = s.replace(old, new)
    p.write_text(s)
    print("STEP 3: OK (PinGateController::heartbeat uses TenantAuthPolicy)")
PY

# ----------------------------------------------------------------------------
# STEP 4 — PinGateService::requirePin reads from TenantAuthPolicy
# ----------------------------------------------------------------------------

python3 <<'PY'
from pathlib import Path
p = Path('app/Services/PinGateService.php')
s = p.read_text()

if "TenantAuthPolicy" in s:
    print("STEP 4: SKIP (PinGateService already uses TenantAuthPolicy)")
else:
    old = """        $stickyConfig = config('intake.auth.pin_action_sticky_sec', []);
        $stickySec = (int) ($stickyConfig[$action] ?? 0);"""

    new = "        $stickySec = \\App\\Services\\TenantAuthPolicy::actionStickySec($tenant, $action);"

    if s.count(old) != 1:
        print(f"STEP 4: ABORT (anchor matches {s.count(old)} times)")
        raise SystemExit(1)
    s = s.replace(old, new)
    p.write_text(s)
    print("STEP 4: OK (PinGateService::requirePin uses TenantAuthPolicy)")
PY

# ----------------------------------------------------------------------------
# STEP 5 — DeviceTrustService::mint() reads expiry from TenantAuthPolicy
# ----------------------------------------------------------------------------

python3 <<'PY'
from pathlib import Path
p = Path('app/Services/DeviceTrustService.php')
s = p.read_text()

if "TenantAuthPolicy::deviceTrustExpiryDays" in s:
    print("STEP 5a: SKIP (DeviceTrustService::mint already uses TenantAuthPolicy)")
else:
    # mint() — use per-tenant expiry
    old_mint = "'expires_at'        => now()->addDays(self::EXPIRY_DAYS),"
    new_mint = "'expires_at'        => now()->addDays(\\App\\Services\\TenantAuthPolicy::deviceTrustExpiryDays($tenant)),"

    if s.count(old_mint) != 1:
        print(f"STEP 5a: ABORT (mint anchor matches {s.count(old_mint)} times)")
        raise SystemExit(1)
    s = s.replace(old_mint, new_mint)
    p.write_text(s)
    print("STEP 5a: OK (DeviceTrustService::mint uses TenantAuthPolicy)")
PY

# Now touch() — also uses self::EXPIRY_DAYS but doesn't have $tenant in scope.
# It does have $device which has tenant_id. Need to load the tenant.
python3 <<'PY'
from pathlib import Path
p = Path('app/Services/DeviceTrustService.php')
s = p.read_text()

if "TenantAuthPolicy::deviceTrustExpiryDays(\\App\\Models\\Tenant::find" in s:
    print("STEP 5b: SKIP (DeviceTrustService::touch already uses TenantAuthPolicy)")
else:
    old_touch = """        $device->forceFill([
            'last_used_at' => now(),
            'expires_at'   => now()->addDays(self::EXPIRY_DAYS),
            'ip_last_seen' => $request->ip(),
        ])->save();"""

    new_touch = """        // Per-tenant expiry. Resolved fresh per touch — could be cached if
        // it becomes a hot-path concern, but reads of tenant.settings are
        // cheap (already in container via ResolveTenant in the common case).
        $expiryDays = \\App\\Services\\TenantAuthPolicy::deviceTrustExpiryDays(
            app('tenant') ?? \\App\\Models\\Tenant::find($device->tenant_id)
        );

        $device->forceFill([
            'last_used_at' => now(),
            'expires_at'   => now()->addDays($expiryDays),
            'ip_last_seen' => $request->ip(),
        ])->save();"""

    if s.count(old_touch) != 1:
        print(f"STEP 5b: ABORT (touch anchor matches {s.count(old_touch)} times)")
        raise SystemExit(1)
    s = s.replace(old_touch, new_touch)
    p.write_text(s)
    print("STEP 5b: OK (DeviceTrustService::touch uses TenantAuthPolicy)")
PY

# ----------------------------------------------------------------------------
# STEP 6 — Remove the "values save but aren't enforced" note from the
#          Security settings view
# ----------------------------------------------------------------------------

python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/security/index.blade.php')
s = p.read_text()

old = """    <div style=\"margin-top:18px;padding:12px 14px;background:rgba(255,255,255,.03);border:0.5px solid var(--ia-border);border-radius:6px;font-size:11.5px;opacity:.65;line-height:1.5\">
      <strong>Note:</strong> Sign-in policy reads will be wired in a follow-up patch. Until then, these values save successfully but the platform defaults remain in effect.
    </div>"""

if old not in s:
    print("STEP 6: SKIP (note already removed)")
else:
    if s.count(old) != 1:
        print(f"STEP 6: ABORT (anchor matches {s.count(old)} times)")
        raise SystemExit(1)
    s = s.replace(old, "")
    p.write_text(s)
    print("STEP 6: OK (chunk 8 deferred-wiring note removed from settings view)")
PY

# ----------------------------------------------------------------------------
# Verification
# ----------------------------------------------------------------------------

echo ""
echo "----------------------------------------"
echo "VERIFY: new helper"
echo "----------------------------------------"
ls -la "$HELPER_FILE"

echo ""
echo "----------------------------------------"
echo "VERIFY: read sites updated"
echo "----------------------------------------"
echo "EnsurePinFresh:"
grep -n "TenantAuthPolicy" app/Http/Middleware/EnsurePinFresh.php | head -3
echo ""
echo "PinGateController:"
grep -n "TenantAuthPolicy" app/Http/Controllers/Tenant/PinGateController.php | head -3
echo ""
echo "PinGateService:"
grep -n "TenantAuthPolicy" app/Services/PinGateService.php | head -3
echo ""
echo "DeviceTrustService:"
grep -n "TenantAuthPolicy" app/Services/DeviceTrustService.php | head -3

echo ""
echo "----------------------------------------"
echo "VERIFY: stale 'reads will be wired' note removed"
echo "----------------------------------------"
if grep -q "wired in a follow-up patch" resources/views/tenant/security/index.blade.php; then
    echo "  ✗ note STILL present"
else
    echo "  ✓ note removed"
fi

echo ""
echo "=========================================="
echo "Chunk 8.1 application complete."
echo ""
echo "Server steps:"
echo "  git pull && composer install --no-interaction --no-scripts && \\"
echo "  php artisan optimize:clear && \\"
echo "  systemctl stop php8.3-fpm && sleep 2 && systemctl start php8.3-fpm"
echo "  (no migrations, no view changes that need view:clear)"
echo ""
echo "Verify in tinker:"
echo "  >>> \$t = \\App\\Models\\Tenant::where('subdomain','thebikehub')->first();"
echo "  >>> \\App\\Services\\TenantAuthPolicy::idleThresholdSec(\$t)"
echo "  // expect: 120 (platform default — no override saved yet)"
echo ""
echo "  Then visit /admin/security, change idle threshold to e.g. 60, save."
echo ""
echo "  >>> \\App\\Services\\TenantAuthPolicy::idleThresholdSec(\$t->fresh())"
echo "  // expect: 60 (the saved override is now read)"
echo ""
echo "  Real-world verify: change the idle threshold to 30 seconds, save,"
echo "  walk away for 35 seconds — overlay should fire faster than the"
echo "  platform default of 120s."
echo "=========================================="
