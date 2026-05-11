<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_customers', function (Blueprint $table) {
            // Manual VIP flag. Default false. Tenants toggle this manually
            // from the customer detail page. v1.1 introduces a learning
            // engine that suggests candidates based on tenant's pattern of
            // manual toggles; engine writes to a separate suggestions table.
            $table->boolean('is_vip')->default(false)->after('country');
            $table->index(['tenant_id', 'is_vip'], 'tnt_customers_vip_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_customers', function (Blueprint $table) {
            $table->dropIndex('tnt_customers_vip_idx');
            $table->dropColumn('is_vip');
        });
    }
};
