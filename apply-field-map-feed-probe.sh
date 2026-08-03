#!/usr/bin/env bash
# apply-field-map-feed-probe.sh
# MARKER-FIELDMAP-PROBE — see the real feed values for one product, on the
# same screen where you write the mapping.
#
# Editing a map today is blind in the one way that matters: you can pick a
# feed column from the list, but you cannot see what is IN it. So you map
# something, run a sync, and find out later — which is exactly how BTI's cost
# sat null while the map looked perfectly reasonable.
#
# Type a UPC (or EAN, or part number) and the form shows every column that
# distributor sends for that product, its actual value, and — the part that
# makes this useful to hand to someone else — which Intake field each column
# is currently mapped to, or "not mapped".
#
# Built for a contractor to use without context: one identifier in, a full
# picture out, no need to know which columns exist or what is already done.
#
# The probe field is display-only. It is not a database column, so it is
# marked not-dehydrated — otherwise saving the form would try to write it and
# fail.
set -e

python3 <<'PY'
import io

p = 'app/Filament/Resources/DistributorFieldMapResource.php'
s = io.open(p, encoding='utf-8').read()

assert 'FIELDMAP-PICKERS2' in s, (
    'Run apply-field-map-real-dropdowns.sh first — this builds on its '
    'sourcePathOptions() helper.'
)
assert 'MARKER-FIELDMAP-PROBE' not in s, 'already applied'

# ---------------------------------------------------------------- form section
old = """            Forms\\Components\\Section::make('Transform args')"""
assert s.count(old) == 1, 'P1 args section anchor'
s = s.replace(old, """            // MARKER-FIELDMAP-PROBE — what does this feed actually send?
            Forms\\Components\\Section::make('See real values from this feed')
                ->description('Enter any product identifier to list every column this distributor sends for it, with its value and whether it is mapped yet.')
                ->collapsible()
                ->collapsed()
                ->schema([
                    Forms\\Components\\TextInput::make('probe_identifier')
                        ->label('UPC, EAN or part number')
                        ->dehydrated(false)   // display only — not a column
                        ->live(onBlur: true)
                        ->placeholder('e.g. 4717784045276')
                        ->helperText('Leave the form and come back to keep editing; this box never saves.'),

                    Forms\\Components\\Placeholder::make('probe_output')
                        ->hiddenLabel()
                        ->content(fn (Forms\\Get $get) => new \\Illuminate\\Support\\HtmlString(
                            self::probeHtml(
                                (string) $get('distributor_code'),
                                (string) $get('probe_identifier')
                            )
                        )),
                ]),

            Forms\\Components\\Section::make('Transform args')""")

