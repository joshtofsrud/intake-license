<?php

namespace App\Support;

/**
 * MARKER-NAME-SEARCH: the one way a customer search box matches people.
 *
 * Neither first_name nor last_name alone contains "John Smith", so searches
 * that only looked in each column found nobody once a full name was typed.
 * Here a person matches when:
 *   - the whole text is in any one column (email, phone, a first name...), or
 *   - the whole text is in "first last", or
 *   - every word typed is in their first name, last name or business name,
 *     in any order ("smith john", "john q smith").
 *
 *   CustomerMatch::apply($query, $q, ['email', 'phone', 'business_name'],
 *       fn ($w, $like) => $w->orWhereHas('tags', ...));   // more OR clauses
 *
 * Works on a customer query, inside whereHas('customer', ...), and on rows
 * that copy the name onto themselves (pass the column names).
 */
class CustomerMatch
{
    public static function apply($query, string $q, array $also = ['email', 'phone'], ?callable $extra = null,
        string $first = 'first_name', string $last = 'last_name', array $wordCols = ['business_name'])
    {
        $q = trim(preg_replace('/\s+/u', ' ', $q));
        if ($q === '') return $query;
        $like = self::like($q);
        $words = array_values(array_filter(explode(' ', $q), fn ($w) => $w !== ''));
        $wordCols = array_values(array_intersect($wordCols, $also)) ?: [];

        return $query->where(function ($w) use ($q, $like, $words, $also, $extra, $first, $last, $wordCols) {
            $w->where($first, 'like', $like)->orWhere($last, 'like', $like);
            foreach ($also as $col) $w->orWhere($col, 'like', $like);
            $w->orWhereRaw("CONCAT(COALESCE({$first},''), ' ', COALESCE({$last},'')) LIKE ?", [$like]);

            if (count($words) > 1) {
                $w->orWhere(function ($all) use ($words, $first, $last, $wordCols) {
                    foreach ($words as $word) {
                        $wl = self::like($word);
                        $all->where(function ($one) use ($wl, $first, $last, $wordCols) {
                            $one->where($first, 'like', $wl)->orWhere($last, 'like', $wl);
                            foreach ($wordCols as $col) $one->orWhere($col, 'like', $wl);
                        });
                    }
                });
            }

            if ($extra) $extra($w, $like);
        });
    }

    /** %text% with the user's own % and _ taken literally. */
    public static function like(string $q): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    }
}
