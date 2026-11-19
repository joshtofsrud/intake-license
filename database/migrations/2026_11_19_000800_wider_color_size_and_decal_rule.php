<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Paint names run past 60 characters ("... w/ ... Decals"), so color and size
 * widen to 191 in the catalog and on shops' items. The first brand's color
 * map gets a clean-up rule (a setting, editable in Field Mapping) that drops
 * the decal part from the color: "Matte Midnight w/ Blue-Grey Decals" is
 * "Matte Midnight". The full paint name stays in the product's specs.
 */
return new class extends Migration {
    public function up(): void
    {
        foreach (['platform_distributor_catalogs', 'tenant_inventory_items'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('color', 191)->nullable()->change();
                $t->string('size', 191)->nullable()->change();
            });
        }

        $row = DB::table('distributor_field_maps')->where('distributor_code', 'KONA')->where('canonical_field', 'color')->first();
        if ($row) {
            $c = json_decode((string) $row->cleanup, true) ?: [];
            $rule = ['pattern' => '\s*w/\s+.*\bdecals?\s*$', 'with' => ''];
            $replace = (array) ($c['replace'] ?? []);
            if (! in_array($rule['pattern'], array_column($replace, 'pattern'), true)) {
                $replace[] = $rule;
                $c['replace'] = $replace;
                DB::table('distributor_field_maps')->where('id', $row->id)->update(['cleanup' => json_encode($c), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
    }
};
