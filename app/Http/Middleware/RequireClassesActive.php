<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Classes are on only when the shop is entitled to them (the 'classes'
 * add-on) AND has switched them on. Until now the nav hid classes but every
 * admin and public classes URL still answered.
 *
 *   ->middleware(RequireClassesActive::class)           admin: 403 with a reason
 *   ->middleware(RequireClassesActive::class.':public') public site: plain 404
 *
 * MARKER-CLASSES-P0
 */
class RequireClassesActive
{
    public function handle(Request $request, Closure $next, string $mode = 'admin'): Response
    {
        $tenant = tenant();

        if (! $tenant || ! $tenant->classes_active) {
            if ($mode === 'public') {
                abort(404);
            }
            abort(403, 'Classes are not turned on for this shop. Turn them on in Settings, or add Group classes from Add-ons.');
        }

        return $next($request);
    }
}

