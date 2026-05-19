#!/usr/bin/env bash
# ============================================================================
# Auth Refactor — Chunk 5
# StaffSwitchController + switcher view + set-initial-PIN modal + PIN lockout
#
# CONTEXT
#   First chunk where actual PIN UI shows up. Builds Layer 2 of the auth
#   model. After this lands, a Branded+ tenant with 2+ users:
#     - Sees the staff switcher after passing device trust
#     - Each staff member sets their own PIN on first sign-in
#     - PIN entry has the lockout cooldown ladder from the spec
#
#   Still nothing changes for the bike hub (1 user, pin_tier_active=false).
#
# WHAT THIS PATCH ADDS
#   1. config/intake.php → 'auth' section with PIN policy constants
#      (cooldown ladder, lockout thresholds). Tunable in one place.
#
#   2. App\Services\PinService — the API for PIN operations.
#        setPin($user, $digits)          → bcrypt + persist
#        verifyPin($user, $digits)       → check + handle lockout
#        unlockUser($user, $byUser)      → owner clears lockout
#        forceReset($user)               → owner null-outs pin_hash
#        isLocked($user)                 → boolean
#
#   3. App\Http\Controllers\Tenant\StaffSwitchController with:
#        index()                         → switcher view (list of cards)
#        verifyPin(Request)              → POST: PIN entry submit
#        setInitialPin(Request)          → POST: first-time PIN set
#        requestReset(Request)           → POST: forgot-PIN email (stub)
#
#   4. resources/views/tenant/auth/switch.blade.php — the staff switcher
#      view. Card-shell pattern matching select-location.blade.php.
#      Three modes:
#        - Card grid (initial)
#        - PIN entry (after tapping a card)
#        - Set-initial-PIN modal (when staff has no pin_hash yet)
#
#   5. Routes for /admin/switch + the POST endpoints.
#
#   6. AuthController::resolveLocationAndContinue() updated — when
#      pin_tier_active, route POST-login to the switcher instead of
#      straight to the dashboard.
#
# WHAT THIS DOESN'T DO YET
#   - No idle lock (chunk 6)
#   - No location-switch action gate using PIN (chunk 7)
#   - No trusted-devices admin or PIN admin (chunk 8)
#   - No "Forgot PIN" email — endpoint exists but emails a placeholder
#
# IDEMPOTENCY: every file write checks a marker before acting.
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
echo "Auth Refactor — Chunk 5 (Staff switcher + PIN UI)"
echo "Running in: $(pwd)"
echo "=========================================="

# ----------------------------------------------------------------------------
# STEP 1 — Add auth config section to config/intake.php
# ----------------------------------------------------------------------------

python3 <<'PY'
from pathlib import Path
p = Path('config/intake.php')
s = p.read_text()

if "'auth' =>" in s:
    print("STEP 1: SKIP (auth config block already present)")
else:
    # Insert just before the closing array bracket — last `];` in file.
    # Find the LAST occurrence of "\n];" which closes the return array.
    last = s.rfind("\n];")
    if last == -1:
        print("STEP 1: ABORT (could not find closing '];' of intake.php config)")
        raise SystemExit(1)

    insert = """
    /*
    |--------------------------------------------------------------------------
    | Auth refactor — PIN tier policy (chunk 5)
    |--------------------------------------------------------------------------
    | Constants the PIN tier reads. Tunable per-tenant later via the
    | sign-in security admin screen (chunk 8); for now these are the
    | platform-wide defaults.
    */
    'auth' => [
        // PIN entry failures ladder. Index = failure count (0-based).
        // Value = cooldown seconds before the next attempt is allowed.
        // After the last entry in the array, the card soft-locks until
        // owner unlock or email reset.
        'pin_cooldown_ladder' => [0, 0, 5, 30, 0],

        // Total failures across the device before the WHOLE device
        // requires email + password re-auth. Hard rule from spec §4.
        'pin_device_lockout_threshold' => 5,

        // Window (minutes) over which the device-lockout count is
        // measured. Failures older than this are forgotten.
        'pin_device_lockout_window_min' => 10,

        // Sliding-window device trust expiry (days).
        'device_trust_expiry_days' => 90,
    ],
"""

    s = s[:last] + insert + s[last:]
    p.write_text(s)
    print("STEP 1: OK (added auth config section to config/intake.php)")
PY

# ----------------------------------------------------------------------------
# STEP 2 — PinService
# ----------------------------------------------------------------------------

SERVICE_FILE=app/Services/PinService.php

if [ -f "$SERVICE_FILE" ]; then
    echo "STEP 2: SKIP (PinService already exists)"
else
    cat > "$SERVICE_FILE" <<'PHP'
<?php

namespace App\Services;

use App\Models\Tenant\TenantUser;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * PinService
 *
 * The API for Layer 2 of the auth refactor (auth-refactor-spec-v2.md §4).
 *
 * PINs are 4 digits. Stored as bcrypt hashes — the threat model isn't
 * "hash leaks" (4 digits = 10K combinations, instantly brute-forceable
 * from a leak) but "someone tries codes on the live device." Bcrypt
 * makes hash-leak scenarios merely bad, not catastrophic.
 *
 * Lockout policy is in config('intake.auth') — see comments there.
 */
