<?php

namespace App\Support;

use App\Models\Tenant\TenantStaffSearchTerm;
use Illuminate\Database\Eloquent\Builder;

/**
 * One search for inventory items, shared by every staff surface that looks
 * an item up: Inventory, Register, the top search bar, work-order parts,
 * receiving and the campaign product picker. Before this each had its own
 * copy and they had drifted — the register ignored supplier part numbers,
 * the top bar needed the whole query as one phrase, receiving matched a UPC
 * only when typed in full.
 *
 * How a query matches:
 *  - It is split into words. EVERY word must appear somewhere on the item,
 *    in any order: name, subtitle, SKU, UPC, EAN, MPN, brand (the shop's or
 *    the catalog's), colour, size, any supplier's part number, or an old
 *    code kept from a merge.
 *  - A barcode also matches its twins: a UPC-A and the EAN-13 / GTIN-14 it
 *    becomes with leading zeros are the same product, whichever form the
 *    scanner or the feed produced.
 *  - If nothing matches, each word is checked against the shop's own word
 *    list and a misspelling (up to two letters off) is swapped for the
 *    nearest real word, then the search runs again. The caller says which
 *    words were used, so the screen can show "Showing results for …".
 *
 * Speed: each item carries search_text — every field
 * above in one lowercase string, rebuilt nightly by inventory:search-text and
 * cleared whenever the item is saved. A word is one LIKE on that column.
 * Items whose search_text is empty (just saved, just imported) fall back to
 * the full field-by-field check, so nothing goes missing between rebuilds.
 *
 * Ranking (rank()): an exact barcode / SKU / part number first; then items
 * where more of the words start a word ("minion" beats "Dominion"); then
 * what sold most in the last 90 days; then what is in stock; then A–Z.
 */
final class InventorySearch
{
    private const T = 'tenant_inventory_items';
    private const MAX_WORDS = 8;

    /**
     * Constrain $q to the search and return which words were used.
     *
     * @return array{used:string, corrected:?string}
     */
    public static function apply(Builder $q, string $tenantId, string $raw, array $opts = []): array
    {
        $raw   = trim($raw);
        $words = self::words($raw);
        if (! $words) {
            return ['used' => '', 'corrected' => null, 'missing' => []];
        }

        $probe = clone $q;
        self::constrain($probe, $words, $opts);
        if ($probe->exists()) {
            self::constrain($q, $words, $opts);
            return ['used' => $raw, 'corrected' => null, 'missing' => []];
        }

        // nothing matched every word. Find the words
        // that match nothing on their own: only those get spell-corrected
        // (correcting a word that already matches, like "rock" in "rock shox
        // metrci", swapped it for something else and lost the search), and
        // if a word still matches nothing it is dropped and reported, so
        // "rock shox metrci" shows RockShox with "metrci" marked as missing.
        $hits = function (array $ws) use ($q, $opts) {
            $p = clone $q;
            self::constrain($p, $ws, $opts);
            return $p->exists();
        };
        $fixed   = [];
        $changed = false;
        $missing = [];
        foreach ($words as $w) {
            if ($hits([$w])) {
                $fixed[] = $w;
                continue;
            }
            $c = self::looksLikeCode($w) ? null : TenantStaffSearchTerm::correct($tenantId, $w);
            if ($c !== null && mb_strtolower($c) !== mb_strtolower($w) && $hits([$c])) {
                $fixed[] = $c;
                $changed = true;
            } else {
                $missing[] = $w;
            }
        }

        if (! $missing && $changed && $hits($fixed)) {
            self::constrain($q, $fixed, $opts);
            $used = implode(' ', $fixed);
            return ['used' => $used, 'corrected' => $used, 'missing' => []];
        }

        if ($missing && $fixed && $hits($fixed)) {
            self::constrain($q, $fixed, $opts);
            $used = implode(' ', $fixed);
            return ['used' => $used, 'corrected' => $changed ? $used : null, 'missing' => $missing];
        }

        // Still nothing: keep the original constraint so the result is an
        // honest "no matches" for what was typed.
        self::constrain($q, $words, $opts);
        return ['used' => $raw, 'corrected' => null, 'missing' => []];
    }

    /**
     * Best match first. Call after apply(), before any other orderBy.
     *
     * the sort key is computed for EVERY matching
     * row before the limit applies, so it must be cheap: plain column
     * compares and LIKEs on the stored text. The old key ran two EXISTS
     * subqueries per matching row; "mi" matches thousands of rows while
     * someone types, and that alone took seconds.
     */
    public static function rank(Builder $q, string $used): void
    {
        $used = trim($used);
        if ($used === '') {
            return;
        }
        $t     = self::T;
        $codes = self::codes($used);
        $in    = implode(',', array_fill(0, count($codes), '?'));

        // 1 — the exact identifier someone scanned or typed. Supplier part
        // numbers and old merged codes are tokens in search_text.
        $q->orderByRaw(
            "CASE WHEN {$t}.sku IN ({$in}) OR {$t}.catalog_upc IN ({$in}) OR {$t}.catalog_ean IN ({$in}) OR {$t}.catalog_mpn = ?
                   OR {$t}.search_text LIKE ? THEN 0 ELSE 1 END",
            array_merge($codes, $codes, $codes, [$used, '% ' . self::escape(mb_strtolower($used)) . ' %'])
        );

        // 2 — how many of the words start a word, not sit inside one.
        $parts = [];
        $binds = [];
        foreach (self::words($used) as $w) {
            $parts[] = "CASE WHEN COALESCE({$t}.search_text, CONCAT(' ', LOWER({$t}.name))) LIKE ? THEN 1 ELSE 0 END";
            $binds[] = '% ' . self::escape(mb_strtolower($w)) . '%';
        }
        if ($parts) {
            $q->orderByRaw('(' . implode(' + ', $parts) . ') DESC', $binds);
        }

        // 3 — what actually sells, then what is on a shelf.
        $q->orderByDesc("{$t}.recent_sales")
          ->orderByRaw("CASE WHEN {$t}.computed_stock_count > 0 THEN 0 ELSE 1 END");
    }

