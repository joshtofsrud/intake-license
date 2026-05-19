#!/usr/bin/env bash
# ============================================================================
# Auth Refactor — Chunk 3
# DeviceTrustService + EnsureTrustedDevice middleware + TenantTrustedDevice model
#
# CONTEXT
#   Builds Layer 1 of the auth model (see auth-refactor-spec-v2.md §3).
#   Pure backend — no UI changes, no routing changes, no behavior change in
#   the request lifecycle yet. The pieces sit dark until chunk 4 wires them
#   into AuthController.
#
# WHAT THIS PATCH ADDS
#   1. TenantTrustedDevice model — represents one row in
#      tenant_trusted_devices. Has scopes for "active" (not expired, not
#      revoked) and "for this tenant."
#
#   2. DeviceTrustService — the API for the auth flow to call. Methods:
#        mint($tenant, $request)         → generate token, persist row, return cookie
#        validate($tenant, $cookieValue) → check hash matches, return device or null
#        touch($device, $request)        → bump last_used_at + IP on each request
#        revoke($device, $byUser = null) → mark revoked_at + revoked_by_user_id
#        revokeAllForTenant($tenant)     → owner action: revoke every device
#
#      All methods use SHA-256 hashes for the DB column; plaintext token
#      never written. Cookie value is 64 char random string.
#
#   3. EnsureTrustedDevice middleware — defined but NOT mounted in routes
#      yet. Logic:
#        - If tenant->pin_tier_active is FALSE → pass through (Starter +
#          single-user Branded still use email/password every visit, the
#          existing flow)
#        - If a valid device cookie is present → touch the row, set
#          $request->attributes->set('trusted_device', $device), pass through
#        - Else → redirect to login
#
#      Chunk 4 wires this into the route group AND modifies AuthController
#      to consult it.
#
# IDEMPOTENCY: every file write checks before acting.
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
echo "Auth Refactor — Chunk 3 (DeviceTrustService + middleware)"
echo "Running in: $(pwd)"
echo "=========================================="

# ----------------------------------------------------------------------------
# STEP 1 — TenantTrustedDevice model
# ----------------------------------------------------------------------------

MODEL_FILE=app/Models/Tenant/TenantTrustedDevice.php

if [ -f "$MODEL_FILE" ]; then
    echo "STEP 1: SKIP (model file already exists)"
else
    cat > "$MODEL_FILE" <<'PHP'
<?php

namespace App\Models\Tenant;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TenantTrustedDevice
 *
 * Represents one browser cookie that a tenant user explicitly trusted
 * ("Trust this device" at sign-in). See auth-refactor-spec-v2.md §3.
 *
 * Lookup is by SHA-256 hash of the cookie value — plaintext never reaches
 * the DB. Mint via DeviceTrustService::mint(); validate via ::validate().
 */
class TenantTrustedDevice extends Model
{
    use HasUuids;

    protected $table = 'tenant_trusted_devices';

    protected $fillable = [
        'tenant_id',
        'device_token_hash',
        'label',
        'user_agent_seen',
        'ip_first_seen',
        'ip_last_seen',
        'trusted_at',
        'last_used_at',
        'expires_at',
        'revoked_at',
        'revoked_by_user_id',
    ];

    protected $casts = [
        'trusted_at'   => 'datetime',
        'last_used_at' => 'datetime',
        'expires_at'   => 'datetime',
        'revoked_at'   => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'revoked_by_user_id');
    }

    /**
     * Active = not revoked, not expired.
     */
    public function isActive(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }
        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }
        return true;
    }

    /**
     * Scope: only active devices for a given tenant.
     */
    public function scopeActiveForTenant($query, string $tenantId)
    {
        return $query
            ->where('tenant_id', $tenantId)
            ->whereNull('revoked_at')
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            });
    }
}
PHP
    echo "STEP 1: OK (created TenantTrustedDevice model)"
fi

# ----------------------------------------------------------------------------
# STEP 2 — DeviceTrustService
# ----------------------------------------------------------------------------

SERVICE_FILE=app/Services/DeviceTrustService.php

if [ -f "$SERVICE_FILE" ]; then
    echo "STEP 2: SKIP (service already exists)"
else
    cat > "$SERVICE_FILE" <<'PHP'
<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\Tenant\TenantTrustedDevice;
use App\Models\Tenant\TenantUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * DeviceTrustService
 *
 * The API for Layer 1 of the auth refactor (auth-refactor-spec-v2.md §3).
 *
 * Trust model: when a user opts in at sign-in, we mint a long-lived cookie
 * whose value is a 64-char random string. We persist SHA-256(value) in
 * tenant_trusted_devices, never the value itself. On subsequent requests
 * the EnsureTrustedDevice middleware (chunk 3) hashes the cookie and looks
 * up the row.
 *
 * Expiration: 90-day sliding window. last_used_at is touched on every
 * authenticated request; expires_at is pushed to last_used_at + 90 days.
 *
 * Threats this defends against:
 *   - DB leak: hash makes the cookie unusable without the plaintext
 *   - Cookie theft: standard HttpOnly/Secure/SameSite=Lax mitigations
 *   - Stale devices: 90-day idle expiry + manual revoke
 */
