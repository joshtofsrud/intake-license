<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-PATCH-168 — Stripe Connect Session A.
 *
 * Adds the columns needed for a tenant to have a Connect account, track
 * its state (charges enabled, payouts enabled, restricted, etc.), and
 * carry a per-tenant application-fee markup in basis points.
 *
 * application_fee_bps is in basis points (100 = 1%). Default 0 means
 * pure pass-through with no Intake markup. Set per tenant when the
 * pricing strategy lands.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            $t->string('stripe_connect_account_id', 64)->nullable()->index();
            $t->string('stripe_connect_country', 2)->default('US');
            $t->boolean('stripe_connect_charges_enabled')->default(false);
            $t->boolean('stripe_connect_payouts_enabled')->default(false);
            $t->timestamp('stripe_connect_details_submitted_at')->nullable();
            $t->json('stripe_connect_requirements_due')->nullable();
            $t->string('stripe_connect_disabled_reason', 128)->nullable();
            $t->timestamp('stripe_connect_last_synced_at')->nullable();
            $t->unsignedSmallInteger('stripe_application_fee_bps')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            $t->dropColumn([
                'stripe_connect_account_id',
                'stripe_connect_country',
                'stripe_connect_charges_enabled',
                'stripe_connect_payouts_enabled',
                'stripe_connect_details_submitted_at',
                'stripe_connect_requirements_due',
                'stripe_connect_disabled_reason',
                'stripe_connect_last_synced_at',
                'stripe_application_fee_bps',
            ]);
        });
    }
};
