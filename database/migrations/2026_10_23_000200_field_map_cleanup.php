<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-FIELD-CLEANUP: clean-up options per field map row (renames, values
 * that mean "nothing", spec labels, which specs to hide), edited in master
 * admin and applied by the mapper for every distributor.
 *
 * Additive. Shimano's rows get their starting clean-up only where they have
 * none, so nothing set in admin is overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('distributor_field_maps', 'cleanup')) {
            Schema::table('distributor_field_maps', function (Blueprint $t) {
                $t->json('cleanup')->nullable();
            });
        }

        foreach (\Database\Seeders\ShimanoFieldMapSeeder::cleanup() as $field => $c) {
            DB::table('distributor_field_maps')
                ->where('distributor_code', 'SHIMANO')->where('canonical_field', $field)
                ->whereNull('cleanup')
                ->update(['cleanup' => json_encode($c), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('distributor_field_maps', 'cleanup')) {
            Schema::table('distributor_field_maps', fn (Blueprint $t) => $t->dropColumn('cleanup'));
        }
    }
};
