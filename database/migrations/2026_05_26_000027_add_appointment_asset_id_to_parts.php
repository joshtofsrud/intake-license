<?php
// MARKER-PATCH-158-G4

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add nullable appointment_asset_id to tenant_appointment_parts.
 *
 * Mirrors the items/addons pattern from migration 000025: NULL = loose
 * on the appointment (legacy back-compat), SET = pinned to a specific
 * asset card in the multi-asset view.
 *
 * nullOnDelete: detaching an asset from an appointment leaves the parts
 * intact but unpinned, rather than cascade-deleting them. Staff might
 * detach a bike without losing the chain and tube they already grabbed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_appointment_parts', function (Blueprint $table) {
            $table->foreignUuid('appointment_asset_id')
                  ->nullable()
                  ->after('inventory_item_id')
                  ->constrained('tenant_appointment_assets')
                  ->nullOnDelete();

            $table->index('appointment_asset_id', 'tap_asset_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_appointment_parts', function (Blueprint $table) {
            $table->dropForeign(['appointment_asset_id']);
            $table->dropIndex('tap_asset_lookup');
            $table->dropColumn('appointment_asset_id');
        });
    }
};
