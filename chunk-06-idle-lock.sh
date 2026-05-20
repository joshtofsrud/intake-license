#!/usr/bin/env bash
# ============================================================================
# Auth Refactor — Chunk 6
# Idle lock middleware + heartbeat + PIN-unlock endpoint + lock overlay UI
#
# CONTEXT
#   Layer 3 of the auth refactor (auth-refactor-spec-v2.md §5).
#
#   Problem this solves: a signed-in iPad sitting idle is wide open. The
#   PIN tier (chunk 5) authenticates identity once, then trusts forever.
#   Real shops need the device to lock itself after inactivity, with
#   re-PIN to unlock. Page state stays intact under the overlay.
#
# WHAT THIS PATCH ADDS
#   1. Session keys:
#        last_pin_activity_at  - timestamp of last verified PIN entry
#                                (set on PIN verify in chunk 5)
#                                (touched on every authenticated request)
#        pin_lock_pending      - boolean flag set by middleware when
#                                staleness detected on a page render
#
#   2. EnsurePinFresh middleware - mounted in the auth'd tenant group:
#        - Skip if tenant->pin_tier_active is false
#        - Skip if route is in the whitelist (heartbeat, unlock, switcher,
#          location-picker)
#        - If last_pin_activity_at is null OR older than threshold:
#            * AJAX request -> 423 Locked + JSON { locked: true }
#            * Page render -> set $pinLockPending = true (view reads it)
#            * Either way: client opens the overlay
#        - Otherwise: touch the timestamp (capped at 1 update per minute
#          to avoid hammering the session store)
#
#   3. PinGateController with two endpoints:
#        POST /admin/pin/heartbeat - client pings every 60s while active;
#                                    touches last_pin_activity_at
#        POST /admin/pin/unlock    - verify PIN for the currently signed-in
#                                    user; clears pin_lock_pending and
#                                    refreshes last_pin_activity_at
#
#   4. lock-overlay.blade.php partial - the overlay markup. Always rendered
#      in app.blade.php (display:none by default). Opens when:
#        - JS detects idle locally (client-side timer)
#        - Server flagged the page render as stale ($pinLockPending = true)
#        - An AJAX fetch returned 423 (caught by global response handler)
#
#   5. idle-lock.js - client-side idle tracking + overlay control:
#        - Activity listeners (mousemove, keydown, touchstart, scroll, click)
#        - Idle threshold check on a tight interval
#        - Heartbeat poster (every 60s while not idle)
#        - Global 423 catcher for fetch()
#        - Overlay show/hide + PIN auto-advance + submit
#
# CONFIG
#   New keys in config/intake.php auth section:
#     - pin_idle_threshold_sec        (default 120)
#     - pin_heartbeat_interval_sec    (default 60)
#
# IDEMPOTENCY: every step checks before acting.
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
echo "Auth Refactor — Chunk 6 (idle lock)"
echo "Running in: $(pwd)"
echo "=========================================="

# ----------------------------------------------------------------------------
# STEP 1 — Add config keys for idle threshold + heartbeat interval
# ----------------------------------------------------------------------------

python3 <<'PY'
from pathlib import Path
p = Path('config/intake.php')
s = p.read_text()

if "'pin_idle_threshold_sec'" in s:
    print("STEP 1: SKIP (idle config already present)")
else:
    old = "'device_trust_expiry_days' => 90,\n    ],"
    new = """'device_trust_expiry_days' => 90,

        // Idle lock — Layer 3 of the auth refactor (chunk 6).
        // last_pin_activity_at older than this triggers the lock overlay.
        // Tunable per-tenant later via the sign-in security admin (chunk 8).
        'pin_idle_threshold_sec' => 120,

        // Client heartbeat interval. Should be well below the idle
        // threshold; we picked half. 60s pings against a 120s threshold
        // means at most one missed heartbeat before the lock fires.
        'pin_heartbeat_interval_sec' => 60,
    ],"""

    if s.count(old) != 1:
        print(f"STEP 1: ABORT (anchor matches {s.count(old)} times)")
        raise SystemExit(1)
    s = s.replace(old, new)
    p.write_text(s)
    print("STEP 1: OK (added pin_idle_threshold_sec + pin_heartbeat_interval_sec)")
PY

# ----------------------------------------------------------------------------
# STEP 2 — EnsurePinFresh middleware
# ----------------------------------------------------------------------------