class DeviceTrustService
{
    /** Cookie name. Scoped to .intake.works so all tenants share the same name. */
    public const COOKIE_NAME = 'intake_device_trust';

    /** Sliding-window expiry in days. */
    public const EXPIRY_DAYS = 90;

    /** Cookie lifetime in minutes (matches EXPIRY_DAYS). */
    public const COOKIE_MINUTES = 60 * 24 * 90;

    /**
     * Mint a new trusted-device row + return the cookie value to set.
     *
     * Caller is responsible for actually setting the cookie on the response
     * — this method only generates and persists. Returns the plaintext
     * cookie value (the only time it exists in memory outside the cookie).
     *
     * @return string  The plaintext cookie value to set on the response.
     */
    public function mint(Tenant $tenant, Request $request, ?string $label = null): string
    {
        $plaintext = Str::random(64);
        $hash = hash('sha256', $plaintext);

        TenantTrustedDevice::create([
            'tenant_id'         => $tenant->id,
            'device_token_hash' => $hash,
            'label'             => $label,
            'user_agent_seen'   => substr((string) $request->userAgent(), 0, 1000),
            'ip_first_seen'     => $request->ip(),
            'ip_last_seen'      => $request->ip(),
            'trusted_at'        => now(),
            'last_used_at'      => now(),
            'expires_at'        => now()->addDays(self::EXPIRY_DAYS),
        ]);

        return $plaintext;
    }

    /**
     * Validate a cookie value against the tenant's active trusted devices.
     *
     * Returns the matched device row, or null if no match / expired /
     * revoked.
     */
    public function validate(Tenant $tenant, ?string $cookieValue): ?TenantTrustedDevice
    {
        if (! $cookieValue || ! is_string($cookieValue)) {
            return null;
        }

        // Length sanity check — short-circuits malformed cookies without
        // hitting the DB.
        if (strlen($cookieValue) !== 64) {
            return null;
        }

        $hash = hash('sha256', $cookieValue);

        $device = TenantTrustedDevice::activeForTenant($tenant->id)
            ->where('device_token_hash', $hash)
            ->first();

        return $device;
    }

    /**
     * Touch a device on each authenticated request: bump last_used_at and
     * push expires_at forward (sliding window). Also updates ip_last_seen
     * if the IP changed.
     *
     * Cheap operation — no DB query if the row was already touched within
     * the last hour (avoids hammering on every request).
     */
    public function touch(TenantTrustedDevice $device, Request $request): void
    {
        // Skip if touched recently. Hour granularity is plenty for a
        // 90-day sliding window.
        if ($device->last_used_at && $device->last_used_at->diffInMinutes(now()) < 60) {
            return;
        }

        $device->forceFill([
            'last_used_at' => now(),
            'expires_at'   => now()->addDays(self::EXPIRY_DAYS),
            'ip_last_seen' => $request->ip(),
        ])->save();
    }

    /**
     * Revoke a single device. Used by:
     *   - "Sign out this device" (sets revoked_by_user_id to the current user)
     *   - Trusted-devices admin screen (owner revoking another device)
     */
    public function revoke(TenantTrustedDevice $device, ?TenantUser $byUser = null): void
    {
        if ($device->revoked_at !== null) {
            return; // already revoked, idempotent
        }

        $device->forceFill([
            'revoked_at'         => now(),
            'revoked_by_user_id' => $byUser?->id,
        ])->save();

        Log::info('DeviceTrust.revoke', [
            'tenant_id' => $device->tenant_id,
            'device_id' => $device->id,
            'by_user'   => $byUser?->id,
        ]);
    }

    /**
     * Revoke every active device for a tenant. The "lost iPad — revoke
     * everything" admin action.
     */
    public function revokeAllForTenant(Tenant $tenant, ?TenantUser $byUser = null): int
    {
        $count = TenantTrustedDevice::activeForTenant($tenant->id)
            ->update([
                'revoked_at'         => now(),
                'revoked_by_user_id' => $byUser?->id,
                'updated_at'         => now(),
            ]);

        Log::info('DeviceTrust.revokeAll', [
            'tenant_id' => $tenant->id,
            'count'     => $count,
            'by_user'   => $byUser?->id,
        ]);

        return $count;
    }
}
PHP
    echo "STEP 2: OK (created DeviceTrustService)"
fi

# ----------------------------------------------------------------------------
# STEP 3 — EnsureTrustedDevice middleware (defined; not mounted yet)
# ----------------------------------------------------------------------------

MW_FILE=app/Http/Middleware/EnsureTrustedDevice.php

