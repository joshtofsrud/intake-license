<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\AddonManagementService;
use App\Services\StripeBillingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * StripeWebhookController — Phase 3 complete.
 *
 * Flow per request:
 *   1. Verify Stripe signature (throws if invalid)
 *   2. Check idempotency table — if we\'ve seen this event_id, return 200 early
 *   3. Insert a row locking the event_id (DB-level dedupe)
 *   4. Dispatch to a typed handler based on event type
 *   5. Mark row processed_at on success, or set error on failure
 *   6. Always return 2xx (except signature failure) so Stripe does not retry
 *
 * Important: we never trust event contents without verification. The $event
 * object comes from the Stripe SDK and has been cryptographically verified.
 *
 * Route: POST /webhooks/stripe/subscriptions (no auth, no CSRF).
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

        $eventId = $event->id ?? null;
        $type = $event->type ?? 'unknown';

        if (! $eventId) {
            Log::warning('[StripeWebhook] event missing id', ['type' => $type]);
            return response()->json(['received' => true]);
        }

        // Idempotency: try to insert a new row. If it collides on event_id
        // unique index, we\'ve already processed (or are processing) this event.
        try {
            DB::table('stripe_webhook_events')->insert([
                'event_id' => $eventId,
                'type' => $type,
                'received_at' => now(),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Duplicate key — already handled. Log once then return.
            Log::info('[StripeWebhook] duplicate event ignored', [
                'event_id' => $eventId, 'type' => $type,
            ]);
            return response()->json(['received' => true, 'duplicate' => true]);
        }

        Log::info('[StripeWebhook] processing', ['event_id' => $eventId, 'type' => $type]);

        try {
            match ($type) {
                'invoice.paid' => $this->onInvoicePaid($event),
                'invoice.payment_failed' => $this->onInvoicePaymentFailed($event),
                // customer.subscription.created is functionally equivalent to updated
                // for our purposes — same payload shape, same fields we care about.
                // Stripe fires both at subscription start; belt-and-suspenders sync.
                'customer.subscription.created',
                'customer.subscription.updated' => $this->onSubscriptionUpdated($event),
                'customer.subscription.deleted' => $this->onSubscriptionDeleted($event),
                default => Log::info('[StripeWebhook] unhandled event type', ['type' => $type]),
            };

            DB::table('stripe_webhook_events')
                ->where('event_id', $eventId)
                ->update(['processed_at' => now()]);

        } catch (\Throwable $e) {
            Log::error('[StripeWebhook] handler error', [
                'event_id' => $eventId,
                'type' => $type,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            DB::table('stripe_webhook_events')
                ->where('event_id', $eventId)
                ->update(['error' => substr($e->getMessage(), 0, 65535)]);

            // Return 200 anyway — Stripe retrying a buggy handler is just noise.
            // The error is logged; a human will investigate via logs.
        }

        return response()->json(['received' => true]);
    }

    /**
     * invoice.paid
     *
     * A scheduled invoice was paid — the subscription is (re)confirmed active.
     * Clear past_due state if present. Trial is over at this point.
     */
    protected function onInvoicePaid($event): void
    {
        $invoice = $event->data->object ?? null;
        $customerId = $invoice->customer ?? null;
        $subscriptionId = $invoice->subscription ?? null;

        if (! $customerId) {
            Log::info('[StripeWebhook] invoice.paid without customer, skipping');
            return;
        }

        $tenant = Tenant::where('stripe_customer_id', $customerId)->first();
        if (! $tenant) {
            Log::warning('[StripeWebhook] invoice.paid no tenant', ['customer' => $customerId]);
            return;
        }

        $updates = ['subscription_status' => 'active'];
        if ($subscriptionId && ! $tenant->stripe_subscription_id) {
            $updates['stripe_subscription_id'] = $subscriptionId;
        }

        $tenant->update($updates);

        Log::info('[StripeWebhook] tenant marked active', [
            'tenant' => $tenant->subdomain,
        ]);
    }

    /**
     * invoice.payment_failed
     *
     * Card declined on a scheduled invoice. Stripe will retry based on its
     * smart-retry schedule. Mark the tenant past_due so the admin UI can
     * show a dunning banner. Actual suspension happens on customer.subscription.deleted.
     */
    protected function onInvoicePaymentFailed($event): void
    {
        $invoice = $event->data->object ?? null;
        $customerId = $invoice->customer ?? null;

        if (! $customerId) return;

        $tenant = Tenant::where('stripe_customer_id', $customerId)->first();
        if (! $tenant) {
            Log::warning('[StripeWebhook] invoice.payment_failed no tenant', ['customer' => $customerId]);
            return;
        }

        $tenant->update(['subscription_status' => 'past_due']);

        Log::warning('[StripeWebhook] tenant past_due', [
            'tenant' => $tenant->subdomain,
            'invoice_amount' => $invoice->amount_due ?? null,
        ]);
    }

    /**
     * customer.subscription.updated
     *
     * Fires for trial-will-end, plan changes, cadence flips, status transitions,
     * and cancel_at_period_end toggles. We sync the cached state on the tenant
     * row so the admin UI stays current without polling Stripe.
     */
    protected function onSubscriptionUpdated($event): void
    {
        $sub = $event->data->object ?? null;
        $subId = $sub->id ?? null;
        $customerId = $sub->customer ?? null;

        if (! $customerId || ! $subId) return;

        $tenant = Tenant::where('stripe_customer_id', $customerId)->first();
        if (! $tenant) {
            Log::warning('[StripeWebhook] subscription.updated no tenant', ['customer' => $customerId]);
            return;
        }

        $updates = [
            'stripe_subscription_id' => $subId,
            'subscription_status' => $sub->status ?? 'unknown',
        ];

        // trial_ends_at: Stripe exposes trial_end as epoch seconds (or null)
        if (isset($sub->trial_end) && $sub->trial_end) {
            $updates['trial_ends_at'] = Carbon::createFromTimestamp($sub->trial_end);
        } elseif (($sub->status ?? null) === 'active') {
            // Trial converted to active — clear the cached trial end
            $updates['trial_ends_at'] = null;
        }

        // Cadence: read from metadata we set at subscription creation
        $metaCadence = $sub->metadata->cadence ?? null;
        if ($metaCadence) {
            $updates['stripe_subscription_cadence'] = $metaCadence;
        }

        $tenant->update($updates);

        Log::info('[StripeWebhook] subscription updated', [
            'tenant' => $tenant->subdomain,
            'status' => $updates['subscription_status'],
            'cadence' => $updates['stripe_subscription_cadence'] ?? null,
        ]);
    }

    /**
     * customer.subscription.deleted
     *
     * Subscription is fully canceled (not just cancel_at_period_end — that
     * fires subscription.updated). Tenant loses access; addon entitlements
     * tied to this subscription should be expired.
     */
    protected function onSubscriptionDeleted($event): void
    {
        $sub = $event->data->object ?? null;
        $subId = $sub->id ?? null;
        $customerId = $sub->customer ?? null;

        if (! $customerId) return;

        $tenant = Tenant::where('stripe_customer_id', $customerId)->first();
        if (! $tenant) {
            Log::warning('[StripeWebhook] subscription.deleted no tenant', ['customer' => $customerId]);
            return;
        }

        $tenant->update([
            'subscription_status' => 'canceled',
        ]);

        // Expire any addon entitlements tied to this subscription.
        // AddonManagementService handles the details — we delegate.
        if ($subId && method_exists($this->manager, 'expireAddonsForSubscription')) {
            try {
                $this->manager->expireAddonsForSubscription($tenant, $subId);
            } catch (\Throwable $e) {
                Log::error('[StripeWebhook] addon expiry failed', [
                    'tenant' => $tenant->subdomain,
                    'subscription' => $subId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::warning('[StripeWebhook] tenant subscription canceled', [
            'tenant' => $tenant->subdomain,
            'subscription' => $subId,
        ]);
    }
}
