<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds default_vendor_id to tenant_inventory_items.
 *
 * Independent of the tenant_inventory_item_vendors pivot. The pivot
 * is authoritative for "which vendors can supply this." default_vendor_id
 * is just a convenience pointer: "when ordering this, default to vendor X."
 *
 * Usually equals the pivot row with is_preferred=true, but they can
 * diverge in valid cases (e.g., temporarily ordering from a non-preferred
 * vendor while the preferred is out of stock).
 *
 * Nullable. Null means "no default set — staff picks at SO creation."
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_inventory_items', function (Blueprint $table) {
            $table->foreignUuid('default_vendor_id')
                ->nullable()
                ->after('shop_reorder_threshold')
                ->constrained('tenant_vendors')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_inventory_items', function (Blueprint $table) {
            $table->dropForeign(['default_vendor_id']);
            $table->dropColumn('default_vendor_id');
        });
    }
};
