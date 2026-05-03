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

        return $this->resolveLocationAndContinue($request, $user);
    }

    public function logout(Request $request)
    {
        Auth::guard('tenant')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('tenant.login');
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

        $request->session()->put('current_location_id', $request->input('location_id'));
        return redirect()->intended(route('tenant.dashboard'));
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