MW_FILE=app/Http/Middleware/EnsurePinFresh.php

if [ -f "$MW_FILE" ]; then
    echo "STEP 2: SKIP (middleware already exists)"
else
    cat > "$MW_FILE" <<'PHP'
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsurePinFresh
 *
 * Layer 3 of the auth refactor. Enforces an idle-timeout re-PIN.
 *
 * Resolution:
 *   1. Tenant pin_tier_active is false -> pass through. (Starter and
 *      single-user Branded never see the lock.)
 *   2. Route is in the whitelist (heartbeat, unlock, switch, location
 *      picker, logout) -> pass through. These routes need to work even
 *      when the lock is active.
 *   3. Read session('last_pin_activity_at'). If null or older than the
 *      configured threshold:
 *        - For AJAX requests: respond with 423 Locked + JSON body.
 *          Client overlay catches this globally.
 *        - For page renders: set $pinLockPending in the view so the
 *          layout opens the overlay on render. Page state under the
 *          overlay stays intact.
 *   4. Else: touch the timestamp (rate-limited to once per minute to
 *      avoid hammering the session store).
 *
 * The server is the source of truth for staleness; the client-side
 * idle detector is just a UX accelerator that shows the overlay
 * locally before the next request would have shown it server-side.
 */
class EnsurePinFresh
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = app('tenant') ?? null;

        if (! $tenant || ! $tenant->pin_tier_active) {
            return $next($request);
        }

        // Routes that must work even when the lock is pending.
        $routeName = $request->route()?->getName() ?? '';
        $whitelist = [
            'tenant.pin.heartbeat',
            'tenant.pin.unlock',
            'tenant.switch',
            'tenant.pin.verify',
            'tenant.pin.set',
            'tenant.pin.reset-request',
            'tenant.logout',
            'tenant.select-location',
            'tenant.select-location.store',
            'tenant.switch-location',
        ];
        if (in_array($routeName, $whitelist, true)) {
            return $next($request);
        }

        $thresholdSec = (int) config('intake.auth.pin_idle_threshold_sec', 120);
        $lastIso = $request->session()->get('last_pin_activity_at');

        $isStale = true;
        if ($lastIso) {
            try {
                $last = \Illuminate\Support\Carbon::parse($lastIso);
                $isStale = $last->lt(now()->subSeconds($thresholdSec));
            } catch (\Throwable $e) {
                $isStale = true;
            }
        }

        if ($isStale) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'ok'     => false,
                    'locked' => true,
                    'error'  => 'pin_stale',
                ], 423);
            }

            // Page render — flag the staleness; layout opens the overlay.
            view()->share('pinLockPending', true);
            return $next($request);
        }

        // Fresh — touch the activity timestamp, but cap at once a minute
        // so we don't write the session on every single request.
        if ($lastIso) {
            try {
                $last = \Illuminate\Support\Carbon::parse($lastIso);
                if ($last->lt(now()->subMinute())) {
                    $request->session()->put('last_pin_activity_at', now()->toIso8601String());
                }
            } catch (\Throwable $e) {
                // If parse failed, set it fresh so subsequent requests work.
                $request->session()->put('last_pin_activity_at', now()->toIso8601String());
            }
        }

        view()->share('pinLockPending', false);
        return $next($request);
    }
}
PHP
    echo "STEP 2: OK (created EnsurePinFresh middleware)"
fi

# ----------------------------------------------------------------------------
# STEP 3 — PinGateController (heartbeat + unlock)
# ----------------------------------------------------------------------------

CTRL_FILE=app/Http/Controllers/Tenant/PinGateController.php

if [ -f "$CTRL_FILE" ]; then
    echo "STEP 3: SKIP (PinGateController already exists)"
else
    cat > "$CTRL_FILE" <<'PHP'
<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\PinService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * PinGateController
 *
 * Two endpoints supporting Layer 3 (idle lock) of the auth refactor.
 *
 *   POST /admin/pin/heartbeat  - client pings while active; touches
 *                                last_pin_activity_at so the idle
 *                                middleware doesn't lock prematurely.
 *
 *   POST /admin/pin/unlock     - verify PIN for the currently signed-in
 *                                user; on success, refresh
 *                                last_pin_activity_at and dismiss the
 *                                overlay.
 *
 * Subdomain trap: every method takes `string $subdomain` first.
 */
