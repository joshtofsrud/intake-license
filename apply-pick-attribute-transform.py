#!/usr/bin/env python3
"""Field mapper: let it assign attributes, not just price and cost.

Colour and size are first-class item fields — editable on the item form,
shown as list columns — but nothing in the mapper could produce them.
They were only ever derived by CatalogTitleComposer, configured on a
different master-admin screen. A field a shop can see and edit should
have a visible source in the mapper like every other one.

Adds a GENERIC `pick_attribute` transform: give it a priority list of
attribute names and it returns the first matching Value. That makes ANY
attribute mappable to ANY canonical field, which is the general fix —
colour and size are just the first two users of it.

It reads {Name,Value} pairs, which is what all three distributors end up
with: BTI zips two pipe strings (so pick_attribute accepts the same
keys/values args as zip_pipe and zips them itself, because the resolver
sees RAW source rows, not other canonical fields), HLC and QBP carry
Attributes natively.

CatalogTitleComposer keeps its own lookup for title tokens, but the item
fields now come from the map, and the precedence already shipped means a
map row wins with the composer only filling gaps.
Run from repo root: python3 apply-pick-attribute-transform.py
"""
import sys

RES = 'app/Services/Distributors/DistributorMapResolver.php'

def sub(p, old, new, label):
    s = open(p).read()
    if new in s:
        print(f"SKIP (already applied): {label}"); return
    if old not in s:
        print(f"FAIL: anchor not found for {label} in {p}"); sys.exit(1)
    open(p, 'w').write(s.replace(old, new, 1))
    print(f"OK: {label}")

# ============================================================
# 1) The transform
# ============================================================
sub(RES,
    """            // MARKER-BTI-TRANSFORMS
            'zip_pipe'            => $this->zipPipe($ctx, $args),""",
    """            // MARKER-PICK-ATTR — any attribute to any field.
            'pick_attribute'      => $this->pickAttribute($ctx, $row->source_path, $args),
            // MARKER-BTI-TRANSFORMS
            'zip_pipe'            => $this->zipPipe($ctx, $args),""",
    "resolver: dispatch")

sub(RES,
    """    private function zipPipe(array $ctx, array $args): ?array""",
    """    /**
     * MARKER-PICK-ATTR — first matching attribute value, by priority.
     *
     * args:
     *   names:  ['Color', 'Primary Color']   priority order, case-insensitive
     *   keys/values/sep:  optional — when the source carries two parallel
     *                     pipe strings (BTI) instead of {Name,Value} pairs
     *
     * The resolver sees RAW source rows, never other canonical fields, so a
     * distributor whose attributes only exist as pipe strings has to zip
     * them here rather than leaning on the `attributes` map row.
     */
    private function pickAttribute(array $ctx, ?string $sourcePath, array $args): ?string
    {
        $names = $args['names'] ?? [];
        if (! is_array($names) || ! $names) {
            return null;
        }

        // Two shapes in, one shape out.
        $pairs = isset($args['keys'], $args['values'])
            ? $this->zipPipe($ctx, $args)
            : $this->path($ctx, $sourcePath);

        if (! is_array($pairs)) {
            return null;
        }

        foreach ($names as $want) {
            foreach ($pairs as $pair) {
                if (! is_array($pair)) {
                    continue;
                }
                $name  = $pair['Name']  ?? $pair['name']  ?? null;
                $value = $pair['Value'] ?? $pair['value'] ?? null;
                if ($name === null || $value === null) {
                    continue;
                }
                if (strcasecmp(trim((string) $name), trim((string) $want)) !== 0) {
                    continue;
                }
                $value = trim((string) $value);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    private function zipPipe(array $ctx, array $args): ?array""",
    "resolver: pickAttribute")

print("\\nPart 1 of 2 — run apply-pick-attribute-seeds.py for the map rows.")
