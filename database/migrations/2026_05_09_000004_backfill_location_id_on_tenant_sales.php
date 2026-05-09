<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time backfill: any sale with null location_id gets the tenant's
 * default location. Same lookup as the appointments backfill.
 *
 * This unblocks refunds on appointment-derived sales that were created
 * before the bridge service was patched to set location_id explicitly.
 */
return new class extends Migration {
    public function up(): void
    {
        $tenantIds = DB::table('tenant_sales')
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
                DB::table('tenant_sales')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('location_id')
                    ->update(['location_id' => $defaultLoc]);
            }
        }
    }

    public function down(): void
    {
        // Backfill is forward-only. Reversing would lose information.
    }
};
