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

        // Check authentication against the tenant guard
        if (! Auth::guard('tenant')->check()) {
            return redirect()->route('tenant.login', [
                'subdomain' => $tenant->subdomain,
            ]);
        }

        $user = Auth::guard('tenant')->user();

        // Verify the authenticated user belongs to this tenant
        if ($user->tenant_id !== $tenant->id) {
            Auth::guard('tenant')->logout();
            abort(403, 'Access denied.');
        }

        // Check minimum role requirement
        if (! $this->hasMinimumRole($user->role, $minRole)) {
            abort(403, 'Insufficient permissions.');
        }

        // Share the authenticated user with views
        view()->share('authUser', $user);

        return $next($request);
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
