<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenant_sales', function (Blueprint $table) {
            // Which location the sale was rung up at.
            // Nullable in DB (legacy/imported sales may lack it).
            // SaleService enforces presence at write time.
            $table->foreignUuid('location_id')
                  ->nullable()
                  ->after('register_id')
                  ->constrained('tenant_locations')
                  ->onDelete('restrict');
            // restrict: deleting a location should fail if sales reference it.
            // Soft-archive the location instead via is_active.

            $table->index(['tenant_id', 'location_id'], 'tenant_sales_tenant_location_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_sales', function (Blueprint $table) {
            $table->dropIndex('tenant_sales_tenant_location_idx');
            $table->dropForeign(['location_id']);
            $table->dropColumn('location_id');
        });
    }
};
