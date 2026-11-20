<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** MARKER-POS-XSERIES-FAMILIES: a POS whose products are written through a separate API version. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('platform_pos_adapters', 'products_api_base')) {
            Schema::table('platform_pos_adapters', function (Blueprint $t) {
                $t->string('products_api_base', 300)->nullable()->after('api_base');
            });
        }
    }

    public function down(): void
    {
        Schema::table('platform_pos_adapters', function (Blueprint $t) {
            $t->dropColumn('products_api_base');
        });
    }
};
