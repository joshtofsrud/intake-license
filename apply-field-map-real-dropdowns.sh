#!/usr/bin/env bash
# apply-field-map-real-dropdowns.sh
# MARKER-FIELDMAP-PICKERS2 — make source path an actual dropdown, and stop
# calling it "canonical field" on screen.
#
# The previous patch left source path as a text box with suggestions because
# of two cases I thought a dropdown could not cover. Both are easy:
#
#   nested paths   — walk the feed row and emit dotted paths as options
#                    (CaseDimensions.Quantity), so they are IN the list
#   no path at all — coalesce and computed rows get an explicit
#                    "— none —" option rather than an empty text box
#
# Also relabels canonical_field. The column keeps its name; the FORM says
# "Intake field", and every option reads in plain language — "Product name
# (name)" rather than "name" — because the whole point of the list is that
# you should not need to already know what the column is called.
#
# Runs whether or not the earlier picker patch was applied.
set -e

python3 <<'PY'
import io, re

HELPERS_SRC = """    /**
     * MARKER-FIELDMAP-PICKERS2 — codes that already have maps, plus every code
     * the registry supports, so a newly registered adapter is selectable
     * before it has a single mapping row.
     *
     * @return array<string,string>
     */
    public static function distributorOptions(): array
    {
        $used = \\App\\Models\\DistributorFieldMap::query()
            ->select('distributor_code')->distinct()->pluck('distributor_code')->all();

        $supported = [];
        try { $supported = app(\\App\\Services\\Distributors\\DistributorRegistry::class)->supported(); }
        catch (\\Throwable) {}

        return collect($used)->merge(array_map('strtoupper', (array) $supported))
            ->map(fn ($c) => strtoupper((string) $c))->filter()->unique()->sort()->values()
            ->mapWithKeys(fn ($c) => [$c => $c])->all();
    }

    /**
     * MARKER-FIELDMAP-PICKERS2 — the Intake fields a map may fill, labelled in
     * plain language. The list is read from the model's fillable so it cannot
     * drift from the schema; the labels are ours, because the column name told
     * you what it is called and nothing about what it does.
     *
     * @return array<string,string>
     */
    public static function canonicalFieldOptions(): array
    {
        // Filled by the sync itself — offering them invites a map that is
        // silently overwritten on the next run.
        $owned = [
            'source_raw', 'display_name', 'display_subtitle', 'search_text',
            'distributor_name', 'last_synced_at', 'is_active',
            'prev_cost_cents', 'prev_map_cents', 'prev_msrp_cents',
            'cost_cents',
        ];

        $labels = [
            'name' => 'Product name', 'manufacturer' => 'Brand',
            'manufacturer_sku' => 'Manufacturer part number',
            'brand_id' => 'Brand id (distributor own)',
            'upc' => 'UPC barcode', 'ean' => 'EAN barcode',
            'product_key' => 'Grouping key (dedupe identity)',
            'distributor_product_no' => 'Distributor product number',
            'distributor_variant_no' => 'Distributor variant number',
            'description' => 'Description', 'category' => 'Category',
            'category_id' => 'Category id',
            'category_path' => 'Category path (Tires > Mountain)',
            'attributes' => 'Attributes (name/value pairs)',
            'images' => 'Images', 'image_urls' => 'Image URLs',
            'msrp_cents' => 'MSRP', 'map_cents' => 'MAP (minimum advertised price)',
            'alt_prices' => 'Other prices', 'uom' => 'Unit of measure',
            'case_quantity' => 'Case quantity', 'weight' => 'Weight',
            'dimensions' => 'Dimensions', 'item_group' => 'Item group',
            'size_id' => 'Size', 'color_id' => 'Colour', 'config' => 'Configuration',
            'taxable' => 'Taxable', 'is_sellable' => 'Sellable',
            'canonical_status' => 'Status',
            'source_status_id' => 'Status code (distributor own)',
            'source_status_label' => 'Status label (distributor own)',
            'source_modified_at' => 'Last changed at source',
            'ground_only' => 'Ground shipping only', 'hazmat_type' => 'Hazmat type',
            'freight_class' => 'Freight class',
            'dropship_fulfillable' => 'Dropship available',
        ];

        return collect((new \\App\\Models\\PlatformDistributorCatalog())->getFillable())
            ->reject(fn ($f) => in_array($f, $owned, true))
            ->sort()->values()
            ->mapWithKeys(fn ($f) => [$f => ($labels[$f] ?? $f) . '  (' . $f . ')'])
            ->all();
    }

    /**
     * MARKER-FIELDMAP-PICKERS2 — the feed's own columns, as a real list.
     *
     * A distributor's column names are written down nowhere in Intake except
     * inside the rows they produced, so this reads source_raw — the untouched
     * feed row kept on every catalog row — and walks it.
     *
     * Nested objects are flattened to dotted paths (CaseDimensions.Quantity)
     * so they appear as options rather than forcing a typed guess. Lists are
     * NOT walked: a map targets `Prices` as a whole and lets a transform pick
     * from it, so Prices.0.Amount would be a misleading thing to offer.
     *
     * @return array<string,string>
     */
    public static function sourcePathOptions(string $code): array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return ['' => '- none (coalesce or computed) -'];
        }

        static $cache = [];
        if (isset($cache[$code])) {
            return $cache[$code];
        }

        $rows = \\App\\Models\\PlatformDistributorCatalog::query()
            ->where('distributor_code', $code)
            ->whereNotNull('source_raw')
            ->latest('last_synced_at')
            ->limit(25)
            ->pluck('source_raw');

        $paths = [];

        $walk = function (array $arr, string $prefix, int $depth) use (&$walk, &$paths): void {
            if ($depth > 3) { return; }
            foreach ($arr as $k => $v) {
                if (is_int($k)) { continue; }
                $path = $prefix === '' ? (string) $k : $prefix . '.' . $k;
                $paths[$path] = true;
                if (is_array($v) && $v !== [] && ! array_is_list($v)) {
                    $walk($v, $path, $depth + 1);
                }
            }
        };

        foreach ($rows as $raw) {
            $arr = is_string($raw) ? json_decode($raw, true) : $raw;
            if (is_array($arr)) { $walk($arr, '', 1); }
        }

        ksort($paths);

        $out = ['' => '- none (coalesce or computed) -'];
        foreach (array_keys($paths) as $path) { $out[$path] = $path; }

        return $cache[$code] = $out;
    }

"""


