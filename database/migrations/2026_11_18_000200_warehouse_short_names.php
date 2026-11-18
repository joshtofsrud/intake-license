<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A short label per supplier warehouse ("Q-Central", "FL"), set in master
 * admin and shown wherever a shop sees stock by warehouse. Blank means the
 * label is worked out from the feed's name (DistributionWarehouse::label()).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('platform_distribution_warehouses') && ! Schema::hasColumn('platform_distribution_warehouses', 'short_name')) {
            Schema::table('platform_distribution_warehouses', function (Blueprint $t) {
                $t->string('short_name', 24)->nullable()->after('name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('platform_distribution_warehouses', 'short_name')) {
            Schema::table('platform_distribution_warehouses', fn (Blueprint $t) => $t->dropColumn('short_name'));
        }
    }
};
