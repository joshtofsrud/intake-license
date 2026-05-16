<?php

namespace App\Http\Middleware;

use App\Services\FeatureAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RequireRetailCapability
 *
 * Route-group middleware enforcing the 'retail' capability — the coupled
 * access gate for Register, Inventory, and the walk-in flow.
 *
 * The gate resolves through FeatureAccessService (tier inclusion +
 * per-tenant grants/suppressions). A tenant either has retail operations
 * or doesn't — this middleware refuses the request if not.
 *
 * Used at route-group level (in routes/web.php) so every register route
 * is gated by construction. Adding a new register route inside the group
 * automatically inherits the gate; it cannot be forgotten.
 */
class RequireRetailCapability
{
    public function __construct(
        protected FeatureAccessService $featureAccess,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = tenant();

        if (!$tenant || !$this->featureAccess->hasAddon($tenant, 'retail')) {
            abort(403, 'Retail operations require the Retail capability. Upgrade to Branded or Scale to access the register and inventory.');
        }

        return $next($request);
    }
}