p = 'app/Filament/Resources/DistributorFieldMapResource.php'
s = io.open(p, encoding='utf-8').read()

# This patch rewrites the form from its ORIGINAL shape. Running it on top of
# the earlier picker patch consumes the whole form and table section and
# leaves a class with neither — which parses fine and renders blank pages.
assert 'FIELDMAP-PICKERS' not in s, (
    'This file already carries a picker patch. Restore it from git first:\n'
    '  git log --oneline -6 -- app/Filament/Resources/DistributorFieldMapResource.php\n'
    '  git checkout <sha-before-the-pickers> -- app/Filament/Resources/DistributorFieldMapResource.php'
)

# ---------------------------------------------------------------- source path
# Two possible current states: the original TextInput, or the datalist version
# from the first picker patch.
new_select = """                        // MARKER-FIELDMAP-PICKERS2 — a real dropdown. Nested
                        // paths are flattened into the options, and rows with
                        // no path (coalesce, computed) pick "none" explicitly
                        // instead of being left to guess at an empty box.
                        Forms\\Components\\Select::make('source_path')
                            ->label('Feed column')
                            ->native(false)->searchable()
                            ->options(fn (Forms\\Get $get) => self::sourcePathOptions((string) $get('distributor_code')))
                            ->helperText('Columns seen in this distributor\\'s feed'),"""

