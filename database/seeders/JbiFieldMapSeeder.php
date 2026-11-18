<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * J&B (JBI) field map. Source paths are the keys JbiClient writes into each
 * joined record (inv_mast + inv_bullets + inv_spec), not JBI's column names.
 *
 * updateOrInsert on (distributor_code, canonical_field): safe to re-run, and
 * it never overwrites a row already edited in master admin.
 */
class JbiFieldMapSeeder extends Seeder
{
    /**
     * JBI replaces every " and ' in its files with a backtick. After a digit
     * it was an inch mark (29` -> 29"); anywhere else an apostrophe
     * (Women`s -> Women's). Editable in master admin like any clean-up.
     */
    public const BACKTICKS = [
        ['pattern' => '(?<=\d)`', 'with' => '"'],
        ['pattern' => '`', 'with' => "'"],
    ];

    /** @return array<string,array> */
    public static function cleanup(): array
    {
        $text = ['replace' => self::BACKTICKS];
        $cats = ['replace' => self::BACKTICKS, 'value_case' => 'caps_to_sentence'];
        return [
            'name'          => $text,
            'description'   => $text,
            'category'      => $cats,
            'category_path' => $cats,
            'attributes'    => ['replace' => self::BACKTICKS, 'label_case' => 'sentence'],
            'color'         => $text,
            'size'          => $text,
        ];
    }

    public function run(): void
    {
        $code = 'JBI';

        // [canonical_field, source_path, transform, args, lookup, notes]
        $rows = [
            // identity. upc_ean is split by digit count in the client; the MPN
            // is the maker's part number, brand-qualified by the matcher.
            ['distributor_variant_no', 'item_id', 'direct', ['cast' => 'string'], null, 'J&B part number, the unique key'],
            ['distributor_product_no', 'product_code', 'direct', ['cast' => 'string'], null, 'JBI has no product groups: one product per part number'],
            ['upc', 'upc', 'direct', null, null, '12-digit values from upc_ean'],
            ['ean', 'ean', 'direct', null, null, '13-digit values from upc_ean'],
            ['manufacturer_sku', 'mpn', 'direct', ['cast' => 'trim'], null, 'supplier_part_no'],

            // descriptive
            ['name', 'name', 'direct', null, null, "inv_mast description: JBI's invoice wording; titles come from Title control"],
            ['description', 'description', 'direct', null, null, 'model bullets, then SKU bullets, one per line'],
            ['manufacturer', 'brand', 'direct', null, null, 'arrives in capitals; Brand names resolves it'],
            ['category', 'category', 'direct', null, null, 'deepest of cat_1..cat_4'],
            ['category_id', 'category_path', 'direct', ['cast' => 'string'], null, 'JBI has no category ids; the path is the id'],
            ['category_path', 'category_path', 'direct', null, null, 'cat_1 > cat_2 > cat_3 > cat_4'],
            ['attributes', 'attrs', 'json_passthrough', null, null, 'inv_spec title/spec pairs; values without a label are skipped and counted'],
            ['color', 'attrs', 'pick_attribute', ['names' => ['Color', 'Colour', 'Primary Color']], null, null],
            ['size', 'attrs', 'pick_attribute', ['names' => ['Size', 'Frame Size', 'Wheel Size', 'Length']], null, null],

            // money
            ['cost_cents', 'cost', 'direct', ['cast' => 'cents'], null, 'price: dealer each-price for this login'],
            ['msrp_cents', 'msrp', 'direct', ['cast' => 'cents'], null, null],
            ['map_cents', 'map_price', 'direct', ['cast' => 'cents_zero_null'], null, '.00 in the file means no MAP'],
            ['case_quantity', 'case_qty', 'direct', ['cast' => 'int'], null, null],
            ['uom', 'uom', 'direct', null, null, null],

            // physical and shipping
            ['weight', 'weight', 'direct', ['cast' => 'float'], null, 'packaged, lbs'],
            ['dimensions', 'dimensions', 'json_passthrough', null, null, '[length, width, height], packaged'],
            ['hazmat_type', 'hazmat', 'direct', null, null, 'ormd = Y'],
            ['dropship_fulfillable', 'dropship', 'bool', null, null, 'dropshipable'],

            // status: the file carries active SKUs only
            ['source_status_label', 'status', 'direct', null, null, null],
            ['canonical_status', 'status', 'lookup', ['default' => 'unknown'], ['active' => 'sellable'], null],
            ['is_sellable', 'status', 'lookup', ['default' => false, 'cast' => 'bool'], ['active' => true], null],
        ];

        $now = now();
        foreach ($rows as [$field, $path, $transform, $args, $lookup, $notes]) {
            $existing = DB::table('distributor_field_maps')
                ->where('distributor_code', $code)->where('canonical_field', $field)->first();

            if ($existing) {
                if (empty($existing->cleanup) && isset(self::cleanup()[$field])) {
                    DB::table('distributor_field_maps')->where('id', $existing->id)
                        ->update(['cleanup' => json_encode(self::cleanup()[$field]), 'updated_at' => $now]);
                }
                continue;
            }

            DB::table('distributor_field_maps')->insert([
                'id'               => (string) Str::uuid(),
                'distributor_code' => $code,
                'canonical_field'  => $field,
                'source_path'      => $path,
                'transform'        => $transform,
                'transform_args'   => $args ? json_encode($args) : null,
                'lookup_table'     => $lookup ? json_encode($lookup) : null,
                'cleanup'          => isset(self::cleanup()[$field]) ? json_encode(self::cleanup()[$field]) : null,
                'notes'            => $notes,
                'is_active'        => true,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);
        }

        $this->command?->info('JbiFieldMapSeeder: added any missing JBI field maps; rows already there were left as they are.');
    }
}
