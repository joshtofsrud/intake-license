<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds calendar-related columns to the existing appointment tables.
 *
 * Context: the 2026_04_20_000003 rebuild migration already ran on
 * production before we added resource_id + prep/cleanup snapshot columns
 * to its create() calls locally. Since Laravel won't re-run a migration
 * that's already in the migrations table, we add the columns via an
 * additive ALTER in this new migration.
 *
 * This is the standard "you never edit a migration that already ran"
 * pattern. The rebuild migration file has been reverted to match the
 * schema that production saw on batch 10.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_appointments', function (Blueprint $table) {
            $table->foreignUuid('resource_id')
                  ->nullable()
                  ->after('customer_id')
                  ->constrained('tenant_resources')
                  ->nullOnDelete();

            $table->unsignedSmallInteger('prep_before_minutes_snapshot')
                  ->default(0)
                  ->after('total_duration_minutes');

            $table->unsignedSmallInteger('cleanup_after_minutes_snapshot')
                  ->default(0)
                  ->after('prep_before_minutes_snapshot');

            // Composite index: calendar queries by (tenant, resource, date).
            // Heaviest query pattern in the system at scale — must be indexed.
            $table->index(
                ['tenant_id', 'resource_id', 'appointment_date'],
                'tenant_appts_tenant_resource_date_idx'
            );
        });

        Schema::table('tenant_appointment_items', function (Blueprint $table) {
            $table->unsignedSmallInteger('prep_before_minutes_snapshot')
                  ->default(0)
                  ->after('duration_minutes_snapshot');

            $table->unsignedSmallInteger('cleanup_after_minutes_snapshot')
                  ->default(0)
                  ->after('prep_before_minutes_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_appointment_items', function (Blueprint $table) {
            $table->dropColumn([
                'cleanup_after_minutes_snapshot',
                'prep_before_minutes_snapshot',
            ]);
        });

        Schema::table('tenant_appointments', function (Blueprint $table) {
            $table->dropIndex('tenant_appts_tenant_resource_date_idx');
            $table->dropForeign(['resource_id']);
            $table->dropColumn([
                'cleanup_after_minutes_snapshot',
                'prep_before_minutes_snapshot',
                'resource_id',
            ]);
        });
    }
};
