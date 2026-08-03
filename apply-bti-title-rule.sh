#!/usr/bin/env bash
# apply-bti-title-rule.sh
# MARKER-BTI-TITLE-RULE — BTI gets its own title recipe.
#
# BTI has no rule of its own, so every BTI title is built by the catch-all:
#
#     {brand} {model} {size} {color} {unit} {type0}
#
# That recipe is written for a distributor whose `name` is a bare model — it
# reassembles the detail from other fields. HLC is exactly that: name is
# "Minion DHF", so the recipe has to add size and colour back.
#
# BTI is the opposite. Its `name` maps from item_description, which already
# reads "Minion DHF Tire, 29×2.5", MT/EXO+/TR". Running that through a recipe
# built for terse names appends things the string already contains, plus two
# tokens that are noise on any distributor:
#
#     {unit}   -> "EA"          a packing unit, not part of a product name
#     {type0}  -> "Disc Pads"   the category, on an item already filed there
#
# Both are visible in the live output you have:
#     TRP Resin Disc Pads, 4 Piston Calipers *Disc Pads*
#     Maxxis Ikon Black *EA Tires*
#
# So BTI's rule is simply {brand} {model}. Not a simplification for its own
# sake — BTI's model string is already the descriptive one, and every extra
# token risks repeating what it says.
#
# ATTRIBUTE PRIORITIES ARE THE PART THAT MATTERS LATER. Even though {size} is
# not in the title above, setting the priority now means any category rule you
# add later resolves size from BTI's actual "Size" attribute. Without it the
# composer falls back to scraping the description — which on tires finds the
# thread count before the size, the same trap behind "size looks like a thread
# count" on the HLC tire category.
#
# BTI's attribute names, read from a real feed row:
#     Model | Size | Width | Casing | TPI | Compound | Bead | Type | Color | Weight
#
# Search text keeps every token. Nobody reads it; it only has to match.
#
# Nothing changes until you recompose — see the notes after the patch runs.
set -e

python3 <<'PY'
import os

MIG = 'database/migrations/2026_08_02_000300_bti_title_rule.php'
assert not os.path.exists(MIG), 'migration already exists'

open(MIG, 'w', encoding='utf-8').write('''<?php

// MARKER-BTI-TITLE-RULE — a distributor-level title recipe for BTI.
//
// Additive and idempotent: one row in catalog_title_settings, keyed on
// (distributor_code, category_key). The composer walks distributor rules
// before the '*' catch-all, and fills each field from the first rule that
// has a value — so this row overrides the global recipe for BTI only, and
// only for the fields it sets.

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Support\\Facades\\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $row = [
            // {brand} {model} and nothing else. BTI's `name` comes from
            // item_description and already carries size, casing and compound;
            // the catch-all's extra tokens repeat it and append {unit} "EA"
            // plus {type0}, the category the item is already filed under.
            'title_template'    => '{brand} {model}',
            'subtitle_template' => '{mpn}',

            // Search is never read by a person — width beats tidiness.
            'search_template'   => '{mpn} {brand} {model} {type0} {size} {color} {allattr}',

            // Named attributes, so a future category rule using {size} or
            // {color} resolves them properly instead of falling back to
            // scraping the description (which finds TPI before the size).
            'size_attribute_priority'  => json_encode(['Size', 'Width']),
            'color_attribute_priority' => json_encode(['Color']),

            'is_active' => true,
            'notes'     => 'BTI ships a descriptive item_description as the model, '
                         . 'so the title adds only the brand. Attribute priorities are '
                         . 'set for category rules added later.',
            'updated_at' => $now,
        ];

        $exists = DB::table('catalog_title_settings')
            ->where('distributor_code', 'BTI')
            ->where(fn ($q) => $q->whereNull('category_key')->orWhere('category_key', ''))
            ->first();

        if ($exists) {
            DB::table('catalog_title_settings')->where('id', $exists->id)->update($row);
        } else {
            DB::table('catalog_title_settings')->insert($row + [
                'distributor_code' => 'BTI',
                'category_key'     => '',
                'created_at'       => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('catalog_title_settings')
            ->where('distributor_code', 'BTI')
            ->where(fn ($q) => $q->whereNull('category_key')->orWhere('category_key', ''))
            ->delete();
    }
};
''')
print('created', MIG)
PY

echo
echo "--- braces ---"
python3 - <<'PY'
import io
s = io.open('database/migrations/2026_08_02_000300_bti_title_rule.php', encoding='utf-8').read()
i, n, d, par = 0, len(s), 0, 0
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
        i += 1
print('braces', d, 'parens', par)
PY

cat <<'NOTES'

--------------------------------------------------------------------
AFTER DEPLOY — nothing changes until you recompose.

  1. See it on one product first, without writing anything:
       Catalog Titles -> filter BTI -> the sample title column
     or Item lookup -> a BTI row -> display_name (still the old one
     until step 2).

  2. Rebuild BTI's titles:
       php artisan distributor:recompose BTI

  3. Refresh the health index so the warnings match reality:
       php artisan catalog:scan-titles

EXPECT A WAVE OF TITLE FLAGS. Recomposing changes display_name on
~24,600 BTI rows. The nightly tenant sync compares that against each
item's stored title, so every linked BTI item raises "renamed by
distributor". Ground Control has ~228 linked BTI items, so expect
roughly that many. They are proposals, not changes — nothing is
applied without you choosing it.

TUNE FROM HERE. The rule is one row in Catalog Titles under BTI. Add
category rules beneath it the same way you did for HLC tires; a rule
keyed on a top-level category (e.g. "Tires") covers every subcategory
under it.
--------------------------------------------------------------------
NOTES

echo "apply-bti-title-rule: OK"
