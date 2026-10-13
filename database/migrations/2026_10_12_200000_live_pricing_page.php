<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-LIVE-PRICING
 *  - site_settings.pricing_blurbs / pricing_basics: the plan one-liners and the
 *    rows that aren't add-ons (team size, domain ...), edited on
 *    Billing > Pricing page and read by the public pricing section.
 *  - additional_location ($29) is closed to new shops: an extra location bills
 *    at the full plan price, as quantity on the plan, not as an add-on. Shops
 *    that already have it keep it (closed, not retired).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $t) {
            if (! Schema::hasColumn('site_settings', 'pricing_blurbs')) {
                $t->json('pricing_blurbs')->nullable();
            }
            if (! Schema::hasColumn('site_settings', 'pricing_basics')) {
                $t->json('pricing_basics')->nullable();
            }
        });

        if (DB::table('site_settings')->where('id', 1)->exists()) {
            $row = DB::table('site_settings')->where('id', 1)->first();
            $set = [];
            if (empty($row->pricing_blurbs)) {
                $set['pricing_blurbs'] = json_encode(\App\Support\PricingCatalog::DEFAULT_BLURBS);
            }
            if (empty($row->pricing_basics)) {
                $set['pricing_basics'] = json_encode(\App\Support\PricingCatalog::DEFAULT_BASICS);
            }
            if ($set) {
                DB::table('site_settings')->where('id', 1)->update($set);
            }
        }

        if (Schema::hasTable('addons')) {
            DB::table('addons')->where('code', 'additional_location')->where('status', 'active')
                ->update(['status' => 'deprecated', 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $t) {
            foreach (['pricing_blurbs', 'pricing_basics'] as $c) {
                if (Schema::hasColumn('site_settings', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
