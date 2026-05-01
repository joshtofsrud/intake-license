<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Receiving header: one row per shipment received against a (future) PO.
 *
 * v1 ships without proper PO management — shops manually create a shipment
 * and add line items. v2 will add purchase_orders and link shipments to POs.
 *
 * Status flow: draft -> committed (one-way, no edits after commit).
 * On commit, each line item writes a movement of type 'receive'.
 *
 * Per the wireframe in the POS spec gallery: stat strip shows
 * expected/received/backorder/unexpected counts. Computed from line items.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_inventory_receive_shipments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            // Shipment identity — tenant-scoped sequential number for human reference
            $table->string('shipment_number', 30); // 'SHIP-2026-04-26-0471'

            // Distributor reference — text, not FK
            $table->string('distributor_code', 32)->nullable();
            $table->string('distributor_name', 128)->nullable();

            // Future PO link (v2); nullable for v1
            $table->uuid('purchase_order_id')->nullable();

            $table->enum('status', ['draft', 'committed', 'voided'])->default('draft');

            $table->date('received_date')->nullable();
            $table->integer('shipping_cost_cents')->default(0);

            // Cached counts for the wireframe stat strip
            $table->integer('expected_count')->default(0);
            $table->integer('received_count')->default(0);
            $table->integer('backorder_count')->default(0);
            $table->integer('unexpected_count')->default(0);

            $table->text('notes')->nullable();

            // Audit
            $table->foreignUuid('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignUuid('committed_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('committed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'shipment_number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'received_date']);
            $table->index(['tenant_id', 'distributor_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_inventory_receive_shipments');
    }
};
