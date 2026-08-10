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
