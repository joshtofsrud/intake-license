<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RequireOnboarded
 *
 * If the tenant hasn't completed onboarding, redirect the shop admin
 * to the setup wizard. Applied to all tenant admin routes except
 * the onboarding wizard itself.
 */
class RequireOnboarded
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = app('tenant');

        if ($tenant && ! $tenant->isOnboarded()) {
            // Don't redirect if already on an onboarding route
            if (! $request->routeIs('tenant.onboarding.*')) {
                return redirect()->route('tenant.onboarding.index');
            }
        }

        return $next($request);
    }
}
