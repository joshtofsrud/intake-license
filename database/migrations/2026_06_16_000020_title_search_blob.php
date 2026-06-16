<?php
// MARKER-PATCH-HLCA — search blob column + search template + chosen defaults.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('platform_distributor_catalogs', 'search_text')) {
            Schema::table('platform_distributor_catalogs', function (Blueprint $t) {
                $t->text('search_text')->nullable()->after('display_subtitle');
            });
        }
        if (! Schema::hasColumn('catalog_title_settings', 'search_template')) {
            Schema::table('catalog_title_settings', function (Blueprint $t) {
                $t->string('search_template', 255)
                  ->default('{mpn} {brand} {model} {type0} {unit} {size} {color} {allattr}')
                  ->after('subtitle_template');
            });
        }

        // Chosen defaults on the global '*' settings row.
        $title = '{brand} {model} {size} {color} {unit} {type0}';
        $sub   = '{mpn}';
        $search = '{mpn} {brand} {model} {type0} {unit} {size} {color} {allattr}';
        if (DB::table('catalog_title_settings')->where('distributor_code', '*')->exists()) {
            DB::table('catalog_title_settings')->where('distributor_code', '*')->update([
                'title_template' => $title, 'subtitle_template' => $sub,
                'search_template' => $search, 'updated_at' => now(),
            ]);
        } else {
            DB::table('catalog_title_settings')->insert([
                'distributor_code' => '*', 'title_template' => $title,
                'subtitle_template' => $sub, 'search_template' => $search,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('platform_distributor_catalogs', 'search_text')) {
            Schema::table('platform_distributor_catalogs', fn (Blueprint $t) => $t->dropColumn('search_text'));
        }
        if (Schema::hasColumn('catalog_title_settings', 'search_template')) {
            Schema::table('catalog_title_settings', fn (Blueprint $t) => $t->dropColumn('search_template'));
        }
    }
};
