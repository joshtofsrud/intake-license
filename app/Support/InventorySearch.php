<?php
// MARKER-INV-SEARCH

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
 * Ranking (rank()): an exact identifier first, then names starting with the
 * query, then names containing it as a phrase, then everything else.
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
            return ['used' => '', 'corrected' => null];
        }

        $probe = clone $q;
        self::constrain($probe, $words, $opts);
        if ($probe->exists()) {
            self::constrain($q, $words, $opts);
            return ['used' => $raw, 'corrected' => null];
        }

        // Nothing matched — try the nearest real word for each one.
        $fixed   = [];
        $changed = false;
        foreach ($words as $w) {
            $c = self::looksLikeCode($w) ? null : TenantStaffSearchTerm::correct($tenantId, $w);
            if ($c !== null && mb_strtolower($c) !== mb_strtolower($w)) {
                $fixed[] = $c;
                $changed = true;
            } else {
                $fixed[] = $w;
            }
        }

        if ($changed) {
            $probe = clone $q;
            self::constrain($probe, $fixed, $opts);
            if ($probe->exists()) {
                self::constrain($q, $fixed, $opts);
                $used = implode(' ', $fixed);
                return ['used' => $used, 'corrected' => $used];
            }
        }

        // Still nothing: keep the original constraint so the result is an
        // honest "no matches" for what was typed.
        self::constrain($q, $words, $opts);
        return ['used' => $raw, 'corrected' => null];
    }

    /** Best match first. Call after apply(), before any other orderBy. */
    public static function rank(Builder $q, string $used): void
    {
        $used = trim($used);
        if ($used === '') {
            return;
        }
        $t     = self::T;
        $codes = self::codes($used);
        $in    = implode(',', array_fill(0, count($codes), '?'));
        $esc   = self::escape($used);

        $q->orderByRaw(
            "CASE
               WHEN {$t}.sku IN ({$in}) OR {$t}.catalog_upc IN ({$in}) OR {$t}.catalog_ean IN ({$in}) OR {$t}.catalog_mpn = ?
                 OR EXISTS (SELECT 1 FROM tenant_inventory_item_aliases al_r WHERE al_r.inventory_item_id = {$t}.id AND al_r.code IN ({$in}))
                 OR EXISTS (SELECT 1 FROM tenant_inventory_item_vendors iv_r WHERE iv_r.inventory_item_id = {$t}.id AND iv_r.vendor_sku = ?)
               THEN 0
               WHEN {$t}.name LIKE ? THEN 1
               WHEN {$t}.name LIKE ? THEN 2
               ELSE 3 END",
            array_merge($codes, $codes, $codes, [$used], $codes, [$used], [$esc . '%', '%' . $esc . '%'])
        );
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

    private static function constrain(Builder $q, array $words, array $opts): void
    {
        $t          = self::T;
        $vendorSkus = $opts['vendor_skus'] ?? true;

        $q->where(function ($all) use ($words, $t, $vendorSkus) {
            foreach ($words as $w) {
                $like  = '%' . self::escape($w) . '%';
                $codes = self::codes($w);

                $all->where(function ($one) use ($t, $like, $codes, $vendorSkus) {
                    $one->whereRaw(
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
                        $one->orWhereExists(function ($s) use ($t, $like) {
                            $s->selectRaw('1')->from('tenant_inventory_item_vendors as iv_s')
                              ->whereColumn('iv_s.inventory_item_id', "{$t}.id")
                              ->where('iv_s.vendor_sku', 'like', $like);
                        });
                    }

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
