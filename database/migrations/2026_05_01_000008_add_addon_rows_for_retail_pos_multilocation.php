<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds four new addon rows into the existing addons table.
 *
 *   retail                    — bundled in branded + scale (free)
 *   pos                       — bundled in scale, $59/mo for branded
 *   multi_location_calendar   — $19/mo per additional location
 *   multi_location_pos        — $59/mo per additional location, requires pos
 *
 * Per-location pricing is calculated in code (count locations beyond first,
 * multiply by addon price). The addon row stores the per-unit price.
 *
 * Stripe price IDs left null — populated when the corresponding Stripe
 * products are created.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('addons')->insert([
            [
                'code' => 'retail',
                'name' => 'Retail',
                'category' => 'retail',
                'description' => 'Sell merch, accessories, and small items right from your booking page. Track stock counts, ring sales, issue refunds. Included free with Branded and Scale.',
                'tooltip' => 'Basic retail sales — perfect for studios selling apparel, salons selling product, or any service business with light retail.',
                'price_cents' => 0,
                'billing_cadence' => 'monthly',
                'price_display_override' => 'Included',
                'included_in_plans' => json_encode(['branded', 'scale']),
                'sort_order' => 100,
                'status' => 'active',
                'is_self_serve' => 0,
                'is_new' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'pos',
                'name' => 'POS',
                'category' => 'retail',
                'description' => 'Full point-of-sale: distributor catalog sync, multi-register sessions with drawer reconciliation, parts on appointments with auto-decrement, aging inventory and dead stock reports, professional receiving flow with PO matching.',
                'tooltip' => 'Built for retail-first businesses — bike shops, ski shops, pet groomers, anyone with real inventory depth.',
                'price_cents' => 5900,
                'billing_cadence' => 'monthly',
                'price_display_override' => null,
                'included_in_plans' => json_encode(['scale']),
                'sort_order' => 110,
                'status' => 'active',
                'is_self_serve' => 1,
                'is_new' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'multi_location_calendar',
                'name' => 'Multi-location · Calendar',
                'category' => 'operations',
                'description' => 'Add additional locations to your booking platform. Each location has its own calendar, hours, capacity, staff, and customer-facing booking flow. Customers unified across all locations.',
                'tooltip' => 'Calendar-only multi-location. Per additional location beyond the first.',
                'price_cents' => 1900,
                'billing_cadence' => 'monthly',
                'price_display_override' => '$19/mo per location',
                'included_in_plans' => json_encode([]),
                'sort_order' => 120,
                'status' => 'active',
                'is_self_serve' => 1,
                'is_new' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'multi_location_pos',
                'name' => 'Multi-location · POS',
                'category' => 'retail',
                'description' => 'Full POS at each additional location. Per-location inventory stock counts, register sessions, distributor receiving. Stock transfers between locations supported.',
                'tooltip' => 'Multi-location with full POS at each. Per additional location beyond the first. Requires the POS add-on.',
                'price_cents' => 5900,
                'billing_cadence' => 'monthly',
                'price_display_override' => '$59/mo per location',
                'included_in_plans' => json_encode([]),
                'sort_order' => 130,
                'status' => 'active',
                'is_self_serve' => 1,
                'is_new' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('addons')->whereIn('code', [
            'retail',
            'pos',
            'multi_location_calendar',
            'multi_location_pos',
        ])->delete();
    }
};
