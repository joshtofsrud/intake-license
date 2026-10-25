<?php

namespace App\Support\Dashboard;

/**
 * A template's layout: rows, each split into columns (12-unit widths), each
 * column a stack of placed blocks.
 *
 *   [ ['split' => [7, 5], 'cols' => [ [['b' => 'queue']], [['b' => 'week'], ['b' => 'last30']] ]], ... ]
 *
 * Stored as JSON on dashboard_template_versions.layout.
 */
class Layout
{
    /** The column splits a row can have. Each adds up to 12. */
    public const SPLITS = [[12], [6, 6], [8, 4], [4, 8], [7, 5], [5, 7], [4, 4, 4]];

    public const SPLIT_NAMES = [
        '12' => 'Full width', '6-6' => 'Half and half', '8-4' => 'Wide and narrow', '4-8' => 'Narrow and wide',
        '7-5' => '7 and 5', '5-7' => '5 and 7', '4-4-4' => 'Thirds',
    ];

    public static function splitName(array $split): string
    {
        return self::SPLIT_NAMES[implode('-', $split)] ?? implode(' · ', $split);
    }

    /**
     * A layout fit to store: allowed splits only, known blocks only, each
     * block at most once, extra column settings dropped. Empty rows are kept
     * (the editor drops blocks into them); shops never see them.
     */
    public static function sanitize(array $rows): array
    {
        $known = BlockRegistry::builtIns();
        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            $split = array_values(array_map('intval', (array) ($row['split'] ?? [12])));
            if (! in_array($split, self::SPLITS, true)) {
                $split = [12];
            }
            $cols = [];
            foreach ($split as $i => $_) {
                $col = [];
                foreach ((array) (($row['cols'] ?? [])[$i] ?? []) as $p) {
                    $key = is_array($p) ? ($p['b'] ?? null) : null;
                    if (! is_string($key) || ! isset($known[$key]) || isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $col[] = ['b' => $key];
                }
                $cols[] = $col;
            }
            $out[] = ['split' => $split, 'cols' => $cols];
        }

        return $out;
    }

    /**
     * What changed from $live to $draft, in words, for the editor and the
     * publish dialog. Each entry is [kind, text]; kind is added / removed / moved / layout.
     */
    public static function diff(?array $live, array $draft, array $names = []): array
    {
        $name = fn (string $k) => $names[$k] ?? (BlockRegistry::builtIns()[$k]['name'] ?? $k);
        $draftKeys = self::blockKeys($draft);
        if ($live === null) {
            return [['added', 'First version, ' . count($draftKeys) . ' ' . \Illuminate\Support\Str::plural('block', count($draftKeys))]];
        }
        $L = self::locations($live);
        $D = self::locations($draft);
        $out = [];
        foreach ($D as $k => $_) {
            if (! isset($L[$k])) $out[] = ['added', 'Added ' . $name($k)];
        }
        foreach ($L as $k => $_) {
            if (! isset($D[$k])) $out[] = ['removed', 'Removed ' . $name($k)];
        }
        foreach ($D as $k => $d) {
            if (! isset($L[$k])) continue;
            $l = $L[$k];
            // order among the blocks both versions share, so adding one above
            // another doesn't count as moving it
            $ordL = array_values(array_filter($l['col'], fn ($x) => isset($D[$x])));
            $ordD = array_values(array_filter($d['col'], fn ($x) => isset($L[$x])));
            if ($l['r'] !== $d['r'] || $l['c'] !== $d['c'] || array_search($k, $ordL, true) !== array_search($k, $ordD, true)) {
                $out[] = ['moved', 'Moved ' . $name($k)];
            }
        }
        $ls = array_map(fn ($r) => implode('-', $r['split'] ?? [12]), $live);
        $ds = array_map(fn ($r) => implode('-', $r['split'] ?? [12]), $draft);
        if ($ls !== $ds) {
            $out[] = ['layout', 'Changed the rows'];
        }

        return $out;
    }

    /** block key => [r, c, col (keys in that column)] */
    private static function locations(array $rows): array
    {
        $m = [];
        foreach (array_values($rows) as $r => $row) {
            foreach (array_values((array) ($row['cols'] ?? [])) as $c => $col) {
                $keys = array_values(array_filter(array_map(fn ($p) => is_array($p) ? ($p['b'] ?? null) : null, (array) $col)));
                foreach ($keys as $k) {
                    $m[$k] = ['r' => $r, 'c' => $c, 'col' => $keys];
                }
            }
        }

        return $m;
    }

    /** Every block key in the layout, in reading order. */
    public static function blockKeys(array $rows): array
    {
        $keys = [];
        foreach ($rows as $row) {
            foreach ((array) ($row['cols'] ?? []) as $col) {
                foreach ((array) $col as $p) {
                    if (is_array($p) && isset($p['b']) && is_string($p['b'])) {
                        $keys[] = $p['b'];
                    }
                }
            }
        }

        return $keys;
    }

    /**
     * The layout as a shop should see it: blocks that no longer exist, or
     * that master admin has hidden everywhere, are taken out; a column left
     * empty stays (it holds its width), a row left with no blocks at all goes.
     */
    public static function visible(array $rows, array $hiddenKeys = []): array
    {
        $known = BlockRegistry::builtIns();
        $out = [];
        foreach ($rows as $row) {
            $split = array_values(array_filter((array) ($row['split'] ?? [12]), fn ($n) => is_int($n) && $n > 0));
            if (! $split) $split = [12];
            $cols = [];
            $any = false;
            foreach ($split as $i => $_) {
                $col = [];
                foreach ((array) (($row['cols'] ?? [])[$i] ?? []) as $p) {
                    $key = is_array($p) ? ($p['b'] ?? null) : null;
                    if (! is_string($key) || ! isset($known[$key]) || in_array($key, $hiddenKeys, true)) {
                        continue;
                    }
                    $col[] = $p;
                    $any = true;
                }
                $cols[] = $col;
            }
            if ($any) {
                $out[] = ['split' => $split, 'cols' => $cols];
            }
        }

        return $out;
    }
}