class PinGateController extends Controller
{
    public function __construct(protected PinService $pins) {}

    /**
     * POST /admin/pin/heartbeat
     *
     * No body required. Returns ok: true if session is healthy, or
     * { locked: true } if the heartbeat itself arrived past the
     * idle threshold (in which case the client should open the overlay).
     */
    public function heartbeat(string $subdomain, Request $request)
    {
        if (! Auth::guard('tenant')->check()) {
            return response()->json(['ok' => false, 'error' => 'not_signed_in'], 401);
        }

        $thresholdSec = (int) config('intake.auth.pin_idle_threshold_sec', 120);
        $lastIso = $request->session()->get('last_pin_activity_at');

        if (! $lastIso) {
            // Never had a PIN activity timestamp - shouldn't happen for
            // a pin_tier_active tenant whose user is signed in via PIN,
            // but defensive return locked so the client overlays.
            return response()->json([
                'ok' => false,
                'locked' => true,
                'error' => 'pin_stale',
            ], 423);
        }

        try {
            $last = \Illuminate\Support\Carbon::parse($lastIso);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'locked' => true], 423);
        }

        if ($last->lt(now()->subSeconds($thresholdSec))) {
            // Idle exceeded between heartbeats - tell the client to lock.
            return response()->json([
                'ok' => false,
                'locked' => true,
                'error' => 'pin_stale',
            ], 423);
        }

        // Fresh - bump (rate-limited to once per minute, same as middleware).
        if ($last->lt(now()->subMinute())) {
            $request->session()->put('last_pin_activity_at', now()->toIso8601String());
        }

        return response()->json(['ok' => true]);
    }

    /**
     * POST /admin/pin/unlock
     * Body: { pin }
     *
     * Verifies the PIN against the currently signed-in user.
     */
    public function unlock(string $subdomain, Request $request)
    {
        $user = Auth::guard('tenant')->user();
        if (! $user) {
            return response()->json(['ok' => false, 'error' => 'not_signed_in'], 401);
        }

        $request->validate([
            'pin' => ['required', 'string', 'regex:/^\d{4}$/'],
        ]);

        if (! $user->pin_hash) {
            // No PIN set - should not be possible for pin_tier_active,
            // but handle gracefully.
            return response()->json([
                'ok' => false,
                'error' => 'pin_not_set',
            ], 400);
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
            return response()->json(['ok' => false, 'error' => 'pin_locked'], 423);
        }

        if (! $ok) {
            $user->refresh();
            return response()->json([
                'ok' => false,
                'error' => 'pin_mismatch',
                'failed_count' => $user->pin_failed_count,
            ], 422);
        }

        // Success - bump activity timestamp.
        $request->session()->put('last_pin_activity_at', now()->toIso8601String());

        return response()->json(['ok' => true]);
    }
}
PHP
    echo "STEP 3: OK (created PinGateController)"
fi

# ----------------------------------------------------------------------------
# STEP 4 — Wire heartbeat + unlock routes
# ----------------------------------------------------------------------------

python3 <<'PY'
from pathlib import Path
p = Path('routes/web.php')
s = p.read_text()

if "PinGateController" in s:
    print("STEP 4: SKIP (routes already wired)")
else:
    # Mount heartbeat + unlock inside the auth'd tenant group so they
    # require a signed-in user. They go OUTSIDE EnsurePinFresh because
    # they're whitelisted by it (they need to work even when lock is
    # pending).
    #
    # Anchor: end of select-location.* routes (which sit at the top of
    # the RequireTenantAuth group).
    old = """            Route::post('/switch-location',  [TenantControllers\\AuthController::class, 'switchLocation'])->name('switch-location');"""

    new = """            Route::post('/switch-location',  [TenantControllers\\AuthController::class, 'switchLocation'])->name('switch-location');

            // PIN gate endpoints (chunk 6) - whitelisted by EnsurePinFresh
            // so they work even when the lock overlay is pending.
            Route::post('/pin/heartbeat',    [TenantControllers\\PinGateController::class, 'heartbeat'])->name('pin.heartbeat');
            Route::post('/pin/unlock',       [TenantControllers\\PinGateController::class, 'unlock'])->name('pin.unlock');"""

    if s.count(old) != 1:
        print(f"STEP 4: ABORT (anchor matches {s.count(old)} times)")
        raise SystemExit(1)

    s = s.replace(old, new)
    p.write_text(s)
    print("STEP 4: OK (heartbeat + unlock routes wired)")
