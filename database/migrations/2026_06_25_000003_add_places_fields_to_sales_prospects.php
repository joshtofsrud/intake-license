<?php
// MARKER-SALES-PLACES — Make sales_prospects national-ready.
// Adds the fields the Google Places pipeline produces (place id, business status,
// rating, street address, state, route-loop label) so master.csv can be imported
// directly. Additive + guarded: runs in timestamp order AFTER 000001 whether or
// not the base table was already deployed, and is safe to re-run.
//
// Also drops the (shop, city) UNIQUE constraint: nationally that's a liability
// (two real shops can share a name+city), so identity moves to google_place_id.
// firstOrCreate in the seeder does a SELECT-then-INSERT and does not depend on
// the DB constraint, so the WA seeder keeps working unchanged.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_prospects', function (Blueprint $t) {
            if (! Schema::hasColumn('sales_prospects', 'google_place_id')) {
                $t->string('google_place_id', 191)->nullable()->after('id');
            }
            if (! Schema::hasColumn('sales_prospects', 'state')) {
                $t->string('state', 64)->nullable()->after('city');           // 'WA', 'OR', 'ID'...
            }
            if (! Schema::hasColumn('sales_prospects', 'route_loop')) {
                $t->string('route_loop', 120)->nullable()->after('loop');      // national loop label, vs WA int loop
            }
            if (! Schema::hasColumn('sales_prospects', 'address')) {
                $t->string('address', 255)->nullable()->after('website');      // street address from Places
            }
            if (! Schema::hasColumn('sales_prospects', 'business_status')) {
                $t->string('business_status', 32)->nullable()->after('verified'); // OPERATIONAL | CLOSED_* | null
            }
            if (! Schema::hasColumn('sales_prospects', 'rating')) {
                $t->decimal('rating', 2, 1)->nullable()->after('lead_score');  // 0.0..5.0
            }
            if (! Schema::hasColumn('sales_prospects', 'rating_count')) {
                $t->unsignedInteger('rating_count')->nullable()->after('rating');
            }
            if (! Schema::hasColumn('sales_prospects', 'primary_type')) {
                $t->string('primary_type', 64)->nullable()->after('type');     // Places primaryType
            }
            if (! Schema::hasColumn('sales_prospects', 'google_maps_url')) {
                $t->string('google_maps_url', 512)->nullable()->after('source_url');
            }
        });

        // Identity moves to google_place_id. Add a unique index (nullable → many
        // NULLs allowed in MySQL, fine for the hand-entered WA rows).
        Schema::table('sales_prospects', function (Blueprint $t) {
            try {
                $t->unique('google_place_id', 'sales_prospects_place_id_unique');
            } catch (\Throwable $e) {
                // already present — ignore
            }
        });

        // Drop the old (shop, city) UNIQUE; keep the lookup as a plain index.
        Schema::table('sales_prospects', function (Blueprint $t) {
            try {
                $t->dropUnique('sales_prospects_shop_city_unique');
            } catch (\Throwable $e) {
                // not present (fresh DB built after this patch) — ignore
            }
        });
        Schema::table('sales_prospects', function (Blueprint $t) {
            try {
                $t->index(['shop', 'city'], 'sales_prospects_shop_city_index');
            } catch (\Throwable $e) {
                // already present — ignore
            }
        });

        Schema::table('sales_prospects', function (Blueprint $t) {
            try { $t->index('state', 'sales_prospects_state_index'); } catch (\Throwable $e) {}
            try { $t->index('business_status', 'sales_prospects_status_index'); } catch (\Throwable $e) {}
        });
    }

    public function down(): void
    {
        Schema::table('sales_prospects', function (Blueprint $t) {
            try { $t->dropUnique('sales_prospects_place_id_unique'); } catch (\Throwable $e) {}
            try { $t->dropIndex('sales_prospects_state_index'); } catch (\Throwable $e) {}
            try { $t->dropIndex('sales_prospects_status_index'); } catch (\Throwable $e) {}
            try { $t->dropIndex('sales_prospects_shop_city_index'); } catch (\Throwable $e) {}

            foreach ([
                'google_place_id', 'state', 'route_loop', 'address',
                'business_status', 'rating', 'rating_count', 'primary_type', 'google_maps_url',
            ] as $col) {
                if (Schema::hasColumn('sales_prospects', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
