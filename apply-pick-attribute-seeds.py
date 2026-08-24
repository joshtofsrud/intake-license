#!/usr/bin/env python3
"""Colour and size as visible map rows, for all three distributors.

With pick_attribute in place, colour and size become ordinary mapper
fields — visible, editable, and overridable per distributor like every
other one. The priority lists below are defaults, not hardcoding: they're
rows in distributor_field_maps, so master admin can change the attribute
names without a deploy.

BTI needs keys/values because its attributes only exist as two parallel
pipe strings on the raw row. HLC and QBP carry {Name,Value} natively, so
they just point at their Attributes path.
Run from repo root: python3 apply-pick-attribute-seeds.py
"""
import sys

BTI = 'database/seeders/BtiFieldMapSeeder.php'
HLC = 'database/seeders/DistributorFieldMapSeeder.php'
QBP = 'database/seeders/QbpFieldMapSeeder.php'

def sub(p, old, new, label):
    s = open(p).read()
    if new in s:
        print(f"SKIP (already applied): {label}"); return
    if old not in s:
        print(f"FAIL: anchor not found for {label} in {p}"); sys.exit(1)
    open(p, 'w').write(s.replace(old, new, 1))
    print(f"OK: {label}")

# ---------------------------------------------------------------- BTI
sub(BTI,
    """            // money
            ['cost_cents', 'your_price', 'direct', ['cast' => 'cents'], null, 'dealer cost'],""",
    """            // MARKER-PICK-ATTR — colour and size out of the same two pipe
            // strings the attributes row zips. They're item fields a shop can
            // edit, so their source belongs here rather than being derived
            // silently by the title composer.
            ['color', null, 'pick_attribute', [
                'names'  => ['Color', 'Colour', 'Primary Color'],
                'keys'   => 'attribute_keys',
                'values' => 'attribute_values',
                'sep'    => '|',
            ], null, 'first matching attribute wins'],
            ['size', null, 'pick_attribute', [
                'names'  => ['Size', 'Frame Size', 'Length'],
                'keys'   => 'attribute_keys',
                'values' => 'attribute_values',
                'sep'    => '|',
            ], null, null],

            // money
            ['cost_cents', 'your_price', 'direct', ['cast' => 'cents'], null, 'dealer cost'],""",
    "BTI: colour + size rows")

# ---------------------------------------------------------------- HLC
sub(HLC,
    """            ['attributes', 'Attributes', 'json_passthrough', null, null, 'lossless; curate later'],""",
    """            ['attributes', 'Attributes', 'json_passthrough', null, null, 'lossless; curate later'],
            // MARKER-PICK-ATTR — the NAMES. size_id/color_id above are HLC's
            // opaque codes, which the title templates use as tokens; these are
            // the human-readable values the item form shows.
            ['color', 'Attributes', 'pick_attribute', ['names' => ['Color', 'Colour', 'Primary Color']], null, null],
            ['size',  'Attributes', 'pick_attribute', ['names' => ['Size', 'Frame Size', 'Length']], null, null],""",
    "HLC: colour + size rows")

# ---------------------------------------------------------------- QBP
sub(QBP,
    """            ['attributes', 'Attributes', 'json_passthrough', null, null,
                'adapter flattens classifications 3 levels deep into {Name,Value,Code,Unit}; multiple featureValues joined'],""",
    """            ['attributes', 'Attributes', 'json_passthrough', null, null,
                'adapter flattens classifications 3 levels deep into {Name,Value,Code,Unit}; multiple featureValues joined'],
            // MARKER-PICK-ATTR — QBP's flattened classifications already carry
            // {Name,Value}, so this reads them directly.
            ['color', 'Attributes', 'pick_attribute', ['names' => ['Color', 'Colour', 'Primary Color']], null, null],
            ['size',  'Attributes', 'pick_attribute', ['names' => ['Size', 'Frame Size', 'Length']], null, null],""",
    "QBP: colour + size rows")

print("\\nDone. Post-deploy, re-seed the maps:")
print("  php artisan db:seed --class=BtiFieldMapSeeder --force")
print("  php artisan db:seed --class=DistributorFieldMapSeeder --force")
print("  php artisan db:seed --class=QbpFieldMapSeeder --force")
print("All three use updateOrInsert on (distributor_code, canonical_field),")
print("so re-running is safe and won't disturb rows you've edited by hand.")