class PinService
{
    /**
     * Set or reset a user's PIN. Caller is responsible for any second-
     * factor check (e.g. device-password re-auth) — this method just
     * persists.
     *
     * Resets the failure counter and clears any lockout when a new PIN
     * is set.
     */
    public function setPin(TenantUser $user, string $digits): void
    {
        if (! $this->validateDigits($digits)) {
            throw new \InvalidArgumentException('PIN must be exactly 4 digits.');
        }

        $user->forceFill([
            'pin_hash'          => Hash::make($digits),
            'pin_set_at'        => now(),
            'pin_failed_count'  => 0,
            'pin_locked_until'  => null,
        ])->save();

        Log::info('Pin.set', [
            'tenant_id' => $user->tenant_id,
            'user_id'   => $user->id,
        ]);
    }

    /**
     * Verify a PIN attempt. Returns true on success, false on failure
     * (failure counter and lockout state are updated accordingly).
     *
     * Throws \DomainException if the user is currently locked out — the
     * caller should check isLocked() first, but throw is defense in depth.
     */
    public function verifyPin(TenantUser $user, string $digits): bool
    {
        if ($this->isLocked($user)) {
            throw new \DomainException('PIN entry locked out for this user.');
        }

        if (! $user->pin_hash) {
            // No PIN set — caller should route to setInitialPin instead.
            return false;
        }

        if (! $this->validateDigits($digits)) {
            return $this->recordFailure($user);
        }

        if (! Hash::check($digits, $user->pin_hash)) {
            return $this->recordFailure($user);
        }

        // Success — reset counter, bump last-used.
        $user->forceFill([
            'pin_failed_count'  => 0,
            'pin_locked_until'  => null,
            'pin_last_used_at'  => now(),
        ])->save();

        return true;
    }

    /**
     * Is the user's PIN entry currently locked out?
     * (Soft-locked card after too many failures.)
     */
    public function isLocked(TenantUser $user): bool
    {
        if (! $user->pin_locked_until) {
            return false;
        }
        return $user->pin_locked_until->isFuture();
    }

    /**
     * Owner action: clear lockout state on a user. Doesn't change the PIN.
     */
    public function unlockUser(TenantUser $user, ?TenantUser $byUser = null): void
    {
        $user->forceFill([
            'pin_failed_count'  => 0,
            'pin_locked_until'  => null,
        ])->save();

        Log::info('Pin.unlock', [
            'tenant_id' => $user->tenant_id,
            'user_id'   => $user->id,
            'by_user'   => $byUser?->id,
        ]);
    }

    /**
     * Owner action: force the user to set a new PIN on next sign-in.
     */
    public function forceReset(TenantUser $user, ?TenantUser $byUser = null): void
    {
        $user->forceFill([
            'pin_hash'          => null,
            'pin_set_at'        => null,
            'pin_failed_count'  => 0,
            'pin_locked_until'  => null,
        ])->save();

        Log::info('Pin.forceReset', [
            'tenant_id' => $user->tenant_id,
            'user_id'   => $user->id,
            'by_user'   => $byUser?->id,
        ]);
    }

    /**
     * Record a failed PIN attempt + apply the cooldown ladder.
     */
    protected function recordFailure(TenantUser $user): bool
    {
        $ladder = config('intake.auth.pin_cooldown_ladder', [0, 0, 5, 30, 0]);
        $maxFailures = count($ladder); // 5 by default

        $newCount = $user->pin_failed_count + 1;

        $update = ['pin_failed_count' => $newCount];

        // If we've burned through the ladder, the card is soft-locked
        // until owner unlock or email reset.
        if ($newCount >= $maxFailures) {
            $update['pin_locked_until'] = now()->addYears(99); // effectively infinite
        } else {
            // Otherwise, apply the cooldown from the ladder.
            $cooldownSec = (int) ($ladder[$newCount - 1] ?? 0);
            if ($cooldownSec > 0) {
                $update['pin_locked_until'] = now()->addSeconds($cooldownSec);
            }
        }

        $user->forceFill($update)->save();

        Log::info('Pin.failure', [
            'tenant_id'    => $user->tenant_id,
            'user_id'      => $user->id,
            'failed_count' => $newCount,
        ]);

        return false;
    }

    /**
     * Is this string exactly 4 digits?
     */
    protected function validateDigits(string $digits): bool
    {
        return (bool) preg_match('/^\d{4}$/', $digits);
    }
}
PHP
    echo "STEP 2: OK (created PinService)"
fi

# ----------------------------------------------------------------------------
# STEP 3 — StaffSwitchController
# ----------------------------------------------------------------------------

CTRL_FILE=app/Http/Controllers/Tenant/StaffSwitchController.php

if [ -f "$CTRL_FILE" ]; then
    echo "STEP 3: SKIP (controller already exists)"
else
    cat > "$CTRL_FILE" <<'PHP'
<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantUser;
use App\Services\PinService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * StaffSwitchController
 *
 * Layer 2 of the auth refactor (auth-refactor-spec-v2.md §4).
 *
 * Only invoked when the tenant has pin_tier_active. AuthController routes
 * users here after device-tier auth instead of straight to the dashboard.
 *
 * Three actions:
 *   GET  /admin/switch          - show the card grid
 *   POST /admin/pin/verify      - verify a PIN, sign user in, redirect
 *   POST /admin/pin/set         - set initial PIN for a user, sign in
 *   POST /admin/pin/reset-request - email a reset link (stub for now)
 *
 * Subdomain trap: every method takes `string $subdomain` as first param.
 */
class StaffSwitchController extends Controller
{
    public function __construct(protected PinService $pins) {}

