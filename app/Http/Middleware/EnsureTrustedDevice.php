<?php

namespace App\Http\Middleware;

use App\Services\DeviceTrustService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureTrustedDevice
 *
 * Layer 1 gate. Runs before RequireTenantAuth in the tenant middleware
 * stack (wired in chunk 4). Resolution:
 *
 *   1. If tenant->pin_tier_active is FALSE → pass through. Starter and
 *      single-user Branded tenants don't use the PIN tier; they keep the
 *      existing email/password-every-visit flow.
 *
 *   2. Else, look for the device-trust cookie:
 *        - Present + valid → touch the row, set $request->attributes
 *          'trusted_device' = $device, pass through.
 *        - Missing / invalid / expired → redirect to login (with
 *          ?intended back to the current URL).
 *
 * The presence of $request->attributes->get('trusted_device') is the
 * signal to AuthController and StaffSwitchController that the device
 * tier has been satisfied and they can show the staff switcher instead
 * of the email login.
 *
 * NOT mounted in routes yet. Chunk 4 wires it in.
 */
class EnsureTrustedDevice
{
    public function __construct(protected DeviceTrustService $devices) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = app('tenant') ?? null;

        // No tenant context = platform domain. Not our concern.
        if (! $tenant) {
            return $next($request);
        }

        // Starter + single-user Branded: PIN tier off. Keep existing flow.
        if (! $tenant->pin_tier_active) {
            return $next($request);
        }

        // PATCH-CHUNK-5H session-bypass — an authenticated tenant session
        // is itself proof of identity for this request. Only enforce the
        // device-trust gate when no session is in play. Without this, a
        // user who signs in via email+password (no trust opt-in) gets a
        // redirect loop: login succeeds → dashboard → middleware sees no
        // cookie → back to login.
        if (\Illuminate\Support\Facades\Auth::guard('tenant')->check()) {
            // Still touch the device row if a cookie IS present, so its
            // sliding-expiry stays current.
            $cookieValue = $request->cookie(DeviceTrustService::COOKIE_NAME);
            if ($cookieValue) {
                $device = $this->devices->validate($tenant, $cookieValue);
                if ($device) {
                    $this->devices->touch($device, $request);
                    $request->attributes->set('trusted_device', $device);
                }
            }
            return $next($request);
        }

        $cookieValue = $request->cookie(DeviceTrustService::COOKIE_NAME);
        $device = $this->devices->validate($tenant, $cookieValue);

        if (! $device) {
            // Not trusted (or trust expired). Send to login.
            if ($request->expectsJson()) {
                return response()->json([
                    'ok'    => false,
                    'error' => 'device_not_trusted',
                    'redirect' => route('tenant.login'),
                ], 401);
            }
            return redirect()->guest(route('tenant.login'));
        }

        // Valid device. Bump last_used_at + sliding expiry.
        $this->devices->touch($device, $request);

        // Stash the device on the request so downstream middleware and
        // controllers can read it without re-querying.
        $request->attributes->set('trusted_device', $device);

        return $next($request);
    }
}
