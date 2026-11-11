<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-ORDER-SHIPMENTS: an online order that ships is one or more shipments, one per
// source (the shop, or each supplier warehouse), each priced on its own. Additive only.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_order_shipments')) {
            Schema::create('tenant_order_shipments', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
                $t->foreignUuid('order_id')->constrained('tenant_orders')->cascadeOnDelete();
                $t->string('source', 12);                       // shop | supplier
                $t->uuid('warehouse_id')->nullable();
                $t->string('warehouse_name', 120)->nullable();  // snapshot, survives renames
                $t->unsignedSmallInteger('handling_days')->default(0);
                $t->integer('shipping_cents')->default(0);
                $t->string('status', 16)->default('pending');   // pending | shipped | delivered | cancelled
                $t->string('carrier', 40)->nullable();
                $t->string('tracking_number', 80)->nullable();
                $t->timestamp('shipped_at')->nullable();
                $t->timestamps();
                $t->index(['tenant_id', 'order_id'], 'tos_tenant_order');
            });
        }

        if (! Schema::hasTable('tenant_order_shipment_items')) {
            Schema::create('tenant_order_shipment_items', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->foreignUuid('shipment_id')->constrained('tenant_order_shipments')->cascadeOnDelete();
                $t->uuid('order_item_id');
                $t->unsignedInteger('quantity');                 // units in this shipment
                $t->unsignedInteger('special_quantity')->default(0); // of those, units still to be special-ordered
                $t->timestamps();
                $t->index('order_item_id', 'tosi_item');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_order_shipment_items');
        Schema::dropIfExists('tenant_order_shipments');
    }
};
