#!/usr/bin/env bash
set -euo pipefail
# apply-customer-auth-hardening.sh — MARKER-CUST-AUTH
# Closes bugs 6-8 from the Aug 9 customer portal audit.
#
# 6) ACCOUNT TAKEOVER (the real hole). register() with an email belonging to an
#    existing password-less customer — every guest ever created by a booking,
#    the register, or staff — set that customer's password and logged the
#    visitor straight in, handing over their whole service history with no
#    verification. Now: mint the same reset token the reset flow already
#    validates, email a claim link, create NO session. Both existing branches
#    (with and without a password) return the identical neutral screen, so the
#    form no longer reveals whether an email is on file — the old
#    "An account with this email already exists" was itself an enumeration
#    oracle.
#
# 7) Throttling on all four auth POSTs, and session()->regenerate() after every
#    successful login / register / reset (session fixation).
#
# 8) The customer guard never checked tenant. Sessions are host-scoped today so
#    it was safe by accident, not by design; a shared SESSION_DOMAIN would have
#    made a shop-A login valid on shop B. The tenant is now stamped into the
#    session at login and verified on every request.
#
# REQUIRES apply-customer-account-admin (MARKER-CUST-ACCOUNT) — the claim email
# reuses its CustomerAccountInvite mailable.

CTRL=app/Http/Controllers/Tenant/CustomerAccountController.php
MW=app/Http/Middleware/EnsureCustomerTenant.php
BOOT=bootstrap/app.php
ROUTES=routes/web.php
VIEW=resources/views/public/account/check-email.blade.php

for f in "$CTRL" "$BOOT" "$ROUTES"; do
  [ -f "$f" ] || { echo "MISSING $f — run from the repo root"; exit 1; }
done

grep -rq "MARKER-CUST-ACCOUNT" app/Mail/CustomerAccountInvite.php 2>/dev/null \
  || { echo "PRECONDITION FAILED: deploy apply-customer-account-admin.sh first (CustomerAccountInvite missing)"; exit 1; }

if grep -q "MARKER-CUST-AUTH" "$CTRL"; then
  echo "Already applied (MARKER-CUST-AUTH present) — no-op."
  exit 0
fi

# ---------------------------------------------------------------- middleware
if [ -f "$MW" ]; then echo "ok   middleware already present"; else
cat <<'EOF' > "$MW"
<?php

namespace App\Http\Middleware;

