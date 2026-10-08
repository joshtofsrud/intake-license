<?php

namespace App\Support;

/**
 * MARKER-OPTION-SPLIT / MARKER-OPTION-FIELDS
 *
 * Gives the register picker one dropdown per option (Casing, Compound,
 * Bead, TPI) instead of one long Version list. Values come from each
 * item's catalog row (CatalogSpecs, stored in spec_attrs). An item with no
 * catalog link falls back to reading its own Version text by the rule's
 * known values. An option only becomes a dropdown when it tells at least
 * two variants apart. Version stays only if two variants would otherwise
 * look identical.
 */
class VariantSplitter
{
    /**
     * @param  array  $variants   each with id + version
     * @param  array  $attrs      size / color / version so far
     * @param  string $catPath    the group's catalog category path
     * @param  array  $specById   item id => spec_attrs from its catalog row
     * @return array [$variants, $attrs, $names]
     */
    public static function apply(array $variants, array $attrs, string $catPath, array $specById = []): array
    {
        if (! in_array('version', $attrs, true) || count($variants) < 2) {
            return [$variants, $attrs, []];
        }
        try {
            $rules = CatalogSpecs::rules();
        } catch (\Throwable $e) {
            report($e);
            return [$variants, $attrs, []];
        }
        $names = [];
        $added = [];
        foreach ($rules as $rule) {
            if ($rule['applies'] !== '' && mb_stripos($catPath, $rule['applies']) === false) { continue; }
            $vals = [];
            foreach ($variants as $i => $v) {
                $spec = $specById[$v['id']] ?? null;
                $vals[$i] = is_array($spec)
                    ? (string) ($spec[$rule['key']] ?? '')
                    : ($rule['terms'] ? CatalogSpecs::fromText($rule, (string) $v['version']) : '');
            }
            $found = array_filter($vals, fn ($x) => $x !== '');
            if (! $found || count(array_unique(array_map('mb_strtolower', $vals))) < 2) { continue; }
            foreach ($variants as $i => &$v) { $v[$rule['key']] = $vals[$i]; }
            unset($v);
            $names[$rule['key']] = $rule['name'];
            $added[] = $rule['key'];
        }
        if (! $added) {
            return [$variants, $attrs, []];
        }

        $base = array_values(array_filter($attrs, fn ($a) => $a !== 'version'));
        $keys = array_merge($base, $added);
        $sigs = array_map(fn ($v) => mb_strtolower(implode('|', array_map(fn ($k) => (string) ($v[$k] ?? ''), $keys))), $variants);
        $out = count(array_unique($sigs)) < count($sigs) ? array_merge($keys, ['version']) : $keys;
        return [$variants, $out, $names];
    }
}
