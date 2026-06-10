<?php
// MARKER-PATCH-217

namespace App\Http\Middleware;

use App\Services\FeatureAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RequireRentalCapability
 *
 * Route-group middleware enforcing the 'rentals' capability — the access
 * gate for the Rental Desk, Fleet, Availability, Bookings, and Settings.
 *
 * Resolves through FeatureAccessService (a-la-carte grant + min_plan_tier
 * floor + suppressions; rentals is never tier-included). Used at
 * route-group level so every rental route is gated by construction —
 * adding a route inside the group cannot forget the gate.
 */
class RequireRentalCapability
{
    public function __construct(
        protected FeatureAccessService $featureAccess,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = tenant();

        if (!$tenant || !$this->featureAccess->hasAddon($tenant, 'rentals')) {
            abort(403, 'Rentals require the Rental system add-on (Branded plan or higher).');
        }

        return $next($request);
    }
}