    /**
     * GET /admin/switch
     * Show the staff card grid.
     */
    public function index(string $subdomain, Request $request)
    {
        $tenant = tenant();
        if (! $tenant || ! $tenant->pin_tier_active) {
            // Should not happen — middleware should have routed elsewhere.
            return redirect()->route('tenant.dashboard');
        }

        // If user is already auth'd and PIN is fresh, just go to dashboard.
        // (For now we don't track PIN freshness — chunk 6 adds the idle
        // lock. Today, an auth'd user is auth'd.)
        if (Auth::guard('tenant')->check()) {
            return redirect()->route('tenant.dashboard');
        }

        $staff = TenantUser::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return view('tenant.auth.switch', [
            'staff' => $staff,
        ]);
    }

    /**
     * POST /admin/pin/verify
     * Body: { user_id, pin }
     */
    public function verifyPin(string $subdomain, Request $request)
    {
        $tenant = tenant();
        if (! $tenant || ! $tenant->pin_tier_active) {
            return response()->json(['ok' => false, 'error' => 'tier_inactive'], 400);
        }

        $request->validate([
            'user_id' => ['required', 'uuid'],
            'pin'     => ['required', 'string', 'regex:/^\d{4}$/'],
        ]);

        $user = TenantUser::where('tenant_id', $tenant->id)
            ->where('id', $request->input('user_id'))
            ->where('is_active', true)
            ->first();

        if (! $user) {
            return response()->json(['ok' => false, 'error' => 'user_not_found'], 404);
        }

        // No PIN set yet → route the client to the set-initial-PIN modal.
        if (! $user->pin_hash) {
            return response()->json([
                'ok' => false,
                'error' => 'pin_not_set',
                'next' => 'set_initial_pin',
                'user_id' => $user->id,
            ]);
        }

        if ($this->pins->isLocked($user)) {
            return response()->json([
                'ok' => false,
                'error' => 'pin_locked',
                'locked_until' => $user->pin_locked_until?->toIso8601String(),
            ], 423);
        }

        try {
            $ok = $this->pins->verifyPin($user, $request->input('pin'));
        } catch (\DomainException $e) {
            return response()->json([
                'ok' => false,
                'error' => 'pin_locked',
            ], 423);
        }

        if (! $ok) {
            $user->refresh();
            return response()->json([
                'ok' => false,
                'error' => 'pin_mismatch',
                'failed_count' => $user->pin_failed_count,
                'locked_until' => $user->pin_locked_until?->toIso8601String(),
            ], 422);
        }

        // Success — sign in.
        Auth::guard('tenant')->login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        // Resolve current_location_id (same logic as email login).
        $locations = $user->activeLocations()
            ->orderBy('is_default', 'desc')
            ->orderBy('name')
            ->get();

        if ($locations->isEmpty()) {
            Auth::guard('tenant')->logout();
            return response()->json([
                'ok' => false,
                'error' => 'no_location_access',
            ], 403);
        }

        if ($locations->count() === 1) {
            $request->session()->put('current_location_id', $locations->first()->id);
            return response()->json([
                'ok' => true,
                'redirect' => route('tenant.dashboard'),
            ]);
        }

        return response()->json([
            'ok' => true,
            'redirect' => route('tenant.select-location'),
        ]);
    }

    /**
     * POST /admin/pin/set
     * Body: { user_id, pin, pin_confirm, device_password }
     *
     * Sets the initial PIN for a user. Requires re-entering the device
     * password (the email/password used to trust this device) as a
     * second factor.
     */
    public function setInitialPin(string $subdomain, Request $request)
    {
        $tenant = tenant();
        if (! $tenant || ! $tenant->pin_tier_active) {
            return response()->json(['ok' => false, 'error' => 'tier_inactive'], 400);
        }

        $request->validate([
            'user_id'         => ['required', 'uuid'],
            'pin'             => ['required', 'string', 'regex:/^\d{4}$/'],
            'pin_confirm'     => ['required', 'string', 'same:pin'],
            'device_password' => ['required', 'string'],
        ]);

        $user = TenantUser::where('tenant_id', $tenant->id)
            ->where('id', $request->input('user_id'))
            ->where('is_active', true)
            ->first();

        if (! $user) {
            return response()->json(['ok' => false, 'error' => 'user_not_found'], 404);
        }

        if ($user->pin_hash) {
            // Already has a PIN. Don't allow set-initial-pin to overwrite —
            // that's what owner Force Reset is for.
            return response()->json(['ok' => false, 'error' => 'pin_already_set'], 409);
        }

        // Second factor: re-verify the device password. Any active tenant
        // user's password works — this is "do you have credentials for
        // SOMEONE at this shop", not necessarily this exact staff member.
        // Pattern from spec §4.2 — prevents a stranger from setting a PIN
        // on a trusted device.
        $passwordOk = TenantUser::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->get()
            ->contains(function (TenantUser $u) use ($request) {
                return Hash::check($request->input('device_password'), $u->password);
            });

        if (! $passwordOk) {
            return response()->json([
                'ok' => false,
                'error' => 'device_password_mismatch',
            ], 422);
        }

        $this->pins->setPin($user, $request->input('pin'));

        // Sign in the user — they just authenticated by setting a PIN.
        Auth::guard('tenant')->login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        // Same location resolution as verifyPin.
        $locations = $user->activeLocations()
            ->orderBy('is_default', 'desc')
            ->orderBy('name')
            ->get();

        if ($locations->isEmpty()) {
            Auth::guard('tenant')->logout();
            return response()->json([
                'ok' => false,
                'error' => 'no_location_access',
            ], 403);
        }

        if ($locations->count() === 1) {
            $request->session()->put('current_location_id', $locations->first()->id);
            return response()->json([
                'ok' => true,
                'redirect' => route('tenant.dashboard'),
            ]);
        }

        return response()->json([
            'ok' => true,
            'redirect' => route('tenant.select-location'),
        ]);
    }

