<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-ECOMADDON — online_store stops being bundled with a plan.
 *
 * The gate (Tenant::online_store_enabled -> hasAddon) already governs the nav
 * entry, the settings page, Orders, and the whole public storefront. Removing
 * the plan inclusion is therefore the entire change: no tenant gets ecommerce
 * unless the add-on is granted to them.
 *
 * min_plan_tier is set explicitly because the original decision was "never on
 * Starter", and that floor used to be implied by included_in_plans.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('addons')->where('code', 'online_store')->update([
            'included_in_plans'      => null,
            'price_display_override' => null,
            'min_plan_tier'          => 'branded',
            'is_self_serve'          => 0,
            'updated_at'             => now(),
        ]);
    }

    public function down(): void
    {
        // Restore the previous bundling exactly.
        DB::table('addons')->where('code', 'online_store')->update([
            'included_in_plans'      => json_encode(['branded', 'scale']),
            'price_display_override' => 'Included',
            'min_plan_tier'          => null,
            'updated_at'             => now(),
        ]);
    }
};