PY

# ----------------------------------------------------------------------------
# STEP 5 — Mount EnsurePinFresh middleware in the auth'd tenant group
# ----------------------------------------------------------------------------

python3 <<'PY'
from pathlib import Path
p = Path('routes/web.php')
s = p.read_text()

if "EnsurePinFresh" in s:
    print("STEP 5: SKIP (EnsurePinFresh already mounted)")
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
        print(f"STEP 5: ABORT (middleware group anchor matches {s.count(old)} times)")
        raise SystemExit(1)
    s = s.replace(old, new)
    p.write_text(s)
    print("STEP 5: OK (EnsurePinFresh mounted in tenant middleware stack)")
PY

# ----------------------------------------------------------------------------
# STEP 6 — Set last_pin_activity_at when PIN verify succeeds (chunk 5
#          didn't write the session key because chunk 6 didn't exist yet).
# ----------------------------------------------------------------------------

python3 <<'PY'
from pathlib import Path
p = Path('app/Http/Controllers/Tenant/StaffSwitchController.php')
s = p.read_text()

if "last_pin_activity_at" in s:
    print("STEP 6: SKIP (PIN activity timestamp already set in StaffSwitchController)")
else:
    # Two sites: verifyPin success path, and setInitialPin success path.
    # Both call Auth::guard('tenant')->login($user); ...regenerate(); ...save();
    # Insert the session put after the save() in each.
    occurrences = s.count("$user->forceFill(['last_login_at' => now()])->save();")
    if occurrences != 2:
        print(f"STEP 6: ABORT (expected 2 sites in StaffSwitchController, found {occurrences})")
        raise SystemExit(1)

    new = ("$user->forceFill(['last_login_at' => now()])->save();\n\n"
           "        // Mark PIN activity for the idle-lock middleware (chunk 6).\n"
           "        $request->session()->put('last_pin_activity_at', now()->toIso8601String());")

    s = s.replace(
        "$user->forceFill(['last_login_at' => now()])->save();",
        new
    )
    p.write_text(s)
    print("STEP 6: OK (StaffSwitchController now sets last_pin_activity_at on PIN success)")
PY

# ----------------------------------------------------------------------------
# STEP 7 — Create the lock-overlay partial
# ----------------------------------------------------------------------------

PARTIAL_FILE=resources/views/layouts/tenant/_lock-overlay.blade.php

if [ -f "$PARTIAL_FILE" ]; then
    echo "STEP 7: SKIP (lock overlay partial already exists)"
else
    cat > "$PARTIAL_FILE" <<'BLADE'
{{-- ================================================================
     Idle-lock overlay (chunk 6).
     Always present in the DOM on authenticated pages where pin_tier_active.
     Hidden by default (display:none); shown when:
       1. Server flagged this page render with pinLockPending=true
       2. Client JS detected idle locally
       3. An AJAX fetch returned 423 Locked (caught by global handler in idle-lock.js)
     ================================================================ --}}
@if(isset($currentTenant) && $currentTenant->pin_tier_active && isset($authUser))
<div class="ia-lock-overlay" id="ia-lock-overlay"
     style="display: {{ ($pinLockPending ?? false) ? 'flex' : 'none' }}"
     data-initially-locked="{{ ($pinLockPending ?? false) ? '1' : '0' }}"
     role="dialog"
     aria-modal="true"
     aria-labelledby="ia-lock-title">
  <div class="ia-lock-card">
    <div class="ia-lock-icon" aria-hidden="true">
      <svg width="28" height="28" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
      </svg>
    </div>
    <div class="ia-lock-title" id="ia-lock-title">Signed in as {{ $authUser->name }}</div>
    <div class="ia-lock-sub">Enter your PIN to continue</div>

    <div class="ia-lock-pin-wrap">
      <input type="text" inputmode="numeric" pattern="\d*" maxlength="1" class="ia-lock-pin-input" data-lock-pos="0" autocomplete="off">
      <input type="text" inputmode="numeric" pattern="\d*" maxlength="1" class="ia-lock-pin-input" data-lock-pos="1" autocomplete="off">
      <input type="text" inputmode="numeric" pattern="\d*" maxlength="1" class="ia-lock-pin-input" data-lock-pos="2" autocomplete="off">
      <input type="text" inputmode="numeric" pattern="\d*" maxlength="1" class="ia-lock-pin-input" data-lock-pos="3" autocomplete="off">
    </div>

    <div class="ia-lock-msg" id="ia-lock-msg"></div>

    <div class="ia-lock-actions">
      <a href="{{ route('tenant.switch') }}" class="ia-lock-btn ia-lock-btn-ghost">← Not you?</a>
      <button type="button" class="ia-lock-btn ia-lock-btn-primary" id="ia-lock-submit">Unlock</button>
    </div>

    <div class="ia-lock-footer">Your work is preserved beneath this screen.</div>
  </div>
