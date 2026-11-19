<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * MARKER-BRAND-BUILDER: the field map a brand added from master admin starts
 * with, matching what the general store reader writes. Rows already there are
 * left alone; everything is editable in Field Mapping afterwards.
 */
class BrandFieldMapTemplate
{
    public static function seed(string $code, string $label): int
    {
        $code = strtoupper($code);
        $rows = [
            ['distributor_variant_no', 'VariantNo', 'direct', ['cast' => 'string'], null, 'the part number (the store\'s SKU), the same as in the brand\'s stock file'],
            ['distributor_product_no', 'handle', 'direct', ['cast' => 'string'], null, 'one product on the brand\'s store'],
            ['manufacturer_sku', 'sku', 'direct', ['cast' => 'trim'], null, 'the brand is the maker, so its part number is the MPN'],
            ['name', 'title', 'direct', null, null, 'the product name on the store; titles come from Title control'],
            ['description', 'description', 'direct', null, null, 'the product description'],
            ['manufacturer', null, 'fixed', ['value' => $label], null, 'the brand, whatever the store calls its vendor'],
            ['category', 'category', 'direct', null, null, 'the kind (a tag listed under Kinds), else the product type'],
            ['category_id', 'category_path', 'direct', ['cast' => 'string'], null, 'no category ids on a store; the path is the id'],
            ['category_path', 'category_path', 'direct', null, null, 'product type > kind'],
            ['attributes', 'specs', 'json_passthrough', null, null, 'model year from the tags'],
            ['color', 'color', 'direct', null, null, 'the color option'],
            ['size', 'size', 'direct', null, null, 'the size option'],
            ['images', 'images', 'json_passthrough', null, null, 'photos from the store'],
            ['msrp_cents', 'retail', 'direct', ['cast' => 'cents'], null, 'retail price on the store'],
        ];
        $cleanup = [
            'size' => ['blank_values' => ['Default Title']],
            'color' => ['blank_values' => ['Default Title']],
        ];
        $now = now();
        $n = 0;
        foreach ($rows as [$field, $path, $transform, $args, $lookup, $notes]) {
            if (DB::table('distributor_field_maps')->where('distributor_code', $code)->where('canonical_field', $field)->exists()) {
                continue;
            }
            DB::table('distributor_field_maps')->insert([
                'id' => (string) Str::uuid(), 'distributor_code' => $code, 'canonical_field' => $field,
                'source_path' => $path, 'transform' => $transform,
                'transform_args' => $args ? json_encode($args) : null, 'lookup_table' => $lookup ? json_encode($lookup) : null,
                'cleanup' => isset($cleanup[$field]) ? json_encode($cleanup[$field]) : null,
                'notes' => $notes, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $n++;
        }
        return $n;
    }
}
