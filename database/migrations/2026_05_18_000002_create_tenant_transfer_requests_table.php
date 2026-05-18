<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * patch-100a: transfer requests
 *
 * When a register sale at Location A oversells an item, staff can
 * click "Request transfer" — flagging that another location should
 * physically move stock to Location A. The fulfilling location's
 * staff sees pending requests in their dashboard (patch 100B) and
 * either fulfills or cancels.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenant_transfer_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('inventory_item_id');
            $table->uuid('to_location_id'); // requesting location (where it's oversold)
            $table->uuid('from_location_id')->nullable(); // suggested source (any other loc with stock)
            $table->integer('quantity');
            $table->uuid('requested_by_user_id')->nullable();
            $table->uuid('sale_id')->nullable(); // link back to the sale that triggered it
            $table->string('status', 24)->default('pending'); // pending, fulfilled, cancelled
            $table->text('notes')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->uuid('fulfilled_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'from_location_id', 'status']);
            $table->index(['tenant_id', 'inventory_item_id']);
            $table->index('sale_id');

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('inventory_item_id')->references('id')->on('tenant_inventory_items')->onDelete('cascade');
            $table->foreign('to_location_id')->references('id')->on('tenant_locations')->onDelete('cascade');
            $table->foreign('from_location_id')->references('id')->on('tenant_locations')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_transfer_requests');
    }
};
