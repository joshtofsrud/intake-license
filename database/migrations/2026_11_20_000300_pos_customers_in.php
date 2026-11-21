<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-POS-CUSTOMERS-IN: customers coming in from the Connected POS. Its own switch and
 * its own progress, separate from the product import, so each can be stopped on its own.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenant_pos_connections', function (Blueprint $t) {
            if (! Schema::hasColumn('tenant_pos_connections', 'customers_in')) { $t->boolean('customers_in')->default(false); }
            if (! Schema::hasColumn('tenant_pos_connections', 'customer_sync')) { $t->json('customer_sync')->nullable(); }
        });
        Schema::table('tenant_pos_customers', function (Blueprint $t) {
            $t->index(['tenant_id', 'external_id'], 'tpc_tenant_external');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_pos_customers', function (Blueprint $t) { $t->dropIndex('tpc_tenant_external'); });
        Schema::table('tenant_pos_connections', function (Blueprint $t) { $t->dropColumn(['customers_in', 'customer_sync']); });
    }
};
