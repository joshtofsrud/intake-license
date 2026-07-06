<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantCustomer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CustomerAccountController extends Controller
{
    private function guard()
    {
        return Auth::guard('customer');
    }

    private function tenant()
    {
        return tenant();
    }

    // ------------------------------------------------------------------
    // Register
    // ------------------------------------------------------------------

    public function showRegister()
    {
        if ($this->guard()->check()) {
            return redirect()->route('tenant.customer.portal');
        }
        return view('public.account.register');
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name'  => ['required', 'string', 'max:80'],
            'email'      => ['required', 'email', 'max:180'],
            'password'   => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $tenant = $this->tenant();

        $existing = TenantCustomer::where('tenant_id', $tenant->id)
            ->where('email', $data['email'])
            ->first();

        if ($existing) {
            if ($existing->password) {
                return back()->withErrors(['email' => 'An account with this email already exists.']);
            }
            // Guest customer exists — attach password and log them in
            $existing->update(['password' => $data['password']]);
            $this->guard()->login($existing, true);
            return redirect()->route('tenant.customer.portal')
                ->with('success', 'Welcome back! Your account has been set up.');
        }

        $customer = TenantCustomer::create([
            'tenant_id'  => $tenant->id,
            'first_name' => $data['first_name'],
            'last_name'  => $data['last_name'],
            'email'      => $data['email'],
            'password'   => $data['password'],
        ]);

        $this->guard()->login($customer, true);

        return redirect()->intended(route('tenant.customer.portal'))
            ->with('success', 'Account created. Welcome!');
    }

    // ------------------------------------------------------------------
    // Login
    // ------------------------------------------------------------------

    public function showLogin()
    {
        if ($this->guard()->check()) {
            return redirect()->route('tenant.customer.portal');
        }
        return view('public.account.login');
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $tenant = $this->tenant();

        $customer = TenantCustomer::where('tenant_id', $tenant->id)
            ->where('email', $data['email'])
            ->first();

        if (!$customer || !$customer->password || !Hash::check($data['password'], $customer->password)) {
            return back()->withErrors(['email' => 'These credentials do not match our records.'])->withInput();
        }

        $this->guard()->login($customer, $request->boolean('remember'));

        return redirect()->intended(route('tenant.customer.portal'));
    }

    // ------------------------------------------------------------------
    // Logout
    // ------------------------------------------------------------------

    public function logout(Request $request)
    {
        $this->guard()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('tenant.home');
    }

    // ------------------------------------------------------------------
    // Forgot password
    // ------------------------------------------------------------------

    public function showForgot()
    {
        return view('public.account.forgot');
    }

    public function sendReset(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);

        $tenant   = $this->tenant();
        $customer = TenantCustomer::where('tenant_id', $tenant->id)
            ->where('email', $request->email)
            ->first();

        // Always show success to prevent email enumeration
        if (!$customer) {
            return back()->with('success', 'If an account exists for that email, a reset link has been sent.');
        }

        $token = Str::random(64);
        $customer->update([
            'password_reset_token'   => Hash::make($token),
            'password_reset_sent_at' => now(),
        ]);

        // Send email — uses existing email infrastructure
        \Mail::to($customer->email)->send(
            new \App\Mail\CustomerPasswordReset($customer, $token, $tenant)
        );

        return back()->with('success', 'If an account exists for that email, a reset link has been sent.');
    }

    // ------------------------------------------------------------------
    // Reset password
    // ------------------------------------------------------------------

    public function showReset(Request $request)
    {
        return view('public.account.reset', [
            'token' => $request->query('token'),
            'email' => $request->query('email'),
        ]);
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'email'    => ['required', 'email'],
            'token'    => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $tenant   = $this->tenant();
        $customer = TenantCustomer::where('tenant_id', $tenant->id)
            ->where('email', $data['email'])
            ->whereNotNull('password_reset_token')
            ->first();

        if (!$customer) {
            return back()->withErrors(['email' => 'Invalid or expired reset link.']);
        }

        // Tokens expire after 60 minutes
        if ($customer->password_reset_sent_at->diffInMinutes(now()) > 60) {
            return back()->withErrors(['email' => 'This reset link has expired. Please request a new one.']);
        }

        if (!Hash::check($data['token'], $customer->password_reset_token)) {
            return back()->withErrors(['email' => 'Invalid or expired reset link.']);
        }

        $customer->update([
            'password'               => $data['password'],
            'password_reset_token'   => null,
            'password_reset_sent_at' => null,
        ]);

        $this->guard()->login($customer, true);

        return redirect()->route('tenant.customer.portal')
            ->with('success', 'Password updated. You are now logged in.');
    }

    // ------------------------------------------------------------------
    // Portal
    // ------------------------------------------------------------------

    public function portal()
    {
        $customer = $this->guard()->user();

        if (!$customer) {
            return redirect()->route('tenant.customer.login');
        }

        $tenant = $this->tenant();

        $upcomingClasses = $customer->classRegistrations()
            ->whereIn('status', ['registered', 'waitlisted'])
            ->with(['session.template', 'session.instructorResource'])
            ->whereHas('session', fn($q) => $q->where('starts_at', '>', now()))
            ->orderBy('registered_at', 'desc')
            ->limit(5)
            ->get();

        $pastClasses = $customer->classRegistrations()
            ->whereIn('status', ['checked_in', 'no_show', 'cancelled'])
            ->with(['session.template'])
            ->whereHas('session', fn($q) => $q->where('starts_at', '<', now()))
            ->orderBy('registered_at', 'desc')
            ->limit(10)
            ->get();

        $upcomingAppointments = $customer->appointments()
            ->whereIn('status', ['pending', 'confirmed', 'in_progress'])
            ->orderBy('appointment_date')
            ->limit(5)
            ->get();

        $pastAppointments = $customer->appointments()
            ->whereIn('status', ['completed', 'cancelled'])
            ->orderBy('appointment_date', 'desc')
            ->limit(10)
            ->get();

        // MARKER-PATCH-574 — online order history for the portal
        $onlineOrders = \App\Models\Tenant\TenantOrder::query()
            ->where('tenant_id', $tenant->id)
            ->where('customer_id', $customer->id)
            ->whereNotNull('order_number')
            ->with('items')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $activeMembership = $customer->activeMembership();
        $activePacks      = $customer->activePacks()->get();

        return view('public.account.portal', compact(
            'customer',
            'upcomingClasses',
            'pastClasses',
            'upcomingAppointments',
            'pastAppointments',
            'onlineOrders', // MARKER-PATCH-574
            'activeMembership',
            'activePacks'
        ));
    }
}
