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
    private const COUNTRY = [
        'CN' => 'China', 'TW' => 'Taiwan', 'MY' => 'Malaysia', 'VN' => 'Vietnam', 'JP' => 'Japan',
        'ID' => 'Indonesia', 'TH' => 'Thailand', 'KH' => 'Cambodia', 'IN' => 'India', 'PH' => 'Philippines',
        'KR' => 'South Korea', 'SG' => 'Singapore', 'US' => 'United States', 'MX' => 'Mexico',
        'DE' => 'Germany', 'IT' => 'Italy', 'FR' => 'France', 'CH' => 'Switzerland', 'PT' => 'Portugal',
        'ES' => 'Spain', 'GB' => 'United Kingdom', 'CZ' => 'Czechia', 'SK' => 'Slovakia', 'HU' => 'Hungary',
    ];

    /**
     * MARKER-FIELD-CLEANUP: Shimano's starting clean-up, per field. Every value
     * here is editable in master admin (Field maps → the row → Clean-up); it
     * is only written where a row has no clean-up yet.
     *
     * @return array<string,array>
     */
    public static function cleanup(): array
    {
        $category = [
            'replace'    => [
                ['pattern' => '^(HG|LSG|PRO|LAZER|MOTOREX|SHIMANO)_', 'with' => ''],   // family prefix
                ['pattern' => '_', 'with' => ' '],
            ],
            'value_case' => 'first_upper',
        ];

        $values = ['X' => 'Yes', 'Gender: Unisex' => ''];
        foreach (['Shimano', 'PRO', 'Lazer', 'Motorex'] as $b) {
            $values['Riding: ' . $b] = '';   // the brand in the sport column says nothing
        }
        foreach (self::COUNTRY as $code => $name) {
            $values['Made in: ' . $code] = $name;
        }

        return [
            'color'         => ['blank_values' => ['ONE', 'NONE', 'N/A']],
            'size'          => ['blank_values' => ['ONE', 'One size', 'OS', 'N/A']],
            'category'      => $category,
            'category_path' => $category,
            'attributes'    => [
                'label_words' => ['Axel' => 'Axle'],
                'label_case'  => 'sentence',
                'drop_labels' => ['Color', 'Series', 'Generation'],
                'value_map'   => $values,
                'value_case'  => 'title_to_sentence',
            ],
        ];
    }

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
                // a row already there may have been edited in master admin:
                // leave it, and only give it clean-up if it has none
                if (empty($existing->cleanup) && isset(self::cleanup()[$field])) {
                    DB::table('distributor_field_maps')->where('id', $existing->id)
                        ->update(['cleanup' => json_encode(self::cleanup()[$field]), 'updated_at' => $now]);
                }
            } else {
                if (isset(self::cleanup()[$field])) {
                    $payload['cleanup'] = json_encode(self::cleanup()[$field]);
                }
                $payload['id'] = (string) Str::uuid();
                $payload['distributor_code'] = $code;
                $payload['canonical_field'] = $field;
                $payload['created_at'] = $now;
                DB::table('distributor_field_maps')->insert($payload);
            }
        }

        $this->command?->info('ShimanoFieldMapSeeder: added any missing Shimano field maps; rows already there were left as they are.');
    }
}
