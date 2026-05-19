<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RequireCurrentLocation
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('tenant')->user();
        if (!$user) {
            return redirect()->route('tenant.login');
        }

        $locationId = $request->session()->get('current_location_id');

        $stillValid = $locationId
            && $user->activeLocations()
                    ->where('tenant_locations.id', $locationId)
                    ->exists();

        if (!$stillValid) {
            $request->session()->forget('current_location_id');

            if ($request->expectsJson()) {
                return response()->json([
                    'ok'    => false,
                    'error' => 'location_required',
                    'redirect' => route('tenant.select-location'),
                ], 409);
            }

            return redirect()->route('tenant.select-location');
        }

        // ----------------------------------------------------------------
        // Patch 103 — share the current location + the user's full active-
        // locations list with all Blade views. The header switcher (and any
        // other location-aware UI) reads these. Loaded once here to avoid
        // N+1 queries from views that need the same data.
        // ----------------------------------------------------------------
        $currentLocation = $user->activeLocations()
            ->where('tenant_locations.id', $locationId)
            ->first();

        $userLocations = $user->activeLocations()
            ->orderBy('is_default', 'desc')
            ->orderBy('name')
            ->get();

        view()->share('currentLocation', $currentLocation);
        view()->share('userLocations', $userLocations);

        // Also bind into the container so non-view code can resolve it
        // without re-querying.
        app()->instance('current_location', $currentLocation);

        return $next($request);
    }
}
