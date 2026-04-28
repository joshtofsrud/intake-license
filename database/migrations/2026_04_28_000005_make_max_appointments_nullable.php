<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Relax tenant_capacity_rules.max_appointments to allow NULL.
 *
 * Original column was NOT NULL because every rule had a shop-wide cap.
 * After the capacity rebuild (2026-04-28), max_appointments is now an
 * OPTIONAL override on top of per-resource caps:
 *   - drop-off mode: NULL = use sum of resource caps as ceiling
 *   - time-slot mode: NULL = no override, grid math governs
 *
 * Required for the new seeder + admin UI to write NULL values.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenant_capacity_rules', function (Blueprint $t) {
            $t->unsignedInteger('max_appointments')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Revert: NOT NULL with no default is unsafe (existing NULLs would break),
        // so on rollback we set existing NULLs to 0 first, then enforce NOT NULL.
        \Illuminate\Support\Facades\DB::statement(
            'UPDATE tenant_capacity_rules SET max_appointments = 0 WHERE max_appointments IS NULL'
        );
        Schema::table('tenant_capacity_rules', function (Blueprint $t) {
            $t->unsignedInteger('max_appointments')->nullable(false)->change();
        });
    }
};
