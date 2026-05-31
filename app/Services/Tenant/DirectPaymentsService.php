<?php

namespace App\Services\Tenant;

use App\Models\Tenant;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * MARKER-PATCH-170 — Direct Payments Session 2A.
 *
 * Tenant-scoped Stripe client + helpers for the hand-keyed card flow.
 *
 * Keys come from tenant settings JSON (register_payments_test_sk /
 * register_payments_live_sk depending on mode), NOT from BillingSettings.
 * Each tenant uses their own Stripe account.
 *
 * No application_fee — this is direct, not Connect. The tenant is the
 * merchant of record. Intake doesn\'t take a cut, doesn\'t carry liability.
 */
class DirectPaymentsService
{
    public function __construct(protected Tenant $tenant) {}

    public function isEnabled(): bool
    {
        if (! $this->tenant->direct_payments_enabled) return false;
        return (bool) $this->activeSecretKey();
    }

    public function publishableKey(): ?string
    {
        $s = $this->tenant->settings ?? [];
        return $this->mode() === 'live'
            ? ($s['register_payments_live_pk'] ?? null)
            : ($s['register_payments_test_pk'] ?? null);
    }

    public function activeSecretKey(): ?string
    {
        $s = $this->tenant->settings ?? [];
        return $this->mode() === 'live'
            ? ($s['register_payments_live_sk'] ?? null)
            : ($s['register_payments_test_sk'] ?? null);
    }

    public function webhookSecret(): ?string
    {
        $s = $this->tenant->settings ?? [];
        return $s['register_payments_webhook_secret'] ?? null;
    }

    public function mode(): string
    {
        $s = $this->tenant->settings ?? [];
        return ($s['register_payments_mode'] ?? 'test') === 'live' ? 'live' : 'test';
    }

    /**
     * Create a PaymentIntent for a register sale.
     *
     * Returns the PI object. The caller hands the client_secret to the
     * front-end Stripe.js to confirm the card on the customer\'s behalf.
     */
    public function createPaymentIntent(int $amountCents, string $currency = 'usd', array $metadata = []): \Stripe\PaymentIntent
    {
        $client = $this->client();

        return $client->paymentIntents->create([
            'amount'   => $amountCents,
            'currency' => $currency,
            // automatic_payment_methods enables card, Apple Pay, Google Pay,
            // and Link automatically based on the customer\'s device. The
            // Payment Element on the front end picks up whatever Stripe
            // says is available.
            'automatic_payment_methods' => ['enabled' => true],
            'metadata' => array_merge([
                'intake_tenant_id'   => $this->tenant->id,
                'intake_tenant_name' => $this->tenant->name,
                'intake_environment' => app()->environment(),
            ], $metadata),
        ]);
    }

    /**
     * Retrieve a PaymentIntent from Stripe to verify its final state
     * before we commit the sale. We don\'t trust client claims that
     * the charge succeeded — we always check Stripe.
     */
    public function retrievePaymentIntent(string $piId): \Stripe\PaymentIntent
    {
        return $this->client()->paymentIntents->retrieve($piId, [
            'expand' => ['latest_charge.payment_method_details'],
        ]);
    }

    /**
     * Extract card display details from a confirmed PaymentIntent.
     * Returns [brand, last4, funding] or nulls.
     */
    public function extractCardDetails(\Stripe\PaymentIntent $pi): array
    {
        // MARKER-PATCH-171 — handles two response shapes:
        //   1. Classic: PI.latest_charge.payment_method_details.card.{brand,last4,funding}
        //   2. Payment Element with automatic methods: same path, but charge
        //      may come back as a string ID until we explicitly expand it.
        //   3. Fallback: pull from PI.payment_method_options.card or
        //      retrieve the PaymentMethod object directly.
        $chargeId = null;
        $charge = $pi->latest_charge ?? null;

        if (is_string($charge)) {
            try {
                $charge = $this->client()->charges->retrieve($charge, [
                    'expand' => ['payment_method_details'],
                ]);
            } catch (\Throwable $e) {
                $charge = null;
            }
        }

        $brand = null; $last4 = null; $funding = null;

        if (is_object($charge)) {
            $chargeId = $charge->id ?? null;
            $pmDetails = $charge->payment_method_details ?? null;
            if ($pmDetails) {
                $card = $pmDetails->card ?? null;
                if ($card) {
                    $brand   = $card->brand ?? null;
                    $last4   = $card->last4 ?? null;
                    $funding = $card->funding ?? null;
                }
            }
        }

        // Fallback: hit the PaymentMethod directly if we still don\'t have it.
        // Happens when the PI confirms via certain digital-wallet routes that
        // surface differently in latest_charge.
        if (! $brand && ! $last4) {
            $pmId = is_string($pi->payment_method ?? null) ? $pi->payment_method : null;
            if ($pmId) {
                try {
                    $pm = $this->client()->paymentMethods->retrieve($pmId);
                    if ($pm->type === 'card' && $pm->card) {
                        $brand   = $pm->card->brand ?? null;
                        $last4   = $pm->card->last4 ?? null;
                        $funding = $pm->card->funding ?? null;
                    }
                } catch (\Throwable $e) {
                    // Best effort — leave nulls.
                }
            }
        }

        return [
            'brand'     => $brand,
            'last4'     => $last4,
            'funding'   => $funding,
            'charge_id' => $chargeId,
        ];
    }

