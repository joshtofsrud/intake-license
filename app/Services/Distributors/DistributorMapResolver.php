<?php
// MARKER-PATCH-HLC3A

namespace App\Services\Distributors;

use App\Models\DistributorFieldMap;

/**
 * Executes the distributor_field_maps grid against a raw source variant and
 * returns a canonical Intake catalog row. Knows nothing about any specific
 * distributor — it only runs the transforms the map tells it to.
 *
 * Context: the product's fields (minus its Variants array) merged with the
 * variant's fields, variant winning. So a map row can reference product-level
 * ('Brand', 'Categories') or variant-level ('VariantNo', 'Prices') fields.
 */
class DistributorMapResolver
{
    /** @var array<string,array<string,DistributorFieldMap>> */
    private array $mapCache = [];

    /**
     * @return array<string,mixed> canonical_field => resolved value
     */
    public function resolve(string $distributorCode, array $variant, array $product = []): array
    {
        $ctx = $this->context($variant, $product);
        $out = [];
        foreach ($this->mapsFor($distributorCode) as $field => $row) {
            $out[$field] = $this->applyRow($row, $ctx);
        }
        return $out;
    }

    /** @return array<string,DistributorFieldMap> */
    public function mapsFor(string $code): array
    {
        $code = strtoupper($code);
        if (! isset($this->mapCache[$code])) {
            $this->mapCache[$code] = DistributorFieldMap::query()
                ->where('distributor_code', $code)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get()
                ->keyBy('canonical_field')
                ->all();
        }
        return $this->mapCache[$code];
    }

    public function flushCache(): void
    {
        $this->mapCache = [];
    }

    private function context(array $variant, array $product): array
    {
        unset($product['Variants']);
        return array_merge($product, $variant);
    }

    private function applyRow(DistributorFieldMap $row, array $ctx): mixed
    {
        $args = $row->transform_args ?? [];

        $value = match ($row->transform) {
            'direct'              => $this->path($ctx, $row->source_path),
            'bool'                => $this->toBool($this->path($ctx, $row->source_path)),
            'json_passthrough'    => $this->path($ctx, $row->source_path),
            'pick_from_array'     => $this->pickFromArray($this->path($ctx, $row->source_path), $args),
            'lookup'              => $this->lookup($this->path($ctx, $row->source_path), $row->lookup_table ?? [], $args),
            'coalesce'            => $this->coalesce($ctx, $args),
            'pick_category_level' => $this->pickLevel($this->path($ctx, $row->source_path), $args),
            'join_array'          => $this->joinArray($this->path($ctx, $row->source_path), $args),
            // MARKER-BTI-TRANSFORMS
            'zip_pipe'            => $this->zipPipe($ctx, $args),
            'split_pipe'          => $this->splitPipe($this->path($ctx, $row->source_path), $args),
            default               => null,
        };

        return $this->cast($value, $args['cast'] ?? null);
    }

    /** Dot-path into the merged context. Returns null on any miss. */
    private function path(array $ctx, ?string $path): mixed
    {
        if ($path === null || $path === '') {
            return null;
        }
        $cur = $ctx;
        foreach (explode('.', $path) as $seg) {
            if (is_array($cur) && array_key_exists($seg, $cur)) {
                $cur = $cur[$seg];
            } else {
                return null;
            }
        }
        return $cur;
    }

