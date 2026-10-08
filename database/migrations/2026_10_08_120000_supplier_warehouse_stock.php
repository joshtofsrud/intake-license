<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-SUPPLY: each distributor's stock per warehouse, kept beside the
 * total (live_avail). Filled by the tenant distributor sync. Additive.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenant_inventory_item_vendors', function (Blueprint $t) {
            if (! Schema::hasColumn('tenant_inventory_item_vendors', 'live_warehouses')) {
                $t->json('live_warehouses')->nullable()->after('live_avail');
            }
        });
    }

    public function down(): void
    {
        // additive: the column stays.
    }
};
