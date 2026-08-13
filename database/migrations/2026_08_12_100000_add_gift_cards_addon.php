<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-GIFTCARDS-GATE — gift cards become a gate-able add-on, included
 * with Scale. Price deliberately 0: Josh sets pricing later (same call as
 * the ecommerce addon — inventing a number in a migration would be worse).
 * Selling is gated; redemption and the public balance check are not, so
 * revoking the add-on can never strand balances customers already paid for.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('addons')->insert([
            'code' => 'gift_cards',
            'name' => 'Gift Cards',
            'category' => 'retail',
            'description' => 'Sell physical and e-gift cards at the register and online. Internal balance ledger, live balance check on your website, scheduled e-gift delivery. Included free with Scale.',
            'tooltip' => 'Physical + e-gift cards with a full balance ledger. Existing card balances always stay redeemable, even if the add-on is later removed.',
            'price_cents' => 0,
            'billing_cadence' => 'monthly',
            'price_display_override' => null,
            'included_in_plans' => json_encode(['scale']),
            'sort_order' => 145,
            'status' => 'active',
            'is_self_serve' => 0,
            'is_new' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('addons')->where('code', 'gift_cards')->delete();
    }
};
