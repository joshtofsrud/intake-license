<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-location capacity and hours.
 *
 * tenant_capacity_rules holds both hours-of-operation (open_time, close_time,
 * is_closed) and capacity (max_appointments) for each (tenant, day-of-week)
 * or (tenant, specific_date) combination.
 *
 * Adding location_id makes hours and capacity per-location. The backfill
 * command stamps every existing row with the tenant's default location_id
 * before the column becomes NOT NULL in a downstream commit.
 *
 * Column is nullable here. The intake:backfill-multi-location command
 * populates it, and a follow-up migration (after the backfill runs in
 * production) tightens it to NOT NULL. This is the only place we accept
 * the two-step pattern — capacity rules are one of the heaviest tenant
 * tables and we want zero data risk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_capacity_rules', function (Blueprint $table) {
            $table->foreignUuid('location_id')
                ->nullable()
                ->after('tenant_id')
                ->constrained(table: 'tenant_locations', indexName: 'tcr_location_fk')
                ->cascadeOnDelete();

            $table->index(['tenant_id', 'location_id', 'rule_type'], 'tcr_tenant_loc_type_idx');
            $table->index(['tenant_id', 'location_id', 'specific_date'], 'tcr_tenant_loc_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_capacity_rules', function (Blueprint $table) {
            $table->dropIndex('tcr_tenant_loc_type_idx');
            $table->dropIndex('tcr_tenant_loc_date_idx');
            $table->dropForeign('tcr_location_fk');
            $table->dropColumn('location_id');
        });
    }
};
