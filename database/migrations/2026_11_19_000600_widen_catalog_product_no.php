<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A brand's product id can be its web address (a store "handle"); some run
 * past 64 characters and failed to save. 191 stays within an indexable length.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('platform_distributor_catalogs', function (Blueprint $t) {
            $t->string('distributor_product_no', 191)->nullable()->change();
        });
    }

    public function down(): void
    {
        // left wide: narrowing would cut off values already saved
    }
};
