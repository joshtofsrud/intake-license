<?php
// MARKER-PATCH-HLC2

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distributor catalog identity (patch 2).
 *
 *  - distributor_variant_no: the distributor's OWN stable id for a product
 *    (e.g. HLC variant 500027-01). THIS, not UPC, is a distributor's identity:
 *    UPCs can be null or shared; variant numbers are unique within a feed.
 *  - product_key: normalised grouping key (UPC when present, else
 *    manufacturer + manufacturer_sku/MPN). Lets catalog-link gather EVERY
 *    distributor row for one physical product, so a product carried by five
 *    distributors stays ONE item with five sources — never five SKUs.
 *  - map_cents: Minimum Advertised Price, for the pricing-attention surface.
 *
 * NOTE: existing `manufacturer_sku` is treated as the manufacturer part
 * number (MPN). The unique key moves from (distributor_code, upc) to
 * (distributor_code, distributor_variant_no). Safe now — table is empty
 * (sync hasn't run). down() restores the original key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_distributor_catalogs', function (Blueprint $table) {
            $table->string('product_key', 80)->nullable()->after('upc');
            $table->string('distributor_variant_no', 64)->nullable()->after('manufacturer_sku');
            $table->integer('map_cents')->nullable()->after('msrp_cents');

            $table->index('product_key', 'pdc_product_key_idx');
            $table->index(['distributor_code', 'distributor_variant_no'], 'pdc_dist_variant_idx');
        });

        Schema::table('platform_distributor_catalogs', function (Blueprint $table) {
            $table->dropUnique('pdc_dist_upc_unique');
            $table->unique(['distributor_code', 'distributor_variant_no'], 'pdc_dist_variant_unique');
        });
    }

    public function down(): void
    {
        Schema::table('platform_distributor_catalogs', function (Blueprint $table) {
            $table->dropUnique('pdc_dist_variant_unique');
            $table->unique(['distributor_code', 'upc'], 'pdc_dist_upc_unique');
        });

        Schema::table('platform_distributor_catalogs', function (Blueprint $table) {
            $table->dropIndex('pdc_product_key_idx');
            $table->dropIndex('pdc_dist_variant_idx');
            $table->dropColumn(['product_key', 'distributor_variant_no', 'map_cents']);
        });
    }
};
