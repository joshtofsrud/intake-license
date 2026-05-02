<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every receive shipment arrives at a specific location.
 *
 * Multi-location shops can receive separate shipments at each location
 * (different distributor accounts, different timing).
 *
 * Nullable here, populated by intake:backfill-multi-location, tightened
 * to NOT NULL in a follow-up migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_inventory_receive_shipments', function (Blueprint $table) {
            $table->foreignUuid('location_id')
                ->nullable()
                ->after('tenant_id')
                ->constrained(table: 'tenant_locations', indexName: 'tirs_location_fk')
                ->cascadeOnDelete();

            $table->index(['tenant_id', 'location_id', 'received_date'], 'tirs_tenant_loc_recv_idx');
            $table->index(['tenant_id', 'location_id', 'status'], 'tirs_tenant_loc_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_inventory_receive_shipments', function (Blueprint $table) {
            $table->dropIndex('tirs_tenant_loc_recv_idx');
            $table->dropIndex('tirs_tenant_loc_status_idx');
            $table->dropForeign('tirs_location_fk');
            $table->dropColumn('location_id');
        });
    }
};
