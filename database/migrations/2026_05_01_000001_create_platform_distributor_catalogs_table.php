<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-level distributor catalog cache.
 *
 * Shared across ALL tenants. BTI publishes one catalog — 500 bike shops do
 * not each store a copy. One row per (distributor_code, upc).
 *
 * Tenants reference these rows from tenant_inventory_items.distributor_catalog_id
 * and toggle which distributors they want active via
 * tenant_distributor_catalog_subscriptions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_distributor_catalogs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Distributor identity
            $table->string('distributor_code', 32);  // 'bti', 'qbp', 'jb', 'trek_b2b'
            $table->string('distributor_name', 128); // 'BTI Bike Tools' — denormalised for display

            // Item identity
            $table->string('upc', 20);
            $table->string('manufacturer_sku', 64)->nullable();
            $table->string('name', 255);
            $table->string('manufacturer', 128)->nullable();
            $table->string('category', 64)->nullable(); // distributor's category, not ours
            $table->text('description')->nullable();

            // Catalog data (these become catalog_* fields on tenant_inventory_items)
            $table->integer('cost_cents')->nullable();
            $table->integer('msrp_cents')->nullable();
            $table->integer('case_quantity')->nullable();

            // Sync metadata
            $table->timestamp('last_synced_at')->nullable();
            $table->boolean('is_active')->default(true); // distributor discontinued the SKU

            $table->timestamps();
            $table->softDeletes();

            // Unique per spec section 4 — sync deduplication key
            $table->unique(['distributor_code', 'upc']);
            $table->index('upc');                  // UPC scan, all distributors
            $table->index('distributor_code');     // filter by distributor
            $table->index(['distributor_code', 'manufacturer']);
            $table->index(['distributor_code', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_distributor_catalogs');
    }
};
