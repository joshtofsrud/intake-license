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
 * Subdomain trap: every method takes `` as first param.
 */
class StaffSwitchController extends Controller
{
    public function __construct(protected PinService $pins) {}

    /**
     * GET /admin/switch
     * Show the staff card grid.
     */
    public function index(Request $request)
    {
        $tenant = tenant();
        if (! $tenant || ! $tenant->pin_tier_active) {
            // Should not happen — middleware should have routed elsewhere.
            return redirect()->route('tenant.dashboard');
        }

        // PATCH-CHUNK-5H2 wipe-session — /admin/switch is both the
        // initial-PIN-entry surface AND the "switch staff" surface.
        // When someone is already signed in and visits here, they want
        // to hand the device to another staff member, NOT bounce home.
        // Wipe the user session (keep the device-trust cookie intact),
        // then render the switcher cards.
        if (Auth::guard('tenant')->check()) {
            Auth::guard('tenant')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
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
    public function verifyPin(Request $request)
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

        // Mark PIN activity for the idle-lock middleware (chunk 6).
        $request->session()->put('last_pin_activity_at', now()->toIso8601String());

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
    public function setInitialPin(Request $request)
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

        // Mark PIN activity for the idle-lock middleware (chunk 6).
        $request->session()->put('last_pin_activity_at', now()->toIso8601String());

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
    public function requestReset(Request $request)
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
