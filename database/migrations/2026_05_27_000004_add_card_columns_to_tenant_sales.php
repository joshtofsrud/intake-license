<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-PATCH-170 — Direct Payments Session 2A.
 *
 * Columns to record card-charge metadata when a sale is paid via the
 * Direct Payments flow (tenant\'s own Stripe account, not Connect).
 *
 * stripe_payment_intent_id — PI id, used by refunds + idempotency
 * stripe_charge_id          — Charge id (PaymentIntent.latest_charge), for refunds
 * card_brand                — visa, mastercard, amex, discover…
 * card_last4                — last 4 digits, for display
 * card_funding              — credit | debit | prepaid (informational)
 *
 * checkout_session_id is added now even though the link-based flow ships
 * in 2B — including it here means we only run one migration instead of two.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenant_sales', function (Blueprint $t) {
            $t->string('stripe_payment_intent_id', 64)->nullable()->index('sale_pi_idx');
            $t->string('stripe_charge_id', 64)->nullable();
            $t->string('checkout_session_id', 80)->nullable()->index('sale_cs_idx');
            $t->string('card_brand', 24)->nullable();
            $t->string('card_last4', 4)->nullable();
            $t->string('card_funding', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_sales', function (Blueprint $t) {
            $t->dropIndex('sale_pi_idx');
            $t->dropIndex('sale_cs_idx');
            $t->dropColumn([
                'stripe_payment_intent_id',
                'stripe_charge_id',
                'checkout_session_id',
                'card_brand',
                'card_last4',
                'card_funding',
            ]);
        });
    }
};
