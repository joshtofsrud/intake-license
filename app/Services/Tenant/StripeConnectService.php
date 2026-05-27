<?php

namespace App\Services\Tenant;

use App\Models\BillingSettings;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * MARKER-PATCH-168 — Stripe Connect Session A.
 *
 * Lightweight service for managing tenant Connect accounts. Express
 * accounts only, US only for v1.
 *
 * createAccount + createAccountLink drive the onboarding redirect flow.
 * refreshAccount is called after the tenant returns from Stripe and
 * by the webhook handler when account.updated fires.
 *
 * All API calls scope through the platform key from BillingSettings,
 * which is the same key already used for subscription billing.
 */
class StripeConnectService
{
    /**
     * Create a Stripe Express account for the tenant, store its ID,
     * and return the account object.
     */
    public function createAccount(Tenant $tenant): \Stripe\Account
    {
        if ($tenant->stripe_connect_account_id) {
            // Idempotent — reuse existing account
            return $this->client()->accounts->retrieve($tenant->stripe_connect_account_id);
        }

        $account = $this->client()->accounts->create([
            'type' => 'express',
            'country' => 'US',
            'email' => $tenant->users()->first()?->email,
            'business_type' => 'company',
            'capabilities' => [
                'card_payments' => ['requested' => true],
                'transfers'     => ['requested' => true],
            ],
            'business_profile' => [
                'name' => $tenant->name,
                'url'  => 'https://' . ($tenant->custom_domain ?: ($tenant->subdomain . '.intake.works')),
            ],
            'settings' => [
                'payouts' => [
                    'schedule' => [
                        'interval' => 'weekly',
                        'weekly_anchor' => 'friday',
                    ],
                ],
            ],
            'metadata' => [
                'tenant_id' => $tenant->id,
                'intake_environment' => app()->environment(),
            ],
        ]);

        $tenant->stripe_connect_account_id = $account->id;
        $tenant->stripe_connect_country = $account->country;
        $tenant->save();

        $this->updateTenantFromAccount($tenant, $account);

        Log::info('stripe_connect.account_created', [
            'tenant_id'  => $tenant->id,
            'account_id' => $account->id,
        ]);

        return $account;
    }

    /**
     * Create an Account Link for the tenant to complete or update onboarding.
     * Returns the URL to redirect them to.
     *
     * $type is either:
     *   'account_onboarding' — first-time setup or resuming an incomplete one
     *   'account_update'     — updating existing details (e.g. resolving requirements)
     */
    public function createAccountLink(Tenant $tenant, string $type = 'account_onboarding'): string
    {
        if (! $tenant->stripe_connect_account_id) {
            throw new \RuntimeException('Tenant has no Connect account yet.');
        }

        $returnUrl  = $this->urlFor($tenant, '/admin/settings/payments?stripe_return=1');
        $refreshUrl = $this->urlFor($tenant, '/admin/settings/payments?stripe_refresh=1');

        $link = $this->client()->accountLinks->create([
            'account'     => $tenant->stripe_connect_account_id,
            'return_url'  => $returnUrl,
            'refresh_url' => $refreshUrl,
            'type'        => $type,
        ]);

        return $link->url;
    }

    /**
     * Fetch fresh account state from Stripe and update tenant columns.
     * Called after Stripe redirects back and from the webhook handler.
     */
    public function refreshAccount(Tenant $tenant): ?\Stripe\Account
    {
        if (! $tenant->stripe_connect_account_id) return null;

        try {
            $account = $this->client()->accounts->retrieve($tenant->stripe_connect_account_id);
        } catch (ApiErrorException $e) {
            Log::warning('stripe_connect.refresh_failed', [
                'tenant_id' => $tenant->id,
                'account_id' => $tenant->stripe_connect_account_id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        $this->updateTenantFromAccount($tenant, $account);
        return $account;
    }

    /**
     * Disconnect the tenant from Stripe. The Stripe account itself is
     * NOT deleted — we just clear our reference to it. The tenant can
     * reconnect to the same account later if they want.
     */
    public function disconnect(Tenant $tenant): void
    {
        // Note: we do not call accounts->delete. Stripe allows deleting only
        // accounts with zero balance. Even then, deletion is irreversible and
        // permanently severs Stripe's link to all past transactions. Leaving
        // the account intact lets the tenant reconnect cleanly later.
        $tenant->stripe_connect_account_id = null;
        $tenant->stripe_connect_charges_enabled = false;
        $tenant->stripe_connect_payouts_enabled = false;
        $tenant->stripe_connect_details_submitted_at = null;
        $tenant->stripe_connect_requirements_due = null;
        $tenant->stripe_connect_disabled_reason = null;
        $tenant->stripe_connect_last_synced_at = now();
        $tenant->save();

        Log::info('stripe_connect.disconnected', ['tenant_id' => $tenant->id]);
    }

    /**
     * Lookup a tenant by their Connect account_id. Used by the webhook
     * handler to route account.updated events.
     */
    public function findTenantByAccountId(string $accountId): ?Tenant
    {
        return Tenant::where('stripe_connect_account_id', $accountId)->first();
    }

    /**
     * Get the publishable key (for client-side Stripe.js in Session B).
     */
    public function publishableKey(): ?string
    {
        return BillingSettings::current()->activePublishableKey();
    }

    // ==================================================================
    // Internal
    // ==================================================================

    protected function updateTenantFromAccount(Tenant $tenant, \Stripe\Account $account): void
    {
        $reqs = $account->requirements?->currently_due ?? [];
        $disabledReason = $account->requirements?->disabled_reason ?? null;

        $tenant->stripe_connect_charges_enabled  = (bool) $account->charges_enabled;
        $tenant->stripe_connect_payouts_enabled  = (bool) $account->payouts_enabled;
        $tenant->stripe_connect_details_submitted_at = $account->details_submitted ? now() : null;
        $tenant->stripe_connect_requirements_due = $reqs;
        $tenant->stripe_connect_disabled_reason  = $disabledReason;
        $tenant->stripe_connect_last_synced_at   = now();
        $tenant->save();
    }

    /**
     * Build a tenant-scoped URL. Express callbacks have to land on the
     * exact subdomain the tenant uses or session/CSRF won\'t resolve.
     */
    protected function urlFor(Tenant $tenant, string $path): string
    {
        $host = $tenant->custom_domain ?: ($tenant->subdomain . '.intake.works');
        $scheme = app()->environment('local') ? 'http' : 'https';
        return $scheme . '://' . $host . $path;
    }

    protected function client(): StripeClient
    {
        $key = BillingSettings::current()->activeSecretKey();
        if (! $key) {
            throw new \RuntimeException('Stripe secret key not configured. Set it in master admin -> Billing configuration.');
        }
        return new StripeClient([
            'api_key' => $key,
            'stripe_version' => '2024-06-20',
        ]);
    }
}