# ---------------------------------------------------------------- helper
old = """    public static function getPages(): array"""
assert s.count(old) == 1, 'P2 getPages anchor'
s = s.replace(old, """    /**
     * MARKER-FIELDMAP-PROBE — every column this distributor sends for one
     * product, with its value and its current mapping.
     *
     * Reads source_raw, the untouched feed row stored on every catalog row,
     * so this is what the distributor actually sent rather than what we made
     * of it. Nested objects are flattened to the same dotted paths the Feed
     * column picker offers, so what you see here is what you can select.
     */
    public static function probeHtml(string $code, string $identifier): string
    {
        $code = strtoupper(trim($code));
        $identifier = trim($identifier);

        if ($code === '' || $identifier === '') {
            return '<div style="opacity:.6;font-size:13px">Pick a distributor and enter an identifier.</div>';
        }

        $row = \\App\\Models\\PlatformDistributorCatalog::query()
            ->where('distributor_code', $code)
            ->where(function ($q) use ($identifier) {
                $q->where('upc', $identifier)
                  ->orWhere('ean', $identifier)
                  ->orWhere('manufacturer_sku', $identifier)
                  ->orWhere('distributor_variant_no', $identifier)
                  ->orWhere('distributor_product_no', $identifier);
            })
            ->first();

        if (! $row) {
            return '<div style="opacity:.7;font-size:13px">Nothing in the '
                 . e($code) . ' catalog matches <code>' . e($identifier)
                 . '</code>. Identifiers are matched exactly.</div>';
        }

        $raw = is_string($row->source_raw) ? json_decode($row->source_raw, true) : $row->source_raw;
        if (! is_array($raw) || $raw === []) {
            return '<div style="opacity:.7;font-size:13px">This row has no stored feed data. '
                 . 'It predates source_raw being kept — re-sync the catalog to populate it.</div>';
        }

        // Flatten exactly like the Feed column picker, so every path shown
        // here is one you can actually select.
        $flat = [];
        $walk = function (array $arr, string $prefix, int $depth) use (&$walk, &$flat): void {
            if ($depth > 3) { return; }
            foreach ($arr as $k => $v) {
                if (is_int($k)) { continue; }
                $path = $prefix === '' ? (string) $k : $prefix . '.' . $k;
                if (is_array($v) && $v !== [] && ! array_is_list($v)) {
                    $walk($v, $path, $depth + 1);
                } else {
                    $flat[$path] = $v;
                }
            }
        };
        $walk($raw, '', 1);
        ksort($flat);

        // Which Intake field each column currently feeds. This is what turns
        // the list from "here is the data" into "here is what is left to do".
        $mapped = \\App\\Models\\DistributorFieldMap::query()
            ->where('distributor_code', $code)
            ->where('is_active', true)
            ->get(['canonical_field', 'source_path', 'transform', 'transform_args']);

        $byPath = [];
        foreach ($mapped as $m) {
            if (filled($m->source_path)) {
                $byPath[(string) $m->source_path][] = (string) $m->canonical_field;
            }
            // coalesce / zip_pipe reference their columns inside the args
            foreach ((array) ($m->transform_args ?? []) as $v) {
                foreach ((array) (is_array($v) ? $v : [$v]) as $inner) {
                    if (is_string($inner) && isset($flat[$inner])) {
                        $byPath[$inner][] = (string) $m->canonical_field . ' (via ' . $m->transform . ')';
                    }
                }
            }
        }

        $h  = '<div style="font-size:12.5px;margin-bottom:8px;opacity:.75">'
            . e($row->name ?: (string) $row->distributor_variant_no)
            . ' &middot; ' . count($flat) . ' columns in this feed row</div>';

        $h .= '<div style="max-height:460px;overflow:auto;border:1px solid rgba(255,255,255,.1);border-radius:8px">';
        $h .= '<table style="width:100%;border-collapse:collapse;font-size:12.5px">';
        $h .= '<thead><tr style="text-align:left;opacity:.6;font-size:10.5px;letter-spacing:.05em;text-transform:uppercase">'
            . '<th style="padding:7px 10px">Feed column</th>'
            . '<th style="padding:7px 10px">Value</th>'
            . '<th style="padding:7px 10px">Mapped to</th></tr></thead><tbody>';

        foreach ($flat as $path => $value) {
            if (is_bool($value)) {
                $shown = $value ? 'true' : 'false';
            } elseif (is_array($value)) {
                $shown = json_encode($value, JSON_UNESCAPED_SLASHES);
            } elseif ($value === null || $value === '') {
                $shown = '';
            } else {
                $shown = (string) $value;
            }
            $blank = ($shown === '');
            $shown = $blank ? 'empty' : \\Illuminate\\Support\\Str::limit($shown, 160);

            $to = $byPath[$path] ?? [];
            $toHtml = $to
                ? '<span style="color:#4ade80">' . e(implode(', ', array_unique($to))) . '</span>'
                : '<span style="opacity:.4">not mapped</span>';

            $h .= '<tr style="border-top:1px solid rgba(255,255,255,.07)">'
                . '<td style="padding:7px 10px;font-family:ui-monospace,monospace">' . e($path) . '</td>'
                . '<td style="padding:7px 10px' . ($blank ? ';opacity:.35;font-style:italic' : '') . '">' . e($shown) . '</td>'
                . '<td style="padding:7px 10px">' . $toHtml . '</td>'
                . '</tr>';
        }

        return $h . '</tbody></table></div>';
    }

    public static function getPages(): array""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo
echo "--- probe wired ---"
grep -n "MARKER-FIELDMAP-PROBE\|probe_identifier\|probeHtml" app/Filament/Resources/DistributorFieldMapResource.php | head

echo
echo "--- form and table still intact ---"
grep -c "public static function form\|public static function table\|Section::make('Mapping')\|columns(\[" app/Filament/Resources/DistributorFieldMapResource.php | xargs echo "  key structures present:"

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
echo "apply-field-map-feed-probe: OK"
