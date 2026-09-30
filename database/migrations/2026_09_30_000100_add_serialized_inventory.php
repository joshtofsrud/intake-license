<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-SERIAL-FOUNDATION — serialized inventory.
 *
 * A category can track serial numbers (its subcategories inherit it). Each
 * serialized unit is a row here: its serial, where it is, what it cost, and
 * whether it's in stock, sold or written off. Stock quantities still come
 * from the movement ledger as before; units give those quantities identities.
 * Receiving keeps the serials typed on a draft line until the shipment is
 * committed, when they become units.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_inventory_categories', function (Blueprint $t) {
            $t->boolean('track_serials')->default(false)->after('tax_class_code');
        });

        Schema::table('tenant_inventory_receive_shipment_items', function (Blueprint $t) {
            $t->json('serials')->nullable()->after('notes');
        });

        Schema::create('tenant_inventory_units', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->uuid('inventory_item_id');
            $t->uuid('location_id')->nullable();
            $t->string('serial', 100);
            $t->string('serial_key', 100);           // upper-cased, spaces removed — what uniqueness checks
            $t->string('status', 20)->default('in_stock'); // in_stock | sold | written_off
            $t->integer('cost_cents')->nullable();
            $t->uuid('received_shipment_id')->nullable();
            $t->uuid('received_shipment_item_id')->nullable();
            $t->timestamp('received_at')->nullable();
            $t->uuid('sale_id')->nullable();
            $t->timestamp('sold_at')->nullable();
            $t->timestamp('written_off_at')->nullable();
            $t->string('write_off_reason', 60)->nullable();
            $t->string('notes', 500)->nullable();
            $t->uuid('created_by')->nullable();
            $t->timestamps();

            $t->unique(['tenant_id', 'serial_key'], 'tiu_tenant_serial_uq');
            $t->index(['tenant_id', 'inventory_item_id', 'status'], 'tiu_item_status_idx');
            $t->index(['tenant_id', 'location_id', 'status'], 'tiu_loc_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_inventory_units');
        Schema::table('tenant_inventory_receive_shipment_items', function (Blueprint $t) {
            $t->dropColumn('serials');
        });
        Schema::table('tenant_inventory_categories', function (Blueprint $t) {
            $t->dropColumn('track_serials');
        });
    }
};
