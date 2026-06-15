<?php
// MARKER-PATCH-HLC2

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend the item->source pivot so ONE item can carry MANY distributor
 * sources. Each row already holds vendor_sku/cost/lead/preferred; we add the
 * link to the distributor's catalog row plus a per-tenant live cache.
 *
 *  - distributor_code: which distributor this source is (denormalised, for
 *    fast "all HLC sources" filtering).
 *  - distributor_catalog_id: FK to the distributor's catalog entry — carries
 *    variant_no, MAP, etc.
 *  - live_cost_cents / live_avail / live_checked_at: per-tenant cache fetched
 *    with THIS shop's key. Availability is on-demand; these back the list and
 *    replenish views without hitting the API per row. live_checked_at marks
 *    staleness. Local-only suppliers leave all of these null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_inventory_item_vendors', function (Blueprint $table) {
            $table->string('distributor_code', 32)->nullable()->after('vendor_id');
            $table->uuid('distributor_catalog_id')->nullable()->after('distributor_code');

            $table->integer('live_cost_cents')->nullable()->after('unit_cost_cents');
            $table->integer('live_avail')->nullable()->after('live_cost_cents');
            $table->timestamp('live_checked_at')->nullable()->after('live_avail');

            $table->index('distributor_catalog_id', 'tiiv_dist_catalog_idx');
            $table->index('distributor_code', 'tiiv_dist_code_idx');
        });

        // Hard FK only where the driver can ALTER-ADD one. SQLite (local dev)
        // can't add a foreign key after table creation; the index above plus
        // app-level scoping covers integrity there. Production is MySQL.
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('tenant_inventory_item_vendors', function (Blueprint $table) {
                $table->foreign('distributor_catalog_id', 'tiiv_dist_catalog_fk')
                    ->references('id')->on('platform_distributor_catalogs')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('tenant_inventory_item_vendors', function (Blueprint $table) {
                $table->dropForeign('tiiv_dist_catalog_fk');
            });
        }

        Schema::table('tenant_inventory_item_vendors', function (Blueprint $table) {
            $table->dropIndex('tiiv_dist_catalog_idx');
            $table->dropIndex('tiiv_dist_code_idx');
            $table->dropColumn([
                'distributor_code',
                'distributor_catalog_id',
                'live_cost_cents',
                'live_avail',
                'live_checked_at',
            ]);
        });
    }
};
