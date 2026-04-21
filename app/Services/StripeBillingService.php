<?php

namespace App\Services;

use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * StripeBillingService
 *
 * Facade for all Stripe interactions. Currently stubbed - every method that
 * would hit Stripe returns a simulated response so the rest of the app can
 * be built and tested end-to-end.
 *
 * When plumbing Stripe next session:
 *   1. composer require stripe/stripe-php
 *   2. Add STRIPE_SECRET, STRIPE_WEBHOOK_SECRET to .env
 *   3. Config: config/services.php -> 'stripe' => ['secret' => env(...), 'webhook_secret' => env(...)]
 *   4. Replace each `simulated*` method body with the real Stripe SDK call
 *   5. Leave the method signatures alone - callers depend on them.
 *
 * Design principle: this class NEVER writes to tenant_feature_addons directly.
 * It returns data to AddonManagementService, which is the only writer.
 */
class StripeBillingService
{
    public function isLive(): bool
    {
        // TODO(stripe): return !empty(config('services.stripe.secret'));
        return false;
    }

    /**
     * Add a recurring addon to the tenant's existing Stripe subscription.
     *
     * Returns array: [
     *   'subscription_item_id' => 'si_...',
     *   'price_id'             => 'price_...',
     *   'current_period_end'   => Carbon,
     *   'proration_amount'     => int (cents charged now, may be 0),
     * ]
     */
    public function addSubscriptionItem(Tenant $tenant, object $addon): array
    {
        // TODO(stripe): implement
        //   \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
        //   $item = \Stripe\SubscriptionItem::create([
        //       'subscription' => $tenant->stripe_subscription_id,
        //       'price' => $addon->stripe_price_id_monthly,
        //       'proration_behavior' => 'create_prorations',
        //   ]);

        return $this->simulatedSubscriptionItemAddition($tenant, $addon);
    }

    public function cancelSubscriptionItemAtPeriodEnd(Tenant $tenant, string $subscriptionItemId): void
    {
        // TODO(stripe): implement cancel-at-period-end pattern
        Log::info('[StripeBillingService] STUB cancelSubscriptionItemAtPeriodEnd', [
            'tenant_id' => $tenant->id,
            'subscription_item_id' => $subscriptionItemId,
        ]);
    }

    public function chargeOneTime(Tenant $tenant, object $addon): array
    {
        // TODO(stripe): create invoice item + invoice + finalize
        return [
            'invoice_id' => 'in_stub_' . uniqid(),
            'amount' => $addon->price_cents,
            'status' => 'paid',
        ];
    }

    public function verifyWebhook(string $payload, string $signatureHeader): object
    {
        // TODO(stripe): implement
        //   return \Stripe\Webhook::constructEvent(
        //       $payload,
        //       $signatureHeader,
        //       config('services.stripe.webhook_secret')
        //   );

        return (object) json_decode($payload, false);
    }

    public function syncSubscriptionFromStripe(Tenant $tenant): array
    {
        // TODO(stripe): fetch subscription + all items, reconcile against tenant_feature_addons
        return [
            'synced' => false,
            'reason' => 'stripe not live',
        ];
    }

    protected function simulatedSubscriptionItemAddition(Tenant $tenant, object $addon): array
    {
        Log::info('[StripeBillingService] STUB addSubscriptionItem', [
            'tenant_id' => $tenant->id,
            'addon_code' => $addon->code,
            'price_cents' => $addon->price_cents,
        ]);

        $periodEnd = Carbon::now()->endOfMonth();

        return [
            'subscription_item_id' => 'si_stub_' . uniqid(),
            'price_id' => $addon->stripe_price_id_monthly ?? ('price_stub_' . $addon->code),
            'current_period_end' => $periodEnd,
            'proration_amount' => 0,
        ];
    }
}