</div>
@endif
BLADE
    echo "STEP 7: OK (created _lock-overlay.blade.php)"
fi

# ----------------------------------------------------------------------------
# STEP 8 — Append CSS for the overlay
# ----------------------------------------------------------------------------

python3 <<'PY'
from pathlib import Path
p = Path('public/css/tenant/base.css')
s = p.read_text()

if "/* === Idle lock overlay (chunk 6) === */" in s:
    print("STEP 8: SKIP (overlay CSS already present)")
else:
    css = """

/* === Idle lock overlay (chunk 6) === */
.ia-lock-overlay {
  position: fixed;
  inset: 0;
  z-index: 9999;
  background: rgba(0, 0, 0, 0.55);
  backdrop-filter: blur(4px);
  -webkit-backdrop-filter: blur(4px);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
  overflow: auto;
}
.ia-lock-card {
  background: var(--ia-surface-1, #1a1a1a);
  border: 1px solid var(--ia-border, rgba(255,255,255,.12));
  border-radius: var(--ia-r-lg, 12px);
  padding: 28px 32px;
  width: 100%;
  max-width: 380px;
  text-align: center;
  box-shadow: 0 12px 48px rgba(0, 0, 0, 0.5);
}
.ia-lock-icon {
  width: 60px;
  height: 60px;
  border-radius: 50%;
  background: var(--ia-accent-soft, rgba(190, 242, 100, 0.1));
  color: var(--ia-accent, #BEF264);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 14px;
}
.ia-lock-title {
  font-size: 16px;
  font-weight: 600;
  color: var(--ia-text, inherit);
  margin-bottom: 4px;
}
.ia-lock-sub {
  font-size: 13px;
  opacity: 0.6;
  margin-bottom: 22px;
}
.ia-lock-pin-wrap {
  display: flex;
  justify-content: center;
  gap: 10px;
  margin-bottom: 16px;
}
.ia-lock-pin-input {
  width: 50px;
  height: 60px;
  background: rgba(255, 255, 255, 0.05);
  border: 1px solid var(--ia-border, rgba(255,255,255,.12));
  border-radius: var(--ia-r-md, 8px);
  color: var(--ia-text, inherit);
  font-size: 22px;
  font-weight: 600;
  text-align: center;
  font-family: inherit;
  transition: border-color 0.12s;
}
.ia-lock-pin-input:focus {
  outline: none;
  border-color: var(--ia-accent, #BEF264);
}
.ia-lock-pin-input.error {
  border-color: #E24B4A;
}
.ia-lock-msg {
  font-size: 13px;
  min-height: 18px;
  margin-bottom: 14px;
  color: #F09595;
}
.ia-lock-msg.info {
  color: var(--ia-muted, rgba(255,255,255,.55));
}
.ia-lock-actions {
  display: flex;
  gap: 10px;
  margin-bottom: 16px;
}
.ia-lock-btn {
  flex: 1;
  padding: 10px 14px;
  border-radius: var(--ia-r-md, 8px);
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  border: 1px solid var(--ia-border, rgba(255,255,255,.12));
  background: transparent;
  color: var(--ia-text, inherit);
  font-family: inherit;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  transition: background 0.12s, filter 0.12s;
}
.ia-lock-btn-ghost:hover {
  background: rgba(255, 255, 255, 0.06);
}
.ia-lock-btn-primary {
  background: var(--ia-accent, #BEF264);
  color: var(--ia-accent-text, #000);
  border-color: var(--ia-accent, #BEF264);
}
.ia-lock-btn-primary:hover {
  filter: brightness(0.93);
}
.ia-lock-footer {
  font-size: 11px;
  opacity: 0.4;
  padding-top: 12px;
  border-top: 0.5px solid var(--ia-border, rgba(255,255,255,.10));
}

/* Light theme polish */
.ia-theme-b .ia-lock-card {
  background: #ffffff;
  border-color: rgba(0, 0, 0, 0.10);
  box-shadow: 0 12px 48px rgba(0, 0, 0, 0.18);
}
.ia-theme-b .ia-lock-pin-input {
  background: rgba(0, 0, 0, 0.03);
  border-color: rgba(0, 0, 0, 0.10);
}
.ia-theme-b .ia-lock-btn {
  border-color: rgba(0, 0, 0, 0.12);
}
.ia-theme-b .ia-lock-btn-ghost:hover {
  background: rgba(0, 0, 0, 0.05);
}
"""
    s = s + css
    p.write_text(s)
    print("STEP 8: OK (appended overlay CSS to base.css)")
