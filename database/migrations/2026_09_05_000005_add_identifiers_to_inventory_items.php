<?php
// MARKER-ITEM-IDENTIFIERS

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_inventory_items', function (Blueprint $table) {
            $table->string('catalog_ean', 32)->nullable()->after('catalog_upc');
            $table->string('catalog_mpn', 64)->nullable()->after('catalog_ean');
            $table->index(['tenant_id', 'catalog_ean'], 'tii_tenant_ean_idx');
            $table->index(['tenant_id', 'catalog_mpn'], 'tii_tenant_mpn_idx');
        });

        // Backfill from the catalog row each item is already linked to. Without
        // this only newly imported items would be findable, which is the
        // opposite of the problem being solved.
        $n = DB::update("
            UPDATE tenant_inventory_items i
            JOIN platform_distributor_catalogs c ON c.id = i.distributor_catalog_id
            SET i.catalog_ean = NULLIF(TRIM(COALESCE(c.ean, '')), ''),
                i.catalog_mpn = NULLIF(TRIM(COALESCE(c.manufacturer_sku, '')), '')
            WHERE i.distributor_catalog_id IS NOT NULL
        ");

        Log::info("MARKER-ITEM-IDENTIFIERS: backfilled EAN/MPN on {$n} item(s)");
    }

    public function down(): void
    {
        Schema::table('tenant_inventory_items', function (Blueprint $table) {
            $table->dropIndex('tii_tenant_ean_idx');
            $table->dropIndex('tii_tenant_mpn_idx');
            $table->dropColumn(['catalog_ean', 'catalog_mpn']);
        });
    }
};
