<?php
// MARKER-REG-GROUPED · MARKER-SEARCH-ONE-PASS

namespace App\Services\Tenant;

use App\Support\InventorySearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Register search, grouped. Takes the item query InventorySearch has already
 * constrained and ranked, and turns its matches into product groups:
 * variants of one product (same name once size and colour are taken out)
 * become one entry with a chip per variant, instead of a flat list cut off
 * at 15 rows.
 *
 * Also returns what the filter row above the results needs: how many groups
 * each stock scope holds, and the brands and suppliers among the matches.
 *
 * Scopes: "here" = available at this register's location (company-wide when
 * no location is set), "remote" = available at another location, "all" =
 * every match whatever its stock. A code-like query (a barcode, SKU or part
 * number) always uses "all": someone holding the box wants the item, not a
 * reminder that the shelf is empty.
 */
class RegisterProductSearch
{
    /** Matches considered per search. Past this, the screen says to narrow. */
    public const CANDIDATE_CAP = 600;

    /** Matches read in the one pass, before brand / supplier filtering. */
    public const SCAN_CAP = 2000;

    private const T = 'tenant_inventory_items';

    public function run(Builder $query, string $tenantId, ?string $locationId, string $q, array $opt = []): array
    {
        $t        = self::T;
        $brand    = trim((string) ($opt['brand'] ?? ''));
        $supplier = trim((string) ($opt['supplier'] ?? ''));
        $limit    = max(25, min(200, (int) ($opt['groups'] ?? 25)));
        $scope    = in_array($opt['scope'] ?? '', ['here', 'remote', 'all'], true) ? $opt['scope'] : 'here';
        // MARKER-REG-SORT — best match unless asked otherwise.
        $sort     = in_array($opt['sort'] ?? '', ['price_asc', 'price_desc', 'name'], true) ? $opt['sort'] : '';
        $codeLike = (bool) preg_match('/^\S*\d\S*$/u', trim($q)) && mb_strlen(trim($q)) >= 4;
        if ($codeLike) {
            $scope = 'all';
        }

        // MARKER-SEARCH-ONE-PASS — ONE read of the matching rows, with a sort
        // key that is a plain column (so the database sorts thousands of
        // matches in milliseconds). Everything else — ranking, brand and
        // supplier lists and filters, grouping, scope — happens here in PHP
        // on at most SCAN_CAP rows, which costs the database nothing more.
        $all = (clone $query)->reorder()->toBase()
            ->select(["{$t}.id", "{$t}.name", "{$t}.display_subtitle", "{$t}.sku", "{$t}.size", "{$t}.color",
                      "{$t}.computed_stock_count", "{$t}.recent_sales", "{$t}.shop_brand", "{$t}.distributor_catalog_id",
                      "{$t}.catalog_upc", "{$t}.catalog_ean", "{$t}.catalog_mpn", "{$t}.search_text",
                      "{$t}.shop_sell_price_cents", "{$t}.catalog_msrp_cents"]) // MARKER-REG-SORT
            ->orderByDesc("{$t}.recent_sales")
            ->orderBy("{$t}.name")
            ->limit(self::SCAN_CAP + 1)
            ->get();
        $scanCapped = $all->count() > self::SCAN_CAP;
        $all = $all->take(self::SCAN_CAP);
        $ids = $all->pluck('id')->all();

        // Catalog brand (for rows without a shop brand) and the catalog's full
        // title (MARKER-CHIP-SPECS — where specs like "134mm 400 lb" live when
        // the shop's own name leaves them out): one lookup by key.
        $catIds = $all->pluck('distributor_catalog_id')->filter()->unique()->values()->all();
        $cats = $catIds
            ? DB::table('platform_distributor_catalogs')->whereIn('id', $catIds)->get(['id', 'manufacturer', 'display_name'])->keyBy('id')->all()
            : [];
        foreach ($all as $r) {
            $cat = $cats[$r->distributor_catalog_id] ?? null;
            $r->brand = trim((string) $r->shop_brand) !== ''
                ? trim((string) $r->shop_brand)
                : trim((string) ($cat->manufacturer ?? ''));
            $r->cat_title = (string) ($cat->display_name ?? '');
        }

        // Suppliers (and their part numbers) per matched item: one lookup.
        $supOf  = [];
        $vskuOf = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            DB::table('tenant_inventory_item_vendors as iv_x')
                ->join('tenant_vendors as v_x', 'v_x.id', '=', 'iv_x.vendor_id')
                ->whereIn('iv_x.inventory_item_id', $chunk)
                ->get(['iv_x.inventory_item_id', 'iv_x.vendor_sku', 'v_x.name'])
                ->each(function ($r) use (&$supOf, &$vskuOf) {
                    if (trim((string) $r->name) !== '') { $supOf[$r->inventory_item_id][(string) $r->name] = true; }
                    if (trim((string) $r->vendor_sku) !== '') { $vskuOf[$r->inventory_item_id][] = mb_strtolower(trim((string) $r->vendor_sku)); }
                });
        }

