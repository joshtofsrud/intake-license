<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * ApplyTenantTheme
 *
 * Reads the tenant's chosen admin theme from settings and shares
 * $adminTheme with all views. Applied to all tenant admin routes.
 *
 * Valid values: 'b' (top nav airy), 'c' (dark premium)
 * Default: 'c' — dark premium is the house style.
 *
 * Note: 'a' (sidebar + light) was removed because it rendered
 * poorly on mobile/tablet. Any tenant with 'a' stored will see
 * 'c' at runtime and can re-pick on the branding page.
 */
class ApplyTenantTheme
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = app('tenant');

        $theme = 'c'; // default — dark premium

        if ($tenant) {
            $settings = $tenant->settings ?? [];
            $stored   = $settings['admin_theme'] ?? 'c';
            $theme    = in_array($stored, ['b', 'c']) ? $stored : 'c';
        }

        // MARKER-USER-THEME-PREF — the signed-in person's own choice wins.
        // Null means they have never picked, so they inherit the shop value
        // resolved above. This middleware also runs on the staff-switcher
        // group where nobody is authenticated yet; there the shop value is
        // all we have, which is correct for a locked screen.
        $user = Auth::guard('tenant')->user();
        if ($user && in_array($user->admin_theme, ['b', 'c'], true)) {
            $theme = $user->admin_theme;
        }

        View::share('adminTheme', $theme);

        return $next($request);
    }
}
