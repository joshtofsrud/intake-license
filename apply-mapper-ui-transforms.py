#!/usr/bin/env python3
"""Mapper UI: offer every transform the resolver understands.

The Filament dropdown lists eight transforms. The resolver understands
eleven — zip_pipe and split_pipe were added for BTI and never reached the
UI, and pick_attribute is new. A transform missing from the dropdown
can't be chosen, so those map rows are readable but not editable through
the interface that exists to edit them.

Also documents pick_attribute's args in the existing examples line, since
that's where someone will look for the JSON shape.
Run from repo root: python3 apply-mapper-ui-transforms.py
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

sub(RES,
    """        'json_passthrough'    => 'json_passthrough — store the whole value',
    ];""",
    """        'json_passthrough'    => 'json_passthrough — store the whole value',
        // MARKER-PICK-ATTR — these three were in the resolver but not here,
        // so rows using them couldn't be edited through this form.
        'pick_attribute'      => 'pick_attribute — first matching attribute by name',
        'zip_pipe'            => 'zip_pipe — two pipe strings → {Name,Value} pairs',
        'split_pipe'          => 'split_pipe — pipe string → list',
    ];""",
    "UI: full transform vocabulary")

# Anchor on a short fragment; the full line is long and full of plain
# double quotes, which are easy to over-escape by hand.
sub(RES,
    "'JSON arguments for the transform. Examples: ",
    '\'JSON arguments for the transform. pick_attribute → {"names":["Color","Colour"]} against a path holding {Name,Value} pairs — add {"keys":"attribute_keys","values":"attribute_values","sep":"|"} when the source is two parallel pipe strings instead. More examples: ',
    "UI: document pick_attribute args")

print("\\nDone. Post-deploy: php artisan filament:cache-components && php artisan optimize:clear")
