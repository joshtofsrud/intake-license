<?php

namespace App\Http\Middleware;

use App\Support\SectionRegistry;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * MARKER-PATCH-492 — EnforceSectionAccess
 *
 * Runs after RequireTenantAuth. Maps the current route name to a
 * SectionRegistry key and blocks users whose role doesn't include it.
 * This is the "real permission, not just a hidden link" half of
 * Roles & access: nav hiding is cosmetic, this is the gate.
 *
 * Rules:
 *  - Routes with no section mapping (login, pin, switch, location
 *    picker, webhooks) always pass.
 *  - Owner enum always passes; users without a role_id fall back to
 *    legacy full access (see TenantUser::canAccessSection).
 *  - Denied JSON/AJAX requests get 403 rather than a redirect so
 *    fetch() callers fail loudly instead of parsing a dashboard page.
 *  - Denied page loads redirect to the user's first allowed section;
 *    403 only if a role somehow allows nothing.
 */
class EnforceSectionAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $section = SectionRegistry::sectionForRoute($request->route()?->getName());
        if ($section === null) {
            return $next($request);
        }

        $user = Auth::guard('tenant')->user();
        if (!$user || $user->canAccessSection($section)) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->ajax()) {
            abort(403, 'Your role does not include this section.');
        }

        return redirect($this->firstAllowedUrl($user))
            ->with('section_denied', SectionRegistry::all()[$section]['label'] ?? $section);
    }

    /**
     * First section this user can open, in registry order, respecting
     * tenant feature gates. Dashboard wins when allowed since it's
     * first in the registry.
     */
    private function firstAllowedUrl($user): string
    {
        $tenant = app('tenant');

        foreach (SectionRegistry::all() as $key => $def) {
            if ($def['gate'] && !$tenant->{$def['gate']}) continue;
            if (!$user->canAccessSection($key)) continue;

            foreach ([$def['prefixes'][0] . '.index', $def['prefixes'][0]] as $routeName) {
                if (Route::has($routeName)) {
                    return route($routeName);
                }
            }
        }

        abort(403, 'Your role does not include any sections. Ask an owner to update it.');
    }
}
