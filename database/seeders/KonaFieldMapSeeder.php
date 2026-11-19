<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Kona field map. Source paths are the keys KonaClient writes into each
 * size's record (from Kona's product feed and product page), not Shopify's.
 *
 * updateOrInsert on (distributor_code, canonical_field): safe to re-run, and
 * it never overwrites a row already edited in master admin.
 */
class KonaFieldMapSeeder extends Seeder
{
    /** @return array<string,array> */
    public static function cleanup(): array
    {
        return [
            // Kona's spec labels are in capitals ("REAR SHOCK"); "n/a" means none
            'attributes' => ['label_case' => 'caps_to_sentence', 'blank_values' => ['n/a', 'na', '-']],
            // drop-bar sizes arrive as bare numbers ("48"); they are centimetres
            // "Default Title" is the store's placeholder for a product with one option, not a size
            'size'       => ['replace' => [['pattern' => '^(\d{2})$', 'with' => '$1cm']], 'blank_values' => ['Default Title']],
            // the store's product type is singular ("Bike")
            'category'      => ['value_map' => ['Bike' => 'Bikes']],
            'category_path' => ['replace' => [['pattern' => '^Bike(?= >|$)', 'with' => 'Bikes']]],
        ];
    }

    public function run(): void
    {
        $code = 'KONA';

        // [canonical_field, source_path, transform, args, lookup, notes]
        $rows = [
            ['distributor_variant_no', 'sku', 'direct', ['cast' => 'string'], null, "Kona's part number: the same as Inventory ID in a dealer's stock file"],
            ['distributor_product_no', 'handle', 'direct', ['cast' => 'string'], null, 'one product per bike and color on Kona\'s store'],
            ['manufacturer_sku', 'sku', 'direct', ['cast' => 'trim'], null, 'Kona is the maker, so its part number is the MPN'],

            ['name', 'title', 'direct', null, null, 'the model ("Rove AL"); titles come from Title control'],
            ['description', 'description', 'direct', null, null, 'the product page paragraph'],
            ['manufacturer', null, 'fixed', ['value' => 'Kona'], null, 'the feed says "Kona Bicycle Company USA"'],
            ['category', 'category', 'direct', null, null, 'Kona\'s riding category tag, else the product type'],
            ['category_id', 'category_path', 'direct', ['cast' => 'string'], null, 'Kona has no category ids; the path is the id'],
            ['category_path', 'category_path', 'direct', null, null, 'product type > riding category'],
            ['attributes', 'specs', 'json_passthrough', null, null, 'model year, then the spec table from the product page'],
            ['color', 'paint_color', 'direct', null, null, 'PAINT COLOR from the spec table'],
            ['size', 'size', 'direct', null, null, 'the size option'],
            ['images', 'images', 'json_passthrough', null, null, 'full-size photos from Kona\'s store'],

            // money: the store shows retail. Dealer cost comes from each shop's stock file.
            ['msrp_cents', 'retail', 'direct', ['cast' => 'cents'], null, 'retail price on Kona\'s store'],

            // no is_sellable: "available" on the brand's own web store means it has stock
            // to sell to the public, not whether a dealer can order it
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

        $this->command?->info('KonaFieldMapSeeder: added any missing Kona field maps; rows already there were left as they are.');
    }
}