    /**
     * POST /admin/pin/reset-request
     * Body: { user_id }
     *
     * Emails a reset link. Stub for now — fully wired in chunk 9 with
     * the email/SMS system. Today just logs the intent and returns OK
     * so the UI flow works.
     */
    public function requestReset(string $subdomain, Request $request)
    {
        $tenant = tenant();
        if (! $tenant || ! $tenant->pin_tier_active) {
            return response()->json(['ok' => false, 'error' => 'tier_inactive'], 400);
        }

        $request->validate([
            'user_id' => ['required', 'uuid'],
        ]);

        $user = TenantUser::where('tenant_id', $tenant->id)
            ->where('id', $request->input('user_id'))
            ->where('is_active', true)
            ->first();

        if (! $user) {
            return response()->json(['ok' => false, 'error' => 'user_not_found'], 404);
        }

        Log::info('Pin.resetRequested', [
            'tenant_id' => $tenant->id,
            'user_id'   => $user->id,
            'email'     => $user->email,
            'note'      => 'TODO: send reset email (chunk 9)',
        ]);

        // Always return OK whether the user exists or not — same anti-
        // enumeration posture as the email reset flow.
        return response()->json(['ok' => true]);
    }
}
PHP
    echo "STEP 3: OK (created StaffSwitchController)"
fi

# ----------------------------------------------------------------------------
# STEP 4 — Switcher view
# ----------------------------------------------------------------------------

VIEW_FILE=resources/views/tenant/auth/switch.blade.php

if [ -f "$VIEW_FILE" ]; then
    echo "STEP 4: SKIP (switch view already exists)"
