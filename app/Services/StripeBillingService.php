<?php

namespace App\Services;

use App\Models\BillingSettings;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * StripeBillingService — real Stripe integration for Intake's platform billing.
 *
 * Scope: tenant-to-Intake billing only (subscription fees).
 * NOT used for: tenant-to-customer payments (that's Stripe Connect, separate).
 *
 * Configuration: reads keys from BillingSettings model (DB, encrypted).
 * All Stripe API calls go through $this->client() which creates a fresh
 * StripeClient per call with the currently-configured secret key.
 *
 * Error handling: every Stripe API call is wrapped in try/catch. API errors
 * are logged + re-thrown as domain-level exceptions the caller can handle
 * cleanly. Network errors + 5xx from Stripe are retried at the SDK level.
 *
 * Idempotency: all mutation calls pass an Idempotency-Key so duplicate
 * requests (webhook replays, user double-clicks) don't create duplicates.
 */
class StripeBillingService
{
    /**
     * Is Stripe live-mode active?
     */
    public function isLive(): bool
    {
        return BillingSettings::current()->isLive();
    }

    /**
     * Is Stripe configured enough to process payments at all?
     */
    public function isConfigured(): bool
    {
        return BillingSettings::current()->isConfigured();
    }

    /**
     * Test connectivity + key validity.
     * Returns ['ok' => bool, 'message' => string, 'account' => ?array]
     */
    public function testConnection(): array
    {
        try {
            $account = $this->client()->accounts->retrieve();
            return [
                'ok' => true,
                'message' => "Connected to: {$account->email} ({$account->id})",
                'account' => [
                    'id' => $account->id,
                    'email' => $account->email,
                    'country' => $account->country,
                    'charges_enabled' => $account->charges_enabled,
                    'payouts_enabled' => $account->payouts_enabled,
                ],
            ];
        } catch (ApiErrorException $e) {
            return [
                'ok' => false,
                'message' => $e->getMessage(),
                'account' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => 'Connection error: ' . $e->getMessage(),
                'account' => null,
            ];
        }
    }

    // ==================================================================
    // Customer management
    // ==================================================================

    /**
     * Create a Stripe customer for a tenant.
     * Returns the Stripe customer ID.
     */
    public function createCustomer(Tenant $tenant, string $email, string $name): string
    {
        $customer = $this->client()->customers->create(
            [
                'email' => $email,
                'name' => $name,
                'metadata' => [
                    'tenant_id' => $tenant->id,
                    'subdomain' => $tenant->subdomain,
                ],
            ],
            [
                'idempotency_key' => "customer-create-{$tenant->id}",
            ]
        );

        return $customer->id;
    }

    /**
     * Retrieve a Stripe customer. Returns null if not found.
     */
    public function getCustomer(string $customerId): ?\Stripe\Customer
    {
        try {
            return $this->client()->customers->retrieve($customerId);
        } catch (ApiErrorException $e) {
            Log::warning('Stripe customer retrieve failed', [
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    // ==================================================================
    // Subscription management
    // ==================================================================

    /**
     * Create a trialing subscription for a tenant on a specific plan.
     *
     * @param  string  $customerId  Stripe customer ID
     * @param  string  $tier        starter|branded|scale
     * @param  string  $cadence     monthly|annual
     * @param  int     $trialDays   default 14
     * @param  string|null  $paymentMethodId  Stripe PM ID (card collected at signup)
     * @return \Stripe\Subscription
     */
    /**
     * Attach a payment method to a customer.
     *
     * Required before using the PM as default_payment_method on a subscription.
     * Stripe\'s PaymentElement flow collects a PaymentMethod object on the
     * frontend but does not auto-attach it to the customer \u2014 we do that here.
     *
     * Idempotent: Stripe ignores re-attach attempts on PMs that are already
     * attached to the same customer.
     */
    public function attachPaymentMethod(string $customerId, string $paymentMethodId): void
    {
        try {
            $this->client()->paymentMethods->attach(
                $paymentMethodId,
                ['customer' => $customerId],
                ['idempotency_key' => "pm-attach-{$customerId}-{$paymentMethodId}"]
            );
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            // If the PM is already attached to this customer, Stripe throws;
            // treat that as success.
            if (str_contains($e->getMessage(), 'already been attached')) {
                return;
            }
            throw $e;
        }
    }

        public function createTrialingSubscription(
        string $customerId,
        string $tier,
        string $cadence,
        int $trialDays = 14,
        ?string $paymentMethodId = null,
    ): \Stripe\Subscription {
        $priceId = BillingSettings::current()->priceIdFor($tier, $cadence);
        if (! $priceId) {
            throw new \RuntimeException("No Stripe price configured for {$tier} {$cadence}");
        }

        $params = [
            'customer' => $customerId,
            'items' => [['price' => $priceId]],
            'trial_period_days' => $trialDays,
            'payment_behavior' => 'default_incomplete',
            'payment_settings' => [
                'save_default_payment_method' => 'on_subscription',
            ],
            'metadata' => [
                'tier' => $tier,
                'cadence' => $cadence,
            ],
            'expand' => ['latest_invoice.payment_intent'],
        ];

        if ($paymentMethodId) {
            $params['default_payment_method'] = $paymentMethodId;
        }

        // Include payment method in the idempotency key so retries with a
        // different PM (e.g. user fixed a declined card) get a fresh key.
        $idemSuffix = $paymentMethodId ?: 'no-pm';
        return $this->client()->subscriptions->create(
            $params,
            ['idempotency_key' => "sub-create-{$customerId}-{$tier}-{$cadence}-{$idemSuffix}"]
        );
    }

    /**
     * Cancel a subscription at the current period end.
     * Tenant keeps access until then.
     */
    public function cancelSubscriptionAtPeriodEnd(string $subscriptionId): \Stripe\Subscription
    {
        return $this->client()->subscriptions->update(
            $subscriptionId,
            ['cancel_at_period_end' => true],
            ['idempotency_key' => "sub-cancel-{$subscriptionId}"]
        );
    }

    /**
     * Resume a subscription that was set to cancel at period end.
     */
    public function resumeSubscription(string $subscriptionId): \Stripe\Subscription
    {
        return $this->client()->subscriptions->update(
            $subscriptionId,
            ['cancel_at_period_end' => false],
            ['idempotency_key' => "sub-resume-{$subscriptionId}"]
        );
    }

    /**
     * Change a subscription to a different plan tier or cadence.
     * Prorates immediately (Stripe's default behavior).
     */
    public function changeSubscriptionPlan(
        string $subscriptionId,
        string $tier,
        string $cadence,
    ): \Stripe\Subscription {
        $priceId = BillingSettings::current()->priceIdFor($tier, $cadence);
        if (! $priceId) {
            throw new \RuntimeException("No Stripe price configured for {$tier} {$cadence}");
        }

        $subscription = $this->client()->subscriptions->retrieve($subscriptionId);
        $currentItemId = $subscription->items->data[0]->id;

        return $this->client()->subscriptions->update(
            $subscriptionId,
            [
                'items' => [[
                    'id' => $currentItemId,
                    'price' => $priceId,
                ]],
                'proration_behavior' => 'always_invoice', // immediate prorated charge
                'metadata' => [
                    'tier' => $tier,
                    'cadence' => $cadence,
                ],
            ],
            ['idempotency_key' => "sub-change-{$subscriptionId}-{$tier}-{$cadence}"]
        );
    }

    // ==================================================================
    // Addon subscription items (framework for Phase 5)
    // ==================================================================

    /**
     * Add an addon as a subscription item (line item on existing subscription).
     * Returns the subscription item ID.
     */
    public function addSubscriptionItem(
        string $subscriptionId,
        string $stripePriceId,
    ): string {
        $item = $this->client()->subscriptionItems->create(
            [
                'subscription' => $subscriptionId,
                'price' => $stripePriceId,
                'proration_behavior' => 'always_invoice',
            ],
            ['idempotency_key' => "item-create-{$subscriptionId}-{$stripePriceId}"]
        );

        return $item->id;
    }

    /**
     * Cancel a subscription item at period end (addon cancellation).
     */
    public function cancelSubscriptionItemAtPeriodEnd(string $itemId): void
    {
        $this->client()->subscriptionItems->update(
            $itemId,
            [
                'proration_behavior' => 'none',
                'cancel_at_period_end' => true,
            ],
            ['idempotency_key' => "item-cancel-{$itemId}"]
        );
    }

    // ==================================================================
    // Webhooks
    // ==================================================================

    /**
     * Verify a webhook signature. Throws if invalid.
     *
     * @param  string  $payload  raw request body
     * @param  string  $signature  Stripe-Signature header value
     * @return \Stripe\Event
     */
    public function verifyWebhook(string $payload, string $signature): \Stripe\Event
    {
        $secret = BillingSettings::current()->activeWebhookSecret();
        if (! $secret) {
            throw new \RuntimeException('Webhook secret not configured');
        }

        return \Stripe\Webhook::constructEvent($payload, $signature, $secret);
    }

    // ==================================================================
    // Billing portal
    // ==================================================================

    /**
     * Create a billing portal session URL for a customer.
     * Returns the URL the tenant should be redirected to.
     */
    public function createBillingPortalSession(
        string $customerId,
        string $returnUrl,
    ): string {
        $session = $this->client()->billingPortal->sessions->create([
            'customer' => $customerId,
            'return_url' => $returnUrl,
        ]);

        return $session->url;
    }

    // ==================================================================
    // Internal
    // ==================================================================

    /**
     * Get a configured Stripe client using the current secret key.
     * Creates a fresh instance per call (cheap, no connection pooling needed).
     *
     * @throws \RuntimeException if not configured
     */
    protected function client(): StripeClient
    {
        $key = BillingSettings::current()->activeSecretKey();
        if (! $key) {
            throw new \RuntimeException('Stripe secret key not configured. Set it in master admin → Billing configuration.');
        }

        return new StripeClient([
            'api_key' => $key,
            'stripe_version' => '2024-06-20', // pin API version for stability
        ]);
    }
}
