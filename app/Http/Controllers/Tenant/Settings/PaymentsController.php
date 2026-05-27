<?php

namespace App\Http\Controllers\Tenant\Settings;

use App\Http\Controllers\Controller;
use App\Services\Tenant\StripeConnectService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * MARKER-PATCH-168 — Stripe Connect Session A.
 *
 * Routes:
 *   GET  /admin/settings/payments              -> index
 *   POST /admin/settings/payments/connect      -> start onboarding (creates account if needed, returns Stripe URL)
 *   POST /admin/settings/payments/resume       -> resume onboarding for an existing account
 *   POST /admin/settings/payments/disconnect   -> clear our reference to the Connect account
 *
 * The index view refreshes account state automatically on load when
 * ?stripe_return=1 is present (tenant just came back from Stripe).
 */
class PaymentsController extends Controller
{
    public function __construct(protected StripeConnectService $connect) {}

    public function index(Request $request): View
    {
        $tenant = tenant();

        // If the tenant just returned from Stripe, refresh state immediately
        // before rendering so they see the right banner.
        if ($request->boolean('stripe_return') && $tenant->stripe_connect_account_id) {
            $this->connect->refreshAccount($tenant);
            $tenant->refresh();
        }

        return view('tenant.settings.payments', [
            'tenant'         => $tenant,
            'connectStatus'  => $tenant->stripe_connect_status,
            'requirements'   => $tenant->stripe_connect_requirements_due ?? [],
        ]);
    }

    public function connect(Request $request): RedirectResponse
    {
        $tenant = tenant();

        try {
            // Idempotent: returns existing account if already created
            $this->connect->createAccount($tenant);
            $url = $this->connect->createAccountLink($tenant, 'account_onboarding');
            return redirect()->away($url);
        } catch (\Throwable $e) {
            Log::error('stripe_connect.connect_failed', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
            return redirect()->route('tenant.settings.payments.index')
                ->with('error', 'Could not start Stripe setup. Please try again or contact support.');
        }
    }

    public function resume(Request $request): RedirectResponse
    {
        $tenant = tenant();

        if (! $tenant->stripe_connect_account_id) {
            return redirect()->route('tenant.settings.payments.index');
        }

        try {
            $type = $tenant->stripe_connect_status === 'restricted'
                ? 'account_update'
                : 'account_onboarding';
            $url = $this->connect->createAccountLink($tenant, $type);
            return redirect()->away($url);
        } catch (\Throwable $e) {
            Log::error('stripe_connect.resume_failed', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
            return redirect()->route('tenant.settings.payments.index')
                ->with('error', 'Could not open Stripe setup. Please try again.');
        }
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $request->validate(['confirm' => 'required|in:yes']);

        $tenant = tenant();
        $this->connect->disconnect($tenant);

        return redirect()->route('tenant.settings.payments.index')
            ->with('success', 'Stripe disconnected. Card payments are off in the register.');
    }
}
