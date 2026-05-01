<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-level distributor catalog cache.
 *
 * Shared across ALL tenants. BTI publishes one catalog — 500 bike shops do
 * not each store a copy. One row per (distributor_code, upc).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_distributor_catalogs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('distributor_code', 32);
            $table->string('distributor_name', 128);

            $table->string('upc', 20);
            $table->string('manufacturer_sku', 64)->nullable();
            $table->string('name', 255);
            $table->string('manufacturer', 128)->nullable();
            $table->string('category', 64)->nullable();
            $table->text('description')->nullable();

            $table->integer('cost_cents')->nullable();
            $table->integer('msrp_cents')->nullable();
            $table->integer('case_quantity')->nullable();

            $table->timestamp('last_synced_at')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            // Explicit short index names — MySQL has a 64-char identifier limit
            $table->unique(['distributor_code', 'upc'], 'pdc_dist_upc_unique');
            $table->index('upc', 'pdc_upc_idx');
            $table->index('distributor_code', 'pdc_dist_idx');
            $table->index(['distributor_code', 'manufacturer'], 'pdc_dist_mfr_idx');
            $table->index(['distributor_code', 'is_active'], 'pdc_dist_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_distributor_catalogs');
    }
};
