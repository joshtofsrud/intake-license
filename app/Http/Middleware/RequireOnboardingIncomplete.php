<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RequireOnboardingIncomplete
 *
 * Gates the onboarding wizard. A tenant who has already completed
 * onboarding (onboarding_status === 'complete') has no business in
 * the wizard — re-running it would wipe & recreate hours/services
 * via the idempotent save logic, which would surprise them.
 *
 * Send completed tenants back to the dashboard. Pair this with the
 * symmetric DashboardController gate that pushes incomplete tenants
 * INTO the wizard. Together: completed → dashboard, incomplete → wizard,
 * each path has exactly one home.
 */
class RequireOnboardingIncomplete
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = app('tenant');

        if ($tenant && $tenant->onboarding_status === 'complete') {
            // Wizard step submits are fetch() calls that expect JSON {next_url}.
            // An HTML redirect makes res.json() choke ("Unexpected token '<'"),
            // so hand JSON requests the dashboard URL via next_url to navigate to.
            // MARKER-PATCH-446
            if ($request->expectsJson()) {
                return response()->json([
                    'ok'       => true,
                    'next_url' => route('tenant.dashboard', ['subdomain' => $tenant->subdomain]),
                ]);
            }

            return redirect()->route('tenant.dashboard', [
                'subdomain' => $tenant->subdomain,
            ]);
        }

        return $next($request);
    }
}
