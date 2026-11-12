<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-ONLINE-REPORTS: what a shipment actually cost the shop (the label, or the supplier's
 * freight). shipping_cents stays what the customer was charged. Null = not entered yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_order_shipments') && ! Schema::hasColumn('tenant_order_shipments', 'cost_cents')) {
            Schema::table('tenant_order_shipments', function (Blueprint $t) {
                $t->unsignedInteger('cost_cents')->nullable()->after('shipping_cents');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tenant_order_shipments', 'cost_cents')) {
            Schema::table('tenant_order_shipments', fn (Blueprint $t) => $t->dropColumn('cost_cents'));
        }
    }
};
