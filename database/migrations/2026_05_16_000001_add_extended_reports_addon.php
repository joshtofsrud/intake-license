<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the extended_reports addon row into the addons table.
 *
 * extended_reports — bundled in branded + scale (free); not separately sold.
 *
 * Gates the Customers tab (and future Full reports tabs) on the Reports page.
 * Starter tenants see a locked preview with an upsell modal; Branded+ tenants
 * get the full tab with real data.
 *
 * Same pattern as 'retail' — capability flag, not a sellable product, hence
 * is_self_serve=0 and price 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('addons')->insert([
            'code' => 'extended_reports',
            'name' => 'Extended Reports',
            'category' => 'operations',
            'description' => 'Find your lapsed customers, identify your most valuable regulars, fix gaps in your customer database. Customer reports across all time — not just the current date range. Included free with Branded and Scale.',
            'tooltip' => 'Whole-database customer insights: lapsed list, highest LTV, missing contact info, and more reporting tabs as they ship.',
            'price_cents' => 0,
            'billing_cadence' => 'monthly',
            'price_display_override' => 'Included',
            'included_in_plans' => json_encode(['branded', 'scale']),
            'sort_order' => 140,
            'status' => 'active',
            'is_self_serve' => 0,
            'is_new' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('addons')->where('code', 'extended_reports')->delete();
    }
};
