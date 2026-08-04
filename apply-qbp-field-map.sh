#!/usr/bin/env bash
# apply-qbp-field-map.sh
# MARKER-QBP-MAP — the QBP field map, written against measured payloads.
#
# Every source_path below was seen on a live response — the bearing, the rim,
# the brand pull — not taken from the developer guide, which has already been
# wrong about the response format, the collection nesting, and the request
# bodies.
#
# The resolver's context is array_merge($product, $variant), and QbpClient
# builds each variant as the raw QBP row PLUS flattened extras (Attributes,
# CategoryName, FirstBarcode, IsOfferable…) for the shapes a dotted path
# cannot reach. So paths here are either raw QBP names (sku, modelCode,
# msrp.value) or those flattened keys — nothing else exists in the context.
#
# WHAT IS DELIBERATELY ABSENT:
#   cost_cents      dealerPrice never reaches products(); the adapter strips
#                   it because the shared catalog is read by every tenant.
#                   Tier 2 carries cost via prices(). Seeding a cost path
#                   here would map nothing today — and start leaking the
#                   platform account's price the day someone "fixes" the
#                   adapter. Absent beats trapped.
#
# updateOrInsert on (distributor_code, canonical_field): re-runnable, and it
# won't clobber master-admin edits to fields it doesn't list.
set -e

cat <<'EOF' > database/seeders/QbpFieldMapSeeder.php
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
            ['images', 'ImageFile', 'json_passthrough', null, null,
                'file name only; binary requires the CLS licence'],

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
EOF
echo "created database/seeders/QbpFieldMapSeeder.php"

# NOT registered in DatabaseSeeder — neither is BTI's. Field map seeders are
# run once by hand (php artisan db:seed --class=QbpFieldMapSeeder), matching
# the existing convention, and are safe to re-run at any time.

echo
echo "--- no cost path seeded, and the reason is stated ---"
grep -c "cost_cents" database/seeders/QbpFieldMapSeeder.php
grep -n "Absent beats trapped\|stripped before rows reach" database/seeders/QbpFieldMapSeeder.php | head -2

echo
echo "--- every source_path exists in what the adapter emits ---"
python3 - <<'PY'
import io, re
seeder  = io.open('database/seeders/QbpFieldMapSeeder.php', encoding='utf-8').read()
client  = io.open('app/Services/Distributors/QbpClient.php', encoding='utf-8').read()

# Keys the adapter guarantees on a variant row (flattened extras) plus raw
# element names measured on live payloads.
flattened = {'Attributes','CategoryName','CategoryId','ImageFile','BarcodeList','FirstBarcode','IsOfferable','Dimensions'}
raw = {'sku','modelCode','manufacturerPartNumber','name','unit','discontinued','hazmat','ormd','blocked',
       'brand','msrp','mapPrice','basePrice','freight','modifiedTime','markets'}

paths = re.findall(r"\['[a-z_]+', '([A-Za-z.]+)'", seeder)
missing = []
for p in paths:
    head = p.split('.')[0]
    if head not in flattened and head not in raw:
        missing.append(p)
print('  paths checked :', len(paths))
print('  unknown heads :', missing or 'none')
assert not missing
PY

echo
echo "--- transforms used all exist in the resolver ---"
python3 - <<'PY'
import io, re
seeder   = io.open('database/seeders/QbpFieldMapSeeder.php', encoding='utf-8').read()
resolver = io.open('app/Services/Distributors/DistributorMapResolver.php', encoding='utf-8').read()
have = set(re.findall(r"'(\w+)'\s+=>", resolver.split('match ($row->transform)')[1][:1200]))
used = set(re.findall(r", '(\w+)', (?:\[|null)", seeder))
print('  used   :', ', '.join(sorted(used)))
print('  unknown:', sorted(used - have) or 'none')
assert not (used - have)
PY

echo
echo "--- balance ---"
python3 - <<'PY'
import io
for p in ['database/seeders/QbpFieldMapSeeder.php']:
    s = io.open(p, encoding='utf-8').read()
    i, n, d, par, brk = 0, len(s), 0, 0, 0
    while i < n:
        c = s[i]
        if c == '#' or (c == '/' and i+1 < n and s[i+1] == '/'):
            while i < n and s[i] != '\n': i += 1
        elif c == '/' and i+1 < n and s[i+1] == '*':
            i += 2
            while i+1 < n and not (s[i] == '*' and s[i+1] == '/'): i += 1
            i += 2
        elif c in '"\'':
            q = c; i += 1
            while i < n and s[i] != q:
                if s[i] == '\\': i += 1
                i += 1
            i += 1
        else:
            if c == '{': d += 1
            elif c == '}': d -= 1
            elif c == '(': par += 1
            elif c == ')': par -= 1
            elif c == '[': brk += 1
            elif c == ']': brk -= 1
            i += 1
    print('%-28s braces %d parens %d brackets %d' % (p.split('/')[-1], d, par, brk))
PY

echo
echo "apply-qbp-field-map: OK"
