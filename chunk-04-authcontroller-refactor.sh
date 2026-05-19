#!/usr/bin/env bash
# ============================================================================
# Auth Refactor — Chunk 4
# AuthController refactor + EnsureTrustedDevice middleware wired into routes
#
# CONTEXT
#   First chunk that actually changes request-lifecycle behavior. But the
#   change is GATED by tenant->pin_tier_active — which is false for every
#   tenant in the system right now (the bike hub has only 1 user). So even
#   after this lands, behavior on every existing tenant is unchanged.
#
#   The new flow only activates when a tenant has both:
#     - additional_users capability (Branded+)
#     - 2+ tenant_users rows
#
#   Until then, the middleware passes through, the controller skips the
#   minting code path, and login looks exactly like today.
#
# WHAT THIS PATCH DOES
#   1. Adds 'Trust this device' checkbox to the login view (shown only
#      when pin_tier_active for the tenant — so invisible to everyone
#      right now).
#
#   2. Modifies AuthController::login() to mint a device-trust cookie when
#      the box is checked on a pin_tier_active tenant. Cookie is set on
#      the redirect response so it's available on the next request.
#
#   3. Modifies AuthController::logout() to revoke the trusted device when
#      the user 'signs out this device' (vs the upcoming 'switch staff' that
#      will preserve trust).
#
#   4. Wires EnsureTrustedDevice into the tenant middleware group,
#      positioned BEFORE RequireTenantAuth so it can redirect to login
#      before the auth check fails for cookie-missing cases.
#
# WHAT THIS DOESN'T DO YET
#   - No staff switcher UI (chunk 5)
#   - No PIN entry or set-PIN flow (chunk 5)
#   - No idle lock (chunk 6)
#   - No location-switch action gate (chunk 7)
#   - No trusted-devices admin screen (chunk 8)
#
# IDEMPOTENCY: every step checks a marker before editing.
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
echo "Auth Refactor — Chunk 4 (AuthController + middleware wiring)"
echo "Running in: $(pwd)"
echo "=========================================="

# ----------------------------------------------------------------------------
# STEP 1 — Modify AuthController::login() to mint a device-trust cookie
#          when the user checks "Trust this device" on a pin_tier_active
#          tenant.
# ----------------------------------------------------------------------------

python3 <<'PY'
from pathlib import Path
p = Path('app/Http/Controllers/Tenant/AuthController.php')
s = p.read_text()

# 1a — add DeviceTrustService import + use
if "use App\\Services\\DeviceTrustService;" not in s:
    old_uses = "use Illuminate\\Support\\Str;"
    new_uses = "use Illuminate\\Support\\Str;\nuse App\\Services\\DeviceTrustService;\nuse Illuminate\\Support\\Facades\\Cookie;"
    if s.count(old_uses) != 1:
        print(f"STEP 1a: ABORT (use anchor matches {s.count(old_uses)} times)")
        raise SystemExit(1)
    s = s.replace(old_uses, new_uses)
    print("STEP 1a: OK (added DeviceTrustService + Cookie imports)")
else:
    print("STEP 1a: SKIP (imports already present)")

# 1b — inject device-trust minting into login() right before resolveLocationAndContinue
if "PATCH-CHUNK-4 mint" not in s:
    old = """        Auth::guard('tenant')->login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        return $this->resolveLocationAndContinue($request, $user);"""

    new = """        Auth::guard('tenant')->login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        // PATCH-CHUNK-4 mint — device trust opt-in. Only kicks in when the
        // tenant's PIN tier is active (additional_users + 2+ staff). For
        // every tenant right now this is false, so this whole block is a
        // no-op until a tenant adds a second user.
        $trustCookie = null;
        if ($tenant->pin_tier_active && $request->boolean('trust_device')) {
            $devices = app(DeviceTrustService::class);
            $plaintext = $devices->mint($tenant, $request);
            $trustCookie = cookie(
                DeviceTrustService::COOKIE_NAME,
                $plaintext,
                DeviceTrustService::COOKIE_MINUTES,
                '/',           // path
                null,          // domain (default → current host)
                true,          // secure
                true,          // httpOnly
                false,         // raw
                'lax'          // sameSite
            );
        }

        $response = $this->resolveLocationAndContinue($request, $user);

        if ($trustCookie) {
            $response = $response->withCookie($trustCookie);
        }

        return $response;"""

    if s.count(old) != 1:
        print(f"STEP 1b: ABORT (login() anchor matches {s.count(old)} times)")
        raise SystemExit(1)
    s = s.replace(old, new)
    print("STEP 1b: OK (login() now mints device-trust cookie on opt-in)")
else:
    print("STEP 1b: SKIP (mint block already present)")

# 1c — modify logout() to revoke the trusted device for this cookie
if "PATCH-CHUNK-4 revoke" not in s:
    old = """    public function logout(Request $request)
    {
        Auth::guard('tenant')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('tenant.login');
    }"""

    new = """    public function logout(Request $request)
    {
        // PATCH-CHUNK-4 revoke — "sign out this device" semantics. If a
        // device-trust cookie is present, revoke the row + clear the cookie.
        // This is the strong sign-out: device trust is gone, next visit
        // requires email + password again.
        $tenant = tenant();
        $user = Auth::guard('tenant')->user();

        if ($tenant) {
            $cookieValue = $request->cookie(DeviceTrustService::COOKIE_NAME);
            if ($cookieValue) {
                $devices = app(DeviceTrustService::class);
                $device = $devices->validate($tenant, $cookieValue);
                if ($device) {
                    $devices->revoke($device, $user);
                }
            }
        }

        Auth::guard('tenant')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('tenant.login')
            ->withCookie(Cookie::forget(DeviceTrustService::COOKIE_NAME));
    }"""

    if s.count(old) != 1:
        print(f"STEP 1c: ABORT (logout() anchor matches {s.count(old)} times)")
        raise SystemExit(1)
    s = s.replace(old, new)
    p.write_text(s)
    print("STEP 1c: OK (logout() now revokes trusted device)")