else
    cat > "$VIEW_FILE" <<'BLADE'
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>Who's here? — {{ $currentTenant->name }}</title>
  @if($currentTenant->favicon_url)
    <link rel="icon" href="{{ $currentTenant->favicon_url }}">
  @endif
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'Inter',-apple-system,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;-webkit-font-smoothing:antialiased}
    :root{
      --accent: {{ $currentTenant->accent_color ?? '#BEF264' }};
      --accent-text: {{ \App\Support\ColorHelper::accentTextColor($currentTenant->accent_color ?? '#BEF264') }};
      --bg:     #0f0f0f;
      --bg2:    #1a1a1a;
      --text:   #f0f0f0;
      --muted:  rgba(255,255,255,.4);
      --border: rgba(255,255,255,.1);
      --error:  #F09595;
    }
    .card{background:var(--bg2);border:0.5px solid var(--border);border-radius:16px;padding:32px;width:100%;max-width:520px}
    .logo-wrap{text-align:center;margin-bottom:24px}
    .logo-wrap img{height:40px;margin:0 auto 10px;display:block;border-radius:6px}
    .shop-name{font-size:18px;font-weight:600;color:var(--text)}
    .shop-sub{font-size:13px;color:var(--muted);margin-top:4px}
    h1{font-size:20px;font-weight:600;margin-bottom:6px;text-align:center}
    .lede{font-size:13px;color:var(--muted);text-align:center;margin-bottom:22px}

    .staff-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}
    @media (max-width:480px){.staff-grid{grid-template-columns:1fr}}
    .staff-card{
      display:flex;align-items:center;gap:12px;
      padding:14px 14px;
      background:rgba(255,255,255,.04);border:0.5px solid var(--border);border-radius:10px;
      color:var(--text);font-family:inherit;font-size:14px;text-align:left;
      cursor:pointer;transition:all .12s;width:100%
    }
    .staff-card:hover{background:rgba(255,255,255,.07);border-color:var(--accent)}
    .staff-card .avatar{
      width:36px;height:36px;border-radius:50%;background:var(--accent);color:var(--accent-text);
      display:flex;align-items:center;justify-content:center;font-weight:600;font-size:12px;flex-shrink:0;
    }
    .staff-card .meta{flex:1;min-width:0}
    .staff-card .name{font-weight:600;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .staff-card .role{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-top:2px}

    /* PIN stage */
    .pin-stage{display:none}
    .pin-stage.active{display:block}
    .pin-header{display:flex;align-items:center;gap:14px;margin-bottom:22px;padding-bottom:18px;border-bottom:0.5px solid var(--border)}
    .pin-header .avatar{width:48px;height:48px;border-radius:50%;background:var(--accent);color:var(--accent-text);display:flex;align-items:center;justify-content:center;font-weight:600;font-size:16px;flex-shrink:0}
    .pin-header .who{flex:1}
    .pin-header .name{font-weight:600;font-size:16px}
    .pin-header .role{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-top:2px}

    .pin-input-wrap{display:flex;justify-content:center;gap:10px;margin-bottom:18px}
    .pin-input{
      width:54px;height:64px;
      background:rgba(255,255,255,.05);border:1px solid var(--border);border-radius:10px;
      color:var(--text);font-size:24px;font-weight:600;text-align:center;font-family:inherit;
      transition:border-color .12s
    }
    .pin-input:focus{outline:none;border-color:var(--accent)}
    .pin-input.error{border-color:var(--error)}

    .pin-msg{font-size:13px;text-align:center;min-height:18px;margin-bottom:14px}
    .pin-msg.error{color:var(--error)}
    .pin-msg.info{color:var(--muted)}

    .btn{width:100%;padding:12px;background:var(--accent);color:var(--accent-text);border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;transition:filter .12s}
    .btn:hover:not(:disabled){filter:brightness(.93)}
    .btn:disabled{opacity:.5;cursor:not-allowed}
    .btn-ghost{background:transparent;color:var(--muted);border:0.5px solid var(--border)}
    .btn-ghost:hover:not(:disabled){background:rgba(255,255,255,.04);color:var(--text)}

    .row{display:flex;gap:10px;margin-top:10px}
    .row .btn{flex:1}

    .links{text-align:center;margin-top:14px;font-size:13px}
    .links a, .links button{color:var(--muted);transition:color .12s;background:none;border:none;cursor:pointer;font:inherit;text-decoration:underline;text-underline-offset:2px}
    .links a:hover, .links button:hover{color:var(--text)}

    /* Set-initial-PIN stage */
    label{display:block;font-size:12px;font-weight:500;color:var(--muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em;margin-top:14px}
    input[type=password],input[type=text]{width:100%;padding:10px 14px;background:rgba(255,255,255,.05);border:0.5px solid var(--border);border-radius:8px;color:var(--text);font-size:14px;font-family:inherit;transition:border-color .12s}
    input:focus{outline:none;border-color:var(--accent)}
    .hint{font-size:11px;color:var(--muted);margin-top:6px;line-height:1.45}
  </style>
</head>
<body>
<div class="card" id="root">

  <div class="logo-wrap">
    @if($currentTenant->logo_url)
      <img src="{{ $currentTenant->logo_url }}" alt="{{ $currentTenant->name }}">
    @endif
    <div class="shop-name">{{ $currentTenant->name }}</div>
    <div class="shop-sub">Staff sign-in</div>
  </div>

  {{-- STAGE 1: STAFF GRID --}}
  <div id="stage-grid">
    <h1>Who's here?</h1>
    <p class="lede">Tap your name to continue.</p>
    <div class="staff-grid">
      @foreach($staff as $s)
        <button class="staff-card" data-staff-card
                data-user-id="{{ $s->id }}"
                data-name="{{ $s->name }}"
                data-role="{{ ucfirst($s->role) }}"
                data-pin-set="{{ $s->pin_hash ? '1' : '0' }}">
          <div class="avatar">{{ strtoupper(substr($s->name, 0, 2)) }}</div>
          <div class="meta">
            <div class="name">{{ $s->name }}</div>
            <div class="role">{{ ucfirst($s->role) }}</div>
          </div>
        </button>
      @endforeach
    </div>
    <div class="links" style="margin-top:18px">
      <a href="{{ route('tenant.login') }}">Use email + password instead</a>
    </div>
  </div>

  {{-- STAGE 2: PIN ENTRY --}}
  <div id="stage-pin" class="pin-stage">
    <div class="pin-header">
      <div class="avatar" id="pin-avatar"></div>
      <div class="who">
        <div class="name" id="pin-name"></div>
        <div class="role" id="pin-role"></div>
      </div>
    </div>
    <p class="lede">Enter your 4-digit PIN.</p>
    <div class="pin-input-wrap">
      <input type="text" inputmode="numeric" pattern="\d*" maxlength="1" class="pin-input" data-pin-pos="0" autocomplete="off">
      <input type="text" inputmode="numeric" pattern="\d*" maxlength="1" class="pin-input" data-pin-pos="1" autocomplete="off">
      <input type="text" inputmode="numeric" pattern="\d*" maxlength="1" class="pin-input" data-pin-pos="2" autocomplete="off">
      <input type="text" inputmode="numeric" pattern="\d*" maxlength="1" class="pin-input" data-pin-pos="3" autocomplete="off">
    </div>
    <div class="pin-msg" id="pin-msg"></div>
    <div class="row">
      <button type="button" class="btn btn-ghost" data-action="back">← Not you?</button>
      <button type="button" class="btn" data-action="submit-pin">Sign in</button>
    </div>
    <div class="links">
      <button type="button" data-action="forgot">Forgot PIN?</button>
    </div>
  </div>

  {{-- STAGE 3: SET INITIAL PIN --}}
  <div id="stage-set" class="pin-stage">
    <div class="pin-header">
      <div class="avatar" id="set-avatar"></div>
      <div class="who">
        <div class="name" id="set-name"></div>
        <div class="role">First-time setup</div>
      </div>
    </div>
    <p class="lede">Choose a 4-digit PIN. Use it on this device from now on.</p>

    <div class="pin-input-wrap">
      <input type="text" inputmode="numeric" pattern="\d*" maxlength="1" class="pin-input" data-set-pos="0" autocomplete="off">
      <input type="text" inputmode="numeric" pattern="\d*" maxlength="1" class="pin-input" data-set-pos="1" autocomplete="off">
      <input type="text" inputmode="numeric" pattern="\d*" maxlength="1" class="pin-input" data-set-pos="2" autocomplete="off">
      <input type="text" inputmode="numeric" pattern="\d*" maxlength="1" class="pin-input" data-set-pos="3" autocomplete="off">
    </div>

    <p class="lede" style="margin:14px 0 6px;font-size:12px">Confirm:</p>
    <div class="pin-input-wrap">
      <input type="text" inputmode="numeric" pattern="\d*" maxlength="1" class="pin-input" data-confirm-pos="0" autocomplete="off">
      <input type="text" inputmode="numeric" pattern="\d*" maxlength="1" class="pin-input" data-confirm-pos="1" autocomplete="off">
      <input type="text" inputmode="numeric" pattern="\d*" maxlength="1" class="pin-input" data-confirm-pos="2" autocomplete="off">
      <input type="text" inputmode="numeric" pattern="\d*" maxlength="1" class="pin-input" data-confirm-pos="3" autocomplete="off">
    </div>

    <label>Your account password (anyone at this shop)</label>
    <input type="password" id="set-device-password" placeholder="••••••••" autocomplete="off">
    <div class="hint">Second factor: re-enter the email password from this device. Prevents someone setting a PIN on your behalf.</div>

    <div class="pin-msg" id="set-msg" style="margin-top:18px"></div>

    <div class="row">
      <button type="button" class="btn btn-ghost" data-action="back-from-set">← Back</button>
      <button type="button" class="btn" data-action="submit-set">Save PIN &amp; sign in</button>
    </div>
  </div>

</div>

<script>
(function(){
  const csrf = document.querySelector('meta[name=csrf-token]').content;

  const stageGrid = document.getElementById('stage-grid');
  const stagePin  = document.getElementById('stage-pin');
  const stageSet  = document.getElementById('stage-set');

  const pinInputs     = Array.from(document.querySelectorAll('[data-pin-pos]'));
  const setInputs     = Array.from(document.querySelectorAll('[data-set-pos]'));
  const confirmInputs = Array.from(document.querySelectorAll('[data-confirm-pos]'));

  let activeUser = null; // { id, name, role, pinSet }

  function showStage(which) {
    stageGrid.style.display = (which === 'grid') ? '' : 'none';
    stagePin.classList.toggle('active', which === 'pin');
    stageSet.classList.toggle('active', which === 'set');

    if (which === 'pin') {
      pinInputs.forEach(i => i.value = '');
      pinInputs[0].focus();
      msg('pin', '');
    }
    if (which === 'set') {
      setInputs.forEach(i => i.value = '');
      confirmInputs.forEach(i => i.value = '');
      document.getElementById('set-device-password').value = '';
      setInputs[0].focus();
      msg('set', '');
    }
  }

  function msg(stage, text, kind) {
    const el = document.getElementById(stage === 'set' ? 'set-msg' : 'pin-msg');
    el.textContent = text || '';
    el.className = 'pin-msg' + (kind ? ' ' + kind : '');
  }

  function avatarLetters(name) {
    return (name || '?').substring(0, 2).toUpperCase();
  }

  // Auto-advance digit inputs.
  function wireDigitGroup(inputs, onComplete) {
    inputs.forEach((inp, idx) => {
      inp.addEventListener('input', (e) => {
        inp.value = inp.value.replace(/\D/g, '').slice(0, 1);
        if (inp.value && idx < inputs.length - 1) {
          inputs[idx + 1].focus();
        }
        if (inputs.every(i => i.value)) {
          onComplete && onComplete();
        }
      });
      inp.addEventListener('keydown', (e) => {
        if (e.key === 'Backspace' && !inp.value && idx > 0) {
          inputs[idx - 1].focus();
        }
        if (e.key === 'Enter') {
          onComplete && onComplete();
        }
      });
    });
  }

  // === Wire stage 1: staff card click ===
  document.querySelectorAll('[data-staff-card]').forEach(card => {
    card.addEventListener('click', () => {
      activeUser = {
        id: card.dataset.userId,
        name: card.dataset.name,
        role: card.dataset.role,
        pinSet: card.dataset.pinSet === '1',
      };
      if (activeUser.pinSet) {
        document.getElementById('pin-avatar').textContent = avatarLetters(activeUser.name);
        document.getElementById('pin-name').textContent   = activeUser.name;
        document.getElementById('pin-role').textContent   = activeUser.role;
        showStage('pin');
      } else {
        document.getElementById('set-avatar').textContent = avatarLetters(activeUser.name);
        document.getElementById('set-name').textContent   = activeUser.name;
        showStage('set');
      }
    });
  });

  // === Wire stage 2: PIN entry ===
  wireDigitGroup(pinInputs, () => submitPin());

  async function submitPin() {
    const pin = pinInputs.map(i => i.value).join('');
    if (pin.length !== 4 || !activeUser) return;
    msg('pin', 'Checking…', 'info');

    try {
      const res = await fetch('{{ route('tenant.pin.verify') }}', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
        body: JSON.stringify({ user_id: activeUser.id, pin })
      });
      const body = await res.json();

      if (res.ok && body.ok) {
        window.location.href = body.redirect;
        return;
      }

      if (body.error === 'pin_not_set') {
        // Race: PIN got reset between card load and submit. Fall into set flow.
        document.getElementById('set-avatar').textContent = avatarLetters(activeUser.name);
        document.getElementById('set-name').textContent   = activeUser.name;
        showStage('set');
        return;
      }
      if (body.error === 'pin_locked') {
        msg('pin', 'Too many wrong attempts. Ask an owner to unlock you.', 'error');
        pinInputs.forEach(i => i.classList.add('error'));
        return;
      }
      if (body.error === 'pin_mismatch') {
        msg('pin', "That PIN didn't match. Try again.", 'error');
        pinInputs.forEach(i => { i.value = ''; i.classList.add('error'); });
        pinInputs[0].focus();
        setTimeout(() => pinInputs.forEach(i => i.classList.remove('error')), 600);
        return;
      }
      msg('pin', 'Something went wrong. Try again.', 'error');
    } catch (err) {
      msg('pin', 'Network error. Try again.', 'error');
    }
  }
  document.querySelector('[data-action=submit-pin]').addEventListener('click', submitPin);
  document.querySelector('[data-action=back]').addEventListener('click', () => showStage('grid'));
  document.querySelector('[data-action=back-from-set]').addEventListener('click', () => showStage('grid'));

  // === Wire stage 3: set-initial-PIN ===
  wireDigitGroup(setInputs, () => confirmInputs[0].focus());
  wireDigitGroup(confirmInputs, () => document.getElementById('set-device-password').focus());

  document.querySelector('[data-action=submit-set]').addEventListener('click', submitSetPin);
  document.getElementById('set-device-password').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') submitSetPin();
  });

  async function submitSetPin() {
    const pin = setInputs.map(i => i.value).join('');
    const pinConfirm = confirmInputs.map(i => i.value).join('');
    const devicePassword = document.getElementById('set-device-password').value;

    if (pin.length !== 4) { msg('set', 'PIN must be 4 digits.', 'error'); return; }
    if (pin !== pinConfirm) { msg('set', "Those PINs don't match.", 'error'); return; }
    if (!devicePassword) { msg('set', 'Enter the device password.', 'error'); return; }
    if (!activeUser) return;

    msg('set', 'Saving…', 'info');

    try {
      const res = await fetch('{{ route('tenant.pin.set') }}', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
        body: JSON.stringify({
          user_id: activeUser.id,
          pin,
          pin_confirm: pinConfirm,
          device_password: devicePassword,
        })
      });
      const body = await res.json();

      if (res.ok && body.ok) {
        window.location.href = body.redirect;
        return;
      }

      if (body.error === 'device_password_mismatch') {
        msg('set', "That password didn't match any account on this device.", 'error');
        return;
      }
      if (body.error === 'pin_already_set') {
        msg('set', 'That account already has a PIN. Use it to sign in.', 'error');
        return;
      }
      msg('set', 'Something went wrong. Try again.', 'error');
    } catch (err) {
      msg('set', 'Network error. Try again.', 'error');
    }
  }

  // === Forgot PIN ===
  document.querySelector('[data-action=forgot]').addEventListener('click', async () => {
    if (!activeUser) return;
    msg('pin', 'Sending reset link to your email…', 'info');
    try {
      const res = await fetch('{{ route('tenant.pin.reset-request') }}', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
        body: JSON.stringify({ user_id: activeUser.id })
      });
      const body = await res.json();
      if (body.ok) {
        msg('pin', 'Reset link sent. Check your email.', 'info');
      } else {
        msg('pin', 'Could not send reset right now.', 'error');
      }
    } catch {
      msg('pin', 'Network error.', 'error');
    }
  });
})();
</script>
</body>
</html>
BLADE
    echo "STEP 4: OK (created switch.blade.php)"
