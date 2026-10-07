<?php
// MARKER-REG-GROUPED

namespace App\Services\Tenant;

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
        $codeLike = (bool) preg_match('/^\S*\d\S*$/u', trim($q)) && mb_strlen(trim($q)) >= 4;
        if ($codeLike) {
            $scope = 'all';
        }

        $brandSql = "COALESCE(NULLIF({$t}.shop_brand, ''), (SELECT pdc_b.manufacturer FROM platform_distributor_catalogs pdc_b WHERE pdc_b.id = {$t}.distributor_catalog_id))";

        // MARKER-REG-FAST — ONE pass over the items. The brand and supplier
        // lists and both filters are worked out from it here; they used to be
        // three more full passes, which is most of why a search took seconds.
        $all = (clone $query)->toBase()
            ->select(["{$t}.id", "{$t}.name", "{$t}.display_subtitle", "{$t}.sku", "{$t}.size", "{$t}.color", "{$t}.computed_stock_count"])
            ->selectRaw("{$brandSql} as brand")
            ->orderBy("{$t}.name")
            ->limit(self::SCAN_CAP + 1)
            ->get();
        $scanCapped = $all->count() > self::SCAN_CAP;
        $all = $all->take(self::SCAN_CAP);

        // Suppliers per matched item: an indexed lookup on the ids just read.
        $supOf = [];
        foreach ($all->pluck('id')->chunk(1000) as $chunk) {
            DB::table('tenant_inventory_item_vendors as iv_x')
                ->join('tenant_vendors as v_x', 'v_x.id', '=', 'iv_x.vendor_id')
                ->whereIn('iv_x.inventory_item_id', $chunk->values()->all())
                ->get(['iv_x.inventory_item_id', 'v_x.name'])
                ->each(function ($r) use (&$supOf) {
                    if (trim((string) $r->name) !== '') { $supOf[$r->inventory_item_id][(string) $r->name] = true; }
                });
        }

        $okBrand = fn ($r) => $brand === '' || trim((string) $r->brand) === $brand;
        $okSup   = fn ($r) => $supplier === '' || isset($supOf[$r->id][$supplier]);

        // Each list ignores its own filter, so picking a brand never empties
        // the brand list.
        $brands = $all->filter($okSup)->map(fn ($r) => trim((string) $r->brand))->filter()->unique()
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
        $n = mb_strtolower(self::strip($name, $size, $color));
        $n = preg_replace('/(?<![\p{L}\p{N}])(ea|each|pr|pair)(?![\p{L}\p{N}])/u', ' ', $n);
        $n = preg_replace('/[^\p{L}\p{N}.+]+/u', ' ', $n);
        return trim(preg_replace('/\s+/u', ' ', $n));
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

        // Sizes in wearing order (S, M, L…) or numeric order, not A–Z.
        if ($sizes) {
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
    private static function diffLabel(object $r, array $items): string
    {
        $words = fn ($x) => preg_split('/[\s,;·|]+/u', trim((string) $x->name . ' ' . (string) $x->display_subtitle)) ?: [];
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
            if (count($own) >= 4) {
                break;
            }
        }
        $label = implode(' ', $own);
        return $label !== '' ? $label : ((string) $r->sku ?: '—');
    }
}
