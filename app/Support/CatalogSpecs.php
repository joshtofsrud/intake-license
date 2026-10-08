<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * MARKER-OPTION-FIELDS
 *
 * Turns one distributor catalog row into clean option values
 * (["s_casing" => "EXO", "s_compound" => "MaxxTerra", "s_bead" => "Folding", "s_tpi" => "60"]).
 *
 * Order of trust: a field that holds only this option (BTI "Compound") →
 * a mixed field, keeping only known values (HLC "Tire Technology":
 * "DH, E50, Wide Trail" → "DH") → the catalog title, by known values
 * (QBP sends no casing or compound field). Every value then goes through
 * the rule's aliases, so "3C MaxxTerra", "3CT" and "MaxxTerra" are one.
 */
class CatalogSpecs
{
    private static ?array $rules = null;

    /** Active rules, prepared. Read once per process. */
    public static function rules(bool $fresh = false): array
    {
        if (self::$rules !== null && ! $fresh) {
            return self::$rules;
        }
        self::$rules = self::prepare(DB::table('variant_split_rules')->where('is_active', true)->orderBy('sort')->orderBy('id')->get()->all());
        return self::$rules;
    }

    /** Rule rows (as stored) → the shape the readers use. */
    public static function prepare(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $name = trim((string) $r->attribute);
            if ($name === '') { continue; }
            $dec = fn ($x) => is_array($d = json_decode((string) $x, true)) ? $d : [];
            $aliases = [];
            foreach ($dec($r->aliases ?? null) as $from => $to) {
                if (trim((string) $from) !== '' && trim((string) $to) !== '') { $aliases[mb_strtolower(trim((string) $from))] = trim((string) $to); }
            }
            $words = array_values(array_filter(array_map(fn ($w) => trim((string) $w), $dec($r->words))));
            // search terms for titles: the known values and every alias spelling, longest first
            $terms = array_unique(array_merge($words, array_map('strval', array_keys($dec($r->aliases ?? null)))));
            usort($terms, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
            $out[] = [
                'key'     => 's_' . trim(preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($name)), '_'),
                'name'    => $name,
                'applies' => trim((string) $r->applies_to),
                'fields'  => array_map(fn ($f) => mb_strtolower(trim((string) $f)), $dec($r->fields ?? null)),
                'mixed'   => array_map(fn ($f) => mb_strtolower(trim((string) $f)), $dec($r->mixed_fields ?? null)),
                'known'   => array_map('mb_strtolower', $words),
                'words'   => $words,
                'terms'   => array_values(array_filter($terms, fn ($t) => trim($t) !== '')),
                'aliases' => $aliases,
            ];
        }
        return $out;
    }

    public static function useRules(?array $rules): void
    {
        self::$rules = $rules;
    }

    /** Every attribute the row carries, by lower-cased name; repeated names are joined. */
    public static function attributes(array $raw): array
    {
        $out = [];
        $add = function ($k, $v) use (&$out) {
            $k = mb_strtolower(trim((string) $k));
            $v = trim((string) $v);
            if ($k === '' || $v === '') { return; }
            $out[$k] = isset($out[$k]) ? $out[$k] . ', ' . $v : $v;
        };
        // BTI: two pipe lists side by side
        if (isset($raw['attribute_keys'])) {
            $ks = explode('|', (string) $raw['attribute_keys']);
            $vs = explode('|', (string) ($raw['attribute_values'] ?? ''));
            foreach ($ks as $i => $k) { $add($k, $vs[$i] ?? ''); }
        }
        // HLC and QBP: a list of {Name, Value}
        foreach (['Attributes', 'attributes'] as $key) {
            if (isset($raw[$key]) && is_array($raw[$key])) {
                foreach ($raw[$key] as $a) {
                    if (is_array($a)) { $add($a['Name'] ?? $a['name'] ?? '', $a['Value'] ?? $a['value'] ?? ''); }
                }
            }
        }
        return $out;
    }

    /** Clean option values for one catalog row. */
    public static function forRow(array $raw, string $title, string $categoryPath): array
    {
        $attrs = self::attributes($raw);
        $out = [];
        foreach (self::rules() as $rule) {
            if ($rule['applies'] !== '' && mb_stripos($categoryPath, $rule['applies']) === false) { continue; }
            $val = '';
            foreach ($rule['fields'] as $f) {
                if (($attrs[$f] ?? '') !== '') { $val = self::canon($rule, $attrs[$f]); break; }
            }
            if ($val === '') {
                foreach ($rule['mixed'] as $f) {
                    if (($attrs[$f] ?? '') === '') { continue; }
                    foreach (preg_split('/\s*[,\/|;]\s*/u', $attrs[$f]) ?: [] as $tok) {
                        $c = self::canon($rule, $tok);
                        if ($c !== '' && in_array(mb_strtolower($c), $rule['known'], true)) { $val = $c; break 2; }
                    }
                }
            }
            if ($val === '' && $rule['terms']) {
                $val = self::fromText($rule, $title);
            }
            if ($val !== '') { $out[$rule['key']] = $val; }
        }
        return $out;
    }

    /** First known value (or alias spelling) found in free text, as its canonical name. */
    public static function fromText(array $rule, string $text): string
    {
        foreach ($rule['terms'] as $t) {
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($t, '/') . '(?![\p{L}\p{N}+])/iu', $text)) {
                return self::canon($rule, $t);
            }
        }
        return '';
    }

    /** One spelling per value: aliases, the known value's own casing, and the option's unit dropped ("60TPI" → "60"). */
    public static function canon(array $rule, string $v): string
    {
        $v = trim(preg_replace('/\s+/u', ' ', $v), " \t,;");
        $v = trim(preg_replace('/\s*' . preg_quote($rule['name'], '/') . '$/iu', '', $v));
        if ($v === '') { return ''; }
        $l = mb_strtolower($v);
        if (isset($rule['aliases'][$l])) { return $rule['aliases'][$l]; }
        foreach ($rule['words'] as $w) {
            if (mb_strtolower($w) === $l) { return $w; }
        }
        return $v;
    }
}
