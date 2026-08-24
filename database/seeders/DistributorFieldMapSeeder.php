<?php
// MARKER-PATCH-HLC3A

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds the HLC field map — every canonical field, with HLC's source paths and
 * transforms. This is the worked example the next distributor is mapped from.
 * updateOrInsert keyed on (distributor_code, canonical_field), so re-running is
 * safe and won't clobber master-admin edits to OTHER fields.
 */
class DistributorFieldMapSeeder extends Seeder
{
    public function run(): void
    {
        $code = 'HLC';

        // [canonical_field, source_path, transform, args, lookup, notes]
        $rows = [
            // identity / grouping
            ['distributor_variant_no', 'VariantNo', 'direct', null, null, 'distributor identity / unique key'],
            ['distributor_product_no', 'ProductNo', 'direct', null, null, 'groups variants within HLC'],
            ['distributor_variant_id', 'VariantId', 'direct', ['cast' => 'string'], null, 'numeric handle for order/cart API'],
            ['upc', 'UPC', 'direct', null, null, null],
            ['ean', 'EAN', 'direct', null, null, 'barcode when UPC is null'],
            ['manufacturer_sku', 'MFGPartNumber', 'direct', null, null, 'MPN'],
            ['product_key', null, 'coalesce', ['order' => [
                ['path' => 'UPC'],
                ['path' => 'EAN'],
                ['concat' => ['BrandId', 'MFGPartNumber'], 'sep' => '-'],
            ]], null, 'cross-distributor grouping: UPC -> EAN -> brand+MPN'],
            ['config', 'Config', 'direct', null, null, null],
            ['size_id', 'SizeId', 'direct', null, null, null],
            ['color_id', 'ColorId', 'direct', null, null, null],

            // descriptive / classification
            ['name', 'Name', 'direct', null, null, 'product short name'],
            ['description', 'Description', 'direct', null, null, 'variant description wins over product'],
            ['manufacturer', 'Brand', 'direct', null, null, null],
            ['brand_id', 'BrandId', 'direct', ['cast' => 'string'], null, null],
            ['category', 'Categories', 'pick_category_level', ['level' => 1, 'field' => 'CategoryName'], null, 'most specific level'],
            ['category_id', 'Categories', 'pick_category_level', ['level' => 1, 'field' => 'CategoryId', 'cast' => 'string'], null, null],
            ['category_path', 'Categories', 'join_array', ['field' => 'CategoryName', 'sep' => ' > ', 'sort_by' => 'Level'], null, 'full path'],
            ['item_group', 'ItemGroup', 'direct', null, null, null],
            ['taxable', 'Taxable', 'bool', null, null, null],

            // pricing (resolved through the map — never hardcoded)
            ['cost_cents', 'Prices', 'pick_from_array', ['match' => ['TypeId' => 0], 'field' => 'Amount', 'cast' => 'cents'], null, 'Base'],
            ['map_cents', 'Prices', 'pick_from_array', ['match' => ['TypeId' => 3], 'field' => 'Amount', 'cast' => 'cents'], null, 'MAP'],
            ['msrp_cents', 'Prices', 'pick_from_array', ['match' => ['TypeId' => 4], 'field' => 'Amount', 'cast' => 'cents'], null, 'MSRP'],
            ['alt_prices', 'Prices', 'json_passthrough', null, null, 'all price rows incl Program/Closeout + qty breaks'],
            ['case_quantity', 'CaseDimensions.Quantity', 'direct', ['cast' => 'int'], null, null],

            // media / physical
            ['images', 'Images', 'json_passthrough', null, null, 'hash-deduped on sync'],
            ['uom', 'UnitOfMesure', 'direct', null, null, null],
            ['weight', 'Dimensions.Weight', 'direct', ['cast' => 'float'], null, null],
            ['dimensions', 'Dimensions', 'json_passthrough', null, null, 'untrusted; stored'],
            ['ground_only', 'GroundOnly', 'bool', null, null, null],
            ['hazmat_type', 'HazmatType', 'direct', null, null, null],
            ['freight_class', 'ProductDimensionGroup', 'direct', null, null, null],

            // fulfillment
            ['dropship_fulfillable', 'CanBeFulFilled', 'bool', null, null, 'HLC dropships direct to customer'],

            // status
            ['source_status_id', 'StatusId', 'direct', ['cast' => 'string'], null, null],
            ['source_status_label', 'StatusDesc', 'direct', null, null, null],
            ['canonical_status', 'StatusId', 'lookup', ['default' => 'unknown'], ['7' => 'sellable', '9' => 'discontinued'], null],
            ['is_sellable', 'StatusId', 'lookup', ['default' => false, 'cast' => 'bool'], ['7' => true], 'only Active is sellable'],

            // open-ended specs + audit
            ['attributes', 'Attributes', 'json_passthrough', null, null, 'lossless; curate later'],
            // MARKER-PICK-ATTR — the NAMES. size_id/color_id above are HLC's
            // opaque codes, which the title templates use as tokens; these are
            // the human-readable values the item form shows.
            ['color', 'Attributes', 'pick_attribute', ['names' => ['Color', 'Colour', 'Primary Color']], null, null],
            ['size',  'Attributes', 'pick_attribute', ['names' => ['Size', 'Frame Size', 'Length']], null, null],
            ['source_modified_at', 'DateLastModified', 'direct', null, null, 'for delta sync'],
        ];

        $now = now();
        $order = 0;
        foreach ($rows as [$field, $path, $transform, $args, $lookup, $notes]) {
            $payload = [
                'source_path'    => $path,
                'transform'      => $transform,
                'transform_args' => $args !== null ? json_encode($args) : null,
                'lookup_table'   => $lookup !== null ? json_encode($lookup) : null,
                'sort_order'     => $order += 10,
                'is_active'      => true,
                'notes'          => $notes,
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

        $this->command?->info('DistributorFieldMapSeeder: seeded ' . count($rows) . ' HLC field maps.');
    }
}