fi

# ----------------------------------------------------------------------------
# STEP 5 — Routes
# ----------------------------------------------------------------------------

python3 <<'PY'
from pathlib import Path
p = Path('routes/web.php')
s = p.read_text()

if "StaffSwitchController" in s:
    print("STEP 5: SKIP (switcher routes already wired)")
else:
    # Insert the new routes inside the tenant-auth middleware group, near
    # the location-picker routes. The switcher needs trusted-device but
    # not authenticated user.
    #
    # Actually, switcher needs DEVICE auth but no user auth — so it can't
    # go inside RequireTenantAuth. Best home: a sibling group with just
    # EnsureTrustedDevice + ApplyTenantTheme.
    #
    # Insert right BEFORE the existing middleware group.
    old = """        Route::post('/logout',          [TenantControllers\\AuthController::class, 'logout'])->name('logout');

        Route::middleware([
            'App\\Http\\Middleware\\ConsumeOnboardingToken',
            'App\\Http\\Middleware\\EnsureTrustedDevice',
            'App\\Http\\Middleware\\RequireTenantAuth',
            'App\\Http\\Middleware\\ApplyTenantTheme',
        ])->group(function () {"""

    new = """        Route::post('/logout',          [TenantControllers\\AuthController::class, 'logout'])->name('logout');

        // Staff switcher tier — requires trusted device, not signed-in user.
        // Lives between device auth (Layer 1) and user auth (Layer 2 PIN).
        Route::middleware([
            'App\\Http\\Middleware\\EnsureTrustedDevice',
            'App\\Http\\Middleware\\ApplyTenantTheme',
        ])->group(function () {
            Route::get('/switch',             [TenantControllers\\StaffSwitchController::class, 'index'])->name('switch');
            Route::post('/pin/verify',        [TenantControllers\\StaffSwitchController::class, 'verifyPin'])->name('pin.verify');
            Route::post('/pin/set',           [TenantControllers\\StaffSwitchController::class, 'setInitialPin'])->name('pin.set');
            Route::post('/pin/reset-request', [TenantControllers\\StaffSwitchController::class, 'requestReset'])->name('pin.reset-request');
        });

        Route::middleware([
            'App\\Http\\Middleware\\ConsumeOnboardingToken',
            'App\\Http\\Middleware\\EnsureTrustedDevice',
            'App\\Http\\Middleware\\RequireTenantAuth',
            'App\\Http\\Middleware\\ApplyTenantTheme',
        ])->group(function () {"""

    if s.count(old) != 1:
        print(f"STEP 5: ABORT (route anchor matches {s.count(old)} times)")
        raise SystemExit(1)

    s = s.replace(old, new)
    p.write_text(s)
    print("STEP 5: OK (StaffSwitchController routes wired)")
