<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsurePinFresh
 *
 * Layer 3 of the auth refactor. Enforces an idle-timeout re-PIN.
 *
 * Resolution:
 *   1. Tenant pin_tier_active is false -> pass through. (Starter and
 *      single-user Branded never see the lock.)
 *   2. Route is in the whitelist (heartbeat, unlock, switch, location
 *      picker, logout) -> pass through. These routes need to work even
 *      when the lock is active.
 *   3. Read session('last_pin_activity_at'). If null or older than the
 *      configured threshold:
 *        - For AJAX requests: respond with 423 Locked + JSON body.
 *          Client overlay catches this globally.
 *        - For page renders: set $pinLockPending in the view so the
 *          layout opens the overlay on render. Page state under the
 *          overlay stays intact.
 *   4. Else: touch the timestamp (rate-limited to once per minute to
 *      avoid hammering the session store).
 *
 * The server is the source of truth for staleness; the client-side
 * idle detector is just a UX accelerator that shows the overlay
 * locally before the next request would have shown it server-side.
 */
class EnsurePinFresh
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = app('tenant') ?? null;

        // MARKER-DEMO-FIXES — a demo visitor has no PIN and no way to set one.
        if (! $tenant || ! $tenant->pin_tier_active || $tenant->is_demo) {
            return $next($request);
        }

        // MARKER-IMPERSONATION-PIN — impersonation signs you in as the tenant
        // owner, whose PIN the platform operator does not have; enforcing it
        // would brick the session outright. Reaching this point already
        // required a master admin login, which is the stronger check. The
        // impersonation banner stays visible the whole time, and start/stop
        // are recorded in the debug log.
        if (is_impersonating()) {
            view()->share('pinLockPending', false);
            view()->share('pinBypassImpersonating', true);
            return $next($request);
        }

        // Routes that must work even when the lock is pending.
        $routeName = $request->route()?->getName() ?? '';
        $whitelist = [
            'tenant.pin.heartbeat',
            'tenant.pin.unlock',
            'tenant.pin.setup',
            'tenant.switch',
            'tenant.pin.verify',
            'tenant.pin.set',
            'tenant.pin.reset-request',
            'tenant.logout',
            'tenant.select-location',
            'tenant.select-location.store',
            'tenant.switch-location',
        ];
        if (in_array($routeName, $whitelist, true)) {
            return $next($request);
        }

        $thresholdSec = \App\Services\TenantAuthPolicy::idleThresholdSec($tenant);
        $lastIso = $request->session()->get('last_pin_activity_at');

        $isStale = true;
        if ($lastIso) {
            try {
                $last = \Illuminate\Support\Carbon::parse($lastIso);
                $isStale = $last->lt(now()->subSeconds($thresholdSec));
            } catch (\Throwable $e) {
                $isStale = true;
            }
        }

        if ($isStale) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'ok'     => false,
                    'locked' => true,
                    'error'  => 'pin_stale',
                ], 423);
            }

            // Page render — flag the staleness; layout opens the overlay.
            view()->share('pinLockPending', true);
            $response = $next($request);
            // MARKER-OFFLINE-SYNC-PIN — mark locked renders so the offline
            // service worker never caches a page that required a PIN.
            $response->headers->set('X-Pin-Locked', '1');
            return $response;
        }

        // Fresh — touch the activity timestamp, but cap at once a minute
        // so we don't write the session on every single request.
        // MARKER-OFFLINE-SYNC-PIN — automated background requests (offline
        // snapshot refresh, queue replay) must NOT count as human activity,
        // or the idle lock never engages while a tab is open.
        $isBackground = $request->headers->get('X-Intake-Background') === '1';
        if ($lastIso && ! $isBackground) {
            try {
                $last = \Illuminate\Support\Carbon::parse($lastIso);
                if ($last->lt(now()->subMinute())) {
                    $request->session()->put('last_pin_activity_at', now()->toIso8601String());
                }
            } catch (\Throwable $e) {
                // If parse failed, set it fresh so subsequent requests work.
                $request->session()->put('last_pin_activity_at', now()->toIso8601String());
            }
        }

        view()->share('pinLockPending', false);
        return $next($request);
    }
}
