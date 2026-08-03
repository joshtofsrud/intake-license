#!/usr/bin/env bash
# apply-field-map-dropdowns.sh
# MARKER-FIELDMAP-PICKERS — you cannot edit a map you have to guess at.
#
# Three free-text boxes today: distributor code, canonical field, source path.
# The helper text gives one example each and leaves you to know the rest. A
# typo in canonical_field silently maps to a column that does not exist; a
# typo in source_path silently resolves to null on every row. Neither errors —
# the sync just writes a blank, which is exactly how BTI's cost sat null.
#
# All three lists already exist in the system:
#
#   distributor_code — the codes that actually have rows, plus every code the
#                      registry supports, so a new distributor appears the day
#                      its adapter registers rather than needing a typed guess.
#
#   canonical_field  — the columns the resolver is allowed to write, which is
#                      PlatformDistributorCatalog::$fillable minus the ones the
#                      sync sets itself (source_raw, display_name, timestamps).
#                      Reading it from the model means the list cannot drift
#                      from the schema.
#
#   source_path      — the KEYS OF THE FEED ITSELF. Every catalog row stores
#                      source_raw, the untouched row as the distributor sent
#                      it. Sampling recent rows for the selected distributor
#                      gives the real column names — item_description,
#                      attribute_keys, your_price — instead of a guess.
#
# Source path stays typeable as well as pickable, because nested paths
# (CaseDimensions.Quantity) and coalesce rows with no single path are both
# legitimate and neither appears as a top-level key.
#
# The picker reacts to the distributor field, so choosing BTI lists BTI's
# columns. Sampling is capped and cached per request — this is an admin form,
# not a hot path, but there is no reason to read a 25k-row table for a select.
set -e

python3 <<'PY'
import io

p = 'app/Filament/Resources/DistributorFieldMapResource.php'
s = io.open(p, encoding='utf-8').read()

old = """                    Forms\\Components\\Grid::make(3)->schema([
                        Forms\\Components\\TextInput::make('distributor_code')
                            ->required()->maxLength(32)
                            ->default('HLC')
                            ->helperText('e.g. HLC, QBP'),
                        Forms\\Components\\TextInput::make('canonical_field')
                            ->required()->maxLength(64)
                            ->helperText('Intake column, e.g. cost_cents'),
                        Forms\\Components\\TextInput::make('source_path')
                            ->maxLength(255)
                            ->helperText('Feed path, e.g. Prices or CaseDimensions.Quantity'),
                    ]),"""
assert s.count(old) == 1, 'F1 mapping grid anchor'
s = s.replace(old, """                    // MARKER-FIELDMAP-PICKERS — every one of these lists is
                    // derivable, so none of them should be typed from memory.
                    Forms\\Components\\Grid::make(3)->schema([
                        Forms\\Components\\Select::make('distributor_code')
                            ->required()->native(false)->searchable()
                            ->options(fn () => self::distributorOptions())
                            ->default('HLC')
                            ->live()
                            ->helperText('Codes in use, plus any the registry supports'),

                        Forms\\Components\\Select::make('canonical_field')
                            ->required()->native(false)->searchable()
                            ->options(fn () => self::canonicalFieldOptions())
                            ->helperText('The Intake column this fills'),

                        // TextInput with a datalist, NOT a Select: a Select can
                        // only return one of its options, and a nested path like
                        // CaseDimensions.Quantity never appears as a top-level
                        // key — nor does a coalesce row, which has no path at
                        // all. The datalist suggests the feed's real columns
                        // while still accepting anything typed.
                        Forms\\Components\\TextInput::make('source_path')
                            ->maxLength(255)
                            ->datalist(fn (Forms\\Get $get) => array_keys(
                                self::sourcePathOptions((string) $get('distributor_code'))
                            ))
                            ->helperText('Columns seen in this feed — or type a nested path, or leave blank for coalesce.'),
                    ]),""")

# the option builders
old = """            Forms\\Components\\Section::make('Transform args')"""
assert s.count(old) == 1, 'F2 args section anchor'
s = s.replace(old, """            Forms\\Components\\Section::make('Transform args')""")

# append helpers before the closing brace of the class
old = """    public static function getPages(): array"""
assert s.count(old) == 1, 'F3 getPages anchor'
s = s.replace(old, """    /**
     * MARKER-FIELDMAP-PICKERS — codes that already have maps, plus everything
     * the registry supports, so a newly registered adapter is selectable
     * before it has a single mapping row.
     *
     * @return array<string,string>
     */
    public static function distributorOptions(): array
    {
        $used = \\App\\Models\\DistributorFieldMap::query()
            ->select('distributor_code')->distinct()
            ->pluck('distributor_code')->all();

        $supported = [];
        try {
            $supported = app(\\App\\Services\\Distributors\\DistributorRegistry::class)->supported();
        } catch (\\Throwable) {
            // Registry unavailable in some contexts; the used list still works.
        }

        $codes = collect($used)
            ->merge(array_map('strtoupper', (array) $supported))
            ->map(fn ($c) => strtoupper((string) $c))
            ->filter()->unique()->sort()->values();

        return $codes->mapWithKeys(fn ($c) => [$c => $c])->all();
    }

    /**
     * The columns the resolver may write. Read from the model's fillable so it
     * cannot drift from the schema, minus the ones the sync fills itself —
     * offering those would invite a map that is silently overwritten.
     *
     * @return array<string,string>
     */
    public static function canonicalFieldOptions(): array
    {
        $owned = [
            'source_raw', 'display_name', 'display_subtitle', 'search_text',
            'distributor_name', 'last_synced_at', 'is_active',
            'prev_cost_cents', 'prev_map_cents', 'prev_msrp_cents',
            'cost_cents', // deliberately nulled at platform level; cost is per-tenant
        ];

        return collect((new \\App\\Models\\PlatformDistributorCatalog())->getFillable())
            ->reject(fn ($f) => in_array($f, $owned, true))
            ->sort()->values()
            ->mapWithKeys(fn ($f) => [$f => $f])
            ->all();
    }

    /**
     * The feed's own column names, read from source_raw on real rows.
     *
     * This is the list that was impossible to know: the distributor's columns
     * are not written down anywhere in Intake except inside the rows they
     * produced. Sampling a handful covers optional columns that a single row
     * might omit.
     *
     * @return array<string,string>
     */
    public static function sourcePathOptions(string $code): array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return [];
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

        $keys = [];
        foreach ($rows as $raw) {
            $arr = is_string($raw) ? json_decode($raw, true) : $raw;
            if (! is_array($arr)) {
                continue;
            }
            foreach (array_keys($arr) as $k) {
                $keys[(string) $k] = true;
            }
        }

        ksort($keys);

        return $cache[$code] = collect(array_keys($keys))
            ->mapWithKeys(fn ($k) => [$k => $k])
            ->all();
    }

    public static function getPages(): array""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo
echo "--- pickers wired ---"
grep -n "MARKER-FIELDMAP-PICKERS\|Select::make('distributor_code')\|Select::make('canonical_field')\|Select::make('source_path')\|public static function \(distributorOptions\|canonicalFieldOptions\|sourcePathOptions\)" app/Filament/Resources/DistributorFieldMapResource.php

echo
echo "--- registry actually exposes supported() ---"
grep -n "public function supported" app/Services/Distributors/DistributorRegistry.php

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
echo "apply-field-map-dropdowns: OK"
