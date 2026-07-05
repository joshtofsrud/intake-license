<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-PATCH-560 — Online Retail Wave 1: order lines. Name/image/variant
// are snapshots so a fulfilled order renders forever, even after catalog
// churn or item deletion.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('order_id')->constrained('tenant_orders')->cascadeOnDelete();
            $table->uuid('inventory_item_id')->nullable();
            $table->uuid('distributor_catalog_id')->nullable(); // snapshot ref
            $table->string('name_snapshot', 255);
            $table->string('image_snapshot', 500)->nullable();
            $table->string('variant_snapshot', 120)->nullable(); // size/option
            $table->integer('unit_price_cents')->default(0);
            $table->decimal('quantity', 8, 3)->default(1);
            $table->integer('line_total_cents')->default(0);
            $table->integer('position')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'order_id'], 'toi_tenant_order');
            $table->index('inventory_item_id', 'toi_item');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_order_items');
    }
};
