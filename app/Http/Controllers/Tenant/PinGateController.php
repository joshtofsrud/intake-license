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
 * Subdomain trap: every method takes `` first.
 */
class PinGateController extends Controller
{
    public function __construct(protected PinService $pins) {}

    // MARKER-PATCH-480 — first-time PIN setup from the lock overlay (JSON).
    public function setupPin(Request $request)
    {
        $user = Auth::guard('tenant')->user();
        if (! $user) {
            return response()->json(['ok' => false, 'error' => 'not_signed_in'], 401);
        }
        if ($user->pin_hash) {
            // Already has a PIN — use the normal unlock / account flow instead.
            return response()->json(['ok' => false, 'error' => 'pin_exists'], 409);
        }

        $data = $request->validate([
            'pin' => ['required', 'string', 'regex:/^\d{4}$/'],
        ]);

        $this->pins->setPin($user, $data['pin']);

        return response()->json(['ok' => true]);
    }

    /**
     * POST /admin/pin/heartbeat
     *
     * No body required. Returns ok: true if session is healthy, or
     * { locked: true } if the heartbeat itself arrived past the
     * idle threshold (in which case the client should open the overlay).
     */
    public function heartbeat(Request $request)
    {
        if (! Auth::guard('tenant')->check()) {
            return response()->json(['ok' => false, 'error' => 'not_signed_in'], 401);
        }

        $thresholdSec = \App\Services\TenantAuthPolicy::idleThresholdSec(app('tenant') ?? null);
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
    /**
     * GET /admin/pin/context — MARKER-PATCH-545
     * The overlay calls this when an unlock POST 419s after long idle:
     * returns whether the session is still authenticated plus a fresh
     * CSRF token so the overlay can retry silently instead of looping
     * "Something went wrong."
     */
    public function context()
    {
        return response()->json([
            'authed' => (bool) Auth::guard('tenant')->user(),
            'csrf'   => csrf_token(),
        ]);
    }

    public function unlock(Request $request)
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