    /** @return string[] */
    public static function words(string $raw): array
    {
        $parts = preg_split('/[\s,]+/u', trim($raw)) ?: [];
        $parts = array_values(array_filter($parts, fn ($w) => $w !== ''));
        return array_slice($parts, 0, self::MAX_WORDS);
    }

    /**
     * A barcode and its same-product twins. "12345678901" style codes of 11
     * to 14 digits are padded or unpadded across UPC-A (12), EAN-13 and
     * GTIN-14, since leading zeros are the only difference.
     *
     * @return string[]
     */
    public static function codes(string $w): array
    {
        $w = trim($w);
        if (! preg_match('/^\d{11,14}$/', $w)) {
            return [$w];
        }
        $core = ltrim($w, '0');
        $out  = [$w];
        foreach ([12, 13, 14] as $len) {
            if ($core !== '' && strlen($core) <= $len) {
                $out[] = str_pad($core, $len, '0', STR_PAD_LEFT);
            }
        }
        return array_values(array_unique($out));
    }

    private static function looksLikeCode(string $w): bool
    {
        return (bool) preg_match('/\d/', $w);
    }

    private static function escape(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    }

    /**
     * The field-by-field check: every column, the catalog brand, old merged
     * codes and supplier part numbers. Only runs for items whose search_text
     * has not been built yet.
     */
    private static function fieldMatch($w, string $t, string $like, bool $vendorSkus): void
    {
        $w->whereRaw(
            "CONCAT_WS(' ', {$t}.name, {$t}.display_subtitle, {$t}.sku, {$t}.catalog_upc, {$t}.catalog_ean, {$t}.catalog_mpn, {$t}.shop_brand, {$t}.color, {$t}.size) LIKE ?",
            [$like]
        )
        // The catalog's brand, for items whose name leaves it out.
        ->orWhereExists(function ($s) use ($t, $like) {
            $s->selectRaw('1')->from('platform_distributor_catalogs as pdc_s')
              ->whereColumn('pdc_s.id', "{$t}.distributor_catalog_id")
              ->where('pdc_s.manufacturer', 'like', $like);
        })
        // Old labels kept from a merge.
        ->orWhereExists(function ($s) use ($t, $like) {
            $s->selectRaw('1')->from('tenant_inventory_item_aliases as al_s')
              ->whereColumn('al_s.inventory_item_id', "{$t}.id")
              ->where('al_s.code', 'like', $like);
        });

        if ($vendorSkus) {
            // Each supplier's own part number.
            $w->orWhereExists(function ($s) use ($t, $like) {
                $s->selectRaw('1')->from('tenant_inventory_item_vendors as iv_s')
                  ->whereColumn('iv_s.inventory_item_id', "{$t}.id")
                  ->where('iv_s.vendor_sku', 'like', $like);
            });
        }
    }

    private static function constrain(Builder $q, array $words, array $opts): void
    {
        $t          = self::T;
        $vendorSkus = $opts['vendor_skus'] ?? true;

        $q->where(function ($all) use ($words, $t, $vendorSkus) {
            foreach ($words as $w) {
                $like  = '%' . self::escape($w) . '%';
                $codes = self::codes($w);

                $all->where(function ($one) use ($t, $like, $codes, $vendorSkus) {
                    // one LIKE on the stored text; the
                    // field-by-field check only for items not indexed yet.
                    $one->where("{$t}.search_text", 'like', $like)
                        ->orWhere(function ($stale) use ($t, $like, $vendorSkus) {
                            $stale->whereNull("{$t}.search_text")
                                  ->where(fn ($old) => self::fieldMatch($old, $t, $like, $vendorSkus));
                        });

                    if (count($codes) > 1) {
                        // A barcode in another length.
                        $one->orWhereIn("{$t}.catalog_upc", $codes)
                            ->orWhereIn("{$t}.catalog_ean", $codes)
                            ->orWhereIn("{$t}.sku", $codes)
                            ->orWhereExists(function ($s) use ($t, $codes) {
                                $s->selectRaw('1')->from('tenant_inventory_item_aliases as al_c')
                                  ->whereColumn('al_c.inventory_item_id', "{$t}.id")
                                  ->whereIn('al_c.code', $codes);
                            });
                    }
                });
            }
        });
    }
}
