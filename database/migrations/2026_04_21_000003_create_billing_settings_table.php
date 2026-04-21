<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * billing_settings: Intake's own Stripe keys, encrypted at rest.
 *
 * Single-row table (id = 1 always). Managed through master admin UI.
 * Encrypted columns use Laravel's `encrypted` cast at the model level.
 *
 * Security notes:
 *  - Values are encrypted with APP_KEY before storage
 *  - DB backups contain ciphertext, not plaintext
 *  - If APP_KEY is rotated, these columns must be re-encrypted
 *  - Access should be restricted to master admin users only
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_settings', function (Blueprint $t) {
            $t->id();

            // Test-mode keys
            $t->text('stripe_test_publishable_key')->nullable();
            $t->text('stripe_test_secret_key')->nullable();
            $t->text('stripe_test_webhook_secret')->nullable();

            // Live-mode keys
            $t->text('stripe_live_publishable_key')->nullable();
            $t->text('stripe_live_secret_key')->nullable();
            $t->text('stripe_live_webhook_secret')->nullable();

            // Which mode is active (test or live)
            $t->string('stripe_mode', 10)->default('test');

            // Stripe Product + Price IDs (non-secret, stored plain)
            // Set after creating products/prices in Stripe dashboard.
            $t->string('stripe_price_starter_monthly', 64)->nullable();
            $t->string('stripe_price_starter_annual', 64)->nullable();
            $t->string('stripe_price_branded_monthly', 64)->nullable();
            $t->string('stripe_price_branded_annual', 64)->nullable();
            $t->string('stripe_price_scale_monthly', 64)->nullable();
            $t->string('stripe_price_scale_annual', 64)->nullable();

            // Audit: last tested connection + result
            $t->timestamp('last_verified_at')->nullable();
            $t->string('last_verified_status', 32)->nullable();
            $t->text('last_verified_message')->nullable();

            $t->timestamps();
        });

        // Seed the single row
        \DB::table('billing_settings')->insert([
            'id' => 1,
            'stripe_mode' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_settings');
    }
};
