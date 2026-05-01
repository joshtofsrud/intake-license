<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The architectural keystone of the POS surface.
 *
 * THE CATALOG/SHOP COLUMN-PAIR PATTERN:
 *
 *   catalog_*  <- overwritten by nightly distributor sync. Sync owns these.
 *   shop_*     <- never touched by sync. Set once, stays set.
 *
 * Effective values resolve via fallback accessor on the model:
 *   shop_value ?? catalog_value
 *
 * This makes the Ascend RMS / Lightspeed pain pattern (catalog updates
 * clobbering shop overrides) STRUCTURALLY IMPOSSIBLE. Sync code never
 * writes to shop_* columns. The boundary is enforced by the schema.
 *
 * This is a real sales pitch with bike shops — not a backend detail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_inventory_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            $table->foreignUuid('category_id')
                ->nullable()
                ->constrained('tenant_inventory_categories')
                ->nullOnDelete();

            // Identity (tenant-defined, never overwritten by sync)
            $table->string('sku', 64);
            $table->string('name', 255);
            $table->text('description')->nullable();

            // --- CATALOG fields --- overwritten by nightly sync. SYNC OWNS THESE.
            // Never write to these from shop-owner UI. Never read shop preferences
            // from these. They reflect what the distributor said, period.
            $table->foreignUuid('distributor_catalog_id')
                ->nullable()
                ->constrained('platform_distributor_catalogs')
                ->nullOnDelete();
            $table->integer('catalog_cost_cents')->nullable();
            $table->integer('catalog_msrp_cents')->nullable();
            $table->integer('catalog_case_quantity')->nullable();
            $table->string('catalog_upc', 20)->nullable();
            $table->timestamp('catalog_synced_at')->nullable();

            // --- SHOP fields --- never touched by sync. SET ONCE, STAYS SET.
            // The whole point of the architecture lives in this block.
            $table->integer('shop_cost_cents')->nullable();
            $table->integer('shop_sell_price_cents')->nullable();
            $table->integer('shop_case_quantity')->nullable();
            $table->integer('shop_reorder_threshold')->nullable();
            $table->integer('shop_reorder_quantity')->nullable();
            $table->string('shop_bin_location', 50)->nullable();

            // --- Stock cache ---
            // Source of truth: SUM(quantity_delta) from tenant_inventory_movements.
            // This column is a denormalised cache maintained ONLY by InventoryService
            // write paths. Never read or write directly from controllers.
            $table->integer('computed_stock_count')->default(0);

            // --- Behavior flags
            $table->boolean('allow_oversell')->default(true); // committed default per POS spec section 12 Q1
            $table->boolean('is_active')->default(true);

            // Forward-compat: per-item tax class override (spec section 12 Q2)
            // Falls back to category->tax_class_code, then to shop-wide rate.
            $table->string('tax_class_code', 32)->nullable();

            $table->timestamps();
            $table->softDeletes();

            // --- Indexes per spec section 4 ---
            $table->unique(['tenant_id', 'sku']);
            $table->index(['tenant_id', 'name']);                  // search by name
            $table->index(['tenant_id', 'category_id']);           // filter by category
            $table->index(['tenant_id', 'is_active']);             // default list filter
            $table->index(['tenant_id', 'computed_stock_count']);  // low-stock queries
            $table->index('catalog_upc');                          // UPC scan lookup
            $table->index('distributor_catalog_id');               // sync propagation joins
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_inventory_items');
    }
};