    private function toBool(mixed $v): ?bool
    {
        if ($v === null) {
            return null;
        }
        return filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    /** Find the array element matching all `match` pairs; return `field` or the element. */
    private function pickFromArray(mixed $arr, array $args): mixed
    {
        if (! is_array($arr)) {
            return null;
        }
        $match = $args['match'] ?? [];
        foreach ($arr as $el) {
            if (! is_array($el)) {
                continue;
            }
            $ok = true;
            foreach ($match as $k => $v) {
                if (! array_key_exists($k, $el) || $el[$k] != $v) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return isset($args['field']) ? ($el[$args['field']] ?? null) : $el;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $table */
    private function lookup(mixed $v, array $table, array $args): mixed
    {
        if ($v === null) {
            return $args['default'] ?? null;
        }
        $key = (string) $v;
        return array_key_exists($key, $table) ? $table[$key] : ($args['default'] ?? null);
    }

    /** First non-empty of an ordered list of {path} or {concat:[...],sep} specs. */
    private function coalesce(array $ctx, array $args): mixed
    {
        foreach (($args['order'] ?? []) as $spec) {
            if (isset($spec['path'])) {
                $v = $this->path($ctx, $spec['path']);
                if ($v !== null && $v !== '') {
                    return $v;
                }
            } elseif (isset($spec['concat'])) {
                $parts = [];
                foreach ($spec['concat'] as $p) {
                    $pv = $this->path($ctx, $p);
                    if ($pv === null || $pv === '') {
                        $parts = [];
                        break; // all parts required for a usable composite key
                    }
                    $parts[] = (string) $pv;
                }
                if ($parts) {
                    return implode($spec['sep'] ?? '-', $parts);
                }
            }
        }
        return null;
    }

    private function pickLevel(mixed $arr, array $args): mixed
    {
        if (! is_array($arr)) {
            return null;
        }
        $level = $args['level'] ?? 0;
        $field = $args['field'] ?? 'CategoryName';
        $best = null;
        foreach ($arr as $el) {
            if (! is_array($el)) {
                continue;
            }
            if (($el['Level'] ?? null) == $level) {
                return $el[$field] ?? null;
            }
            // fall back to the deepest level <= requested
            if (($el['Level'] ?? -1) <= $level) {
                $best = $el[$field] ?? $best;
            }
        }
        return $best;
    }

    private function joinArray(mixed $arr, array $args): ?string
    {
        if (! is_array($arr)) {
            return null;
        }
        $field = $args['field'] ?? null;
        $sep = $args['sep'] ?? ' > ';
        $rows = $arr;
        if (isset($args['sort_by'])) {
            usort($rows, fn ($a, $b) => ($a[$args['sort_by']] ?? 0) <=> ($b[$args['sort_by']] ?? 0));
        }
        $parts = [];
        foreach ($rows as $el) {
            $v = $field ? ($el[$field] ?? null) : $el;
            if ($v !== null && $v !== '') {
                $parts[] = (string) $v;
            }
        }
        return $parts ? implode($sep, $parts) : null;
    }

    /**
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

    private function cast(mixed $v, ?string $cast): mixed
    {
        if ($cast === null || $v === null) {
            return $v;
        }
        return match ($cast) {
            'cents'  => (int) round(((float) $v) * 100),
            // MARKER-BTI-TRANSFORMS
            // MARKER-BTI-ENCODING
            // trim: BTI ships vendor_item_id as " SOX-6M", and at least one
            // row pads it with a NON-BREAKING space, which PHP's trim() does
            // not touch — it only strips ASCII whitespace. An MPN carrying an
            // invisible leading character never matches its counterpart at
            // another distributor, so this strips unicode whitespace too.
            'trim'   => preg_replace(
                '/^[\s\x{00A0}\x{200B}\x{FEFF}]+|[\s\x{00A0}\x{200B}\x{FEFF}]+$/u',
                '',
                (string) $v
            ),
            // zero_null: BTI writes map 0.0 to mean NO MAP. Kept as a number
            // it would floor the price at zero wherever MAP is enforced.
            // Applied after 'cents' by chaining two map rows if both are
            // wanted; on its own it works on the raw feed value.
            'zero_null' => ((float) $v) == 0.0 ? null : $v,
            'cents_zero_null' => ((float) $v) == 0.0 ? null : (int) round(((float) $v) * 100),
            'int'    => (int) $v,
            'float'  => (float) $v,
            'bool'   => filter_var($v, FILTER_VALIDATE_BOOLEAN),
            'string' => (string) $v,
            default  => $v,
        };
    }
}
