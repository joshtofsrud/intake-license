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
        $charge = $pi->latest_charge ?? null;
        if (! $charge || ! is_object($charge)) {
            // Could be a string ID if not expanded; we always expand above
            // but defensively try to expand it.
            if (is_string($charge)) {
                try {
                    $charge = $this->client()->charges->retrieve($charge, ['expand' => ['payment_method_details']]);
                } catch (\Throwable $e) {
                    return ['brand' => null, 'last4' => null, 'funding' => null, 'charge_id' => null];
                }
            } else {
                return ['brand' => null, 'last4' => null, 'funding' => null, 'charge_id' => null];
            }
        }

        $pmDetails = $charge->payment_method_details ?? null;
        $card = $pmDetails?->card ?? null;

        return [
            'brand'     => $card?->brand ?? null,
            'last4'     => $card?->last4 ?? null,
            'funding'   => $card?->funding ?? null,
            'charge_id' => $charge->id ?? null,
        ];
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
