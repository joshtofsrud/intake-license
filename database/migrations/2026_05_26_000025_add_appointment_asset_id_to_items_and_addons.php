<?php
// MARKER-PATCH-158-A

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add nullable appointment_asset_id column to existing appointment-items
 * and appointment-addons tables.
 *
 * When NULL: the service/addon is "loose" on the appointment (existing
 * single-bike behavior, fully back-compat).
 * When SET: the service/addon is pinned to a specific asset card in the
 * multi-asset view.
 *
 * Foreign key uses nullOnDelete so that detaching/deleting an
 * appointment_asset row leaves the services intact but unpinned —
 * recoverable rather than cascade-deleted. Staff might detach a bike
 * from an appointment without losing the services already attached.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_appointment_items', function (Blueprint $table) {
            $table->foreignUuid('appointment_asset_id')
                  ->nullable()
                  ->after('service_item_id')
                  ->constrained('tenant_appointment_assets')
                  ->nullOnDelete();

            // For "render all services pinned to this asset"
            $table->index('appointment_asset_id', 'tai_asset_lookup');
        });

        Schema::table('tenant_appointment_addons', function (Blueprint $table) {
            $table->foreignUuid('appointment_asset_id')
                  ->nullable()
                  ->after('addon_id')
                  ->constrained('tenant_appointment_assets')
                  ->nullOnDelete();

            $table->index('appointment_asset_id', 'taad_asset_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_appointment_items', function (Blueprint $table) {
            $table->dropForeign(['appointment_asset_id']);
            $table->dropIndex('tai_asset_lookup');
            $table->dropColumn('appointment_asset_id');
        });

        Schema::table('tenant_appointment_addons', function (Blueprint $table) {
            $table->dropForeign(['appointment_asset_id']);
            $table->dropIndex('taad_asset_lookup');
            $table->dropColumn('appointment_asset_id');
        });
    }
};
