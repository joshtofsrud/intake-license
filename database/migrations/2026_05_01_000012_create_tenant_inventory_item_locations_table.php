<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-location stock for inventory items.
 *
 * Pattern: shared item, per-location stock.
 *
 *   tenant_inventory_items holds the item identity, prices, descriptions —
 *   one row per SKU, shared across all locations.
 *
 *   tenant_inventory_item_locations holds per-location stock count,
 *   bin location, and reorder thresholds — one row per (item, location).
 *
 * Why: shop has one catalog of products sold at multiple locations.
 * Per-location items would create a maintenance nightmare (changing the
 * sell price means updating N rows). Per-location stock is the only
 * thing that genuinely varies by location.
 *
 * Stock truth: SUM of tenant_inventory_movements WHERE item_id = ?
 * AND location_id = ?. computed_stock_count is the cache, written
 * through InventoryService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_inventory_item_locations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignUuid('inventory_item_id')
                ->constrained(table: 'tenant_inventory_items', indexName: 'tiil_item_fk')
                ->cascadeOnDelete();

            $table->foreignUuid('location_id')
                ->constrained(table: 'tenant_locations', indexName: 'tiil_location_fk')
                ->cascadeOnDelete();

            // Stock cache — source of truth is sum of movements for this item AND location
            $table->integer('computed_stock_count')->default(0);

            // Per-location overrides for reorder behavior
            $table->integer('shop_reorder_threshold')->nullable();
            $table->integer('shop_reorder_quantity')->nullable();
            $table->string('shop_bin_location', 50)->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['inventory_item_id', 'location_id'], 'tiil_item_loc_unique');
            $table->index(['tenant_id', 'location_id', 'computed_stock_count'], 'tiil_loc_stock_idx');
            $table->index(['tenant_id', 'location_id', 'is_active'], 'tiil_loc_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_inventory_item_locations');
    }
};
