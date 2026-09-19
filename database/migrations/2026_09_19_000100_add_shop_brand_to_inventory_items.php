<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-IMPORT-MPN-BRAND — a brand the shop owns.
 *
 * Brand has only ever come from a linked distributor catalog row, so an item
 * typed in by hand or loaded from a CSV had none — and sorted last under
 * "Brand A–Z". shop_brand is the tenant's own value and wins over the catalog
 * one, the same way shop_cost_cents relates to catalog_cost_cents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_inventory_items', function (Blueprint $t) {
            $t->string('shop_brand', 128)->nullable()->after('catalog_mpn');
            $t->index(['tenant_id', 'shop_brand'], 'tii_tenant_shop_brand_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_inventory_items', function (Blueprint $t) {
            $t->dropIndex('tii_tenant_shop_brand_idx');
            $t->dropColumn('shop_brand');
        });
    }
};
