<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * ApplyTenantTheme
 *
 * Reads the tenant's chosen admin theme from settings and shares
 * $adminTheme with all views. Applied to all tenant admin routes.
 *
 * Valid values: 'a' (sidebar light), 'b' (top nav airy), 'c' (dark premium)
 * Default: 'a'
 */
class ApplyTenantTheme
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = app('tenant');

        $theme = 'a'; // default

        if ($tenant) {
            $settings = $tenant->settings ?? [];
            $stored   = $settings['admin_theme'] ?? 'a';
            $theme    = in_array($stored, ['a', 'b', 'c']) ? $stored : 'a';
        }

        View::share('adminTheme', $theme);

        return $next($request);
    }
}
