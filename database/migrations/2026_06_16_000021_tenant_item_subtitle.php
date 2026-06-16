<?php
// MARKER-PATCH-HLCC — display_subtitle on tenant items + expanded subtitle default.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('tenant_inventory_items', 'display_subtitle')) {
            Schema::table('tenant_inventory_items', function (Blueprint $t) {
                $t->text('display_subtitle')->nullable()->after('name');
            });
        }

        // The expanded descriptor — every distinguishing fact, readable.
        DB::table('catalog_title_settings')->where('distributor_code', '*')->update([
            'subtitle_template' => '{mpn} {brand} {model} {type0} {unit} {size} {color} {allattr}',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('tenant_inventory_items', 'display_subtitle')) {
            Schema::table('tenant_inventory_items', fn (Blueprint $t) => $t->dropColumn('display_subtitle'));
        }
        DB::table('catalog_title_settings')->where('distributor_code', '*')->update([
            'subtitle_template' => '{mpn}',
        ]);
    }
};
