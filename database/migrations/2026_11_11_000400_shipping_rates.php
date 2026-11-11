<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-SHIPPING-RATES: a shop's own weight and size for an item. Blank = use the
// distributor's figure (converted with the unit master admin set for that distributor).
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tenant_inventory_items', 'shop_weight_oz')) {
            Schema::table('tenant_inventory_items', function (Blueprint $t) {
                $t->decimal('shop_weight_oz', 9, 2)->nullable();
                $t->decimal('shop_length_in', 7, 2)->nullable();
                $t->decimal('shop_width_in', 7, 2)->nullable();
                $t->decimal('shop_height_in', 7, 2)->nullable();
            });
        }

        // what the shopper chose for each shipment, saved as it was priced
        if (Schema::hasTable('tenant_order_shipments') && ! Schema::hasColumn('tenant_order_shipments', 'service_name')) {
            Schema::table('tenant_order_shipments', function (Blueprint $t) {
                $t->string('service_code', 60)->nullable();
                $t->string('service_name', 120)->nullable();
                $t->string('rate_source', 12)->nullable();      // flat | table | carrier | fallback
                $t->json('packages')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tenant_order_shipments', 'service_name')) {
            Schema::table('tenant_order_shipments', fn (Blueprint $t) => $t->dropColumn(['service_code', 'service_name', 'rate_source', 'packages']));
        }
        if (Schema::hasColumn('tenant_inventory_items', 'shop_weight_oz')) {
            Schema::table('tenant_inventory_items', fn (Blueprint $t) => $t->dropColumn(['shop_weight_oz', 'shop_length_in', 'shop_width_in', 'shop_height_in']));
        }
    }
};
