#!/usr/bin/env python3
"""Inventory reports — part 1: the calculation service.

Read-only. No schema change, no writes, nothing that can damage stock.

This is also the local half of the Sell Through engine, so the grain is
chosen to match what distro wants for the cross-shop rollup: weekly
units-moved per item, sparse (no row where nothing moved). The same
method that feeds the movers table can later feed the consented push,
rather than being rewritten.

Three decisions the numbers depend on, made explicit because they change
what the figures mean:

  * REFUNDS SUBTRACT. Refund lines carry POSITIVE quantities on a sale
    row flagged with refund_of_sale_id. Summing lines naively makes a
    refund INCREASE reported sales. Net units subtracts them.

  * TURNS ARE TRAILING 12 MONTHS, not an annualized 90 days. Bike retail
    is violently seasonal: a 90-day annualization reads wildly high in
    July and wrong in January. Recent 90-day pace is reported alongside,
    never instead.

  * TURNS USE CURRENT STOCK AS THE DENOMINATOR. Proper turns divide COGS
    by AVERAGE inventory, and there is no inventory history to average —
    only computed_stock_count as it stands now. The figure is labelled
    for what it is rather than presented as a textbook turns number.
Run from repo root: python3 apply-inventory-reports-service.py
"""
import os, sys

ROOT = os.getcwd()
def newfile(p, content, label):
    full = os.path.join(ROOT, p)
    if os.path.exists(full):
        print(f"SKIP (exists): {label}"); return
    os.makedirs(os.path.dirname(full), exist_ok=True)
    with open(full, 'w') as f: f.write(content)
    print(f"OK: {label}")

newfile('app/Services/Tenant/InventoryReportService.php', """<?php

namespace App\\Services\\Tenant;

use App\\Models\\Tenant;
// Tenant::localToday() returns \\Carbon\\Carbon; Illuminate\\Support\\Carbon
// EXTENDS it, so hinting the subclass would reject a plain Carbon.
use Carbon\\Carbon;
use Illuminate\\Support\\Facades\\DB;

/**
 * MARKER-INV-REPORTS — what you're holding, what's moving, what's stuck.
 *
 * Read-only. Also the local half of Sell Through: unitsByWeek() produces
 * the (item x week) sparse grain distro wants for the cross-shop rollup,
 * so the same computation serves both rather than diverging.
 *
 * NOTHING here ships data anywhere. The push to distro is consent-gated
 * per tenant->rep relationship and is a separate piece of work.
 */
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
        $row = DB::table('tenant_inventory_items')
            ->where('tenant_id', $this->tenant->id)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->where('computed_stock_count', '>', 0)
            ->selectRaw('COUNT(*) as skus')
            ->selectRaw('SUM(computed_stock_count) as units')
            ->selectRaw('SUM(computed_stock_count * COALESCE(shop_cost_cents, catalog_cost_cents, 0)) as cost')
            ->selectRaw('SUM(computed_stock_count * COALESCE(shop_sell_price_cents, catalog_msrp_cents, 0)) as retail')
            ->first();

        $cost   = (int) ($row->cost ?? 0);
        $retail = (int) ($row->retail ?? 0);

        return [
            'skus'         => (int) ($row->skus ?? 0),
            'units'        => (int) ($row->units ?? 0),
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
            ->selectRaw('SUM(computed_stock_count * COALESCE(shop_cost_cents, catalog_cost_cents, 0)) as cost')
            ->first();

        $items = (clone $q)
            ->select('id', 'sku', 'name', 'computed_stock_count')
            ->selectRaw('computed_stock_count * COALESCE(shop_cost_cents, catalog_cost_cents, 0) as tied_cents')
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

    /** Holdings and movement per category. */
    public function byCategory(Carbon $from, Carbon $to): array
    {
        $sold = $this->netSoldByItem($from, $to);

        $items = DB::table('tenant_inventory_items as i')
            ->leftJoin('tenant_inventory_categories as c', 'c.id', '=', 'i.category_id')
            ->where('i.tenant_id', $this->tenant->id)
            ->where('i.is_active', true)
            ->whereNull('i.deleted_at')
            ->select('i.id', 'i.computed_stock_count', 'c.name as category')
            ->selectRaw('COALESCE(i.shop_cost_cents, i.catalog_cost_cents, 0) as unit_cost')
            ->get();

        $rows = [];
        foreach ($items as $it) {
            $key = $it->category ?: 'Uncategorized';
            $rows[$key] ??= ['category' => $key, 'skus' => 0, 'units' => 0, 'cost_cents' => 0, 'sold_units' => 0.0];
            $rows[$key]['skus']++;
            $rows[$key]['units']      += (int) $it->computed_stock_count;
            $rows[$key]['cost_cents'] += (int) $it->computed_stock_count * (int) $it->unit_cost;
            $rows[$key]['sold_units'] += $sold[$it->id]['units'] ?? 0;
        }

        foreach ($rows as $k => $r) {
            // Sell-through = sold / (sold + still on hand): the share of what
            // you had that actually left.
            $base = $r['sold_units'] + $r['units'];
            $rows[$k]['sell_through_pct'] = $base > 0 ? round(($r['sold_units'] / $base) * 100, 1) : null;
        }

        usort($rows, fn ($a, $b) => $b['cost_cents'] <=> $a['cost_cents']);
        return array_values($rows);
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
""", "InventoryReportService")

print("\\nPart 1 of 2 done — run apply-inventory-reports-page.py next.")
