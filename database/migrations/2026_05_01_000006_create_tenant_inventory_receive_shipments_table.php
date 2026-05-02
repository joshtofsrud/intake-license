<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Receiving header: one row per shipment received against a (future) PO.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_inventory_receive_shipments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('shipment_number', 30);

            $table->string('distributor_code', 32)->nullable();
            $table->string('distributor_name', 128)->nullable();

            $table->uuid('purchase_order_id')->nullable();

            $table->enum('status', ['draft', 'committed', 'voided'])->default('draft');

            $table->date('received_date')->nullable();
            $table->integer('shipping_cost_cents')->default(0);

            $table->integer('expected_count')->default(0);
            $table->integer('received_count')->default(0);
            $table->integer('backorder_count')->default(0);
            $table->integer('unexpected_count')->default(0);

            $table->text('notes')->nullable();

            $table->foreignUuid('created_by_tenant_user_id')
                ->nullable()
                ->constrained(table: 'tenant_users', indexName: 'tirs_created_by_fk')
                ->nullOnDelete();
            $table->foreignUuid('committed_by_tenant_user_id')
                ->nullable()
                ->constrained(table: 'tenant_users', indexName: 'tirs_committed_by_fk')
                ->nullOnDelete();
            $table->timestamp('committed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'shipment_number'], 'tirs_tenant_shipnum_unique');
            $table->index(['tenant_id', 'status'], 'tirs_tenant_status_idx');
            $table->index(['tenant_id', 'received_date'], 'tirs_tenant_received_idx');
            $table->index(['tenant_id', 'distributor_code'], 'tirs_tenant_dist_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_inventory_receive_shipments');
    }
};
