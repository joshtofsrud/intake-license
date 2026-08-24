<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-CATALOG-COLORSIZE — the NAMES, not the codes.
 *
 * size_id / color_id already exist and hold HLC's opaque codes, which the
 * title templates use as tokens. These two hold the human-readable values
 * CatalogTitleComposer resolves out of the attributes bag, which had
 * nowhere to be stored and were therefore thrown away every sync.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('platform_distributor_catalogs', function (Blueprint $table) {
            $table->string('color', 60)->nullable()->after('color_id');
            $table->string('size', 60)->nullable()->after('color');
        });
    }

    public function down(): void
    {
        Schema::table('platform_distributor_catalogs', function (Blueprint $table) {
            $table->dropColumn(['color', 'size']);
        });
    }
};
