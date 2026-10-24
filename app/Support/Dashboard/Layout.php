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
