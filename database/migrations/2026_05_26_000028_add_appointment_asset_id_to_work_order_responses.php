<?php
// MARKER-PATCH-158-G5 — original
// MARKER-PATCH-168B — fixed FK name length + made idempotent

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add nullable appointment_asset_id to tenant_appointment_work_order_responses.
 *
 * Original version failed because Laravel auto-generated an FK constraint
 * name longer than MySQL\'s 64-char identifier limit. This version uses
 * an explicit short FK name (wor_asset_fk) and is also idempotent so it
 * can recover from a previous half-applied state.
 *
 * Same pattern as parts (000027) and items+addons (000025): NULL = response
 * applies to the appointment as a whole (legacy back-compat), SET = scoped
 * to one asset card so each bike/pet/car answers the work-order questions
 * independently.
 *
 * nullOnDelete: detaching an asset leaves its responses around as loose
 * appointment-wide rows rather than deleting the staff\'s notes about what
 * was wrong with that bike. Matches the parts behavior.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = 'tenant_appointment_work_order_responses';

        // Step 1: add the column if it does not exist (recovery from previous half-run).
        if (! Schema::hasColumn($table, 'appointment_asset_id')) {
            Schema::table($table, function (Blueprint $t) {
                $t->uuid('appointment_asset_id')->nullable()->after('field_id');
            });
        }

        // Step 2: add the index if it does not exist.
        $idx = DB::select(
            "SHOW INDEX FROM `{$table}` WHERE Key_name = ?",
            ['wor_asset_lookup']
        );
        if (count($idx) === 0) {
            Schema::table($table, function (Blueprint $t) {
                $t->index('appointment_asset_id', 'wor_asset_lookup');
            });
        }

        // Step 3: add the FK with an explicit short name (under MySQL\'s 64-char limit).
        $fk = DB::select(
            "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?",
            [$table, 'wor_asset_fk']
        );
        if (count($fk) === 0) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreign('appointment_asset_id', 'wor_asset_fk')
                  ->references('id')->on('tenant_appointment_assets')
                  ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        $table = 'tenant_appointment_work_order_responses';

        Schema::table($table, function (Blueprint $t) {
            $t->dropForeign('wor_asset_fk');
        });
        Schema::table($table, function (Blueprint $t) {
            $t->dropIndex('wor_asset_lookup');
        });
        Schema::table($table, function (Blueprint $t) {
            $t->dropColumn('appointment_asset_id');
        });
    }
};