PY

# ----------------------------------------------------------------------------
# STEP 9 — idle-lock.js: idle detector, heartbeat, overlay control,
#          global 423 catcher
# ----------------------------------------------------------------------------

JS_FILE=public/js/tenant/idle-lock.js

if [ -f "$JS_FILE" ] && grep -q "CHUNK-6 idle lock" "$JS_FILE"; then
    echo "STEP 9: SKIP (idle-lock.js already exists)"
else
    cat > "$JS_FILE" <<'JS'
/* CHUNK-6 idle lock — client-side idle detector + overlay control */
(function () {
  'use strict';

  const overlay = document.getElementById('ia-lock-overlay');
  if (!overlay) {
    // No overlay in DOM means tenant is not pin_tier_active or user
    // is not authenticated. Nothing to do.
    return;
  }

  const admin = window.IntakeAdmin || {};
  const csrf = admin.csrfToken;

  // Configurable from window.IntakeAdmin if needed; defaults here match
  // the config/intake.php server-side values.
  const IDLE_THRESHOLD_MS    = (admin.pinIdleThresholdSec || 120) * 1000;
  const HEARTBEAT_INTERVAL_MS = (admin.pinHeartbeatIntervalSec || 60) * 1000;

  let lastActivityAt = Date.now();
  let isLocked       = overlay.dataset.initiallyLocked === '1';
  let heartbeatTimer = null;
  let idleCheckTimer = null;

  const inputs = Array.from(overlay.querySelectorAll('.ia-lock-pin-input'));
  const msgEl  = overlay.querySelector('#ia-lock-msg');
  const submitBtn = overlay.querySelector('#ia-lock-submit');

  function showOverlay() {
    if (overlay.style.display === 'flex') return;
    overlay.style.display = 'flex';
    isLocked = true;
    inputs.forEach(i => { i.value = ''; i.classList.remove('error'); });
    setTimeout(() => { inputs[0]?.focus(); }, 50);
    stopHeartbeat();
    msg('', '');
  }

  function hideOverlay() {
    overlay.style.display = 'none';
    isLocked = false;
    lastActivityAt = Date.now();
    inputs.forEach(i => i.value = '');
    msg('', '');
    startHeartbeat();
  }

  function msg(text, kind) {
    if (!msgEl) return;
    msgEl.textContent = text || '';
    msgEl.className = 'ia-lock-msg' + (kind ? ' ' + kind : '');
  }

  function recordActivity() {
    if (isLocked) return;
    lastActivityAt = Date.now();
  }

  function checkIdle() {
    if (isLocked) return;
    if (Date.now() - lastActivityAt >= IDLE_THRESHOLD_MS) {
      showOverlay();
    }
  }

  async function heartbeat() {
    if (isLocked) return;
    try {
      const res = await fetch('/admin/pin/heartbeat', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
      });
      if (res.status === 423) {
        showOverlay();
      } else if (res.status === 401) {
        // User signed out elsewhere. Send them to the login page.
        window.location.href = '/admin/login';
      }
    } catch (err) {
      // Network issue — silent. If it persists, next idle check or
      // user activity will retry.
    }
  }

  async function submitPin() {
    const pin = inputs.map(i => i.value).join('');
    if (pin.length !== 4) return;
    if (submitBtn) submitBtn.disabled = true;
    msg('Checking…', 'info');

    try {
      const res = await fetch('/admin/pin/unlock', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
        body: JSON.stringify({ pin })
      });
      const body = await res.json().catch(() => ({}));

      if (res.ok && body.ok) {
        hideOverlay();
        return;
      }

      if (body.error === 'pin_locked') {
        msg('Too many wrong attempts. Ask an owner to unlock.', '');
        inputs.forEach(i => i.classList.add('error'));
        return;
      }

      if (body.error === 'pin_mismatch') {
        msg("That PIN didn't match. Try again.", '');
        inputs.forEach(i => { i.value = ''; i.classList.add('error'); });
        inputs[0]?.focus();
        setTimeout(() => inputs.forEach(i => i.classList.remove('error')), 600);
        return;
      }

      msg('Something went wrong. Try again.', '');
    } catch (err) {
      msg('Network error. Try again.', '');
    } finally {
      if (submitBtn) submitBtn.disabled = false;
    }
  }

  // Activity listeners — any of these resets idle.
  ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll', 'click'].forEach(ev => {
    document.addEventListener(ev, recordActivity, { passive: true, capture: true });
  });

  // PIN inputs — auto-advance + submit-on-fill.
  inputs.forEach((inp, idx) => {
    inp.addEventListener('input', () => {
      inp.value = inp.value.replace(/\D/g, '').slice(0, 1);
      if (inp.value && idx < inputs.length - 1) {
        inputs[idx + 1].focus();
      }
      if (inputs.every(i => i.value)) {
        submitPin();
      }
    });
    inp.addEventListener('keydown', (e) => {
      if (e.key === 'Backspace' && !inp.value && idx > 0) {
        inputs[idx - 1].focus();
      }
      if (e.key === 'Enter') {
        submitPin();
      }
    });
  });

  if (submitBtn) {
    submitBtn.addEventListener('click', submitPin);
  }

  // Global 423 catcher — wraps fetch() so any auth'd AJAX call that
  // returns 423 opens the overlay automatically.
  const _fetch = window.fetch;
  window.fetch = async function (...args) {
    const res = await _fetch.apply(this, args);
    if (res.status === 423) {
      // Clone before reading so the original caller can still use it.
      try {
        const clone = res.clone();
        const body = await clone.json();
        if (body && body.locked) {
          showOverlay();
        }
      } catch (e) {
        // Body wasn't JSON or didn't have locked:true. Don't intervene.
      }
    }
    return res;
  };

  // Timers
  function startHeartbeat() {
    stopHeartbeat();
    heartbeatTimer = setInterval(heartbeat, HEARTBEAT_INTERVAL_MS);
  }
  function stopHeartbeat() {
    if (heartbeatTimer) clearInterval(heartbeatTimer);
    heartbeatTimer = null;
  }

  idleCheckTimer = setInterval(checkIdle, 5000);

  if (isLocked) {
    // Server-flagged stale render — focus the PIN field, don't start heartbeat.
    setTimeout(() => { inputs[0]?.focus(); }, 100);
  } else {
    startHeartbeat();
  }
})();
JS
    echo "STEP 9: OK (created idle-lock.js)"
