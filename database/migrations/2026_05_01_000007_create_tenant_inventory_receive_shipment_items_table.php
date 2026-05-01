<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Line items for a receiving shipment.
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

            // FK names explicit because the table name (39 chars) plus auto-generated
            // suffix exceeds MySQL's 64-char identifier limit on several columns.
            $table->foreignUuid('tenant_id')
                ->constrained(table: 'tenants', indexName: 'tirsi_tenant_fk')
                ->cascadeOnDelete();

            $table->foreignUuid('shipment_id')
                ->constrained(table: 'tenant_inventory_receive_shipments', indexName: 'tirsi_ship_fk')
                ->cascadeOnDelete();

            $table->foreignUuid('inventory_item_id')
                ->nullable()
                ->constrained(table: 'tenant_inventory_items', indexName: 'tirsi_inv_item_fk')
                ->nullOnDelete();

            $table->string('name', 255);
            $table->string('sku', 64)->nullable();
            $table->string('upc', 20)->nullable();

            $table->foreignUuid('distributor_catalog_id')
                ->nullable()
                ->constrained(table: 'platform_distributor_catalogs', indexName: 'tirsi_dist_cat_fk')
                ->nullOnDelete();

            $table->integer('expected_quantity')->default(0);
            $table->integer('received_quantity')->default(0);

            $table->enum('status', [
                'expected',
                'received',
                'backorder',
                'unexpected_pending',
                'unexpected_added',
                'unexpected_hold',
            ])->default('expected');

            $table->integer('unit_cost_cents')->nullable();
            $table->integer('total_cost_cents')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'shipment_id'], 'tirsi_tenant_ship_idx');
            $table->index('inventory_item_id', 'tirsi_item_idx');
            $table->index(['shipment_id', 'status'], 'tirsi_ship_status_idx');
            $table->index('upc', 'tirsi_upc_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_inventory_receive_shipment_items');
    }
};