    /**
     * MARKER-PATCH-172 — Create a Stripe Checkout Session for a customer-pays-
     * remotely flow (send-payment-link). Customer pays from their own device
     * via a Stripe-hosted page.
     *
     * Single-use session, expires in 24h. amount_cents lives in a single
     * line_item so the Stripe page shows a single "Charge" line for the
     * shop. We don\'t itemize individual cart lines on the Stripe page —
     * the customer sees the total + the shop name, that\'s it.
     */
    public function createCheckoutSession(int $amountCents, string $description, array $metadata = []): \Stripe\Checkout\Session
    {
        $client = $this->client();

        $session = $client->checkout->sessions->create([
            'mode' => 'payment',
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => 'usd',
                    'product_data' => [
                        'name' => $description ?: 'Payment',
                    ],
                    'unit_amount' => $amountCents,
                ],
                'quantity' => 1,
            ]],
            'success_url' => 'https://' . $this->tenantHost() . '/admin/register/checkout-success?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'  => 'https://' . $this->tenantHost() . '/admin/register/checkout-cancel?session_id={CHECKOUT_SESSION_ID}',
            'expires_at'  => time() + (24 * 60 * 60),
            'metadata' => array_merge([
                'intake_tenant_id'   => $this->tenant->id,
                'intake_environment' => app()->environment(),
            ], $metadata),
            // Surface customer email collection so receipts can attach.
            'customer_email' => $metadata['customer_email'] ?? null,
        ]);

        return $session;
    }

    public function retrieveCheckoutSession(string $sessionId): \Stripe\Checkout\Session
    {
        return $this->client()->checkout->sessions->retrieve($sessionId, [
            'expand' => ['payment_intent.latest_charge.payment_method_details'],
        ]);
    }

    protected function tenantHost(): string
    {
        return $this->tenant->custom_domain
            ?: ($this->tenant->subdomain . '.intake.works');
    }

    /**
     * MARKER-PATCH-171 — Refund a Stripe charge for a sale that was paid
     * via the direct-payments flow.
     *
     * $amountCents=null means full refund. Stripe handles partial.
     * $metadata is merged into the refund\'s metadata for traceability.
     *
     * Returns the Refund object on success. Throws on failure — the caller
     * must decide how to handle (different from refundPaymentIntent helper
     * in 170B, which never throws; that one is used for auto-cleanup paths
     * where errors are non-fatal).
     */
    public function refundCharge(string $piId, ?int $amountCents = null, array $metadata = []): \Stripe\Refund
    {
        $params = [
            'payment_intent' => $piId,
            'reason'         => 'requested_by_customer',
            'metadata'       => array_filter(array_merge([
                'intake_tenant_id'    => $this->tenant->id,
                'intake_source'       => 'sale_refund',
            ], $metadata)),
        ];
        if ($amountCents !== null) {
            $params['amount'] = $amountCents;
        }
        return $this->client()->refunds->create($params);
    }

    /**
     * MARKER-PATCH-170B — auto-refund a PaymentIntent that succeeded but
     * couldn\'t be committed to a sale. Used as defense in depth when
     * createSale throws AFTER the card already authorized.
     *
     * Returns the Refund object on success, or null + logs on failure.
     * We never let this throw — the caller is already handling a primary
     * failure and we don\'t want to mask it.
     */
    public function refundPaymentIntent(string $piId, ?string $reason = null): ?\Stripe\Refund
    {
        try {
            return $this->client()->refunds->create([
                'payment_intent' => $piId,
                'reason'         => 'requested_by_customer',
                'metadata'       => array_filter([
                    'intake_tenant_id'   => $this->tenant->id,
                    'intake_auto_refund' => '1',
                    'intake_reason'      => $reason,
                ]),
            ]);
        } catch (\Throwable $e) {
            Log::error('direct_payments.auto_refund_failed', [
                'tenant_id' => $this->tenant->id,
                'pi'        => $piId,
                'error'     => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * MARKER-PATCH-196 — list succeeded PaymentIntents since a unix timestamp,
     * for Stripe-vs-ledger reconciliation. Paginates (100/page, capped) so a
     * busy tenant doesn't blow the request. Returns an array of lightweight
     * rows: [id, amount_cents, created, customer_email, card].
     */
    public function listSucceededPaymentIntents(int $sinceTs, int $maxPages = 5): array
    {
        $client = $this->client();
        $out = [];
        $params = [
            'limit'   => 100,
            'created' => ['gte' => $sinceTs],
        ];
        $page = 0;
        do {
            $resp = $client->paymentIntents->all($params);
            foreach ($resp->data as $pi) {
                if (($pi->status ?? null) !== 'succeeded') continue;
                if ((int) ($pi->amount_received ?? 0) <= 0) continue;
                $out[] = [
                    'id'           => $pi->id,
                    'amount_cents' => (int) ($pi->amount_received ?? $pi->amount ?? 0),
                    'created'      => (int) ($pi->created ?? 0),
                    'description'  => $pi->description ?? null,
                ];
            }
            $page++;
            $hasMore = $resp->has_more ?? false;
            if ($hasMore && !empty($resp->data)) {
                $params['starting_after'] = end($resp->data)->id;
            }
        } while ($hasMore && $page < $maxPages);

        return $out;
    }

    protected function client(): StripeClient
    {
        $key = $this->activeSecretKey();
        if (! $key) {
            throw new \RuntimeException("Tenant {$this->tenant->id} has no active Stripe secret key configured for direct payments.");
        }
        return new StripeClient([
            'api_key'        => $key,
            'stripe_version' => '2024-06-20',
        ]);
    }
}
