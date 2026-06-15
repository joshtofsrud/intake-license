<?php
// MARKER-PATCH-HLC4A

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distinguishes "carried but not stocked" (catalog-only, imported, 0 qty,
 * still sellable/orderable) from items the shop physically stocks. Imported
 * items default to is_stock_tracked = false; manual items keep true. A shop can
 * flip an item to stocked when they decide to carry it on the shelf.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_inventory_items', function (Blueprint $table) {
            $table->boolean('is_stock_tracked')->default(true)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_inventory_items', function (Blueprint $table) {
            $table->dropColumn('is_stock_tracked');
        });
    }
};
