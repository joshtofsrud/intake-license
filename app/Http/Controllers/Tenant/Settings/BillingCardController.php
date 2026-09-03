<?php

namespace App\Http\Controllers\Tenant\Settings;

use App\Http\Controllers\Controller;
use App\Services\Billing\BillingCardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

// MARKER-BILLING-CARD
class BillingCardController extends Controller
{
    private function guardManager()
    {
        $me = Auth::guard('tenant')->user();
        if (! $me || ! $me->isManager()) {
            abort(redirect()->route('tenant.settings.index'));
        }
        return $me;
    }

    public function index(BillingCardService $cards)
    {
        $this->guardManager();
        $tenant = tenant();

        return view('tenant.settings.billing-card', [
            'pageTitle'  => 'Payment method',
            'card'       => $cards->cardState($tenant),
            'configured' => $cards->configured(),
            'pubKey'     => $cards->publishableKey(),
            'billingEmail' => $tenant->billing_email,
        ]);
    }

    /** Called by the page's JS to begin; the card itself never comes here. */
    public function intent(BillingCardService $cards)
    {
        $this->guardManager();

        try {
            return response()->json(['success' => true] + $cards->createSetupIntent(tenant()));
        } catch (\Throwable $e) {
            logger()->error('MARKER-BILLING-CARD setup intent failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => 'Could not start card setup.'], 500);
        }
    }

    /** Stripe redirects back here; the result is verified against Stripe, not trusted. */
    public function complete(Request $request, BillingCardService $cards)
    {
        $this->guardManager();

        $id = (string) $request->query('setup_intent', '');
        if ($id === '') {
            return redirect()->route('tenant.settings.billing_card')
                ->with('error', 'Card setup did not complete.');
        }

        $ok = $cards->storeCardFromSetupIntent(tenant(), $id);

        return redirect()->route('tenant.settings.billing_card')
            ->with($ok ? 'success' : 'error', $ok
                ? 'Card saved. Nothing is charged to it yet.'
                : 'That card could not be saved. Nothing was charged.');
    }

    public function forget(BillingCardService $cards)
    {
        $this->guardManager();
        $cards->forgetCard(tenant());

        return redirect()->route('tenant.settings.billing_card')
            ->with('success', 'Card removed.');
    }

    public function billingEmail(Request $request)
    {
        $this->guardManager();
        $data = $request->validate(['billing_email' => 'nullable|email|max:191']);

        tenant()->forceFill(['billing_email' => $data['billing_email'] ?: null])->save();

        return redirect()->route('tenant.settings.billing_card')
            ->with('success', 'Billing email saved.');
    }
}
