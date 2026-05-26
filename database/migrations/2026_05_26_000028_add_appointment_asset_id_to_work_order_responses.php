<?php
// MARKER-PATCH-158-G5

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add nullable appointment_asset_id to tenant_appointment_work_order_responses.
 *
 * Same pattern as parts (000027) and items+addons (000025): NULL = response
 * applies to the appointment as a whole (legacy back-compat), SET = scoped
 * to one asset card so each bike/pet/car answers the work-order questions
 * independently.
 *
 * nullOnDelete: detaching an asset leaves its responses around as loose
 * appointment-wide rows rather than deleting the staff's notes about what
 * was wrong with that bike. Matches the parts behavior.
 *
 * Index includes appointment_asset_id so the per-asset render query in
 * show() doesn't sequential-scan the wider tenant+appointment index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_appointment_work_order_responses', function (Blueprint $table) {
            $table->foreignUuid('appointment_asset_id')
                  ->nullable()
                  ->after('field_id')
                  ->constrained('tenant_appointment_assets')
                  ->nullOnDelete();

            $table->index('appointment_asset_id', 'wor_asset_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_appointment_work_order_responses', function (Blueprint $table) {
            $table->dropForeign(['appointment_asset_id']);
            $table->dropIndex('wor_asset_lookup');
            $table->dropColumn('appointment_asset_id');
        });
    }
};
