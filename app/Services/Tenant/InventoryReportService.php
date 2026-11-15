<?php

namespace App\Services\Tenant;

use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * what you're holding, what's moving, what's stuck.
 *
 * Read-only. Also the local half of Sell Through: unitsByWeek() produces
 * the (item x week) sparse grain distro wants for the cross-shop rollup,
 * so the same computation serves both rather than diverging.
 *
 * NOTHING here ships data anywhere. The push to distro is consent-gated
 * per tenant->rep relationship and is a separate piece of work.
 */
// these valuations used COALESCE(shop, catalog) while
// TenantInventoryItem::effectiveCostCents() used received ?? catalog. Two
// different orders over the same three columns, so stock valuation and the
// register could disagree about the cost of the same item. Both now read
// received ?? shop ?? catalog. Keep the two in step: if one changes, so does
// the other.
class InventoryReportService
{
    public function __construct(private Tenant $tenant) {}

    /** Days without a sale before stock counts as dead. Tenant-settable. */
    public function deadStockDays(): int
    {
        $v = data_get($this->tenant->settings, 'inventory.dead_stock_days');
        return is_numeric($v) && (int) $v > 0 ? (int) $v : 180;
    }

    /**
     * Net units sold per item in a window.
     *
     * Refund lines are POSITIVE quantities on a sale whose
     * refund_of_sale_id is set, so they must be subtracted — summing every
     * line would let a refund inflate sales. Same for COGS.
     *
     * @return array<string, array{units: float, revenue_cents: int, cogs_cents: int}>
     */
    public function netSoldByItem(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('tenant_sale_items as li')
            ->join('tenant_sales as s', 's.id', '=', 'li.sale_id')
            ->where('li.tenant_id', $this->tenant->id)
            ->whereNotNull('li.inventory_item_id')
            ->where('s.payment_status', 'paid')
            ->whereBetween('s.sale_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('li.inventory_item_id as item_id')
            // A refund row's lines count negative on every measure.
            ->selectRaw('SUM(CASE WHEN s.refund_of_sale_id IS NULL THEN li.quantity ELSE -li.quantity END) as units')
            ->selectRaw('SUM(CASE WHEN s.refund_of_sale_id IS NULL THEN li.line_total_cents ELSE -li.line_total_cents END) as revenue')
            ->selectRaw('SUM(CASE WHEN s.refund_of_sale_id IS NULL THEN COALESCE(li.cost_cents_snapshot,0) * li.quantity ELSE -COALESCE(li.cost_cents_snapshot,0) * li.quantity END) as cogs')
            ->groupBy('li.inventory_item_id')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->item_id] = [
                'units'         => (float) $r->units,
                'revenue_cents' => (int) round($r->revenue),
                'cogs_cents'    => (int) round($r->cogs),
            ];
        }
        return $out;
    }

    /**
     * Sparse weekly units per item — the Sell Through grain.
     *
     * One row per (item, week) ONLY where units moved; zero is the default
     * and needs no row. At ~3-8k SKUs per shop this stays small, which is
     * the whole point of the weekly-sparse choice.
     *
     * @return array<int, array{item_id: string, week_start: string, units: float}>
     */
    public function unitsByWeek(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('tenant_sale_items as li')
            ->join('tenant_sales as s', 's.id', '=', 'li.sale_id')
            ->where('li.tenant_id', $this->tenant->id)
            ->whereNotNull('li.inventory_item_id')
            ->where('s.payment_status', 'paid')
            ->whereBetween('s.sale_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('li.inventory_item_id as item_id')
            // Monday-start weeks, so a week means the same thing everywhere.
            ->selectRaw('DATE(DATE_SUB(s.sale_date, INTERVAL WEEKDAY(s.sale_date) DAY)) as week_start')
            ->selectRaw('SUM(CASE WHEN s.refund_of_sale_id IS NULL THEN li.quantity ELSE -li.quantity END) as units')
            ->groupBy('li.inventory_item_id', 'week_start')
            ->havingRaw('SUM(CASE WHEN s.refund_of_sale_id IS NULL THEN li.quantity ELSE -li.quantity END) <> 0')
            ->get();

        return $rows->map(fn ($r) => [
            'item_id'    => $r->item_id,
            'week_start' => $r->week_start,
            'units'      => (float) $r->units,
        ])->all();
    }

    /** What the shelf is worth right now. */
    public function valuation(): array
    {
        // oversell is allowed, so an item sold but
        // never received carries a NEGATIVE computed_stock_count. Multiplying
        // that by unit cost produced negative money on screen, which means
        // nothing. Value math floors on-hand at zero; the negatives are
        // reported separately below because they're a real thing to fix.
        $row = DB::table('tenant_inventory_items')
            ->where('tenant_id', $this->tenant->id)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->selectRaw('SUM(CASE WHEN computed_stock_count > 0 THEN 1 ELSE 0 END) as skus')
            ->selectRaw('SUM(GREATEST(computed_stock_count, 0)) as units')
            ->selectRaw('SUM(GREATEST(computed_stock_count, 0) * COALESCE(received_cost_cents, shop_cost_cents, catalog_cost_cents, 0)) as cost')
            ->selectRaw('SUM(GREATEST(computed_stock_count, 0) * COALESCE(shop_sell_price_cents, catalog_msrp_cents, 0)) as retail')
            ->selectRaw('SUM(CASE WHEN computed_stock_count < 0 THEN 1 ELSE 0 END) as negative_skus')
            ->first();

        $cost   = (int) ($row->cost ?? 0);
        $retail = (int) ($row->retail ?? 0);

        return [
            'skus'          => (int) ($row->skus ?? 0),
            'units'         => (int) ($row->units ?? 0),
            // Sold but never received — worth fixing, so it's shown.
            'negative_skus' => (int) ($row->negative_skus ?? 0),
            'cost_cents'   => $cost,
            'retail_cents' => $retail,
            // Margin on what's SITTING here, not on what sold — a different
            // question from the one the money report answers.
            'margin_pct'   => $retail > 0 ? round((($retail - $cost) / $retail) * 100, 1) : null,
        ];
    }

    /**
     * Turns, trailing 12 months.
     *
     * Textbook turns divide COGS by AVERAGE inventory at cost. There is no
     * inventory history to average, so this uses current stock at cost.
     * Reported as such — a made-up average would be worse than an honest
     * approximation.
     */
    public function turns(): array
    {
        $to   = $this->tenant->localToday();
        $from = $to->copy()->subYear();

        $sold = $this->netSoldByItem($from, $to);
        $cogs = array_sum(array_column($sold, 'cogs_cents'));

        $onHandCost = $this->valuation()['cost_cents'];

        // Recent pace, shown BESIDE the annual figure, never instead of it:
        // a 90-day annualization in a seasonal trade is a fiction.
        $recentFrom = $to->copy()->subDays(90);
        $recent     = $this->netSoldByItem($recentFrom, $to);
        $recentCogs = array_sum(array_column($recent, 'cogs_cents'));

        return [
            'cogs_12mo_cents'  => $cogs,
            'on_hand_cost'     => $onHandCost,
            'turns'            => $onHandCost > 0 ? round($cogs / $onHandCost, 1) : null,
            'recent_90d_cents' => $recentCogs,
            'basis'            => 'current stock at cost (no inventory history to average)',
        ];
    }

    /** Stock that hasn't sold in the dead-stock window. */
    public function deadStock(int $limit = 50): array
    {
        $days   = $this->deadStockDays();
        $cutoff = $this->tenant->localToday()->copy()->subDays($days);

        // Items that HAVE sold since the cutoff — everything else is stuck.
        $movedIds = DB::table('tenant_sale_items as li')
            ->join('tenant_sales as s', 's.id', '=', 'li.sale_id')
            ->where('li.tenant_id', $this->tenant->id)
            ->whereNotNull('li.inventory_item_id')
            ->where('s.payment_status', 'paid')
            ->whereNull('s.refund_of_sale_id')
            ->where('s.sale_date', '>=', $cutoff->toDateString())
            ->distinct()
            ->pluck('li.inventory_item_id');

        $q = DB::table('tenant_inventory_items')
            ->where('tenant_id', $this->tenant->id)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->where('computed_stock_count', '>', 0)
            ->when($movedIds->isNotEmpty(), fn ($x) => $x->whereNotIn('id', $movedIds));

        $totals = (clone $q)
            ->selectRaw('COUNT(*) as skus')
            ->selectRaw('SUM(computed_stock_count * COALESCE(received_cost_cents, shop_cost_cents, catalog_cost_cents, 0)) as cost')
            ->first();

        $items = (clone $q)
            ->select('id', 'sku', 'name', 'computed_stock_count')
            ->selectRaw('computed_stock_count * COALESCE(received_cost_cents, shop_cost_cents, catalog_cost_cents, 0) as tied_cents')
            ->orderByDesc('tied_cents')
            ->limit($limit)
            ->get();

        return [
            'days'       => $days,
            'skus'       => (int) ($totals->skus ?? 0),
            'cost_cents' => (int) ($totals->cost ?? 0),
            'items'      => $items,
        ];
    }

    /**
     * Holdings and movement per category.
     *
     * MARKER-INV-REPORT-LEVELS: $level 'all' is one row per category an item
     * sits in (the old report). 'top' adds every sub-category into its
     * top-level parent, with 'children' holding the next level down for the
     * open-up view. Rows are keyed by category id, not name: two categories
     * with the same name under different parents used to be merged into one row.
     */
    public function byCategory(Carbon $from, Carbon $to, string $level = 'all'): array
    {
        $sold = $this->netSoldByItem($from, $to);

        $items = DB::table('tenant_inventory_items as i')
            ->where('i.tenant_id', $this->tenant->id)
            ->where('i.is_active', true)
            ->whereNull('i.deleted_at')
            ->select('i.id', 'i.computed_stock_count', 'i.category_id')
            ->selectRaw('COALESCE(i.received_cost_cents, i.shop_cost_cents, i.catalog_cost_cents, 0) as unit_cost')
            ->get();

        $cats = DB::table('tenant_inventory_categories')->where('tenant_id', $this->tenant->id)->whereNull('deleted_at')
            ->get(['id', 'parent_id', 'name'])->keyBy('id');

        // the chain from the top-level category down to this one (guarded against loops)
        $chain = function (?string $id) use ($cats): array {
            $out = []; $seen = 0;
            while ($id && isset($cats[$id]) && $seen++ < 12) { array_unshift($out, $cats[$id]); $id = $cats[$id]->parent_id; }
            return $out;
        };

        $blank = fn (string $key, string $name, ?string $parent = null) => ['key' => $key, 'category' => $name, 'parent' => $parent,
            'skus' => 0, 'units' => 0, 'cost_cents' => 0, 'sold_units' => 0.0, 'children' => []];
        $add = function (array &$row, $it, int $onHand, float $soldUnits) {
            $row['skus']++;
            $row['units']      += $onHand;
            $row['cost_cents'] += $onHand * (int) $it->unit_cost;
            $row['sold_units'] += $soldUnits;
        };

        $rows = [];
        foreach ($items as $it) {
            // floor here too, or a category with
            // oversold items reports negative units and negative cost.
            $onHand = max(0, (int) $it->computed_stock_count);
            $soldUnits = (float) ($sold[$it->id]['units'] ?? 0);
            $path = $it->category_id ? $chain($it->category_id) : [];

            if (! $path) {
                $rows['none'] ??= $blank('none', 'Uncategorized');
                $add($rows['none'], $it, $onHand, $soldUnits);
                continue;
            }

            if ($level === 'top') {
                $root = $path[0];
                $rows[$root->id] ??= $blank($root->id, $root->name);
                $add($rows[$root->id], $it, $onHand, $soldUnits);
                // the next level down: the child of the top-level category on this item's path
                $kid = $path[1] ?? null;
                $kk = $kid ? $kid->id : $root->id . ':self';
                $rows[$root->id]['children'][$kk] ??= $blank($kk, $kid ? $kid->name : 'In ' . $root->name . ' itself', $root->name);
                $add($rows[$root->id]['children'][$kk], $it, $onHand, $soldUnits);
            } else {
                $leaf = end($path);
                $parent = count($path) > 1 ? implode(' › ', array_map(fn ($c) => $c->name, array_slice($path, 0, -1))) : null;
                $rows[$leaf->id] ??= $blank($leaf->id, $leaf->name, $parent);
                $add($rows[$leaf->id], $it, $onHand, $soldUnits);
            }
        }

        // Sell-through = sold / (sold + still on hand): the share of what
        // you had that actually left.
        $finish = function (array $r) use (&$finish): array {
            $base = $r['sold_units'] + $r['units'];
            $r['sell_through_pct'] = $base > 0 ? round(($r['sold_units'] / $base) * 100, 1) : null;
            $kids = array_values(array_map($finish, $r['children']));
            // a top-level category whose items all sit directly in it has nothing to open up
            $r['children'] = (count($kids) === 1 && str_ends_with((string) $kids[0]['key'], ':self')) ? [] : $kids;
            return $r;
        };
        $rows = array_values(array_map($finish, $rows));

        return self::sortCategoryRows($rows, 'cost', 'desc');
    }

    public const CATEGORY_SORTS = ['name', 'skus', 'units', 'cost', 'sold', 'st'];

    /** MARKER-INV-REPORT-SORT: sort rows (and each row's children) by a column. Uncategorized always last. */
    public static function sortCategoryRows(array $rows, string $by, string $dir): array
    {
        $by = in_array($by, self::CATEGORY_SORTS, true) ? $by : 'cost';
        $sign = $dir === 'asc' ? 1 : -1;
        $val = fn (array $r) => match ($by) {
            'name'  => mb_strtolower($r['category']),
            'skus'  => $r['skus'], 'units' => $r['units'], 'cost' => $r['cost_cents'], 'sold' => $r['sold_units'],
            'st'    => $r['sell_through_pct'] ?? -1,
        };
        usort($rows, function ($a, $b) use ($val, $sign) {
            if (($a['key'] ?? '') === 'none') return 1;
            if (($b['key'] ?? '') === 'none') return -1;
            return ($val($a) <=> $val($b)) * $sign ?: strcmp(mb_strtolower($a['category']), mb_strtolower($b['category']));
        });
        foreach ($rows as &$r) {
            if (! empty($r['children'])) $r['children'] = self::sortCategoryRows($r['children'], $by, $dir);
        }
        unset($r);
        return $rows;
    }

    /** Best and worst movers by net units in the window. */
    public function movers(Carbon $from, Carbon $to, int $limit = 10): array
    {
        $sold = $this->netSoldByItem($from, $to);
        if (! $sold) return ['top' => [], 'slow' => []];

        $names = DB::table('tenant_inventory_items')
            ->where('tenant_id', $this->tenant->id)
            ->whereIn('id', array_keys($sold))
            ->select('id', 'sku', 'name', 'computed_stock_count')
            ->get()
            ->keyBy('id');

        $rows = [];
        foreach ($sold as $id => $m) {
            if (! isset($names[$id]) || $m['units'] <= 0) continue;
            $rows[] = [
                'id'      => $id,
                'sku'     => $names[$id]->sku,
                'name'    => $names[$id]->name,
                'units'   => $m['units'],
                'revenue' => $m['revenue_cents'],
                'on_hand' => (int) $names[$id]->computed_stock_count,
            ];
        }

        usort($rows, fn ($a, $b) => $b['units'] <=> $a['units']);
        $top = array_slice($rows, 0, $limit);

        // "Slow" means it sold at least once but barely — items that never
        // sold at all are dead stock, a different list with a different fix.
        $slow = array_slice(array_reverse($rows), 0, $limit);

        return ['top' => $top, 'slow' => $slow];
    }
}