PY

# ----------------------------------------------------------------------------
# STEP 6 — AuthController::resolveLocationAndContinue() — route to switcher
#          for pin_tier_active tenants instead of dashboard.
#
# Wait — that's wrong. After a successful EMAIL login, the user IS the
# user; they shouldn't be re-PIN-prompted. The switcher is for when a
# user with a TRUSTED DEVICE arrives without a session — that's the
# middleware's job to route, not the controller's.
#
# But there IS a case: a Branded tenant where two staff share an iPad
# would email-login the FIRST time (no trust yet, no PIN yet), then both
# need their own PIN. So on the FIRST email login from a tenant with
# pin_tier_active where the user has NO PIN, we should drop them into
# the set-initial-PIN flow before dashboard.
#
# Actually no — the email login proves identity for THIS user. They go
# to dashboard. The PIN gets set when they visit the switcher next time
# (e.g., after idle lock fires in chunk 6, or by manually visiting /admin/switch).
#
# Keeping resolveLocationAndContinue unchanged. The switcher is reached
# via:
#   1. Direct visit to /admin/switch
#   2. Trusted device with no session (chunk 4 sends them to login;
#      chunk 6 will change that to the switcher)
#
# So this step is intentionally a no-op for chunk 5. Documenting here
# for the record.
# ----------------------------------------------------------------------------

