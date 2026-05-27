<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\BillingSettings;
use App\Services\Tenant\StripeConnectService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

/**
 * MARKER-PATCH-168 — Stripe Connect Session A.
 *
 * Handles Connect-account events (account.updated, etc.). These come
 * through a separate webhook endpoint from platform-billing events.
 *
 * For Session A the only event we care about is account.updated — it
 * fires when a tenant completes onboarding, when Stripe enables/disables
 * charges or payouts, or when new requirements are due.
 *
 * Route: POST /webhooks/stripe-connect (no auth, no CSRF)
 *
 * Session B will add: payment_intent.succeeded, payment_intent.payment_failed,
 * charge.refunded, charge.dispute.created, etc.
 */
class StripeConnectWebhookController extends Controller
{
    public function __construct(protected StripeConnectService $connect) {}

    public function handle(Request $request)
    {
        $payload = $request->getContent();
        $sig = $request->header('Stripe-Signature', '');
        $secret = BillingSettings::current()->activeConnectWebhookSecret();

        if (! $secret) {
            Log::error('stripe_connect_webhook.no_secret_configured');
            return response()->json(['error' => 'webhook not configured'], 500);
        }

        try {
            $event = Webhook::constructEvent($payload, $sig, $secret);
        } catch (SignatureVerificationException $e) {
            Log::warning('stripe_connect_webhook.bad_signature', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'bad signature'], 400);
        } catch (\Throwable $e) {
            Log::error('stripe_connect_webhook.parse_failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'parse failed'], 400);
        }

        // Connect events carry the account ID in $event->account
        $accountId = $event->account ?? null;
        if (! $accountId) {
            // Some events legitimately have no account (platform-side events
            // erroneously sent to this endpoint). Acknowledge and move on.
            return response()->json(['received' => true]);
        }

        try {
            $this->dispatch($event, $accountId);
        } catch (\Throwable $e) {
            Log::error('stripe_connect_webhook.handler_failed', [
                'event_type' => $event->type,
                'event_id'   => $event->id,
                'account_id' => $accountId,
                'error'      => $e->getMessage(),
            ]);
            // Return 2xx so Stripe doesn\'t retry forever on our bugs. We have
            // logs. If we returned 5xx, Stripe would retry for 3 days.
        }

        return response()->json(['received' => true]);
    }

    protected function dispatch(\Stripe\Event $event, string $accountId): void
    {
        switch ($event->type) {
            case 'account.updated':
                $this->onAccountUpdated($accountId);
                break;

            // Session B handlers will be added here:
            //   payment_intent.succeeded
            //   payment_intent.payment_failed
            //   charge.refunded
            //   charge.dispute.created

            default:
                Log::info('stripe_connect_webhook.ignored_event', [
                    'type' => $event->type,
                    'account_id' => $accountId,
                ]);
        }
    }

    protected function onAccountUpdated(string $accountId): void
    {
        $tenant = $this->connect->findTenantByAccountId($accountId);
        if (! $tenant) {
            Log::info('stripe_connect_webhook.unknown_account', ['account_id' => $accountId]);
            return;
        }
        $this->connect->refreshAccount($tenant);
    }
}