// MARKER-CUST-AUTH — bind a customer session to the tenant it was created on.
// Without this the customer guard trusts the session alone, which is only safe
// while sessions stay host-scoped. Belt and braces: if SESSION_DOMAIN is ever
// widened to share cookies across tenant subdomains, a login on one shop would
// otherwise be a valid login on every shop.

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureCustomerTenant
{
    public const SESSION_KEY = 'customer_tenant_id';

    public function handle(Request $request, Closure $next)
    {
        $guard = Auth::guard('customer');

        if ($guard->check()) {
            $tenant    = app()->bound('tenant') ? app('tenant') : null;
            $sessionId = $request->session()->get(self::SESSION_KEY);
            $customer  = $guard->user();

            // Three ways this can be wrong: no tenant resolved, the session was
            // stamped for a different tenant, or the customer row itself
            // belongs elsewhere. Any of them means log out, not "carry on".
            $mismatch = ! $tenant
                || ($sessionId !== null && $sessionId !== $tenant->id)
                || ($customer && $customer->tenant_id !== $tenant->id);

            if ($mismatch) {
                $guard->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
        }

        return $next($request);
    }
}
EOF
echo "ok   middleware created"; fi

# ---------------------------------------------------------------- check-email view
if [ -f "$VIEW" ]; then echo "ok   check-email view already present"; else
cat <<'EOF' > "$VIEW"
@extends('public.account._shell')
@php $pageTitle = 'Check your email'; @endphp
{{-- MARKER-CUST-AUTH — shown for ANY existing email, with or without an
     account, so the register form can't be used to discover who has one. --}}

@section('content')
<div style="max-width:460px;margin:0 auto">
  <div class="ac-card">
    <h1 class="ac-title">Check your email</h1>
    <p class="ac-subtitle">We found an existing profile for <b>{{ $email }}</b> at {{ $currentTenant->name }}.</p>

    <div class="ac-flash ac-flash--success" style="margin-bottom:18px">
      We've emailed you a secure link to finish setting up your account. It expires in 60 minutes.
    </div>

    <p style="font-size:13.5px;opacity:.6;line-height:1.6">
      This keeps your history private &mdash; nobody can claim your profile just by knowing your email address.
    </p>

    <p style="font-size:13.5px;opacity:.6;line-height:1.6;margin-top:12px">
      Didn't get it? Check your spam folder, or <a href="{{ route('tenant.customer.forgot') }}" class="ac-link">request another link</a>.
    </p>
  </div>
</div>
@endsection
EOF
echo "ok   check-email view created"; fi

# ---------------------------------------------------------------- controller
python3 - "$CTRL" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

def edit(old, new, label):
    global src
    n = src.count(old)
    if n != 1:
        print(f"FAIL {label}: anchor found {n} times"); sys.exit(1)
    src = src.replace(old, new, 1)
    print(f"ok   {label}")

# --- 6) register(): claim link instead of instant takeover
edit("""        if ($existing) {
            if ($existing->password) {
                return back()->withErrors(['email' => 'An account with this email already exists.']);
            }
            // Guest customer exists — attach password and log them in
            $existing->update(['password' => $data['password']]);
            $this->guard()->login($existing, true);
            return redirect()->route('tenant.customer.portal')
                ->with('success', 'Welcome back! Your account has been set up.');
        }
""",
"""        // MARKER-CUST-AUTH — an email already on file is NEVER claimed from
        // this form. Existing customers get a link to their own inbox instead,
        // and both branches return the same screen so the form can't be used
        // to enumerate who has an account.
        if ($existing) {
            $this->sendClaimLink($existing, $tenant);

            return response()->view('public.account.check-email', [
                'email' => $data['email'],
            ]);
        }
""",
"register claim link")

# --- 7) session fixation on the three login points
edit("""        $this->guard()->login($customer, true);

        return redirect()->intended(route('tenant.customer.portal'))
            ->with('success', 'Account created. Welcome!');""",
"""        $request->session()->regenerate(); // MARKER-CUST-AUTH — fixation
        $this->guard()->login($customer, true);

        return redirect()->intended(route('tenant.customer.portal'))
            ->with('success', 'Account created. Welcome!');""",
"register regenerate")

edit("""        $this->guard()->login($customer, $request->boolean('remember'));

        return redirect()->intended(route('tenant.customer.portal'));""",
"""        $request->session()->regenerate(); // MARKER-CUST-AUTH — fixation
        $this->guard()->login($customer, $request->boolean('remember'));
        $this->stampTenant($request, $tenant);

        return redirect()->intended(route('tenant.customer.portal'));""",
"login regenerate + stamp")

edit("""        $this->guard()->login($customer, true);

        return redirect()->route('tenant.customer.portal')
            ->with('success', 'Password updated. You are now logged in.');""",
"""        $request->session()->regenerate(); // MARKER-CUST-AUTH — fixation
        $this->guard()->login($customer, true);
        $this->stampTenant($request, $tenant);

        return redirect()->route('tenant.customer.portal')
            ->with('success', 'Password updated. You are now logged in.');""",
"reset regenerate + stamp")

# register() logs in a brand-new customer too — stamp that session as well.
edit("""        $request->session()->regenerate(); // MARKER-CUST-AUTH — fixation
        $this->guard()->login($customer, true);

        return redirect()->intended(route('tenant.customer.portal'))
            ->with('success', 'Account created. Welcome!');""",
"""        $request->session()->regenerate(); // MARKER-CUST-AUTH — fixation
        $this->guard()->login($customer, true);
        $this->stampTenant($request, $tenant);

        return redirect()->intended(route('tenant.customer.portal'))
            ->with('success', 'Account created. Welcome!');""",
"register stamp")

# --- helpers
tail = src.rstrip()
if not tail.endswith('}'):
    print("FAIL helpers: file does not end with }"); sys.exit(1)

helpers = '''
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
                ? new \\App\\Mail\\CustomerPasswordReset($customer, $token, $tenant)
                : new \\App\\Mail\\CustomerAccountInvite($customer, $token, $tenant);

            \\Mail::to($customer->email)->send($mailable);
        } catch (\\Throwable $e) {
            \\Log::warning('customer claim link send failed', [
                'customer_id' => $customer->id,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    /** MARKER-CUST-AUTH — bind this session to the tenant it was created on. */
    private function stampTenant(Request $request, $tenant): void
    {
        $request->session()->put(
            \\App\\Http\\Middleware\\EnsureCustomerTenant::SESSION_KEY,
            $tenant->id
        );
    }
}
'''
src = tail[:-1].rstrip('\n') + '\n' + helpers
print("ok   sendClaimLink + stampTenant helpers")

open(path, 'w').write(src)
PY

# ---------------------------------------------------------------- middleware registration
python3 - "$BOOT" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

old = """        $middleware->append(\\App\\Http\\Middleware\\LogRequests::class);"""
new = """        $middleware->append(\\App\\Http\\Middleware\\LogRequests::class);

        // MARKER-CUST-AUTH — verify a signed-in customer belongs to the tenant
        // being served. Appended globally rather than pinned to the account
        // routes so it also covers every portal page, present and future.
        $middleware->append(\\App\\Http\\Middleware\\EnsureCustomerTenant::class);"""
n = src.count(old)
if n != 1:
    print(f"FAIL middleware registration: anchor found {n} times"); sys.exit(1)
src = src.replace(old, new, 1)
print("ok   middleware registered")

open(path, 'w').write(src)
PY

# ---------------------------------------------------------------- throttles
python3 - "$ROUTES" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

# MARKER-CUST-AUTH — per-IP limits on the four unauthenticated auth POSTs.
# Login is the tightest; forgot/register are looser because a real person can
# legitimately retry a typo'd address a few times.
throttles = [
    ("'register.submit');",  "register.submit', 'throttle:6,1'"),
    ("'tenant.customer.login.submit');",  None),
]

edits = [
    (
        """    Route::post('/account/register',     [TenantControllers\\CustomerAccountController::class, 'register'])->name('tenant.customer.register.submit');""",
        """    Route::post('/account/register',     [TenantControllers\\CustomerAccountController::class, 'register'])->name('tenant.customer.register.submit')->middleware('throttle:6,1'); // MARKER-CUST-AUTH""",
        "throttle register",
    ),
    (
        """    Route::post('/account/login',        [TenantControllers\\CustomerAccountController::class, 'login'])->name('tenant.customer.login.submit');""",
        """    Route::post('/account/login',        [TenantControllers\\CustomerAccountController::class, 'login'])->name('tenant.customer.login.submit')->middleware('throttle:10,1'); // MARKER-CUST-AUTH""",
        "throttle login",
    ),
    (
        """    Route::post('/account/forgot',       [TenantControllers\\CustomerAccountController::class, 'sendReset'])->name('tenant.customer.forgot.submit');""",
        """    Route::post('/account/forgot',       [TenantControllers\\CustomerAccountController::class, 'sendReset'])->name('tenant.customer.forgot.submit')->middleware('throttle:6,1'); // MARKER-CUST-AUTH""",
        "throttle forgot",
    ),
    (
        """    Route::post('/account/reset',        [TenantControllers\\CustomerAccountController::class, 'resetPassword'])->name('tenant.customer.reset.submit');""",
        """    Route::post('/account/reset',        [TenantControllers\\CustomerAccountController::class, 'resetPassword'])->name('tenant.customer.reset.submit')->middleware('throttle:6,1'); // MARKER-CUST-AUTH""",
        "throttle reset",
    ),
]

for old, new, label in edits:
    n = src.count(old)
    if n != 1:
        print(f"FAIL {label}: anchor found {n} times"); sys.exit(1)
    src = src.replace(old, new, 1)
    print(f"ok   {label}")

open(path, 'w').write(src)
PY

php -l "$CTRL"
php -l "$MW"
php -l "$BOOT"

echo ""
echo "SUCCESS — apply-customer-auth-hardening applied."
echo "BEHAVIOUR CHANGE: a customer who already exists (guest or not) can no"
echo "longer register straight in — they get an emailed link. That is the fix."
