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
 * InventoryService — there is no update path in the service layer.
 *
 * Snapshot-on-write per Design Principle P13: item_name and item_sku are
 * captured at write time. If an item is renamed or archived, historical
 * movement reports still read correctly.
 *
 * cost_cents_at_time enables accurate historical margin reporting and
 * leaves the door open for FIFO/LIFO accounting later (out of v1 scope).
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

            // The actual delta — negative for sales/decrements, positive for receives
            $table->integer('quantity_delta');

            // Why the change happened
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

            // Polymorphic-ish reference to the source of the movement
            $table->string('reference_type', 32);
            $table->uuid('reference_id')->nullable();

            // Snapshot fields per Design Principle P13 (snapshot-on-write)
            $table->string('item_name_snapshot', 255);
            $table->string('item_sku_snapshot', 64);

            // Cost basis at the moment of movement — for accurate margin reporting.
            $table->integer('cost_cents_at_time')->nullable();

            // Required for adjustments, optional otherwise
            $table->string('reason', 64)->nullable();
            $table->text('notes')->nullable();

            // Audit trail — who triggered the movement
            $table->foreignUuid('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('created_at')->useCurrent();
            // Note: no updated_at, no soft deletes — append-only.

            // --- Indexes per spec section 4 ---
            $table->index(['inventory_item_id', 'created_at']);
            $table->index(['tenant_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
            $table->index(['tenant_id', 'movement_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_inventory_movements');
    }
};
