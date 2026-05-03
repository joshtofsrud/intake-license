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

        return $next($request);
    }
}