patterns = [
    # state A — the datalist version
    re.compile(r"[ \t]*//[^\n]*TextInput with a datalist.*?Forms\\Components\\TextInput::make\('source_path'\).*?->helperText\([^\n]*\),", re.S),
    # state B — the original
    re.compile(r"[ \t]*Forms\\Components\\TextInput::make\('source_path'\)\s*\n\s*->maxLength\(255\)\s*\n\s*->helperText\([^\n]*\),", re.S),
]
done = False
for pat in patterns:
    if pat.search(s):
        s = pat.sub(lambda m: new_select, s, count=1)
        done = True
        break
assert done, 'could not find the source_path field in either known state'

# distributor_code must be live, or the feed-column list never refreshes when
# you switch distributor — the options are a function of it.
old_dist = """                        Forms\\Components\\TextInput::make('distributor_code')
                            ->required()->maxLength(32)
                            ->default('HLC')
                            ->helperText('e.g. HLC, QBP'),"""
if old_dist in s:
    s = s.replace(old_dist, """                        Forms\\Components\\Select::make('distributor_code')
                            ->label('Distributor')
                            ->required()->native(false)->searchable()
                            ->options(fn () => self::distributorOptions())
                            ->default('HLC')
                            ->live(),""")

# ---------------------------------------------------------------- label
if "Select::make('canonical_field')" in s:
    s = s.replace("""                        Forms\\Components\\Select::make('canonical_field')
                            ->required()->native(false)->searchable()
                            ->options(fn () => self::canonicalFieldOptions())
                            ->helperText('The Intake column this fills'),""",
"""                        Forms\\Components\\Select::make('canonical_field')
                            ->label('Intake field')
                            ->required()->native(false)->searchable()
                            ->options(fn () => self::canonicalFieldOptions())
                            ->helperText('What this becomes inside Intake'),""")
else:
    old = """                        Forms\\Components\\TextInput::make('canonical_field')
                            ->required()->maxLength(64)
                            ->helperText('Intake column, e.g. cost_cents'),"""
    assert s.count(old) == 1, 'canonical_field field not found'
    s = s.replace(old, """                        Forms\\Components\\Select::make('canonical_field')
                            ->label('Intake field')
                            ->required()->native(false)->searchable()
                            ->options(fn () => self::canonicalFieldOptions())
                            ->helperText('What this becomes inside Intake'),""")

# ---------------------------------------------------------------- helpers
# Strip any existing versions of the three helpers, then insert fresh ones.
# This runs identically whether or not the earlier picker patch was applied.
for fn in ['distributorOptions', 'canonicalFieldOptions', 'sourcePathOptions']:
    m = re.search(r"    /\*\*.*?\*/\n    public static function " + fn + r"\([^)]*\): array\s*\n    \{.*?\n    \}\n\n?", s, re.S)
    if not m:
        m = re.search(r"    public static function " + fn + r"\([^)]*\): array\s*\n    \{.*?\n    \}\n\n?", s, re.S)
    if m:
        s = s.replace(m.group(0), "")

HELPERS = HELPERS_SRC

anchor = "    public static function getPages(): array"
assert s.count(anchor) == 1, "getPages anchor"
s = s.replace(anchor, HELPERS + anchor)

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo
echo "--- source path is a Select, canonical relabelled ---"
grep -n "Select::make('source_path')\|Select::make('canonical_field')\|->label('Intake field')\|->label('Feed column')\|none (coalesce" app/Filament/Resources/DistributorFieldMapResource.php

echo
echo "--- no TextInput left on either field ---"
grep -n "TextInput::make('source_path')\|TextInput::make('canonical_field')" app/Filament/Resources/DistributorFieldMapResource.php || echo "  none — both are selects"

echo
echo "--- balance ---"
python3 - <<'PY'
import io
s = io.open('app/Filament/Resources/DistributorFieldMapResource.php', encoding='utf-8').read()
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
print('braces', d, 'parens', par, 'brackets', brk)
PY

echo
echo "apply-field-map-real-dropdowns: OK"
