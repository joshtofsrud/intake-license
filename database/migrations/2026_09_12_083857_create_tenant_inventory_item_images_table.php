<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-ITEM-IMAGES — a shop's own photos for an inventory item.
 *
 * A join rather than a column, because the same photo can legitimately serve
 * several items, and because TenantMedia already handles storage, bytes and
 * the quota. Distributor images are NOT stored here: they stay in the catalog
 * and are read live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_inventory_item_images', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('inventory_item_id')
                ->constrained('tenant_inventory_items')->cascadeOnDelete();
            $table->foreignUuid('media_id')
                ->constrained('tenant_media')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            // The same photo must not be attached to one item twice.
            $table->unique(['inventory_item_id', 'media_id'], 'tiii_item_media_unique');
            $table->index(['inventory_item_id', 'sort_order'], 'tiii_item_order');
        });

        Schema::table('tenant_inventory_items', function (Blueprint $table) {
            // URLs of distributor images the shop has switched off. Matching by
            // URL means a replaced image reappears rather than staying hidden —
            // the safe direction to fail in.
            $table->json('hidden_catalog_images')->nullable()->after('catalog_mpn');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_inventory_item_images');

        Schema::table('tenant_inventory_items', function (Blueprint $table) {
            $table->dropColumn('hidden_catalog_images');
        });
    }
};