        // ---- rank in PHP: exact code, then words that start a word, then
        // what sells, then what is on a shelf (the query already put the
        // best sellers first, so ties keep that order).
        $used   = trim($q);
        $codes  = array_map('mb_strtolower', InventorySearch::codes($used));
        $wordsL = array_map(fn ($w) => ' ' . mb_strtolower($w), InventorySearch::words($used));
        $usedL  = mb_strtolower($used);
        $scored = [];
        foreach ($all as $i => $r) {
            $text = $r->search_text !== null && $r->search_text !== ''
                ? $r->search_text
                : ' ' . mb_strtolower(implode(' ', array_filter([$r->name, $r->display_subtitle, $r->sku, $r->catalog_upc, $r->catalog_ean, $r->catalog_mpn, $r->brand, $r->color, $r->size]))) . ' ';
            $own = array_map('mb_strtolower', array_filter([$r->sku, $r->catalog_upc, $r->catalog_ean, $r->catalog_mpn]));
            $exact = array_intersect($own, $codes) || in_array($usedL, $vskuOf[$r->id] ?? [], true)
                || mb_strpos($text, ' ' . $usedL . ' ') !== false;
            $starts = 0;
            foreach ($wordsL as $w) {
                if (mb_strpos($text, $w) !== false) { $starts++; }
            }
            $scored[] = [$exact ? 0 : 1, -$starts, -(int) $r->recent_sales, (int) $r->computed_stock_count > 0 ? 0 : 1, $i];
        }
        usort($scored, fn ($a, $b) => $a <=> $b);
        $all = collect(array_map(fn ($s) => $all[$s[4]], $scored));

        $okBrand = fn ($r) => $brand === '' || $r->brand === $brand;
        $okSup   = fn ($r) => $supplier === '' || isset($supOf[$r->id][$supplier]);

        // Each list ignores its own filter, so picking a brand never empties
        // the brand list.
        $brands = $all->filter($okSup)->map(fn ($r) => $r->brand)->filter()->unique()
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
        $supSet = [];
        foreach ($all->filter($okBrand) as $r) {
            foreach (array_keys($supOf[$r->id] ?? []) as $n) { $supSet[$n] = true; }
        }
        $suppliers = array_keys($supSet);
        natcasesort($suppliers);
        $suppliers = array_values($suppliers);

        $rows   = $all->filter(fn ($r) => $okBrand($r) && $okSup($r))->values();
        $capped = $scanCapped || $rows->count() > self::CANDIDATE_CAP;
        $rows   = $rows->take(self::CANDIDATE_CAP);

        // ---- stock: available here and elsewhere, per item.
        $locNames = DB::table('tenant_locations')
            ->where('tenant_id', $tenantId)->where('is_active', true)
            ->whereNull('deleted_at') // MARKER-REG-LIVE-LOCATIONS — a deleted location is not a location
            ->pluck('name', 'id')->all();
        $multi = count($locNames) > 1;

        $here = [];
        $away = [];
        $reservedAll = [];
        if ($rows->isNotEmpty()) {
            $locRows = DB::table('tenant_inventory_item_locations')
                ->whereIn('inventory_item_id', $rows->pluck('id')->all())
                ->get(['inventory_item_id', 'location_id', 'computed_stock_count', 'reserved_count']);
            foreach ($locRows as $lr) {
                $reservedAll[$lr->inventory_item_id] = ($reservedAll[$lr->inventory_item_id] ?? 0) + (int) $lr->reserved_count;
                if (! isset($locNames[$lr->location_id])) {
                    continue;
                }
                $n = (int) $lr->computed_stock_count - (int) $lr->reserved_count;
                if ($locationId && $lr->location_id === $locationId) {
                    $here[$lr->inventory_item_id] = $n;
                } elseif ($locationId && $n > 0) {
                    $away[$lr->inventory_item_id] = ($away[$lr->inventory_item_id] ?? 0) + $n;
                }
            }
        }
        if (! $locationId) {
            // No register location: the company-wide figure, as the rows show.
            foreach ($rows as $r) {
                $here[$r->id] = (int) $r->computed_stock_count - (int) ($reservedAll[$r->id] ?? 0);
            }
        }

