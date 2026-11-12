<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-SHIPMENT-WORKFLOW: when a shipment went out for delivery and arrived, and why one
// was called off. Additive only.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_order_shipments') && ! Schema::hasColumn('tenant_order_shipments', 'delivered_at')) {
            Schema::table('tenant_order_shipments', function (Blueprint $t) {
                $t->timestamp('out_for_delivery_at')->nullable();
                $t->timestamp('delivered_at')->nullable();
                $t->string('note', 300)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tenant_order_shipments', 'delivered_at')) {
            Schema::table('tenant_order_shipments', fn (Blueprint $t) => $t->dropColumn(['out_for_delivery_at', 'delivered_at', 'note']));
        }
    }
};
