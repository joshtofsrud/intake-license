<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-CONTRIBUTIONS — money given to the project, buying nothing.
// Deliberately NOT on the investors table: a contributor is not an investor
// and must never appear on a cap table, in a total, or in a progress bar.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contributions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3)->default('usd');
            $table->string('note', 500)->nullable();

            $table->string('status', 20)->default('pending');   // pending | paid | failed | expired
            $table->string('stripe_session_id')->nullable()->unique();
            $table->string('stripe_payment_intent')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('ip', 45)->nullable();

            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::table('billing_settings', function (Blueprint $table) {
            // Its own signing secret: a separate Stripe endpoint gets a
            // separate secret, and reusing the billing one would mean a
            // billing event could be accepted here and vice versa.
            $table->text('stripe_test_contrib_webhook_secret')->nullable();
            $table->text('stripe_live_contrib_webhook_secret')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('billing_settings', function (Blueprint $table) {
            $table->dropColumn(['stripe_test_contrib_webhook_secret', 'stripe_live_contrib_webhook_secret']);
        });

        Schema::dropIfExists('contributions');
    }
};
