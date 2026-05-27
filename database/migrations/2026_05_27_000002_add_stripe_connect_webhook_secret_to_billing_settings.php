<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-PATCH-168 — separate webhook secret for Connect events.
 *
 * Platform billing webhooks (subscription renewals etc.) and Connect
 * webhooks (tenant onboarding state, future payment intent events) use
 * different signing secrets. They live in separate Stripe dashboards.
 *
 * Both are encrypted at rest via Laravel's encrypted cast (already the
 * pattern for the other billing_settings key columns).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('billing_settings', function (Blueprint $t) {
            $t->text('stripe_test_connect_webhook_secret')->nullable();
            $t->text('stripe_live_connect_webhook_secret')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('billing_settings', function (Blueprint $t) {
            $t->dropColumn(['stripe_test_connect_webhook_secret', 'stripe_live_connect_webhook_secret']);
        });
    }
};
