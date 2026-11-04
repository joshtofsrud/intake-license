<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-SO-REPAIRS: where a special order is picked up.
 *
 * A special order had no location, so a shop with more than one could not
 * tell which store a part was going to. Additive only: a nullable column,
 * then a backfill from what the order already knows — the sale it came from,
 * else its work order, else the shop's default location.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tenant_special_orders', 'location_id')) {
            Schema::table('tenant_special_orders', function (Blueprint $t) {
                $t->uuid('location_id')->nullable()->after('appointment_id');
                $t->index(['tenant_id', 'location_id', 'status'], 'tso_tenant_loc_status_idx');
            });
        }

        // 1. From the sale it was raised on.
        DB::statement("
            UPDATE tenant_special_orders so
            JOIN tenant_sales s ON s.id = so.sale_id AND s.tenant_id = so.tenant_id
            SET so.location_id = s.location_id
            WHERE so.location_id IS NULL AND s.location_id IS NOT NULL
        ");

        // 2. From its work order.
        DB::statement("
            UPDATE tenant_special_orders so
            JOIN tenant_appointments a ON a.id = so.appointment_id AND a.tenant_id = so.tenant_id
            SET so.location_id = a.location_id
            WHERE so.location_id IS NULL AND a.location_id IS NOT NULL
        ");

        // 3. The shop's default location (else its first active one).
        DB::statement("
            UPDATE tenant_special_orders so
            JOIN (
                SELECT l.tenant_id,
                       SUBSTRING_INDEX(GROUP_CONCAT(l.id ORDER BY l.is_default DESC, l.is_active DESC, l.sort_order, l.created_at), ',', 1) AS loc_id
                FROM tenant_locations l
                WHERE l.deleted_at IS NULL
                GROUP BY l.tenant_id
            ) d ON d.tenant_id = so.tenant_id
            SET so.location_id = d.loc_id
            WHERE so.location_id IS NULL
        ");
    }

    public function down(): void
    {
        if (Schema::hasColumn('tenant_special_orders', 'location_id')) {
            Schema::table('tenant_special_orders', function (Blueprint $t) {
                $t->dropIndex('tso_tenant_loc_status_idx');
                $t->dropColumn('location_id');
            });
        }
    }
};