fi

# ----------------------------------------------------------------------------
# STEP 10 — Mount the overlay in app.blade.php + load idle-lock.js
# ----------------------------------------------------------------------------

python3 <<'PY'
from pathlib import Path
p = Path('resources/views/layouts/tenant/app.blade.php')
s = p.read_text()

if "_lock-overlay" in s:
    print("STEP 10a: SKIP (overlay partial already included)")
else:
    # Include the overlay just before closing </body>. Easiest anchor:
    # the @stack('scripts') line.
    old = "@stack('scripts')"
    new = "@include('layouts.tenant._lock-overlay')\n\n@stack('scripts')"
    if s.count(old) != 1:
        print(f"STEP 10a: ABORT (anchor matches {s.count(old)} times)")
        raise SystemExit(1)
    s = s.replace(old, new)
    p.write_text(s)
    print("STEP 10a: OK (overlay partial included)")
PY

python3 <<'PY'
from pathlib import Path
p = Path('resources/views/layouts/tenant/app.blade.php')
s = p.read_text()

if "js/tenant/idle-lock.js" in s:
    print("STEP 10b: SKIP (idle-lock.js script tag already present)")
else:
    old = '<script src="{{ asset(\'js/tenant/location-switcher.js\') }}?v={{ filemtime(public_path(\'js/tenant/location-switcher.js\')) }}" defer></script>'
    new = old + '\n<script src="{{ asset(\'js/tenant/idle-lock.js\') }}?v={{ filemtime(public_path(\'js/tenant/idle-lock.js\')) }}" defer></script>'
    if s.count(old) != 1:
        print(f"STEP 10b: ABORT (anchor matches {s.count(old)} times)")
        raise SystemExit(1)
    s = s.replace(old, new)
    p.write_text(s)
    print("STEP 10b: OK (idle-lock.js script tag added)")
