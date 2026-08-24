#!/usr/bin/env python3
"""Field map editor: a proper transform legend.

The eleven transforms were documented as one dense run-on line crammed
into a section description, and only four of them were mentioned at all.
Anyone picking a transform from the dropdown had no way to learn what it
does or what args it takes without reading DistributorMapResolver.

Adds a collapsible legend above the args box: every transform, what it
does, its arguments, and a real example taken from the seeded maps rather
than invented. The dense description line goes, since the legend replaces
it and two sources of truth would drift.

Highlights the currently-selected transform, so the row you need is
obvious rather than being one of eleven.
Run from repo root: python3 apply-transform-legend.py
"""
import sys

RES = 'app/Filament/Resources/DistributorFieldMapResource.php'

def sub(p, old, new, label):
    s = open(p).read()
    if new in s:
        print(f"SKIP (already applied): {label}"); return
    if old not in s:
        print(f"FAIL: anchor not found for {label} in {p}"); sys.exit(1)
    open(p, 'w').write(s.replace(old, new, 1))
    print(f"OK: {label}")

s = open(RES).read()

# ---------------------------------------------------------------- 1) section
# GUARD: this step slices by index rather than replacing a unique string, so
# without it a second run inserts a second legend. It did exactly that.
if 'MARKER-TRANSFORM-LEGEND' in s:
    print("SKIP (already applied): legend section")
    old_desc_start = None
else:
    old_desc_start = s.index("            Forms\\Components\\Section::make('Transform args')")

NEW_SECTION = """            // MARKER-TRANSFORM-LEGEND — was one dense run-on line naming four
            // of the eleven transforms. The legend below replaces it; keeping
            // both would guarantee they drift.
            Forms\\Components\\Section::make('What the transforms do')
                ->description('Every transform the resolver understands. The one you have selected is highlighted.')
                ->collapsible()
                ->collapsed()
                ->schema([
                    Forms\\Components\\Placeholder::make('transform_legend')
                        ->hiddenLabel()
                        ->content(fn (Forms\\Get $get) => new \\Illuminate\\Support\\HtmlString(
                            self::legendHtml((string) $get('transform'))
                        )),
                ]),

            Forms\\Components\\Section::make('Transform args')
                ->description('JSON arguments for the selected transform — see the legend above for the shape each one expects.')
"""

if old_desc_start is not None:
    old_desc_end = s.index(
        "                ->collapsed(fn (Forms\\Get $get) => blank($get('transform_args')))",
        old_desc_start,
    )
    s = s[:old_desc_start] + NEW_SECTION + s[old_desc_end:]
    open(RES, 'w').write(s)
    print("OK: legend section + trimmed description")

# ---------------------------------------------------------------- 2) helper
LEGEND = """
    /**
     * MARKER-TRANSFORM-LEGEND — the reference table.
     *
     * Examples are lifted from the seeded maps rather than invented, so a
     * reader can find the same row in the list and see it working.
     */
    public static function legendHtml(string $selected): string
    {
        $rows = [
            ['direct', 'Copy the value at source_path, unchanged.', '—',
             'name ← item_description'],

            ['bool', 'Coerce a truthy value to a real boolean.', '—',
             'taxable ← Taxable'],

            ['lookup', 'Translate one value into another through a table.', 'the lookup table below',
             'status 7 → sellable, 9 → discontinued'],

            ['coalesce', 'First non-empty of several paths; can concatenate.', '{"order":[…]} — paths tried in order',
             'product_key ← UPC, else EAN, else BrandId-MFGPartNumber'],

            ['pick_from_array', 'Choose one element of a list by matching a field.', '{"match":{…},"field":"…"}',
             'cost_cents ← the Prices entry where TypeId = 0'],

            ['pick_category_level', 'Take one level out of a category tree.', '{"level":1,"field":"CategoryName"}',
             'category ← the most specific level'],

            ['join_array', 'Flatten a list into one string.', '{"sep":", "}',
             'joins a list of values for display'],

            ['json_passthrough', 'Store the whole structure as-is.', '—',
             'attributes ← Attributes (lossless)'],

            ['pick_attribute', 'First attribute whose name matches, by priority.',
             '{"names":["Color","Colour"]} · add {"keys":…,"values":…,"sep":"|"} when the source is two parallel pipe strings instead of {Name,Value} pairs',
             'color ← Color, else Colour, else Primary Color'],

            ['zip_pipe', 'Two parallel pipe strings into {Name,Value} pairs.',
             '{"keys":"…","values":"…","sep":"|"}',
             'Model|Color|Size + Snapback Hat|Gray|One Size'],

            ['split_pipe', 'One pipe string into a list.', '{"sep":"|","prefix":"…"} — prefix makes relative paths fetchable',
             'images ← image_paths'],
        ];

        $out = '<div style="font-size:12px;line-height:1.5">'
             . '<table style="width:100%;border-collapse:collapse">'
             . '<thead><tr>'
             . '<th style="text-align:left;padding:6px 8px;font-size:11px;text-transform:uppercase;letter-spacing:.05em;opacity:.6">Transform</th>'
             . '<th style="text-align:left;padding:6px 8px;font-size:11px;text-transform:uppercase;letter-spacing:.05em;opacity:.6">What it does</th>'
             . '<th style="text-align:left;padding:6px 8px;font-size:11px;text-transform:uppercase;letter-spacing:.05em;opacity:.6">Args</th>'
             . '<th style="text-align:left;padding:6px 8px;font-size:11px;text-transform:uppercase;letter-spacing:.05em;opacity:.6">Example</th>'
             . '</tr></thead><tbody>';

        foreach ($rows as [$name, $what, $args, $example]) {
            $on = ($name === $selected);
            $bg = $on ? 'background:rgba(190,242,100,.10);' : '';
            $out .= '<tr style="border-top:1px solid rgba(127,127,127,.18);' . $bg . '">'
                 . '<td style="padding:7px 8px;vertical-align:top;white-space:nowrap">'
                 . '<code style="font-size:11.5px;font-weight:' . ($on ? '700' : '500') . '">' . e($name) . '</code>'
                 . ($on ? ' <span style="font-size:10px;opacity:.7">selected</span>' : '')
                 . '</td>'
                 . '<td style="padding:7px 8px;vertical-align:top">' . e($what) . '</td>'
                 . '<td style="padding:7px 8px;vertical-align:top;opacity:.75"><code style="font-size:11px">' . e($args) . '</code></td>'
                 . '<td style="padding:7px 8px;vertical-align:top;opacity:.75">' . e($example) . '</td>'
                 . '</tr>';
        }

        $out .= '</tbody></table>'
             . '<div style="margin-top:10px;opacity:.7">'
             . 'cast is available on any transform: <code>{"cast":"cents"}</code>, '
             . '<code>"trim"</code>, <code>"string"</code>, <code>"cents_zero_null"</code> (0.0 becomes null, for MAP).'
             . '</div></div>';

        return $out;
    }
"""

s = open(RES).read()
anchor = "    public static function probeHtml("
if 'public static function legendHtml' in s:
    print("SKIP (already applied): legend helper")
elif anchor not in s:
    print("FAIL: probeHtml anchor not found"); sys.exit(1)
else:
    s = s.replace(anchor, LEGEND.lstrip('\n') + "\n" + anchor, 1)
    open(RES, 'w').write(s)
    print("OK: legend helper")

print("\\nDone. Post-deploy: php artisan filament:cache-components && php artisan optimize:clear")
