<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;

class AuthController extends Controller
{
    // ----------------------------------------------------------------
    // Login
    // ----------------------------------------------------------------
    public function showLogin()
    {
        if (Auth::guard('tenant')->check()) {
            return redirect()->route('tenant.dashboard');
        }
        return view('tenant.auth.login');
    }

    public function login(Request $request)
    {
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

        $user->update(['last_login_at' => now()]);

        return redirect()->intended(route('tenant.dashboard'));
    }

    public function logout(Request $request)
    {
        Auth::guard('tenant')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('tenant.login');
    }

    // ----------------------------------------------------------------
    // Forgot password
    // ----------------------------------------------------------------
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

        // Always show success to prevent email enumeration
        if ($user) {
            $token = Str::random(64);

            // Store token in cache for 60 minutes
            Cache::put(
                'pwd_reset_' . $token,
                ['tenant_id' => $tenant->id, 'user_id' => $user->id],
                now()->addMinutes(60)
            );

            $resetUrl = route('tenant.login') . '?reset=' . $token;

            try {
                \Illuminate\Support\Facades\Mail::to($user->email)->send(
                    new \App\Mail\PasswordReset($tenant, $user, $resetUrl)
                );
            } catch (\Throwable $e) {
                logger()->error('Password reset mail failed: ' . $e->getMessage());
            }
        }

        return back()->with('reset_sent', true);
    }

    // ----------------------------------------------------------------
    // Reset password
    // ----------------------------------------------------------------
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
}
