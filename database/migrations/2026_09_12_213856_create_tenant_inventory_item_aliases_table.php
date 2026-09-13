<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-ITEM-ALIASES — identifiers that USED to resolve to an item.
 *
 * Written by merge: the merged-away item's SKU and barcodes become aliases of
 * the survivor, so a label already printed still scans. Kept separate from the
 * item's own identifier columns on purpose — an alias is history, not
 * identity, and catalog matching must never treat an old label as the
 * current product.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_inventory_item_aliases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('inventory_item_id')
                ->constrained('tenant_inventory_items')->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('kind', 8);            // sku | upc | ean | mpn
            $table->string('source', 16);         // merge | manual
            $table->uuid('from_item_id')->nullable(); // the merged-away item, if any
            $table->timestamp('created_at')->nullable();

            $table->unique(['inventory_item_id', 'code'], 'tiia_item_code_unique');
            $table->index(['tenant_id', 'code'], 'tiia_tenant_code_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_inventory_item_aliases');
    }
};
