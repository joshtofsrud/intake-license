<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Shimano field map. The source paths are the keys ShimanoClient writes into
 * each flattened record (not the spreadsheet's column names), so the same map
 * serves all five feed files.
 *
 * updateOrInsert on (distributor_code, canonical_field): safe to re-run, and
 * it will not clobber master-admin edits to fields it does not list.
 */
class ShimanoFieldMapSeeder extends Seeder
{
    public function run(): void
    {
        $code = 'SHIMANO';

        // [canonical_field, source_path, transform, args, lookup, notes]
        $rows = [
            // identity. The duplicate guard reads these: UPC and EAN are
            // split by digit count in the client, and the MPN is brand-qualified.
            ['distributor_variant_no', 'sku', 'direct', ['cast' => 'string'], null, 'SKU Code, the unique key'],
            ['distributor_product_no', 'product_code', 'direct', ['cast' => 'string'], null, 'Product Code groups the colour/size variants'],
            ['upc', 'upc', 'direct', null, null, '12-digit only'],
            ['ean', 'ean', 'direct', null, null, '13-digit only (Motorex ships these)'],
            ['manufacturer_sku', 'sku', 'direct', ['cast' => 'trim'], null, "Shimano's item number is the MPN"],

            // descriptive
            ['name', 'name', 'direct', null, null, 'SKU Name'],
            ['description', 'description', 'direct', null, null, 'headline + long text + features, HTML stripped'],
            ['manufacturer', 'brand', 'direct', null, null, 'Shimano, PRO, Lazer or Motorex'],
            ['category', 'category', 'direct', null, null, 'Product Type with the family prefix removed'],
            ['category_id', 'ptype', 'direct', ['cast' => 'string'], null, null],
            ['category_path', 'category', 'direct', null, null, 'one level in this feed'],
            ['color', 'color', 'direct', null, null, '"ONE" is dropped by the client'],
            ['size', 'size', 'direct', null, null, null],
            ['attributes', 'attrs', 'json_passthrough', null, null, 'every populated SKU / spec column as {Name,Value}'],

            // money
            ['cost_cents', 'cost', 'direct', ['cast' => 'cents'], null, 'Price USD MSWP, dealer cost'],
            ['msrp_cents', 'msrp', 'direct', ['cast' => 'cents'], null, 'Price USD MSRP'],
            ['map_cents', 'map_price', 'direct', ['cast' => 'cents_zero_null'], null, 'MSRP when SKU Map is Yes (meaning to be confirmed with Shimano)'],

            // physical
            ['weight', 'weight', 'direct', ['cast' => 'float'], null, null],
            ['dimensions', 'dimensions', 'json_passthrough', null, null, '[length, width, height]'],

            // media
            ['images', 'images', 'json_passthrough', null, null, 'primary, back, detail, lifestyle, medial, on body, sole, top'],
        ];

        $now = now();

        foreach ($rows as [$field, $path, $transform, $args, $lookup, $notes]) {
            $payload = [
                'source_path'    => $path,
                'transform'      => $transform,
                'transform_args' => $args ? json_encode($args) : null,
                'lookup_table'   => $lookup ? json_encode($lookup) : null,
                'notes'          => $notes,
                'is_active'      => true,
                'updated_at'     => $now,
            ];

            $existing = DB::table('distributor_field_maps')
                ->where('distributor_code', $code)
                ->where('canonical_field', $field)
                ->first();

            if ($existing) {
                DB::table('distributor_field_maps')->where('id', $existing->id)->update($payload);
            } else {
                $payload['id'] = (string) Str::uuid();
                $payload['distributor_code'] = $code;
                $payload['canonical_field'] = $field;
                $payload['created_at'] = $now;
                DB::table('distributor_field_maps')->insert($payload);
            }
        }

        $this->command?->info('ShimanoFieldMapSeeder: seeded ' . count($rows) . ' Shimano field maps.');
    }
}
