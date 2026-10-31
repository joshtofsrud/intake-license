<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-BRAND-NAMES: one brand, one spelling. Distributors spell brands
 * differently ("1st Endurance" at BTI, "First Endurance" at QBP); master
 * admin picks the spelling to use. The distributor's own spelling is kept in
 * manufacturer_raw so a pairing can be undone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('brand_aliases')) {
            Schema::create('brand_aliases', function (Blueprint $t) {
                $t->id();
                $t->string('alias_key', 191)->unique();   // the spelling, lower-cased and trimmed
                $t->string('alias_name', 191);            // as the distributor spells it
                $t->string('canonical_name', 191);        // the spelling every screen shows
                $t->string('created_by', 191)->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasColumn('platform_distributor_catalogs', 'manufacturer_raw')) {
            Schema::table('platform_distributor_catalogs', function (Blueprint $t) {
                $t->string('manufacturer_raw', 191)->nullable()->after('manufacturer');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_aliases');
        if (Schema::hasColumn('platform_distributor_catalogs', 'manufacturer_raw')) {
            Schema::table('platform_distributor_catalogs', fn (Blueprint $t) => $t->dropColumn('manufacturer_raw'));
        }
    }
};
