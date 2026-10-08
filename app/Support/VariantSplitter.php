<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * MARKER-OPTION-SPLIT
 *
 * Distributors pack several options into one string ("3C MaxxTerra EXO TR
 * 60TPI Folding"), so the register's Version dropdown becomes one long list.
 * Each active rule names an option (Casing) and the words that belong to it
 * (EXO, EXO+, DoubleDown). A rule only becomes a dropdown when it tells at
 * least two variants apart; otherwise its words stay in the Version text.
 * Words no rule knows stay in Version when they still differ between
 * variants, and otherwise show quietly as "Also in the name".
 */
class VariantSplitter
{
    private static ?array $rules = null;

    /** Active rules, longest word first so "EXO+" wins over "EXO". Read once per request. */
    public static function rules(): array
    {
        if (self::$rules !== null) {
            return self::$rules;
        }
        self::$rules = [];
        try {
            $rows = DB::table('variant_split_rules')->where('is_active', true)->orderBy('sort')->orderBy('id')->get();
        } catch (\Throwable $e) {
            report($e);
            return self::$rules;
        }
        foreach ($rows as $r) {
            $words = array_values(array_filter(array_map(fn ($w) => trim((string) $w), json_decode((string) $r->words, true) ?: [])));
            usort($words, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
            $name = trim((string) $r->attribute);
            if ($name === '' || ! $words) { continue; }
            self::$rules[] = [
                'key'     => 's_' . trim(preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($name)), '_'),
                'name'    => $name,
                'applies' => trim((string) $r->applies_to),
                'words'   => $words,
            ];
        }
        return self::$rules;
    }

    /** Test hook: use these rules instead of the table. */
    public static function useRules(?array $rules): void
    {
        self::$rules = $rules;
    }

    /**
     * @param  array  $variants  each with a 'version' string
     * @param  array  $attrs     the group's dropdowns so far (size, color, version)
     * @param  string $catPath   the catalog category path of the group, e.g. "Tires > Mountain Tires"
     * @return array [$variants, $attrs, $names]
     */
    public static function apply(array $variants, array $attrs, string $catPath): array
    {
        if (! in_array('version', $attrs, true) || count($variants) < 2) {
            return [$variants, $attrs, []];
        }
        $names = [];
        $added = [];
        foreach (self::rules() as $rule) {
            if ($rule['applies'] !== '' && mb_stripos($catPath, $rule['applies']) === false) { continue; }
            $vals = [];
            $rest = [];
            foreach ($variants as $i => $v) {
                $vals[$i] = '';
                $rest[$i] = (string) $v['version'];
                foreach ($rule['words'] as $w) {
                    $re = '/(?<![\p{L}\p{N}])' . preg_quote($w, '/') . '(?![\p{L}\p{N}+])/iu';
                    if (preg_match($re, $rest[$i])) {
                        $vals[$i] = $w;
                        $rest[$i] = preg_replace($re, ' ', $rest[$i], 1);
                        break;
                    }
                }
            }
            $distinct = array_unique(array_map('mb_strtolower', $vals));
            $found = array_filter($vals, fn ($x) => $x !== '');
            if (! $found || count($distinct) < 2) { continue; }
            foreach ($variants as $i => &$v) {
                $v[$rule['key']] = $vals[$i];
                $v['version'] = self::tidy($rest[$i]);
            }
            unset($v);
            $names[$rule['key']] = $rule['name'];
            $added[] = $rule['key'];
        }
        if (! $added) {
            return [$variants, $attrs, []];
        }

        $base = array_values(array_filter($attrs, fn ($a) => $a !== 'version'));
        $left = array_unique(array_map(fn ($v) => mb_strtolower($v['version']), $variants));
        if (count($left) > 1) {
            $out = array_merge($base, $added, ['version']);
        } else {
            $out = array_merge($base, $added);
            foreach ($variants as &$v) {
                $v['also'] = implode(' · ', preg_split('/\s+/u', $v['version'], -1, PREG_SPLIT_NO_EMPTY) ?: []);
                $v['version'] = '';
            }
            unset($v);
        }
        return [$variants, $out, $names];
    }

    private static function tidy(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', $s), " \t·,-–/");
    }
}
