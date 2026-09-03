<?php

namespace App\Services\Billing;

use App\Models\BillingSettings;
use App\Models\Tenant;
use Stripe\StripeClient;

/**
 * MARKER-BILLING-CARD — saving a card that can be charged later, unattended.
 *
 * Two things this gets right on purpose:
 *
 *   usage: 'off_session' on the SetupIntent. Phase 5 charges when a balance
 *   crosses a threshold, which happens whenever it happens — nobody is at the
 *   screen to satisfy a bank challenge. Declaring the intent up front is what
 *   makes that charge succeed later, and it cannot be added afterwards without
 *   asking the shop to enter the card again.
 *
 *   A Stripe customer even with no subscription. Gifted shops have none, and
 *   they are exactly the shops accruing usage with nothing attached to bill.
 */
class BillingCardService
{
    public function configured(): bool
    {
        return (bool) BillingSettings::current()->activeSecretKey();
    }

    public function publishableKey(): ?string
    {
        return BillingSettings::current()->activePublishableKey();
    }

    /** The tenant's Stripe customer, created on first use. */
    public function ensureCustomer(Tenant $tenant): string
    {
        if ($tenant->stripe_customer_id) {
            return $tenant->stripe_customer_id;
        }

        $customer = $this->client()->customers->create([
            'name'  => $tenant->name,
            'email' => $tenant->billing_email ?: null,
            'metadata' => [
                'tenant_id' => $tenant->id,
                'subdomain' => $tenant->subdomain,
            ],
        ]);

        $tenant->forceFill(['stripe_customer_id' => $customer->id])->save();

        return $customer->id;
    }

    /**
     * A SetupIntent the browser completes. off_session is the whole point —
     * see the class comment.
     */
    public function createSetupIntent(Tenant $tenant): array
    {
        $customerId = $this->ensureCustomer($tenant);

        $intent = $this->client()->setupIntents->create([
            'customer'             => $customerId,
            'usage'                => 'off_session',
            'payment_method_types' => ['card'],
            'metadata'             => ['tenant_id' => $tenant->id],
        ]);

        return ['client_secret' => $intent->client_secret, 'id' => $intent->id];
    }

    /**
     * Called when the browser reports the SetupIntent succeeded. The id is
     * re-fetched from Stripe rather than trusted from the request — the client
     * could send anything.
     */
    public function storeCardFromSetupIntent(Tenant $tenant, string $setupIntentId): bool
    {
        $intent = $this->client()->setupIntents->retrieve($setupIntentId, []);

        if (($intent->metadata['tenant_id'] ?? null) !== $tenant->id) {
            logger()->warning('MARKER-BILLING-CARD setup intent belongs to another tenant', [
                'tenant' => $tenant->id, 'intent' => $setupIntentId,
            ]);
            return false;
        }
        if ($intent->status !== 'succeeded' || ! $intent->payment_method) {
            return false;
        }

        $pm = $this->client()->paymentMethods->retrieve($intent->payment_method, []);

        // Default for future invoices as well, so a subscription later uses
        // the same card rather than silently having none.
        $this->client()->customers->update($this->ensureCustomer($tenant), [
            'invoice_settings' => ['default_payment_method' => $pm->id],
        ]);

        $tenant->forceFill([
            'stripe_payment_method_id' => $pm->id,
            'card_brand'               => $pm->card->brand ?? null,
            'card_last4'               => $pm->card->last4 ?? null,
            'card_exp_month'           => $pm->card->exp_month ?? null,
            'card_exp_year'            => $pm->card->exp_year ?? null,
            'card_added_at'            => now(),
        ])->save();

        // MARKER-BILLING-NOTICES — they did the thing we asked.
        app(\App\Services\Billing\BillingNoticeService::class)->resolve($tenant, 'card_added');

        logger()->info('MARKER-BILLING-CARD card saved', [
            'tenant' => $tenant->id, 'brand' => $pm->card->brand ?? '?', 'last4' => $pm->card->last4 ?? '?',
        ]);

        return true;
    }

    /** Detach at Stripe as well as forgetting locally — a stored id we cannot use is worse than none. */
    public function forgetCard(Tenant $tenant): void
    {
        if ($tenant->stripe_payment_method_id) {
            try {
                $this->client()->paymentMethods->detach($tenant->stripe_payment_method_id, []);
            } catch (\Throwable $e) {
                logger()->warning('MARKER-BILLING-CARD detach failed', [
                    'tenant' => $tenant->id, 'error' => $e->getMessage(),
                ]);
            }
        }

        $tenant->forceFill([
            'stripe_payment_method_id' => null,
            'card_brand'   => null, 'card_last4' => null,
            'card_exp_month' => null, 'card_exp_year' => null, 'card_added_at' => null,
        ])->save();
    }

    /**
     * MARKER-BILLING-ADDRESS — Stripe holds the address any tax calculation
     * would be based on, so it is pushed on save rather than left to drift
     * from what the shop typed.
     */
    public function syncAddress(Tenant $tenant): void
    {
        if (! $tenant->stripe_customer_id) return;

        $this->client()->customers->update($tenant->stripe_customer_id, [
            'address' => array_filter([
                'line1'       => $tenant->billing_address_line1,
                'line2'       => $tenant->billing_address_line2,
                'city'        => $tenant->billing_city,
                'state'       => $tenant->billing_state,
                'postal_code' => $tenant->billing_postcode,
                'country'     => $tenant->billing_country ?: 'US',
            ]),
        ]);
    }

    /** For the UI: what is on file, and whether it is about to expire. */
    public function cardState(Tenant $tenant): array
    {
        if (! $tenant->stripe_payment_method_id) {
            return ['has_card' => false];
        }

        $expiring = false;
        if ($tenant->card_exp_year && $tenant->card_exp_month) {
            $expires  = \Carbon\Carbon::createFromDate($tenant->card_exp_year, $tenant->card_exp_month, 1)->endOfMonth();
            $expiring = $expires->lt(now()->addMonths(2));
        }

        return [
            'has_card'  => true,
            'brand'     => $tenant->card_brand,
            'last4'     => $tenant->card_last4,
            'expires'   => $tenant->card_exp_month ? sprintf('%02d/%d', $tenant->card_exp_month, $tenant->card_exp_year) : null,
            'added_at'  => $tenant->card_added_at,
            'expiring'  => $expiring,
        ];
    }

    private function client(): StripeClient
    {
        $key = BillingSettings::current()->activeSecretKey();
        if (! $key) {
            throw new \RuntimeException('Stripe secret key not configured. Set it in master admin → Billing configuration.');
        }

        return new StripeClient(['api_key' => $key, 'stripe_version' => '2024-06-20']);
    }
}