else:
    p.write_text(s)
    print("STEP 1c: SKIP (revoke block already present)")
PY

# ----------------------------------------------------------------------------
# STEP 2 — Add "Trust this device" checkbox to the login view.
#          Renders only when $currentTenant->pin_tier_active is true.
#          For every existing tenant this is false → checkbox invisible.
# ----------------------------------------------------------------------------

python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/auth/login.blade.php')
s = p.read_text()

if 'name="trust_device"' in s:
    print("STEP 2: SKIP (trust_device checkbox already present)")
else:
    old = """    <label class=\"remember\">
      <input type=\"checkbox\" name=\"remember\" value=\"1\"> Remember me for 30 days
    </label>"""

    new = """    <label class=\"remember\">
      <input type=\"checkbox\" name=\"remember\" value=\"1\"> Remember me for 30 days
    </label>

    @if($currentTenant->pin_tier_active)
      <label class=\"remember\" style=\"flex-direction:column;align-items:flex-start;gap:4px\">
        <span style=\"display:flex;align-items:center;gap:8px\">
          <input type=\"checkbox\" name=\"trust_device\" value=\"1\" checked> Trust this device
        </span>
        <span style=\"font-size:11px;opacity:.55;padding-left:24px\">
          Skip email + password on this browser for 90 days. Leave unchecked on shared or public computers.
        </span>
      </label>
    @endif"""

    if s.count(old) != 1:
        print(f"STEP 2: ABORT (login view anchor matches {s.count(old)} times)")
        raise SystemExit(1)

    s = s.replace(old, new)
    p.write_text(s)
    print("STEP 2: OK (added Trust this device checkbox to login view)")
PY

# ----------------------------------------------------------------------------
# STEP 3 — Wire EnsureTrustedDevice into the tenant middleware group.
#          Goes BEFORE RequireTenantAuth so it can short-circuit
#          unauthenticated requests to login before the auth check fires.
# ----------------------------------------------------------------------------

python3 <<'PY'
from pathlib import Path
p = Path('routes/web.php')
s = p.read_text()

if "EnsureTrustedDevice" in s:
    print("STEP 3: SKIP (middleware already wired)")
else:
    old = """        Route::middleware([
            'App\\Http\\Middleware\\ConsumeOnboardingToken',
            'App\\Http\\Middleware\\RequireTenantAuth',
            'App\\Http\\Middleware\\ApplyTenantTheme',
        ])->group(function () {"""

    new = """        Route::middleware([
            'App\\Http\\Middleware\\ConsumeOnboardingToken',
            'App\\Http\\Middleware\\EnsureTrustedDevice',
            'App\\Http\\Middleware\\RequireTenantAuth',
            'App\\Http\\Middleware\\ApplyTenantTheme',
        ])->group(function () {"""

    if s.count(old) != 1:
        print(f"STEP 3: ABORT (route group anchor matches {s.count(old)} times)")
        raise SystemExit(1)

    s = s.replace(old, new)
    p.write_text(s)
    print("STEP 3: OK (EnsureTrustedDevice wired into tenant middleware stack)")
PY

# ----------------------------------------------------------------------------
# Post-edit verification (re-read affected regions per P-series habit)
# ----------------------------------------------------------------------------

echo ""
echo "----------------------------------------"
echo "VERIFY: AuthController imports + key blocks"
echo "----------------------------------------"
grep -n "DeviceTrustService\|PATCH-CHUNK-4" app/Http/Controllers/Tenant/AuthController.php || true

echo ""
echo "----------------------------------------"
echo "VERIFY: login view checkbox"
echo "----------------------------------------"
grep -n "trust_device\|pin_tier_active" resources/views/tenant/auth/login.blade.php || true

echo ""
echo "----------------------------------------"
echo "VERIFY: middleware wired in routes"
echo "----------------------------------------"
grep -n "EnsureTrustedDevice\|RequireTenantAuth" routes/web.php | head -6

echo ""
echo "=========================================="
echo "Chunk 4 application complete."
echo ""
echo "Server steps:"
echo "  git pull && composer install --no-interaction --no-scripts && \\"
echo "  php artisan view:clear && php artisan optimize:clear && \\"
echo "  systemctl stop php8.3-fpm && sleep 2 && systemctl start php8.3-fpm"
echo ""
echo "Verify on thebikehub.intake.works:"
echo "  1. Visit /admin/login. Should look identical to before — no 'Trust"
echo "     this device' checkbox (because the bike hub still has 1 user)."
echo "  2. Sign in with email + password. Lands at dashboard, no changes."
echo "  3. Sign out. No errors. Lands back at /admin/login."
echo ""
echo "Optional deeper test (requires 2nd user — skip for now):"
echo "  4. Add a second tenant_users row in tinker."
echo "  5. Refresh login page → 'Trust this device' checkbox appears."
echo "  6. Sign in with the box checked → row appears in tenant_trusted_devices."
echo "  7. Cookie 'intake_device_trust' set in browser."
echo "=========================================="