        // ---- group, keeping the ranked order of each group's first match.
        $groups = [];
        foreach ($rows as $r) {
            $key = self::groupKey((string) $r->name, $r->size, $r->color);
            if (! isset($groups[$key])) {
                $groups[$key] = ['items' => [], 'here' => false, 'remote' => false];
            }
            $groups[$key]['items'][] = $r;
            if (($here[$r->id] ?? 0) > 0) { $groups[$key]['here'] = true; }
            if (($away[$r->id] ?? 0) > 0) { $groups[$key]['remote'] = true; }
        }

        $counts = [
            'here'   => count(array_filter($groups, fn ($g) => $g['here'])),
            'remote' => count(array_filter($groups, fn ($g) => $g['remote'])),
            'all'    => count($groups),
        ];

        $inScope = array_values(array_filter($groups, fn ($g) => $scope === 'all' || $g[$scope]));

        // MARKER-REG-SORT — by a group's lowest price, or by its title; best
        // match is the order the groups already have.
        if ($sort !== '') {
            $price = fn ($g) => min(array_map(fn ($r) => (int) ($r->shop_sell_price_cents ?? $r->catalog_msrp_cents ?? PHP_INT_MAX), $g['items']));
            $title = fn ($g) => mb_strtolower((string) $g['items'][0]->name);
            usort($inScope, match ($sort) {
                'price_asc'  => fn ($a, $b) => $price($a) <=> $price($b),
                'price_desc' => fn ($a, $b) => $price($b) <=> $price($a),
                default      => fn ($a, $b) => strnatcmp($title($a), $title($b)),
            });
        }

        $page    = array_slice($inScope, 0, $limit);

        $out = [];
        $ids = [];
        foreach ($page as $g) {
            $out[] = self::shape($g['items']);
            foreach ($g['items'] as $r) {
                $ids[] = $r->id;
            }
        }

