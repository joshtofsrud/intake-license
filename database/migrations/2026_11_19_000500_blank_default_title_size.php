<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Default Title" is a web store's placeholder for a product with one option.
 * It was read as a size. The brand's field map now blanks it (a clean-up rule,
 * editable in master admin); this clears the rows and items already written.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('platform_distributor_connections', 'brand_direct')) {
            return;
        }
        $codes = DB::table('platform_distributor_connections')->where('brand_direct', true)
            ->pluck('distributor_code')->map(fn ($c) => strtoupper((string) $c))->all();
        if (! $codes) {
            return;
        }

        foreach (DB::table('distributor_field_maps')->whereIn('distributor_code', $codes)->where('canonical_field', 'size')->get(['id', 'cleanup']) as $m) {
            $c = json_decode((string) $m->cleanup, true) ?: [];
            $blank = (array) ($c['blank_values'] ?? []);
            if (! in_array('Default Title', $blank, true)) {
                $c['blank_values'] = array_values(array_merge($blank, ['Default Title']));
                DB::table('distributor_field_maps')->where('id', $m->id)->update(['cleanup' => json_encode($c), 'updated_at' => now()]);
            }
        }

        $ids = DB::table('platform_distributor_catalogs')->whereIn('distributor_code', $codes)->where('size', 'Default Title')->pluck('id');
        foreach ($ids->chunk(1000) as $chunk) {
            DB::table('platform_distributor_catalogs')->whereIn('id', $chunk->all())->update(['size' => null]);
            DB::table('tenant_inventory_items')->whereIn('distributor_catalog_id', $chunk->all())->where('size', 'Default Title')->update(['size' => null]);
        }
    }

    public function down(): void
    {
    }
};
