<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use App\Services\DeviceTrustService;
use Illuminate\Support\Facades\Cookie;

class AuthController extends Controller
{
    public function showLogin()
    {
        if (Auth::guard('tenant')->check()) {
            return redirect()->route('tenant.dashboard');
        }
        return view('tenant.auth.login');
    }

    public function login(Request $request)
    {
        Log::info('LOGIN_ATTEMPT', [
            'host'   => $request->getHost(),
            'email'  => $request->input('email'),
            'tenant' => tenant()?->subdomain,
        ]);

        $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $tenant = tenant();
        $user   = TenantUser::where('tenant_id', $tenant->id)
            ->where('email', strtolower($request->input('email')))
            ->first();

        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'These credentials do not match our records.']);
        }

        if (! $user->is_active) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'Your account has been deactivated. Contact your shop owner.']);
        }

        Auth::guard('tenant')->login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        // MARKER-PATCH-497 — a password sign-in is stronger auth than a PIN,
        // so stamp PIN freshness or EnsurePinFresh locks the very first page.
        $request->session()->put('last_pin_activity_at', now()->toIso8601String());

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

        return $response;
    }

    public function logout(Request $request)
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
    }

    public function showForgot()
    {
        return view('tenant.auth.forgot');
    }

    public function sendReset(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);

        $tenant = tenant();
        $user   = TenantUser::where('tenant_id', $tenant->id)
            ->where('email', strtolower($request->input('email')))
            ->first();

        if ($user) {
            $token = Str::random(64);

            Cache::put(
                'pwd_reset_' . $token,
                ['tenant_id' => $tenant->id, 'user_id' => $user->id],
                now()->addMinutes(60)
            );

            $resetUrl = route('tenant.reset') . '?token=' . $token;

            try {
                Mail::to($user->email)->send(
                    new \App\Mail\PasswordReset($tenant, $user, $resetUrl)
                );
            } catch (\Throwable $e) {
                Log::error('Password reset mail failed: ' . $e->getMessage());
            }
        }

        return back()->with('reset_sent', true);
    }

    public function showReset(Request $request)
    {
        $token = $request->query('token');
        if (! $token || ! Cache::has('pwd_reset_' . $token)) {
            return redirect()->route('tenant.login')
                ->withErrors(['email' => 'This reset link is invalid or has expired.']);
        }
        return view('tenant.auth.reset', compact('token'));
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token'    => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $token    = $request->input('token');
        $cacheKey = 'pwd_reset_' . $token;
        $data     = Cache::get($cacheKey);

        if (! $data) {
            return back()->withErrors(['password' => 'Reset link is invalid or has expired.']);
        }

        $user = TenantUser::where('id', $data['user_id'])
            ->where('tenant_id', $data['tenant_id'])
            ->first();

        if (! $user) {
            return back()->withErrors(['password' => 'User not found.']);
        }

        $user->update(['password' => Hash::make($request->input('password'))]);
        Cache::forget($cacheKey);

        Auth::guard('tenant')->login($user);
        // MARKER-PATCH-497 — see login(): stamp PIN freshness on password auth.
        $request->session()->put('last_pin_activity_at', now()->toIso8601String());

        return redirect()->route('tenant.dashboard')
            ->with('success', 'Password updated successfully.');
    }

    /**
     * After successful login, branch on user's active locations:
     *   0 -> error (account misconfigured; owner should attach locations)
     *   1 -> set session, redirect to dashboard
     *   2+ -> redirect to location picker
     */
    protected function resolveLocationAndContinue(Request $request, TenantUser $user)
    {
        $locations = $user->activeLocations()->orderBy('is_default', 'desc')->orderBy('name')->get();

        if ($locations->isEmpty()) {
            // If onboarding is incomplete, let them through — the wizard
            // doesn't need a current_location_id, and TenantObserver should
            // have seeded a default location on tenant creation. This branch
            // catches edge cases (legacy tenants, manual setup, observer failure).
            $tenant = tenant();
            if ($tenant && $tenant->onboarding_status !== 'complete') {
                return redirect()->route('tenant.dashboard');
            }

            Auth::guard('tenant')->logout();
            $request->session()->invalidate();
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'Your account has no location access. Contact your shop owner.']);
        }

        if ($locations->count() === 1) {
            $request->session()->put('current_location_id', $locations->first()->id);
            return redirect()->intended(route('tenant.dashboard'));
        }

        return redirect()->route('tenant.select-location');
    }

    /**
     * GET /admin/select-location
     */
    public function showLocationPicker(Request $request)
    {
        $user = Auth::guard('tenant')->user();
        if (!$user) {
            return redirect()->route('tenant.login');
        }

        $locations = $user->activeLocations()->orderBy('is_default', 'desc')->orderBy('name')->get();

        if ($locations->count() === 1) {
            $request->session()->put('current_location_id', $locations->first()->id);
            return redirect()->route('tenant.dashboard');
        }

        if ($locations->isEmpty()) {
            Auth::guard('tenant')->logout();
            return redirect()->route('tenant.login')
                ->withErrors(['email' => 'Your account has no location access. Contact your shop owner.']);
        }

        return view('tenant.auth.select-location', [
            'locations'         => $locations,
            'currentLocationId' => $request->session()->get('current_location_id'),
        ]);
    }

    /**
     * POST /admin/select-location
     */
    public function selectLocation(Request $request)
    {
        $request->validate(['location_id' => ['required', 'uuid']]);

        $user = Auth::guard('tenant')->user();
        if (!$user) {
            return redirect()->route('tenant.login');
        }

        $hasAccess = $user->activeLocations()
            ->where('tenant_locations.id', $request->input('location_id'))
            ->exists();

        if (!$hasAccess) {
            return back()->withErrors(['location_id' => 'You do not have access to that location.']);
        }

        // CHUNK-7 switch_location action gate.
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
        $gate = app(\App\Services\PinGateService::class);
        if (! $isInitialPick && $gate->requirePin($request, 'switch_location')) {
            $pin = $request->input('pin');

            if (! $pin) {
                // Client must show the modal and re-submit with the pin field.
                // Use 403 (forbidden) with a JSON body. Always reply in JSON
                // here — the new client flow uses fetch() so it expects JSON
                // either way.
                $location = $user->activeLocations()
                    ->where('tenant_locations.id', $request->input('location_id'))
                    ->first();

                return response()->json([
                    'ok'    => false,
                    'error' => 'pin_required',
                    'action' => 'switch_location',
                    'destination' => $location?->name,
                ], 403);
            }

            // PIN provided — verify.
            $ok = $gate->confirm($request, 'switch_location', $pin, $user);
            if (! $ok) {
                return response()->json([
                    'ok'    => false,
                    'error' => 'pin_mismatch',
                ], 422);
            }
        }

        $request->session()->put('current_location_id', $request->input('location_id'));

        // PATCH-108 welcome-flash — on a real mid-session switch (not the
        // initial post-sign-in pick), flash the new location name so the
        // next page render shows the welcome overlay.
        if (! $isInitialPick) {
            $newLoc = $user->activeLocations()
                ->where('tenant_locations.id', $request->input('location_id'))
                ->first();
            if ($newLoc) {
                $request->session()->flash('location_switched', [
                    'name' => $newLoc->name,
                ]);
            }
        }

        // PATCH-103 return_url — the header switcher posts the URL the user
        // was on so we can return them there. Only honor same-host URLs to
        // avoid open-redirect risk.
        $returnUrl = $request->input('return_url');
        $redirectTarget = route('tenant.dashboard');
        if ($returnUrl && is_string($returnUrl)) {
            $current = $request->getSchemeAndHttpHost();
            if (str_starts_with($returnUrl, $current . '/')) {
                $redirectTarget = $returnUrl;
            }
        }

        // CHUNK-7 json-return — fetch-based clients (the location switcher
        // since chunk 7) expect JSON; window.location.href will handle the
        // redirect on the client side.
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'ok'       => true,
                'redirect' => $redirectTarget,
            ]);
        }

        return redirect($redirectTarget);
    }

    /**
     * POST /admin/switch-location
     */
    public function switchLocation(Request $request)
    {
        $request->session()->forget('current_location_id');
        return redirect()->route('tenant.select-location');
    }
}
