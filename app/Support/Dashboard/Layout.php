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

    /** What each split is called in the editor. Shapes, not twelfths. */
    public const SPLIT_NAMES = [
        '12' => 'Full width', '6-6' => 'Halves', '8-4' => '⅔ + ⅓', '4-8' => '⅓ + ⅔',
        '7-5' => 'Wider left (58/42)', '5-7' => 'Wider right (42/58)', '4-4-4' => 'Thirds',
    ];

    public static function splitName(array $split): string
    {
        return self::SPLIT_NAMES[implode('-', $split)] ?? implode(' + ', array_map(fn ($n) => round($n / 12 * 100) . '%', $split));
    }

    /** A column's share of the row, as people read it: "58%". */
    public static function colWidth(array $split, int $i): string
    {
        return round(($split[$i] ?? 12) / 12 * 100) . '%';
    }

    /**
     * A layout fit to store: allowed splits only, known blocks only, each
     * block at most once, extra column settings dropped. Empty rows are kept
     * (the editor drops blocks into them); shops never see them.
     */
    public static function sanitize(array $rows): array
    {
        $known = BlockRegistry::all();
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
                    $col[] = self::placement($p);
                }
                $cols[] = $col;
            }
            $out[] = ['split' => $split, 'cols' => $cols];
        }

        return $out;
    }

    /**
     * One placed block, cleaned: its key, and whether people can hide it
     * (left out = they can). Who sees a block isn't set here: it follows
     * what each person's role can open (BlockAccess). Older layouts held an
     * Owner/Manager/Staff list per block; it is dropped here.
     */
    public static function placement(array $p): array
    {
        $out = ['b' => (string) $p['b']];
        if (! empty($p['lock'])) {
            $out['lock'] = true;
        }
        // MARKER-DASH-BUILDER: this template's own heading for the block
        $title = trim((string) ($p['title'] ?? ''));
        if ($title !== '') {
            $out['title'] = mb_substr($title, 0, 60);
        }

        return $out;
    }

    /**
     * The layout one person sees: blocks their role can't open the data
     * for ($blocked, from BlockAccess) are left out, then their own choices are applied — hidden blocks dropped, and
     * (when the template allows reordering) each column in their order.
     * Locked blocks can't be hidden. Columns keep their width even when empty.
     *
     * @param array $blocked  block keys this person doesn't get
     * @param array $prefs  ['hidden' => [keys], 'order' => ['r-c' => [keys]]]
     * @param string $rearrange  none | hide | both
     */
    public static function personal(array $rows, array $blocked, array $prefs, string $rearrange): array
    {
        $hidden = $rearrange !== 'none' ? array_map('strval', (array) ($prefs['hidden'] ?? [])) : [];
        $order  = $rearrange === 'both' ? (array) ($prefs['order'] ?? []) : [];
        $out = [];
        foreach ($rows as $r => $row) {
            $cols = [];
            $any = false;
            foreach ($row['cols'] as $c => $col) {
                $col = array_values(array_filter($col, function ($p) use ($blocked, $hidden) {
                    if (in_array($p['b'], $blocked, true)) return false;
                    if (empty($p['lock']) && in_array($p['b'], $hidden, true)) return false;
                    return true;
                }));
                $mine = array_values(array_map('strval', (array) ($order[$r . '-' . $c] ?? [])));
                if ($mine) {
                    usort($col, function ($a, $b) use ($mine) {
                        $ia = array_search($a['b'], $mine, true); $ib = array_search($b['b'], $mine, true);
                        return ($ia === false ? PHP_INT_MAX : $ia) <=> ($ib === false ? PHP_INT_MAX : $ib);
                    });
                }
                $any = $any || $col;
                $cols[] = $col;
            }
            if ($any) $out[] = ['split' => $row['split'], 'cols' => $cols];
        }

        return $out;
    }

    /**
     * What changed from $live to $draft, in words, for the editor and the
     * publish dialog. Each entry is [kind, text]; kind is added / removed / moved / layout.
     */
    public static function diff(?array $live, array $draft, array $names = []): array
    {
        $name = fn (string $k) => $names[$k] ?? (BlockRegistry::all()[$k]['name'] ?? $k);
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
            if (($l['p']['title'] ?? null) !== ($d['p']['title'] ?? null)) {
                $out[] = ['moved', isset($d['p']['title']) ? 'Renamed ' . $name($k) . ' to “' . $d['p']['title'] . '”' : 'Gave ' . $name($k) . ' back its own title'];
            }
            if (! empty($l['p']['lock']) !== ! empty($d['p']['lock'])) {
                $out[] = ['moved', $name($k) . (! empty($d['p']['lock']) ? ' can no longer be hidden' : ' can be hidden')];
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
                foreach ((array) $col as $p) {
                    if (is_array($p) && isset($p['b'])) {
                        $m[$p['b']] = ['r' => $r, 'c' => $c, 'col' => $keys, 'p' => self::placement($p)];
                    }
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
        $known = BlockRegistry::all();
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
                    $col[] = self::placement($p);
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