if [ -f "$MW_FILE" ]; then
    echo "STEP 3: SKIP (middleware already exists)"
else
    cat > "$MW_FILE" <<'PHP'
<?php

namespace App\Http\Middleware;

use App\Services\DeviceTrustService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureTrustedDevice
 *
 * Layer 1 gate. Runs before RequireTenantAuth in the tenant middleware
 * stack (wired in chunk 4). Resolution:
 *
 *   1. If tenant->pin_tier_active is FALSE → pass through. Starter and
 *      single-user Branded tenants don't use the PIN tier; they keep the
 *      existing email/password-every-visit flow.
 *
 *   2. Else, look for the device-trust cookie:
 *        - Present + valid → touch the row, set $request->attributes
 *          'trusted_device' = $device, pass through.
 *        - Missing / invalid / expired → redirect to login (with
 *          ?intended back to the current URL).
 *
 * The presence of $request->attributes->get('trusted_device') is the
 * signal to AuthController and StaffSwitchController that the device
 * tier has been satisfied and they can show the staff switcher instead
 * of the email login.
 *
 * NOT mounted in routes yet. Chunk 4 wires it in.
 */
class EnsureTrustedDevice
{
    public function __construct(protected DeviceTrustService $devices) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = app('tenant') ?? null;

        // No tenant context = platform domain. Not our concern.
        if (! $tenant) {
            return $next($request);
        }

        // Starter + single-user Branded: PIN tier off. Keep existing flow.
        if (! $tenant->pin_tier_active) {
            return $next($request);
        }

        $cookieValue = $request->cookie(DeviceTrustService::COOKIE_NAME);
        $device = $this->devices->validate($tenant, $cookieValue);

        if (! $device) {
            // Not trusted (or trust expired). Send to login.
            if ($request->expectsJson()) {
                return response()->json([
                    'ok'    => false,
                    'error' => 'device_not_trusted',
                    'redirect' => route('tenant.login'),
                ], 401);
            }
            return redirect()->guest(route('tenant.login'));
        }

        // Valid device. Bump last_used_at + sliding expiry.
        $this->devices->touch($device, $request);

        // Stash the device on the request so downstream middleware and
        // controllers can read it without re-querying.
        $request->attributes->set('trusted_device', $device);

        return $next($request);
    }
}
PHP
    echo "STEP 3: OK (created EnsureTrustedDevice middleware)"
fi

# ----------------------------------------------------------------------------
# Verification
# ----------------------------------------------------------------------------

echo ""
echo "----------------------------------------"
echo "VERIFY: new files exist with expected headers"
echo "----------------------------------------"
for f in "$MODEL_FILE" "$SERVICE_FILE" "$MW_FILE"; do
    if [ -f "$f" ]; then
        echo "  ✓ $f ($(wc -l < $f) lines)"
    else
        echo "  ✗ $f MISSING"
    fi
done

echo ""
echo "----------------------------------------"
echo "VERIFY: middleware NOT yet wired into routes (intentional — chunk 4 does that)"
echo "----------------------------------------"
if grep -q "EnsureTrustedDevice" routes/web.php; then
    echo "  WARN — EnsureTrustedDevice referenced in routes/web.php (unexpected)"
else
    echo "  ✓ EnsureTrustedDevice not in routes (correct — sits dark until chunk 4)"
fi

echo ""
echo "=========================================="
echo "Chunk 3 application complete."
echo ""
echo "Server steps:"
echo "  git pull && composer install --no-interaction --no-scripts && \\"
echo "  php artisan optimize:clear && \\"
echo "  systemctl stop php8.3-fpm && sleep 2 && systemctl start php8.3-fpm"
echo "  (no migrations — schema landed in chunk 1)"
echo ""
echo "Verify via tinker (php artisan tinker):"
echo ""
echo "  >>> \$t = \\App\\Models\\Tenant::where('subdomain', 'thebikehub')->first();"
echo "  >>> \$svc = app(\\App\\Services\\DeviceTrustService::class);"
echo "  >>> \$req = \\Illuminate\\Http\\Request::create('/', 'GET');"
echo "  >>> \$cookie = \$svc->mint(\$t, \$req, 'tinker test device');"
echo "  >>> strlen(\$cookie)  // expect: 64"
echo "  >>> \$device = \$svc->validate(\$t, \$cookie);"
echo "  >>> \$device->id     // expect: a uuid"
echo "  >>> \$device->isActive()  // expect: true"
echo "  >>> \$svc->validate(\$t, 'wrong_cookie_value_here_xx')  // expect: null"
echo "  >>> \$svc->revoke(\$device); \$device->fresh()->isActive()  // expect: false"
echo ""
echo "Then check existing login still works on thebikehub.intake.works."
echo "(No middleware wiring yet — behavior should be identical to chunk 2.)"
echo "=========================================="
