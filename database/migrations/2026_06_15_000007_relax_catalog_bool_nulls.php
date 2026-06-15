<?php
// MARKER-PATCH-HLC3

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The canonical boolean facts may legitimately be ABSENT from a distributor's
 * feed. Null means "distributor didn't specify" — distinct from an asserted
 * true/false. The resolver returns null in that case; these columns must allow
 * it rather than forcing a default that fabricates a fact. Defaults are kept
 * for rows that don't set the column at all.
 *
 * Also relaxes `upc`: the base table made it NOT NULL, but UPC is frequently
 * absent (every Maxxis variant carries EAN instead). Without this, those items
 * fail to insert on first sync. product_key (UPC->EAN->brand+MPN) is the real
 * grouping key; upc is just one optional barcode.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_distributor_catalogs', function (Blueprint $table) {
            $table->string('upc', 20)->nullable()->change();
            $table->boolean('taxable')->nullable()->default(true)->change();
            $table->boolean('ground_only')->nullable()->default(false)->change();
            $table->boolean('dropship_fulfillable')->nullable()->default(false)->change();
            $table->boolean('is_sellable')->nullable()->default(true)->change();
        });
    }

    public function down(): void
    {
        // upc stays nullable on rollback; only the booleans revert.
        Schema::table('platform_distributor_catalogs', function (Blueprint $table) {
            $table->boolean('taxable')->default(true)->change();
            $table->boolean('ground_only')->default(false)->change();
            $table->boolean('dropship_fulfillable')->default(false)->change();
            $table->boolean('is_sellable')->default(true)->change();
        });
    }
};