PY

# ----------------------------------------------------------------------------
# STEP 11 — Inject config values into window.IntakeAdmin so JS can use them
# ----------------------------------------------------------------------------

python3 <<'PY'
from pathlib import Path
p = Path('resources/views/layouts/tenant/app.blade.php')
s = p.read_text()

if "pinIdleThresholdSec" in s:
    print("STEP 11: SKIP (config values already in IntakeAdmin)")
else:
    old = """    ajaxUrl:    '{{ url(\"/admin/ajax\") }}',
  };
</script>"""

    new = """    ajaxUrl:    '{{ url(\"/admin/ajax\") }}',
    pinIdleThresholdSec:    {{ (int) config('intake.auth.pin_idle_threshold_sec', 120) }},
    pinHeartbeatIntervalSec:{{ (int) config('intake.auth.pin_heartbeat_interval_sec', 60) }},
  };
</script>"""

    if s.count(old) != 1:
        print(f"STEP 11: ABORT (IntakeAdmin block anchor matches {s.count(old)} times)")
        raise SystemExit(1)
    s = s.replace(old, new)
    p.write_text(s)
    print("STEP 11: OK (config values injected into window.IntakeAdmin)")
PY

# ----------------------------------------------------------------------------
# Post-edit verification
# ----------------------------------------------------------------------------

echo ""
echo "----------------------------------------"
echo "VERIFY: new files"
echo "----------------------------------------"
for f in app/Http/Middleware/EnsurePinFresh.php \
         app/Http/Controllers/Tenant/PinGateController.php \
         resources/views/layouts/tenant/_lock-overlay.blade.php \
         public/js/tenant/idle-lock.js; do
    if [ -f "$f" ]; then
        echo "  ✓ $f ($(wc -l < $f) lines)"
    else
        echo "  ✗ $f MISSING"
    fi
done

echo ""
echo "----------------------------------------"
echo "VERIFY: middleware wired"
echo "----------------------------------------"
grep -n "EnsurePinFresh\|RequireTenantAuth" routes/web.php | head -6

echo ""
echo "----------------------------------------"
echo "VERIFY: heartbeat + unlock routes"
echo "----------------------------------------"
grep -n "pin.heartbeat\|pin.unlock" routes/web.php | head -5

echo ""
echo "----------------------------------------"
echo "VERIFY: app.blade.php overlay + JS + config"
echo "----------------------------------------"
grep -n "_lock-overlay\|idle-lock.js\|pinIdleThresholdSec" resources/views/layouts/tenant/app.blade.php | head -5

echo ""
echo "----------------------------------------"
echo "VERIFY: StaffSwitchController writes last_pin_activity_at"
echo "----------------------------------------"
grep -n "last_pin_activity_at" app/Http/Controllers/Tenant/StaffSwitchController.php | head -5

echo ""
echo "=========================================="
echo "Chunk 6 application complete."
echo ""
echo "Server steps:"
echo "  git pull && composer install --no-interaction --no-scripts && \\"
echo "  php artisan view:clear && php artisan optimize:clear && \\"
echo "  systemctl stop php8.3-fpm && sleep 2 && systemctl start php8.3-fpm"
echo "  (no migrations)"
echo ""
echo "Verify on thebikehub.intake.works (with 2 users + PIN tier active):"
echo ""
echo "  1. Visit /admin/switch, sign in via PIN. Lands on dashboard."
echo "  2. DON'T touch the browser for 2+ minutes."
echo "  3. The overlay should drop with your name + PIN field."
echo "  4. Enter your PIN → overlay dismisses, you're back where you were."
echo ""
echo "  Quick test (don't want to wait 2 minutes):"
echo "  In tinker, clear the session activity to force a lock:"
echo "    session()->put('last_pin_activity_at', now()->subHour()->toIso8601String());"
echo "  Then refresh any tenant page → overlay opens immediately."
echo ""
echo "  Click 'Not you?' → wipes session, lands at /admin/switch."
echo "=========================================="
