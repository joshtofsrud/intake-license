<?php
// MARKER-BILLING-CARD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // The saved card. Only the id is authoritative — brand/last4/expiry
            // are copies for display, refreshed whenever the card changes.
            $table->string('stripe_payment_method_id', 64)->nullable()->after('stripe_customer_id');
            $table->string('card_brand', 24)->nullable()->after('stripe_payment_method_id');
            $table->string('card_last4', 4)->nullable()->after('card_brand');
            $table->unsignedSmallInteger('card_exp_month')->nullable()->after('card_last4');
            $table->unsignedSmallInteger('card_exp_year')->nullable()->after('card_exp_month');
            $table->timestamp('card_added_at')->nullable()->after('card_exp_year');

            // Receipts go here rather than to whoever happened to save the card.
            $table->string('billing_email', 191)->nullable()->after('card_added_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'stripe_payment_method_id', 'card_brand', 'card_last4',
                'card_exp_month', 'card_exp_year', 'card_added_at', 'billing_email',
            ]);
        });
    }
};
