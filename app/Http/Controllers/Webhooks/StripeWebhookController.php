<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\AddonManagementService;
use App\Services\StripeBillingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * StripeWebhookController
 *
 * Entry point for every Stripe webhook event. Handlers below are scaffolded;
 * fill in when Stripe is plumbed.
 *
 * Security:
 *   - Signature verification via StripeBillingService::verifyWebhook
 *   - Never trust event contents without verification
 *   - Return 2xx even on unknown events so Stripe stops retrying
 *
 * Route: POST /webhooks/stripe (no auth, no CSRF - Stripe signs the request).
 */
class StripeWebhookController extends Controller
{
    public function __construct(
        protected StripeBillingService $stripe,
        protected AddonManagementService $manager,
    ) {}

    public function handle(Request $request)
    {
        $payload = $request->getContent();
        $sig = $request->header('Stripe-Signature', '');

        try {
            $event = $this->stripe->verifyWebhook($payload, $sig);
        } catch (\Throwable $e) {
            Log::warning('[StripeWebhook] verification failed', ['error' => $e->getMessage()]);
            return response('signature invalid', 400);
        }

        $type = $event->type ?? 'unknown';

        Log::info('[StripeWebhook] event received', ['type' => $type]);

        try {
            match ($type) {
                'invoice.paid' => $this->onInvoicePaid($event),
                'invoice.payment_failed' => $this->onInvoicePaymentFailed($event),
                'customer.subscription.updated' => $this->onSubscriptionUpdated($event),
                'customer.subscription.deleted' => $this->onSubscriptionDeleted($event),
                default => Log::info('[StripeWebhook] unhandled event', ['type' => $type]),
            };
        } catch (\Throwable $e) {
            Log::error('[StripeWebhook] handler error', [
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['received' => true]);
    }

    protected function onInvoicePaid($event): void
    {
        // TODO(stripe): implement
        Log::info('[StripeWebhook] STUB onInvoicePaid');
    }

    protected function onInvoicePaymentFailed($event): void
    {
        // TODO(stripe): implement
        Log::info('[StripeWebhook] STUB onInvoicePaymentFailed');
    }

    protected function onSubscriptionUpdated($event): void
    {
        // TODO(stripe): sync current_period_end, status changes
        Log::info('[StripeWebhook] STUB onSubscriptionUpdated');
    }

    protected function onSubscriptionDeleted($event): void
    {
        // TODO(stripe): expire every tenant_feature_addons row tied to this subscription
        Log::info('[StripeWebhook] STUB onSubscriptionDeleted');
    }
}
