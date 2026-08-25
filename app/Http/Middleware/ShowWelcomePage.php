<?php

namespace App\Http\Middleware;

use App\Support\WelcomePage;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * MARKER-WELCOME — stand in front of the public site while a tenant is
 * still getting ready.
 *
 * Deliberate exemptions:
 *   - Signed-in staff always see the real site. Having to switch the
 *     welcome page off to check your own work is how it ends up left off.
 *   - Non-GET requests pass: a form post from an allowed page (booking,
 *     account login) must still complete.
 *   - The allow-list, so bookings and existing customers keep working.
 */
class ShowWelcomePage
{
    /**
     * MARKER-WELCOME-ADMIN-FIX — paths the holding page must never cover,
     * regardless of settings or sign-in state.
     */
    private const NEVER_BLOCKED = [
        '/admin',               // the staff app, including its login screen
        '/x',                   // rental extension pay links already sent out
        '/gift-cards/balance',  // an in-store gift card stays checkable
    ];

    public function handle(Request $request, Closure $next)
    {
        $tenant = app()->bound('tenant') ? app('tenant') : null;

        if (! WelcomePage::enabled($tenant))              return $next($request);
        if (Auth::guard('tenant')->check())               return $next($request);
        if (! $request->isMethod('GET'))                  return $next($request);
        if ($request->ajax() || $request->expectsJson())  return $next($request);

        $path = '/' . ltrim($request->path(), '/');
        // MARKER-WELCOME-ADMIN-FIX — never stand in front of the staff app.
        // The signed-in check above only passes users who are ALREADY in;
        // /admin/login is a GET by a signed-OUT user, which is exactly the
        // lockout case, so it has to be exempted by path.
        foreach (self::NEVER_BLOCKED as $exempt) {
            if ($path === $exempt || str_starts_with($path, $exempt . '/')) {
                return $next($request);
            }
        }

        if ($path === '/welcome-preview')                 return $next($request);
        if (WelcomePage::allows($tenant, $path))          return $next($request);

        // 503 rather than 200: the site exists and is coming back, and we
        // would rather not have a holding page indexed as the real thing.
        return response()
            ->view('public.welcome', ['w' => WelcomePage::settings($tenant)], 503)
            ->header('Retry-After', '86400');
    }
}
