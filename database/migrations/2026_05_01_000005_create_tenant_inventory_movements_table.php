<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit log of every change to inventory stock.
 *
 * SOURCE OF TRUTH for stock counts. tenant_inventory_items.computed_stock_count
 * is a denormalised cache; this table is the truth.
 *
 * Stock count for an item = SUM(quantity_delta) WHERE inventory_item_id = ?
 *
 * NEVER UPDATED. NEVER DELETED. Append-only by convention and enforced by
 * InventoryService.
 *
 * Snapshot-on-write per Design Principle P13: item_name and item_sku are
 * captured at write time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_inventory_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('inventory_item_id')
                ->constrained('tenant_inventory_items')
                ->cascadeOnDelete();

            $table->integer('quantity_delta');

            $table->enum('movement_type', [
                'sale',
                'sale_void',
                'refund',
                'receive',
                'adjustment',
                'transfer_out',
                'transfer_in',
                'initial',
            ]);

            $table->string('reference_type', 32);
            $table->uuid('reference_id')->nullable();

            $table->string('item_name_snapshot', 255);
            $table->string('item_sku_snapshot', 64);

            $table->integer('cost_cents_at_time')->nullable();

            $table->string('reason', 64)->nullable();
            $table->text('notes')->nullable();

            $table->foreignUuid('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('created_at')->useCurrent();

            // Explicit short index names — MySQL has a 64-char identifier limit
            $table->index(['inventory_item_id', 'created_at'], 'tim_item_created_idx');
            $table->index(['tenant_id', 'created_at'], 'tim_tenant_created_idx');
            $table->index(['reference_type', 'reference_id'], 'tim_ref_idx');
            $table->index(['tenant_id', 'movement_type', 'created_at'], 'tim_tenant_type_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_inventory_movements');
    }
};
