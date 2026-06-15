<?php
// MARKER-PATCH-HLC2

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columns backing the pricing-attention surface.
 *
 *  - catalog_map_cents: MAP mirrored from the catalog by the nightly sync, so
 *    the surface can flag "below MAP" without a join.
 *  - price_ack_at / price_ack_by: "I meant to price it this way." Clears an
 *    item from pricing attention until its MAP/MSRP changes again — the
 *    pressure valve for the off-MSRP bucket, which can run into the thousands.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_inventory_items', function (Blueprint $table) {
            $table->integer('catalog_map_cents')->nullable()->after('catalog_msrp_cents');
            $table->timestamp('price_ack_at')->nullable();
            $table->uuid('price_ack_by')->nullable();

            $table->index(['tenant_id', 'price_ack_at'], 'tii_tenant_priceack_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_inventory_items', function (Blueprint $table) {
            $table->dropIndex('tii_tenant_priceack_idx');
            $table->dropColumn(['catalog_map_cents', 'price_ack_at', 'price_ack_by']);
        });
    }
};
