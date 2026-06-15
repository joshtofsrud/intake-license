<?php
// MARKER-PATCH-HLC3A

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The fixed canonical Intake catalog basis. Every distributor maps its feed
 * INTO these columns via distributor_field_maps. Columns already present from
 * earlier patches (name, manufacturer, category, description, cost_cents,
 * msrp_cents, map_cents, case_quantity, upc, product_key, manufacturer_sku,
 * distributor_variant_no) are not re-added here.
 *
 * prev_*_cents hold the last-synced pricing so the sync can detect a value
 * going present -> gone and raise a pricing-attention flag (only for items a
 * tenant has in stock — handled in the sync patch).
 *
 * source_raw keeps the original variant payload: a mis-map is never permanent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_distributor_catalogs', function (Blueprint $table) {
            // identity / grouping
            $table->string('distributor_product_no', 64)->nullable()->after('distributor_variant_no');
            $table->string('distributor_variant_id', 64)->nullable()->after('distributor_product_no');
            $table->string('ean', 20)->nullable()->after('upc');
            $table->string('brand_id', 32)->nullable()->after('manufacturer');
            $table->string('config', 16)->nullable()->after('distributor_variant_id');
            $table->string('size_id', 32)->nullable()->after('config');
            $table->string('color_id', 32)->nullable()->after('size_id');

            // classification
            $table->string('category_id', 32)->nullable()->after('category');
            $table->string('category_path', 255)->nullable()->after('category_id');
            $table->string('item_group', 32)->nullable()->after('category_path');
            $table->boolean('taxable')->default(true)->after('item_group');

            // pricing extras + vanish memory
            $table->json('alt_prices')->nullable()->after('map_cents');
            $table->integer('prev_cost_cents')->nullable()->after('alt_prices');
            $table->integer('prev_map_cents')->nullable()->after('prev_cost_cents');
            $table->integer('prev_msrp_cents')->nullable()->after('prev_map_cents');

            // media / physical
            $table->json('images')->nullable()->after('case_quantity');
            $table->string('uom', 16)->nullable()->after('images');
            $table->decimal('weight', 9, 3)->nullable()->after('uom');
            $table->json('dimensions')->nullable()->after('weight');
            $table->boolean('ground_only')->default(false)->after('dimensions');
            $table->string('hazmat_type', 32)->nullable()->after('ground_only');
            $table->string('freight_class', 32)->nullable()->after('hazmat_type');

            // fulfillment
            $table->boolean('dropship_fulfillable')->default(false)->after('freight_class');
            $table->json('fulfillment_caps')->nullable()->after('dropship_fulfillable');

            // status
            $table->string('source_status_id', 16)->nullable()->after('fulfillment_caps');
            $table->string('source_status_label', 48)->nullable()->after('source_status_id');
            $table->string('canonical_status', 32)->nullable()->after('source_status_label');
            $table->boolean('is_sellable')->default(true)->after('canonical_status');

            // open-ended specs + audit
            $table->json('attributes')->nullable()->after('is_sellable');
            $table->timestamp('source_modified_at')->nullable()->after('attributes');
            $table->json('source_raw')->nullable()->after('source_modified_at');

            $table->index(['distributor_code', 'canonical_status'], 'pdc_dist_status_idx');
            $table->index('brand_id', 'pdc_brand_idx');
        });
    }

    public function down(): void
    {
        Schema::table('platform_distributor_catalogs', function (Blueprint $table) {
            $table->dropIndex('pdc_dist_status_idx');
            $table->dropIndex('pdc_brand_idx');
            $table->dropColumn([
                'distributor_product_no', 'distributor_variant_id', 'ean', 'brand_id',
                'config', 'size_id', 'color_id',
                'category_id', 'category_path', 'item_group', 'taxable',
                'alt_prices', 'prev_cost_cents', 'prev_map_cents', 'prev_msrp_cents',
                'images', 'uom', 'weight', 'dimensions', 'ground_only', 'hazmat_type', 'freight_class',
                'dropship_fulfillable', 'fulfillment_caps',
                'source_status_id', 'source_status_label', 'canonical_status', 'is_sellable',
                'attributes', 'source_modified_at', 'source_raw',
            ]);
        });
    }
};
