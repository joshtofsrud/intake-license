<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Tracks the tenant's progress through the 8-step wizard so they
            // can resume mid-flow. NULL = not started, 8 = on Done screen.
            // Cleared when onboarding_status flips to 'complete'.
            $table->unsignedTinyInteger('onboarding_step')
                ->nullable()
                ->after('onboarding_status');

            // Industry pack slug picked at step 1 (bike, yoga, crossfit, etc.).
            // Used for analytics, marketing-page deep links, and progressive
            // disclosure of industry-specific features. NOT used for prefill —
            // AI Quick Setup handles that.
            $table->string('industry_pack', 64)
                ->nullable()
                ->after('last_booking_mode_switch_at');

            // Indexed because industry-based segmentation is the most common
            // analytical query against this column.
            $table->index('industry_pack');

            // Payment processor selected at step 7. Records intent only —
            // actual connection happens later (Stripe Connect when wired,
            // dashboard banner for others). 'offline' = takes payments
            // outside the app, no processor needed.
            $table->string('payment_processor', 32)
                ->nullable()
                ->after('industry_pack');

            // State machine: not_started → intent_recorded (after step 7) →
            // connecting (during OAuth) → connected (success) → disabled.
            $table->string('payment_processor_status', 32)
                ->default('not_started')
                ->after('payment_processor');

            // Processor-specific account identifier.
            // Stripe: acct_xxx. PayPal: merchant ID. Square: location ID.
            $table->string('payment_processor_account_id', 255)
                ->nullable()
                ->after('payment_processor_status');

            // Set when status flips to 'connected'; cleared on revoke.
            $table->timestamp('payment_processor_connected_at')
                ->nullable()
                ->after('payment_processor_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex(['industry_pack']);
            $table->dropColumn([
                'onboarding_step',
                'industry_pack',
                'payment_processor',
                'payment_processor_status',
                'payment_processor_account_id',
                'payment_processor_connected_at',
            ]);
        });
    }
};
