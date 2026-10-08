<?php

namespace App\Support;

/**
 * Everything a staff search should find an item by, as one lowercase string
 * with a space at each end (so "% word%" means "a word starting here").
 * Commas, brackets and similar become spaces; hyphens, dots and slashes stay,
 * so part numbers like 942436-01 or 2.0/1.8 still match as typed.
 */
final class ItemSearchText
{
    public static function compose(array $parts): string
    {
        $bits = [];
        foreach ($parts as $p) {
            foreach ((array) $p as $x) {
                $x = trim((string) $x);
                if ($x !== '') {
                    $bits[] = $x;
                }
            }
        }
        $s = mb_strtolower(implode(' ', $bits));
        $s = preg_replace('/[,;:()\[\]|·]+/u', ' ', $s);
        $s = trim(preg_replace('/\s+/u', ' ', $s));

        return ' ' . mb_substr($s, 0, 4000) . ' ';
    }
}
