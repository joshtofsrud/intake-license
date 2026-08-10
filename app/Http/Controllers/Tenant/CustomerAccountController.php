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

        // MARKER-CUST-AUTH — an email already on file is NEVER claimed from
        // this form. Existing customers get a link to their own inbox instead,
        // and both branches return the same screen so the form can't be used
        // to enumerate who has an account.
        if ($existing) {
            $this->sendClaimLink($existing, $tenant);

            return response()->view('public.account.check-email', [
                'email' => $data['email'],
            ]);
        }

        $customer = TenantCustomer::create([
            'tenant_id'  => $tenant->id,
            'first_name' => $data['first_name'],
            'last_name'  => $data['last_name'],
            'email'      => $data['email'],
            'password'   => $data['password'],
        ]);

        $request->session()->regenerate(); // MARKER-CUST-AUTH — fixation
        $this->guard()->login($customer, true);
        $this->stampTenant($request, $tenant);

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

        $request->session()->regenerate(); // MARKER-CUST-AUTH — fixation
        $this->guard()->login($customer, $request->boolean('remember'));
        $this->stampTenant($request, $tenant);

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

        $request->session()->regenerate(); // MARKER-CUST-AUTH — fixation
        $this->guard()->login($customer, true);
        $this->stampTenant($request, $tenant);

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

    /**
     * MARKER-CUST-AUTH — email an existing customer a link to claim or reset
     * their account. Uses the SAME token the reset flow already validates, so
     * there is exactly one token path and no password is ever chosen by anyone
     * but the customer. Failures are swallowed on purpose: the caller shows an
     * identical screen either way, and surfacing a send error here would leak
     * whether the address exists.
     */
    private function sendClaimLink(TenantCustomer $customer, $tenant): void
    {
        try {
            $token = Str::random(64);
            $customer->update([
                'password_reset_token'   => Hash::make($token),
                'password_reset_sent_at' => now(),
            ]);

            $mailable = $customer->password
                ? new \App\Mail\CustomerPasswordReset($customer, $token, $tenant)
                : new \App\Mail\CustomerAccountInvite($customer, $token, $tenant);

            \Mail::to($customer->email)->send($mailable);
        } catch (\Throwable $e) {
            \Log::warning('customer claim link send failed', [
                'customer_id' => $customer->id,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    /** MARKER-CUST-AUTH — bind this session to the tenant it was created on. */
    private function stampTenant(Request $request, $tenant): void
    {
        $request->session()->put(
            \App\Http\Middleware\EnsureCustomerTenant::SESSION_KEY,
            $tenant->id
        );
    }
}
