<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every inventory movement happens at a specific location.
 *
 * Stock for an item at a location = SUM(quantity_delta) WHERE
 * inventory_item_id = ? AND location_id = ?
 *
 * Transfer between locations: two rows, one with movement_type='transfer_out'
 * at source, one with 'transfer_in' at destination, both with the same
 * reference_id linking them.
 *
 * Nullable here, populated by intake:backfill-multi-location, tightened
 * to NOT NULL in a follow-up migration. Same two-step pattern as
 * tenant_capacity_rules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_inventory_movements', function (Blueprint $table) {
            $table->foreignUuid('location_id')
                ->nullable()
                ->after('inventory_item_id')
                ->constrained(table: 'tenant_locations', indexName: 'tim_location_fk')
                ->cascadeOnDelete();

            $table->index(['inventory_item_id', 'location_id', 'created_at'], 'tim_item_loc_created_idx');
            $table->index(['tenant_id', 'location_id', 'created_at'], 'tim_tenant_loc_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_inventory_movements', function (Blueprint $table) {
            $table->dropIndex('tim_item_loc_created_idx');
            $table->dropIndex('tim_tenant_loc_created_idx');
            $table->dropForeign('tim_location_fk');
            $table->dropColumn('location_id');
        });
    }
};
