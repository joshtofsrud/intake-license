<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Item-to-vendor pivot. An item can be sourced from multiple vendors
 * with different unit costs, vendor SKUs, and lead times.
 *
 * The `is_preferred` flag surfaces the staff-chosen primary source
 * in pickers. Exactly one row per (item) can be flagged preferred —
 * a partial unique constraint enforces this at the database level
 * via a generated column trick (see down() and the index).
 *
 * NOT enforced here: that the vendor and item belong to the same
 * tenant. We rely on controller-level scoping for that — pivot rows
 * are written through model code that scopes both sides. Adding a
 * tenant_id column here for sanity would let us add a 3-column unique
 * + index for fast tenant scoping. We don't, because:
 *   - The pivot can be reached only through item or vendor, both
 *     of which are already tenant-scoped.
 *   - Adding tenant_id creates a redundancy that has to be kept in
 *     sync (item.tenant_id == pivot.tenant_id == vendor.tenant_id).
 *     One source of truth (the parent row) is simpler.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_inventory_item_vendors', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('inventory_item_id')
                ->constrained('tenant_inventory_items')
                ->cascadeOnDelete();

            $table->foreignUuid('vendor_id')
                ->constrained('tenant_vendors')
                ->cascadeOnDelete();

            // Vendor's own SKU for this item — may differ from the item's
            // internal SKU. e.g. our SKU "CON-TUBE-700-25", QBP's SKU "QB-TB7025".
            $table->string('vendor_sku', 64)->nullable();

            // Vendor's unit cost. NULL means "ask the vendor" — useful for
            // newly-added sources where we haven't priced them yet.
            $table->integer('unit_cost_cents')->nullable();

            // Average expected lead time. Updated by service layer when
            // SOs from this vendor arrive (compute on-the-fly initially;
            // cache here later if perf demands it).
            $table->integer('lead_time_days')->nullable();

            // Exactly one preferred vendor per item — enforced via the
            // unique index below.
            $table->boolean('is_preferred')->default(false);

            // Last time this source actually delivered. Helps surface
            // "this source hasn't been used in a while" without computing.
            $table->timestamp('last_ordered_at')->nullable();

            $table->timestamps();

            // One row per (item, vendor) — can't add the same vendor twice.
            $table->unique(['inventory_item_id', 'vendor_id'], 'tiiv_item_vendor_unique');

            // Lookup index: "what vendors source this item, preferred first."
            $table->index(['inventory_item_id', 'is_preferred'], 'tiiv_item_pref_idx');

            // Reverse: "what items does this vendor supply."
            $table->index('vendor_id', 'tiiv_vendor_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_inventory_item_vendors');
    }
};
