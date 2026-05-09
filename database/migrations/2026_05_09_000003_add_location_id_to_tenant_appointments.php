<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Add location_id to appointments. Required for multi-location tenants and
 * for appointment-derived register sales to be refundable.
 *
 * Backfill: every existing appointment gets the tenant's default location
 * (is_default=1) or, if none flagged default, the first active location.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenant_appointments', function (Blueprint $t) {
            $t->uuid('location_id')->nullable()->after('resource_id');
            $t->index('location_id');
            $t->foreign('location_id')
                ->references('id')->on('tenant_locations')
                ->nullOnDelete();
        });

        // Backfill: per-tenant lookup of default location, then assign.
        $tenantIds = DB::table('tenant_appointments')
            ->whereNull('location_id')
            ->distinct()
            ->pluck('tenant_id');

        foreach ($tenantIds as $tenantId) {
            $defaultLoc = DB::table('tenant_locations')
                ->where('tenant_id', $tenantId)
                ->where('is_active', 1)
                ->orderByDesc('is_default')
                ->orderBy('created_at')
                ->value('id');

            if ($defaultLoc) {
                DB::table('tenant_appointments')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('location_id')
                    ->update(['location_id' => $defaultLoc]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('tenant_appointments', function (Blueprint $t) {
            $t->dropForeign(['location_id']);
            $t->dropIndex(['location_id']);
            $t->dropColumn('location_id');
        });
    }
};