        return [
            'item_ids'     => $ids,
            'groups'       => $out,
            'scope'        => $scope,
            'sort'         => $sort, // MARKER-REG-SORT
            'scope_forced' => $codeLike,
            'scope_counts' => $counts,
            'multi_location' => $multi,
            'brands'       => $brands,
            'suppliers'    => $suppliers,
            'brand'        => $brand,
            'supplier'     => $supplier,
            'has_more'     => count($inScope) > $limit,
            'shown'        => count($page),
            'total'        => count($inScope),
            'capped'       => $capped,
        ];
    }

    /** The name with its size and colour taken out, normalised. */
    public static function groupKey(string $name, ?string $size, ?string $color): string
    {
        // MARKER-PRODUCT-FAMILY — "Maxxis Minion DHF 27.5''x2.50 EXO…" and
        // "Maxxis Minion DHF 29''x2.30 Dual…" are one product in different
        // sizes: group on the words before the first size or spec.
        $stripped = self::strip($name, $size, $color);
        $n = mb_strtolower(self::family($stripped) ?? $stripped);
        $n = preg_replace('/(?<![\p{L}\p{N}])(ea|each|pr|pair)(?![\p{L}\p{N}])/u', ' ', $n);
        $n = preg_replace('/[^\p{L}\p{N}.+]+/u', ' ', $n);
        return trim(preg_replace('/\s+/u', ' ', $n));
    }

    /**
     * MARKER-PRODUCT-FAMILY — the words before the first size or spec token
     * (27.5''x2.50, 700x28, 165mm, 2.0/1.8/2.0mm, 400), or null when there is
     * none or fewer than two words come before it. Model codes like M8100
     * or 60TPI are not specs, so "Shimano Deore XT M8100 …" stays apart.
     */
    public static function family(string $name): ?string
    {
        $words = preg_split('/\s+/u', trim($name)) ?: [];
        foreach ($words as $i => $w) {
            $t = trim($w, ",;:()[]–-");
            if ($t === '') {
                continue;
            }
            if (preg_match('/^\d+(\.\d+)?(\'\'|"|”|in|mm|cm|lb|lbs|g|kg|t|°|%)?$/iu', $t)
                || preg_match('/^\d+(\.\d+)?(\'\'|"|”)?x\d/iu', $t)
                || preg_match('/^\d+(\.\d+)?\/\d/u', $t)) {
                if ($i < 2) {
                    return null;
                }
                $fam = trim(implode(' ', array_slice($words, 0, $i)), " \t,;:–-");
                return $fam !== '' ? $fam : null;
            }
        }
        return null;
    }

    /** Remove size and colour as whole words; a size may carry its unit. */
    private static function strip(string $name, ?string $size, ?string $color): string
    {
        foreach ([[$size, true], [$color, false]] as [$x, $isSize]) {
            $x = trim((string) $x);
            if ($x === '') {
                continue;
            }
            $unit = $isSize ? '(?:\s?(?:mm|cm|in|"|t))?' : '';
            $name = preg_replace(
                '/(?<![\p{L}\p{N}])' . preg_quote($x, '/') . $unit . '(?![\p{L}\p{N}])/iu',
                ' ',
                $name
            );
        }
        return $name;
    }

    /** One group as the screen draws it: a title, then rows of chips. */
    private static function shape(array $items): array
    {
        $first = $items[0];
        $title = self::strip((string) $first->name, $first->size, $first->color);
        // Tidy what removing the size and colour left behind: doubled
        // separators, a dangling "EA", separators glued to a word.
        $title = preg_replace('/(?<![\p{L}\p{N}])(ea|each|pr|pair)(?![\p{L}\p{N}])/iu', ' ', $title);
        $title = preg_replace('/(\s*[,\/|]\s*)+/u', ', ', $title);
        $title = preg_replace('/\s+([–-])(?:\s*[,–-])*\s*/u', ' $1 ', $title);
        $title = preg_replace('/\s*,(\s*,)+/u', ',', $title);
        $title = trim(preg_replace('/\s+/u', ' ', $title), " \t,/|-–");
        if ($title === '') {
            $title = (string) $first->name;
        }

        // MARKER-PRODUCT-FAMILY — a group of differently named variants takes
        // the shared product name as its title, and its buttons say what
        // differs (size, casing, compound…) rather than one stored field.
        $variantNames = array_unique(array_map(
            fn ($r) => mb_strtolower(self::strip((string) $r->name, $r->size, $r->color)), $items
        ));
        $isFamily = count($items) > 1 && count($variantNames) > 1;
        if ($isFamily) {
            $title = self::family(self::strip((string) $first->name, $first->size, $first->color)) ?? $title;
        }

        if (count($items) === 1) {
            return ['title' => $title, 'brand' => (string) ($first->brand ?? ''), 'rows' => [
                ['label' => '', 'items' => [['id' => $first->id, 'label' => '']]],
            ]];
        }

        $sizes  = array_filter(array_map(fn ($r) => trim((string) $r->size), $items));
        $colors = array_unique(array_filter(array_map(fn ($r) => trim((string) $r->color), $items)));

        $rows = [];
        if ($sizes && count($colors) > 1) {
            foreach ($items as $r) {
                $c = trim((string) $r->color);
                $rows[$c]['label'] = $c;
                $rows[$c]['items'][] = ['id' => $r->id, 'label' => trim((string) $r->size) ?: self::diffLabel($r, $items)];
            }
            $rows = array_values($rows);
        } elseif (! $sizes && count($colors) > 1) {
            $rows[] = ['label' => '', 'items' => array_map(
                fn ($r) => ['id' => $r->id, 'label' => trim((string) $r->color) ?: self::diffLabel($r, $items)],
                $items
            )];
        } else {
            $rows[] = ['label' => '', 'items' => array_map(
                fn ($r) => ['id' => $r->id, 'label' => trim((string) $r->size) ?: self::diffLabel($r, $items)],
                $items
            )];
        }

        if ($isFamily) {
            // MARKER-PRODUCT-FAMILY
            $rows = [['label' => '', 'items' => array_map(
                fn ($r) => ['id' => $r->id, 'label' => self::diffLabel($r, $items, 6)],
                $items
            )]];
        }
        // A button label that two variants share tells staff nothing.
        foreach ($rows as &$row) {
            $seen = array_count_values(array_map(fn ($x) => $x['label'], $row['items']));
            foreach ($row['items'] as &$it) {
                if (($seen[$it['label']] ?? 0) > 1) {
                    foreach ($items as $r) {
                        if ($r->id === $it['id']) { $it['label'] = self::diffLabel($r, $items, 6); break; }
                    }
                }
            }
            unset($it);
            // Still the same after reading the names and catalog titles: the
            // SKU is the only thing left that tells them apart.
            $seen = array_count_values(array_map(fn ($x) => $x['label'], $row['items']));
            foreach ($row['items'] as &$it) {
                if (($seen[$it['label']] ?? 0) > 1) {
                    foreach ($items as $r) {
                        if ($r->id === $it['id'] && (string) $r->sku !== '') { $it['label'] .= ' · ' . $r->sku; break; }
                    }
                }
            }
            unset($it);
        }
        unset($row);

        // Sizes in wearing order (S, M, L…) or numeric order, not A–Z.
        if ($sizes || $isFamily) {
            foreach ($rows as &$row) {
                usort($row['items'], fn ($a, $b) => self::sizeOrder($a['label']) <=> self::sizeOrder($b['label']));
            }
            unset($row);
        }

        return ['title' => $title, 'brand' => (string) ($first->brand ?? ''), 'rows' => $rows];
    }

    private static function sizeOrder(string $label): array
    {
        $l = mb_strtolower(trim($label));
        $named = ['xxs' => 1, '2xs' => 1, 'xs' => 2, 'extra small' => 2, 'x-small' => 2, 's' => 3, 'small' => 3,
                  's/m' => 4, 'm' => 5, 'medium' => 5, 'm/l' => 6, 'l' => 7, 'large' => 7, 'l/xl' => 8,
                  'xl' => 9, 'x-large' => 9, 'extra large' => 9, 'xxl' => 10, '2xl' => 10, 'xx-large' => 10,
                  '3xl' => 11, 'xxxl' => 11];
        if (isset($named[$l])) {
            return [0, $named[$l], ''];
        }
        if (preg_match('/^\d+(\.\d+)?/', $l, $m)) {
            return [1, (float) $m[0], $l];
        }
        return [2, 0, $l];
    }

    /**
     * The words that set this item apart from the rest of its group — for
     * variants whose difference lives only in the name or subtitle
     * ("134mm 400 lb"). Falls back to the SKU.
     */
    private static function diffLabel(object $r, array $items, int $max = 4): string
    {
        // MARKER-CHIP-SPECS — specs, not part numbers: read the name and the
        // catalog's full title, and leave out anything that is an identifier
        // (this item's SKU, barcode or MPN, or a code-shaped token like
        // 00.4118.200.079). The subtitle is skipped: it is usually the MPN.
        $codes = fn ($x) => array_map('mb_strtolower', array_filter([
            (string) $x->sku, (string) ($x->catalog_mpn ?? ''), (string) ($x->catalog_upc ?? ''), (string) ($x->catalog_ean ?? ''),
        ]));
        $isCode = fn ($w, $x) => in_array(mb_strtolower(trim($w, '()[],.')), $codes($x), true)
            || preg_match('/^\d+(?:[.\-]\d+){2,}$/', trim($w, '()[],'))
            || preg_match('/^\d{8,}$/', trim($w, '()[],'));
        $words = fn ($x) => array_values(array_filter(
            preg_split('/[\s,;·|]+/u', trim((string) $x->name . ' ' . (string) ($x->cat_title ?? ''))) ?: [],
            fn ($w) => $w !== '' && ! $isCode($w, $x)
        ));
        $common = null;
        foreach ($items as $x) {
            $set = array_flip(array_map('mb_strtolower', array_filter($words($x), fn ($w) => $w !== '')));
            $common = $common === null ? $set : array_intersect_key($common, $set);
        }
        $own  = [];
        $list = array_values(array_filter($words($r), fn ($w) => $w !== ''));
        foreach ($list as $k => $w) {
            $lw = mb_strtolower(trim($w, '()[]'));
            if (isset($common[mb_strtolower($w)]) || isset($own[$lw])) {
                continue;
            }
            $own[$lw] = trim($w, '()[]');
            // A number keeps its unit even when every variant shares it:
            // "400 lb", not "400".
            $next = $list[$k + 1] ?? '';
            if (preg_match('/\d$/', $w) && preg_match('/^[\p{L}#"]{1,3}$/u', $next) && isset($common[mb_strtolower($next)])) {
                $own[$lw] .= ' ' . $next;
            }
            if (count($own) >= $max) {
                break;
            }
        }
        $label = implode(' ', $own);
        return $label !== '' ? $label : ((string) $r->sku ?: '—');
    }
}
