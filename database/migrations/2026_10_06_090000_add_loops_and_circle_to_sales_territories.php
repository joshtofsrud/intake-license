<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * a territory is any mix of loops, states (with the
 * optional latitude band, which the Modus contract needs for northern
 * California and Nevada) and one map circle (center + miles).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_territories', function (Blueprint $t) {
            if (! Schema::hasColumn('sales_territories', 'loops'))        $t->json('loops')->nullable()->after('states');
            if (! Schema::hasColumn('sales_territories', 'center_label')) $t->string('center_label', 191)->nullable()->after('loops');
            if (! Schema::hasColumn('sales_territories', 'center_lat'))   $t->decimal('center_lat', 10, 6)->nullable()->after('center_label');
            if (! Schema::hasColumn('sales_territories', 'center_lng'))   $t->decimal('center_lng', 10, 6)->nullable()->after('center_lat');
            if (! Schema::hasColumn('sales_territories', 'radius_miles')) $t->unsignedSmallInteger('radius_miles')->nullable()->after('center_lng');
        });
        // states is optional now (a loop- or circle-only territory has none)
        Schema::table('sales_territories', fn (Blueprint $t) => $t->json('states')->nullable()->change());
    }

    public function down(): void
    {
        Schema::table('sales_territories', function (Blueprint $t) {
            $t->dropColumn(['loops', 'center_label', 'center_lat', 'center_lng', 'radius_miles']);
        });
    }
};
