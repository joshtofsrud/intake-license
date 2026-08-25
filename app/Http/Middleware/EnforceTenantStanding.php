<?php
// MARKER-TENANT-STANDING — stands in front of the tenant ADMIN app when a
// shop is suspended or past grace. Registered on the admin group only, so
// the booking page, customer portal and gift-card balance stay reachable in
// every state by construction rather than by an exemption list.

namespace App\Http\Middleware;

use App\Support\TenantStanding;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceTenantStanding
{
    /** Reachable even while locked: signing out, and paying to fix it. */
    private const ALWAYS = ['logout', 'billing', 'account/billing'];

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = app()->bound('tenant') ? app('tenant') : null;
        if (! TenantStanding::blocksAdmin($tenant)) {
            return $next($request);
        }

        $path = ltrim($request->path(), '/');
        foreach (self::ALWAYS as $ok) {
            if (str_contains($path, $ok)) {
                return $next($request);
            }
        }

        $standing = TenantStanding::of($tenant);

        return response()->view('tenant.locked', [
            'tenant'   => $tenant,
            'standing' => $standing,
        ], 402); // Payment Required — honest, and not cached as a real page
    }
}
