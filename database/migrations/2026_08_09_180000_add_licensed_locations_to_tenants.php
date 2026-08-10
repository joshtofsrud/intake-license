<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// MARKER-LOCGATE — how many locations a tenant is licensed for.
// Hand-set by master admin today; derived from the base subscription quantity
// once per-location billing lands (Aug 6 decision).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedSmallInteger('licensed_locations')->default(1)->after('plan_tier');
        });

        // Nobody becomes retroactively over-cap: grant each tenant at least
        // what they are already running.
        DB::statement("
            UPDATE tenants t
            SET licensed_locations = GREATEST(1, (
                SELECT COUNT(*) FROM tenant_locations l
                WHERE l.tenant_id = t.id AND l.is_active = 1
            ))
        ");
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('licensed_locations');
        });
    }
};
