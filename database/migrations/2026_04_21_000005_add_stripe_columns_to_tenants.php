<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add Stripe subscription tracking to tenants table.
 *
 *  - stripe_customer_id:      Stripe Customer record (acct-scoped to Intake's Stripe)
 *  - stripe_subscription_id:  Active subscription (null if no billing, e.g. master admin
 *                             manually created tenants or if Stripe skipped in dev)
 *  - stripe_subscription_cadence: 'monthly' | 'annual' — what the tenant signed up for
 *  - trial_ends_at:           When the trial converts to paid (cached from Stripe for UI)
 *  - subscription_status:     cached status — 'trialing', 'active', 'past_due', 'canceled',
 *                             'unpaid', 'incomplete', 'incomplete_expired', or null if unbilled
 *
 * Note: Stripe is the source of truth. These columns are a local cache for UI/queries,
 * updated by webhook events. Never trust these for authorization decisions — call Stripe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            $t->string('stripe_customer_id', 255)->nullable()->after('plan_tier');
            $t->string('stripe_subscription_id', 255)->nullable()->after('stripe_customer_id');
            $t->enum('stripe_subscription_cadence', ['monthly', 'annual'])
                ->nullable()
                ->after('stripe_subscription_id');
            $t->timestamp('trial_ends_at')->nullable()->after('stripe_subscription_cadence');
            $t->string('subscription_status', 32)->nullable()->after('trial_ends_at');

            $t->index('stripe_customer_id');
            $t->index('stripe_subscription_id');
            $t->index('subscription_status');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            $t->dropIndex(['stripe_customer_id']);
            $t->dropIndex(['stripe_subscription_id']);
            $t->dropIndex(['subscription_status']);
            $t->dropColumn([
                'stripe_customer_id',
                'stripe_subscription_id',
                'stripe_subscription_cadence',
                'trial_ends_at',
                'subscription_status',
            ]);
        });
    }
};
