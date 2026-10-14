<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * RequireTenantAuth
 *
 * Ensures a TenantUser is authenticated via the 'tenant' guard AND
 * that they belong to the current tenant. Prevents a staff member
 * from one shop accessing another shop's admin by manipulating the URL.
 */
class RequireTenantAuth
{
    public function handle(Request $request, Closure $next, string $minRole = 'staff'): Response
    {
        $tenant = app('tenant');

        // reserved hosts (api, www, app…) never
        // resolve a tenant, so everything below would read properties on null.
        // There is no shop to sign into on a platform host; send them to the
        // platform sign-in rather than throwing a 500 at someone who is
        // already lost.
        if (! $tenant) {
            return redirect()->away('https://' . config('intake.domain', 'intake.works') . '/login');
        }

        // Check authentication against the tenant guard
        if (! Auth::guard('tenant')->check()) {
            return redirect()->route('tenant.login', [
                'subdomain' => $tenant->subdomain,
            ]);
        }

        $user = Auth::guard('tenant')->user();

        // Verify the authenticated user belongs to this tenant
        if ($user->tenant_id !== $tenant->id) {
            // during impersonation this is a SWITCH,
            // not an intrusion. The tenant cookie is scoped to .intake.works so
            // it reaches every shop's host; wiping it here killed the shop the
            // operator was actually working in, and the way back with it.
            if (is_impersonating() && $this->mayImpersonate()) {
                $owner = \App\Models\Tenant\TenantUser::where('tenant_id', $tenant->id)
                    ->where('role', 'owner')->where('is_active', true)->first();

                if ($owner) {
                    // MARKER-IMPERSONATE-SWITCH: swap the owner WITHOUT login().
                    // login() gives the session a new id; with two shops open in
                    // tabs every poll did that, and a request racing the new id
                    // wrote an empty session back, logging the operator out of
                    // the shop and master admin alike. The operator already
                    // passed the master-admin check above, so a fresh id adds
                    // nothing here.
                    $guard = Auth::guard('tenant');
                    $request->session()->put($guard->getName(), $owner->getAuthIdentifier());
                    $guard->setUser($owner);
                    // the register picked in the other shop belongs to that shop
                    $request->session()->forget('current_register_id');
                    $user = $owner;
                    debug_log()->impersonation('switch', $tenant, $owner);
                } else {
                    // Nothing to become here. Go back to master admin rather
                    // than destroying a session that is still valid elsewhere.
                    return redirect()->away(
                        'https://' . config('intake.domain', 'intake.works') . '/admin/tenants'
                    );
                }
            } elseif (is_impersonating()) {
                // impersonating and the
                // switch could not happen (no owner, or the gate said no).
                // Destroying the session here is what kept logging Josh out.
                // Go back to master admin instead; the session survives.
                return redirect()->away(
                    'https://' . config('intake.domain', 'intake.works') . '/admin/tenants'
                );
            } else {
                // The case this rule exists for: a real staff member on
                // another shop's host, no impersonation in play.
                Auth::guard('tenant')->logout();
                abort(403, 'Access denied.');
            }
        }

        // Check minimum role requirement
        if (! $this->hasMinimumRole($user->role, $minRole)) {
            abort(403, 'Insufficient permissions.');
        }

        // Share the authenticated user with views
        view()->share('authUser', $user);

        // MARKER-NO-CUSTOMER-RECALL: inside the staff admin, never sign the
        // browser in as a CUSTOMER from a "remember me" cookie. The builder
        // preview renders the public nav, which asks the customer guard who's
        // there; a remember cookie (say, from testing your own portal) made it
        // log in, and that login gives the session a new id. The editor's other
        // requests, in flight at the same moment, still carried the old id and
        // wrote an empty session back: you were logged out of the shop and
        // master admin. Customer sign-ins on the public site are untouched.
        $customerGuard = Auth::guard('customer');
        if ($customerGuard instanceof \Illuminate\Auth\SessionGuard) {
            $clean = $request->duplicate();
            $clean->cookies->remove($customerGuard->getRecallerName());
            $customerGuard->setRequest($clean);
        }

        return $next($request);
    }

    /**
     * is the operator behind this impersonation
     * still a platform admin allowed to impersonate? The session says an
     * impersonation is in progress; this checks the person it belongs to,
     * so a stale session can never be used to walk between shops.
     */
    private function mayImpersonate(): bool
    {
        $from = session('impersonating_from');
        if (! is_array($from) || empty($from['user_id'])) {
            return false;
        }

        $admin = \App\Models\User::find($from['user_id']);

        return $admin
            && $admin->is_admin
            && $admin->suspended_at === null
            && \App\Support\AdminAccess::allows($admin, 'impersonation');
    }

    /**
     * Role hierarchy: owner > manager > staff
     */
    private function hasMinimumRole(string $userRole, string $required): bool
    {
        $hierarchy = ['staff' => 1, 'manager' => 2, 'owner' => 3];

        return ($hierarchy[$userRole] ?? 0) >= ($hierarchy[$required] ?? 0);
    }
}