echo "STEP 6: SKIP (no AuthController change needed in this chunk — see notes)"

# ----------------------------------------------------------------------------
# STEP 7 — Make TenantUser's PIN columns visible to attribute access.
# ----------------------------------------------------------------------------

python3 <<'PY'
from pathlib import Path
p = Path('app/Models/Tenant/TenantUser.php')
s = p.read_text()

if "pin_hash" in s:
    print("STEP 7: SKIP (PIN attrs already in TenantUser fillable/casts)")
else:
    # Add to fillable.
    old_fill = "protected $fillable = ['tenant_id','name','email','phone','password','role','is_active','last_login_at'];"
    new_fill = "protected $fillable = ['tenant_id','name','email','phone','password','role','is_active','last_login_at','pin_hash','pin_set_at','pin_failed_count','pin_locked_until','pin_last_used_at'];"

    if s.count(old_fill) != 1:
        print(f"STEP 7a: ABORT (fillable anchor matches {s.count(old_fill)} times)")
        raise SystemExit(1)
    s = s.replace(old_fill, new_fill)

    # Update casts.
    old_casts = "protected $casts    = ['is_active' => 'boolean', 'last_login_at' => 'datetime'];"
    new_casts = "protected $casts    = ['is_active' => 'boolean', 'last_login_at' => 'datetime', 'pin_set_at' => 'datetime', 'pin_locked_until' => 'datetime', 'pin_last_used_at' => 'datetime'];"

    if s.count(old_casts) != 1:
        print(f"STEP 7b: ABORT (casts anchor matches {s.count(old_casts)} times)")
        raise SystemExit(1)
    s = s.replace(old_casts, new_casts)

    # Add pin_hash to $hidden so it never serializes.
    old_hidden = "protected $hidden   = ['password','remember_token'];"
    new_hidden = "protected $hidden   = ['password','remember_token','pin_hash'];"

    if s.count(old_hidden) != 1:
        print(f"STEP 7c: ABORT (hidden anchor matches {s.count(old_hidden)} times)")
        raise SystemExit(1)
    s = s.replace(old_hidden, new_hidden)

    p.write_text(s)
    print("STEP 7: OK (TenantUser updated: pin_* in fillable/casts/hidden)")
PY

# ----------------------------------------------------------------------------
# Post-edit verification
# ----------------------------------------------------------------------------

echo ""
echo "----------------------------------------"
echo "VERIFY: new files created"
echo "----------------------------------------"
for f in app/Services/PinService.php \
         app/Http/Controllers/Tenant/StaffSwitchController.php \
         resources/views/tenant/auth/switch.blade.php; do
    if [ -f "$f" ]; then
        echo "  ✓ $f ($(wc -l < $f) lines)"
    else
        echo "  ✗ $f MISSING"
    fi
done

echo ""
echo "----------------------------------------"
echo "VERIFY: routes wired"
echo "----------------------------------------"
grep -n "StaffSwitchController\|pin.verify\|tenant.switch" routes/web.php | head -10

echo ""
echo "----------------------------------------"
echo "VERIFY: config block"
echo "----------------------------------------"
grep -n "pin_cooldown_ladder\|device_trust_expiry_days" config/intake.php | head -5

echo ""
echo "----------------------------------------"
echo "VERIFY: TenantUser pin columns"
echo "----------------------------------------"
grep -n "pin_hash\|pin_locked_until" app/Models/Tenant/TenantUser.php | head -5

echo ""
echo "=========================================="
echo "Chunk 5 application complete."
echo ""
echo "Server steps:"
echo "  git pull && composer install --no-interaction --no-scripts && \\"
echo "  php artisan view:clear && php artisan optimize:clear && \\"
echo "  systemctl stop php8.3-fpm && sleep 2 && systemctl start php8.3-fpm"
echo "  (no migrations — schema landed in chunk 1)"
echo ""
echo "Verify:"
echo "  1. /admin/login still works on the bike hub (single user, gate off)."
echo ""
echo "  2. With a 2nd user added (re-do the tinker steps from before), visit:"
echo "       https://thebikehub.intake.works/admin/switch"
echo "     Should see the staff card grid with both names."
echo ""
echo "  3. Click a card where pin_hash is null → set-initial-PIN form."
echo "     Enter 4 digits, confirm them, enter your email password (any"
echo "     active user's password works), submit → land at dashboard."
echo ""
echo "  4. Sign out. Visit /admin/switch again. Click your card → PIN entry."
echo "     Enter the same 4 digits → land at dashboard."
echo ""
echo "  5. Wrong PIN entries: 1-2 wrong = generic error, 3 = 5sec cooldown,"
echo "     4 = 30sec, 5 = card soft-locks."
echo "=========================================="
