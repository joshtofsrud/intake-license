<?php

namespace App\Support;

/**
 * MARKER-FIELD-CLEANUP: the clean-up options on a field map row, applied by
 * the mapper after the transform, for every distributor.
 *
 * They exist so a feed's quirks are fixed in master admin (Field maps →
 * Clean-up), never hard-coded into a distributor's reader:
 *
 *   any field
 *     replace       [{pattern, with}]  regular expressions, in order
 *     value_map     {from: to}         whole value, any case. A key written
 *                                      "Label: value" applies to one spec only.
 *     blank_values  [..]               values that mean "nothing" ("One size")
 *     value_case    sentence | title_to_sentence | first_upper
 *
 *   the attributes (specs) field, per spec
 *     label_map     {from: to}         rename a spec label, matched on the
 *                                      feed's own label
 *     label_words   {from: to}         rename a word inside labels ("Axel" → "Axle")
 *     label_case    sentence
 *     drop_labels   [..]               specs not to show
 *     ...and the value options above, applied to each spec's value.
 *
 * Matching is case-insensitive and trims spaces. A bad pattern is skipped,
 * never fatal: one typo in admin must not stop a sync.
 */
class FieldCleanup
{
    public const CASES = [
        'sentence'          => 'Sentence case ("Chromoly steel")',
        'title_to_sentence' => 'Only Title Case Phrases → sentence case',
        'first_upper'       => 'Capitalise the first letter only',
    ];

    public static function apply(mixed $value, ?array $c, bool $isAttributes = false): mixed
    {
        if (! $c) {
            return $value;
        }
        if ($isAttributes && is_array($value)) {
            return self::attributes($value, $c);
        }
        if (is_string($value)) {
            return self::scalar($value, $c);
        }
        return $value;
    }

    /** @param array<int,mixed> $list  [{Name, Value}, ...] */
    private static function attributes(array $list, array $c): array
    {
        $labelMap = self::lowerKeys((array) ($c['label_map'] ?? []));
        $words    = (array) ($c['label_words'] ?? []);
        $drop     = array_map(fn ($d) => mb_strtolower(trim((string) $d)), (array) ($c['drop_labels'] ?? []));

        $out = [];
        foreach ($list as $a) {
            if (! is_array($a) || ! isset($a['Name'])) {
                $out[] = $a;
                continue;
            }
            $raw   = trim((string) $a['Name']);
            $label = $labelMap[mb_strtolower($raw)] ?? $raw;
            foreach ($words as $from => $to) {
                $from = trim((string) $from);
                if ($from === '') { continue; }
                $label = preg_replace('/(?<![\p{L}\p{N}])' . preg_quote($from, '/') . '(?![\p{L}\p{N}])/iu', (string) $to, $label) ?? $label;
            }
            if (($c['label_case'] ?? '') === 'sentence') {
                $label = self::sentence($label);
            }
            if (in_array(mb_strtolower($raw), $drop, true) || in_array(mb_strtolower($label), $drop, true)) {
                continue;
            }

            $v = $a['Value'] ?? null;
            if (is_string($v)) {
                $v = self::scalar($v, $c, $label);
            }
            if ($v === null || (is_string($v) && trim($v) === '')) {
                continue;
            }
            $out[] = ['Name' => $label, 'Value' => $v] + $a;
        }
        return $out;
    }

    private static function scalar(string $v, array $c, ?string $label = null): ?string
    {
        foreach ((array) ($c['replace'] ?? []) as $r) {
            $pattern = is_array($r) ? (string) ($r['pattern'] ?? '') : '';
            if ($pattern === '') { continue; }
            $new = @preg_replace('~' . str_replace('~', '\~', $pattern) . '~iu', (string) ($r['with'] ?? ''), $v);
            if ($new !== null) {
                $v = $new;
            }
        }
        $v = trim($v);

        $map = self::lowerKeys((array) ($c['value_map'] ?? []));
        $k   = mb_strtolower($v);
        if ($label !== null && array_key_exists(mb_strtolower($label) . ': ' . $k, $map)) {
            $v = (string) $map[mb_strtolower($label) . ': ' . $k];
        } elseif (array_key_exists($k, $map)) {
            $v = (string) $map[$k];
        }

        foreach ((array) ($c['blank_values'] ?? []) as $b) {
            if (mb_strtolower(trim((string) $b)) === mb_strtolower(trim($v))) {
                return null;
            }
        }

        return match ($c['value_case'] ?? '') {
            'sentence'          => self::sentence($v),
            'title_to_sentence' => preg_match('/^\p{Lu}\p{Ll}+( \p{Lu}\p{Ll}+)+$/u', $v) ? self::sentence($v) : $v,
            'first_upper'       => $v === '' ? $v : mb_strtoupper(mb_substr($v, 0, 1)) . mb_substr($v, 1),
            default             => $v,
        };
    }

    /** Words in capitals (SPD, MIPS, SM-SH51) keep them; everything else is lower case after the first letter. */
    private static function sentence(string $s): string
    {
        $words = preg_split('/(\s+)/u', trim($s), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        foreach ($words as $i => $w) {
            if (trim($w) === '' || preg_match('/^[\p{Lu}0-9\-\/]{2,}$/u', $w)) { continue; }
            $words[$i] = mb_strtolower($w);
        }
        $out = implode('', $words);
        return $out === '' ? $out : mb_strtoupper(mb_substr($out, 0, 1)) . mb_substr($out, 1);
    }

    private static function lowerKeys(array $m): array
    {
        $out = [];
        foreach ($m as $k => $v) {
            $out[mb_strtolower(trim((string) $k))] = $v;
        }
        return $out;
    }
}
