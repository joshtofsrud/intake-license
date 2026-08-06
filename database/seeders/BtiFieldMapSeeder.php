<?php

// MARKER-BTI-ADAPTER

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * BTI field map. Column names are identical in the CSV and JSON feeds, so
 * these paths serve both.
 *
 * updateOrInsert on (distributor_code, canonical_field) — safe to re-run and
 * it won't clobber master-admin edits to fields it doesn't list.
 */
class BtiFieldMapSeeder extends Seeder
{
    public function run(): void
    {
        $code = 'BTI';

        // [canonical_field, source_path, transform, args, lookup, notes]
        $rows = [
            // identity
            ['distributor_variant_no', 'id', 'direct', null, null, 'BTI item number — the unique key'],
            ['distributor_product_no', 'group_id', 'direct', null, null, 'groups variants'],
            ['upc', 'upc', 'direct', null, null, null],
            ['ean', 'ean', 'direct', null, null, null],
            ['manufacturer_sku', 'vendor_item_id', 'direct', ['cast' => 'trim'], null, 'ships with a leading space'],

            // descriptive
            ['name', 'item_description', 'direct', null, null, null],
            ['description', 'group_text', 'direct', null, null, 'real marketing copy, unlike HLC'],
            ['manufacturer', 'manufacturer_name', 'direct', null, null, null],
            ['brand_id', 'manufacturer_id', 'direct', ['cast' => 'string'], null, null],

            // classification — two levels, flattened to a path
            ['category', 'sub_category_name', 'direct', null, null, 'leaf'],
            ['category_id', 'sub_category_id', 'direct', ['cast' => 'string'], null, null],
            ['category_path', null, 'coalesce', ['order' => [
                ['concat' => ['category_name', 'sub_category_name'], 'sep' => ' > '],
            ]], null, 'single-spec coalesce == always concat'],

            // attributes — two parallel pipe strings zipped into {Name,Value}
            ['attributes', null, 'zip_pipe', [
                'keys'   => 'attribute_keys',
                'values' => 'attribute_values',
                'sep'    => '|',
            ], null, 'Model|Color|Size + Snapback Hat|Gray|One Size'],

            // money
            ['cost_cents', 'your_price', 'direct', ['cast' => 'cents'], null, 'dealer cost'],
            ['msrp_cents', 'msrp', 'direct', ['cast' => 'cents'], null, null],
            ['map_cents', 'map', 'direct', ['cast' => 'cents_zero_null'], null, '0.0 means NO MAP'],

            // media
            // MARKER-SOURCING-PLACEMENT — the column is `images`, not
            // `image_urls`. The resolver silently drops canonical fields that
            // aren't columns, so every BTI row imported with no images.
            ['images', 'image_paths', 'split_pipe', [
                'sep'    => '|',
                // MARKER-BTI-IMAGE-BASE — /images 404s; photos are under
                // /images/pictures. Verified: bti-usa.com/images/pictures/ma/ma3512a.jpg
                'prefix' => 'https://bti-usa.com/images/pictures',
            ], null, 'relative paths need a host'],
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

        $this->command?->info('BtiFieldMapSeeder: seeded ' . count($rows) . ' BTI field maps.');
    }
}
