<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Line items for a receiving shipment.
 *
 * Each line resolves in one of three ways at commit time:
 *   - matched: inventory_item_id is set, increments stock
 *   - unexpected: came in shipment but not on the (future) PO; shop owner
 *                 decides per-item: 'add_to_inventory' or 'hold_for_return'
 *   - backorder: expected but didn't arrive; line stays for record but
 *                doesn't write a movement
 *
 * On shipment commit, every line with status='received' AND inventory_item_id
 * IS NOT NULL writes a movement of type 'receive'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_inventory_receive_shipment_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('shipment_id')
                ->constrained('tenant_inventory_receive_shipments')
                ->cascadeOnDelete();

            // Item match — nullable because unexpected items may not match yet
            $table->foreignUuid('inventory_item_id')
                ->nullable()
                ->constrained('tenant_inventory_items')
                ->nullOnDelete();

            // Identity at receive time
            $table->string('name', 255);
            $table->string('sku', 64)->nullable();
            $table->string('upc', 20)->nullable();
            $table->foreignUuid('distributor_catalog_id')
                ->nullable()
                ->constrained('platform_distributor_catalogs')
                ->nullOnDelete();

            // Counts
            $table->integer('expected_quantity')->default(0);
            $table->integer('received_quantity')->default(0);

            // Status of this line
            $table->enum('status', [
                'expected',
                'received',
                'backorder',
                'unexpected_pending',
                'unexpected_added',
                'unexpected_hold',
            ])->default('expected');

            // Costs
            $table->integer('unit_cost_cents')->nullable();
            $table->integer('total_cost_cents')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'shipment_id']);
            $table->index('inventory_item_id');
            $table->index(['shipment_id', 'status']);
            $table->index('upc');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_inventory_receive_shipment_items');
    }
};
