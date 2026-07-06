<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-PATCH-563 — the online_store addon row. E-commerce gates through
 * the same addon framework as retail/pos: included with Branded and Scale
 * (floor decision: never available on Starter), price surface reserved so
 * it can become a paid Branded add-on later without a schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('addons')->insert([
            'code' => 'online_store',
            'name' => 'Online Store',
            'category' => 'retail',
            'description' => 'Sell your inventory online — a storefront on your booking site with cart, checkout, pickup and local delivery. Orders flow into the same register ledger and customer timeline as in-store sales.',
            'tooltip' => 'Your catalog, online. Customers browse, buy, and pick up in store or get local delivery.',
            'price_cents' => 0,
            'billing_cadence' => 'monthly',
            'price_display_override' => 'Included',
            'included_in_plans' => json_encode(['branded', 'scale']),
            'sort_order' => 115,
            'status' => 'active',
            'is_self_serve' => 0,
            'is_new' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('addons')->where('code', 'online_store')->delete();
    }
};
