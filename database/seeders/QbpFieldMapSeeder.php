<?php

// MARKER-QBP-MAP

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * QBP field map. Paths match what QbpClient::variant() actually emits: raw
 * element names from the XML, plus the flattened keys the adapter adds for
 * structures a dotted path cannot walk.
 *
 * No cost_cents on purpose — dealerPrice is the platform account's own price
 * and is stripped before rows reach the shared catalog. Cost arrives per
 * tenant through tier 2.
 */
class QbpFieldMapSeeder extends Seeder
{
    public function run(): void
    {
        $code = 'QBP';

        // [canonical_field, source_path, transform, args, lookup, notes]
        $rows = [
            // identity ---------------------------------------------------
            ['distributor_variant_no', 'sku', 'direct', null, null,
                'QBP SKU, e.g. BB1001 — the unique key and the EFTP order line id'],
            ['distributor_product_no', 'modelCode', 'direct', null, null,
                'model groups variants; adapter falls back to sku when absent'],
            ['manufacturer_sku', 'manufacturerPartNumber', 'direct', ['cast' => 'trim'], null, null],

            // barcodes arrive TYPED; FirstBarcode is the adapter's pick of
            // the first entry. Length cannot separate UPC from EAN (leading
            // zeros), so if QBP ships both types this becomes a lookup on
            // BarcodeList — for now every observed row carried a single Y3.
            ['upc', 'FirstBarcode', 'direct', null, null,
                'type Y3 observed = UPC; BarcodeList carries {type,value} if this needs refining'],

            // descriptive ------------------------------------------------
            ['name', 'name', 'direct', null, null,
                'carries real detail like BTI, unlike HLC bare names'],
            // MARKER-QBP-BULLETS — real copy, e.g. "The maximum allowable rim
            // thickness at the valve hole is 18mm". Adapter joins the bullets
            // with newlines; BulletPoints holds the array if a list render is
            // wanted later.
            ['description', 'Description', 'direct', null, null,
                'QBP bulletPoints, newline-joined by the adapter'],

            ['manufacturer', 'brand.description', 'direct', null, null,
                'match cross-distributor on THIS, not brand.id'],
            ['brand_id', 'brand.id', 'direct', ['cast' => 'string'], null,
                'QBP own code: Maxxis=DHN — never matches HLC/BTI ids'],

            // classification ---------------------------------------------
            ['category', 'CategoryName', 'direct', null, null,
                'leaf name, flattened by the adapter'],
            ['category_id', 'CategoryId', 'direct', ['cast' => 'string'], null, null],
            // Full path needs the category tree (flat, parent-linked, walked
            // in QbpClient::categories()); a product row only knows its leaf.
            // The sync joins that at write time the same way HLC's does not
            // need to (HLC ships a path per row). Leaf-only until then.
            ['category_path', 'CategoryName', 'direct', null, null,
                'leaf only — full path requires the walked tree, see QbpClient::categories()'],

            // attributes -------------------------------------------------
            ['attributes', 'Attributes', 'json_passthrough', null, null,
                'adapter flattens classifications 3 levels deep into {Name,Value,Code,Unit}; multiple featureValues joined'],

            // money — value is numeric; formattedValue is "$8.40" ---------
            ['msrp_cents', 'msrp.value', 'direct', ['cast' => 'cents'], null, null],
            ['map_cents', 'mapPrice.value', 'direct', ['cast' => 'cents_zero_null'], null,
                'observed equal to msrp on sample rows; zero means no MAP'],

            // physical ---------------------------------------------------
            ['weight', 'freight.Weight.value', 'direct', null, null, 'pounds'],
            ['dimensions', 'Dimensions', 'json_passthrough', null, null,
                'adapter flattens freight L/W/H (inches) — zip_pipe cannot, it zips pipe strings'],

            // media ------------------------------------------------------
            // File NAMES from API1. The files themselves are CLS/API3 —
            // fetched lazily when something displays them, never bulk.
            // MARKER-QBP-FIXES — ImageFiles (plural). ImageFile held only the
            // first, and before the multi-image fix it held none at all on
            // any product carrying more than one.
            ['images', 'ImageFiles', 'json_passthrough', null, null,
                'all file names; the binaries need the CLS licence'],

            // offerability ----------------------------------------------
            ['is_sellable', 'IsOfferable', 'bool', null, null,
                'adapter: NOT blocked AND NOT discontinued'],
            ['source_status_id', 'discontinued', 'direct', ['cast' => 'string'], null,
                'raw discontinued flag kept for review surfaces'],

            // logistics --------------------------------------------------
            ['hazmat_type', 'hazmat', 'direct', ['cast' => 'string'], null,
                'ormd also present on rows; both are shipping constraints'],
            ['uom', 'unit', 'direct', null, null, 'EA observed'],
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

        $this->command?->info('QbpFieldMapSeeder: seeded ' . count($rows) . ' QBP field maps.');
    }
}
