<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\StripeBillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * BillingController
 *
 * Tenant-facing billing actions. Delegates heavy lifting to Stripe\'s hosted
 * Billing Portal for card updates, invoice history, and cancellation. Plan
 * changes happen in-app through a separate flow (not exposed here) \u2014 the
 * portal configuration in Stripe dashboard disables plan switching.
 *
 * Auth: requires authenticated tenant admin (middleware on the route group).
 */
class BillingController extends Controller
{
    public function __construct(protected StripeBillingService $stripe) {}

    /**
     * GET /admin/billing/portal
     *
     * Generates a one-time Stripe Billing Portal URL and redirects the user
     * to it. The URL expires in a few minutes and can only be used by the
     * associated Stripe customer, so there\'s no token leakage concern.
     */
    public function portal(Request $request, string $subdomain)
    {
        $tenant = tenant();

        if (! $tenant->stripe_customer_id) {
            Log::warning('[Billing] portal requested for tenant with no stripe_customer_id', [
                'tenant' => $tenant->subdomain,
            ]);
            return redirect()
                ->route('tenant.dashboard', ['subdomain' => $subdomain])
                ->withErrors(['general' => 'Billing is not set up for this account. Contact support.']);
        }

        $returnUrl = route('tenant.dashboard', ['subdomain' => $subdomain]);

        try {
            $portalUrl = $this->stripe->createBillingPortalSession(
                customerId: $tenant->stripe_customer_id,
                returnUrl: $returnUrl,
            );
        } catch (\Throwable $e) {
            Log::error('[Billing] portal session creation failed', [
                'tenant' => $tenant->subdomain,
                'customer' => $tenant->stripe_customer_id,
                'error' => $e->getMessage(),
            ]);
            return redirect()
                ->route('tenant.dashboard', ['subdomain' => $subdomain])
                ->withErrors(['general' => 'We couldn\'t open billing right now. Please try again or contact support.']);
        }

        return redirect()->away($portalUrl);
    }
}
