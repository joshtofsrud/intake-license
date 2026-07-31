#!/bin/bash
# distributor-map-transforms-bti — the transforms BTI's feed shape requires.
#
#   Normalisation is data-driven through distributor_field_maps, which is what
#   keeps adapters thin. BTI's feed needs four things the resolver can't yet
#   express, so they go in the resolver as reusable transforms rather than as
#   BTI-specific code in the adapter.
#
#     zip_pipe    attribute_keys "Model|Color|Size" and attribute_values
#                 "Snapback Hat|Gray|One Size Fits Most" are parallel arrays
#                 flattened into strings. Zipped into [{Name,Value}], the same
#                 shape HLC's Attributes already arrive in, so the title
#                 engine and the split-by picker work unchanged. Mismatched
#                 lengths are truncated to the shorter side, not padded — a
#                 value with no key is unusable, and inventing one would put
#                 junk in front of shops.
#
#     split_pipe  image_paths "/1k/a.jpg|/1k/b.jpg" into an array, with an
#                 optional prefix since BTI's paths are host-relative.
#
#     cast trim       vendor_item_id ships as " SOX-6M" with a leading space.
#     cast zero_null  map 0.0 means NO MAP, not a $0 MAP. Left as a number it
#                     would floor prices at zero wherever MAP is enforced.
#
#   Nothing existing changes: these are new match arms and new cast cases.
#   HLC's map doesn't reference any of them.
#
#   Not needed after all: category_path. `coalesce` with a single concat spec
#   already always-concatenates, so "Apparel & Protection" + "Caps" is
#   expressible today.
# NO MIGRATION. Server: optimize:clear.
set -e
if grep -q "MARKER-BTI-TRANSFORMS" app/Services/Distributors/DistributorMapResolver.php; then
  echo "distributor-map-transforms-bti already applied — aborting."; exit 1
fi

python3 - <<'DMT_0_EOF'
import io
p = 'app/Services/Distributors/DistributorMapResolver.php'
s = io.open(p, encoding='utf-8').read()

# --- new match arms -----------------------------------------------------
old = """            'join_array'          => $this->joinArray($this->path($ctx, $row->source_path), $args),
            default               => null,"""
assert s.count(old) == 1, s.count(old)
new = """            'join_array'          => $this->joinArray($this->path($ctx, $row->source_path), $args),
            // MARKER-BTI-TRANSFORMS
            'zip_pipe'            => $this->zipPipe($ctx, $args),
            'split_pipe'          => $this->splitPipe($this->path($ctx, $row->source_path), $args),
            default               => null,"""
s = s.replace(old, new)

# --- implementations ----------------------------------------------------
old = """    private function cast(mixed $v, ?string $cast): mixed"""
assert s.count(old) == 1, s.count(old)
new = """    /**
     * MARKER-BTI-TRANSFORMS
     *
     * Two parallel pipe-delimited strings zipped into [{Name,Value}, ...] —
     * the shape HLC's Attributes already arrive in, so everything downstream
     * (title tokens, the split-by picker, the specs grid) works untouched.
     *
     * args: keys => source path of the names, values => source path of the
     * values, sep => delimiter, default '|'.
     *
     * A length mismatch truncates to the shorter side. Padding would invent
     * an attribute name or an empty value, and a shop reads these.
     */
    private function zipPipe(array $ctx, array $args): ?array
    {
        $sep = $args['sep'] ?? '|';

        $rawKeys = $this->path($ctx, $args['keys'] ?? null);
        $rawVals = $this->path($ctx, $args['values'] ?? null);

        if (! is_string($rawKeys) || ! is_string($rawVals)) {
            return null;
        }
        if (trim($rawKeys) === '' || trim($rawVals) === '') {
            return null;
        }

        $keys = array_map('trim', explode($sep, $rawKeys));
        $vals = array_map('trim', explode($sep, $rawVals));

        $n = min(count($keys), count($vals));
        $out = [];

        for ($i = 0; $i < $n; $i++) {
            if ($keys[$i] === '' || $vals[$i] === '') {
                continue;
            }
            $out[] = ['Name' => $keys[$i], 'Value' => $vals[$i]];
        }

        return $out ?: null;
    }

    /**
     * MARKER-BTI-TRANSFORMS — pipe-delimited string to array. BTI's image
     * paths are host-relative, so `prefix` makes them fetchable.
     */
    private function splitPipe(mixed $raw, array $args): ?array
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $sep    = $args['sep'] ?? '|';
        $prefix = (string) ($args['prefix'] ?? '');

        $out = [];
        foreach (explode($sep, $raw) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $out[] = $prefix !== '' && ! str_starts_with($part, 'http')
                ? rtrim($prefix, '/') . '/' . ltrim($part, '/')
                : $part;
        }

        return $out ?: null;
    }

    private function cast(mixed $v, ?string $cast): mixed"""
s = s.replace(old, new)

# --- new casts ----------------------------------------------------------
old = """        return match ($cast) {
            'cents'  => (int) round(((float) $v) * 100),"""
assert s.count(old) == 1, s.count(old)
new = """        return match ($cast) {
            'cents'  => (int) round(((float) $v) * 100),
            // MARKER-BTI-TRANSFORMS
            // trim: BTI ships vendor_item_id as " SOX-6M".
            'trim'   => trim((string) $v),
            // zero_null: BTI writes map 0.0 to mean NO MAP. Kept as a number
            // it would floor the price at zero wherever MAP is enforced.
            // Applied after 'cents' by chaining two map rows if both are
            // wanted; on its own it works on the raw feed value.
            'zero_null' => ((float) $v) == 0.0 ? null : $v,
            'cents_zero_null' => ((float) $v) == 0.0 ? null : (int) round(((float) $v) * 100),"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('resolver transforms ok')
DMT_0_EOF

php -l app/Services/Distributors/DistributorMapResolver.php

echo
echo "distributor-map-transforms-bti applied."
