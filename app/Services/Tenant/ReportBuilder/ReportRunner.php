<?php

namespace App\Services\Tenant\ReportBuilder;

use App\Models\Tenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * ReportRunner — runs one validated config against the tenant's own data.
 *
 * Read-only, tenant-scoped on every table it touches. Only SQL fragments
 * written in this file ever reach the database; the config supplies KEYS that
 * are looked up here, and filter VALUES that are bound as parameters.
 * (MARKER-REPORT-BUILDER-CORE)
 */
class ReportRunner
{
    public function __construct(private readonly Tenant $tenant) {}

    /**
     * @param array $cfg a config already passed through ReportCatalog::clean()
     * @return array{columns: array, rows: array, totals: array, truncated: bool, range_label: string, from: string, to: string}
     */
    public function run(array $cfg): array
    {
        $def = ReportCatalog::datasets()[$cfg['ds']];
        [$from, $to, $rangeLabel] = ReportCatalog::resolveRange($cfg['range'], $this->tenant->localToday());

        $raw = match ($cfg['ds']) {
            'inventory' => $this->inventoryRows($cfg),
            'dist'      => $this->distRows($cfg),
            default     => $this->salesRows($cfg, $from->toDateString(), $to->toDateString()),
        };

        $dimDef = $def['dims'][$cfg['dim']];
        $isTime = $dimDef['type'] === 'time';

        // Turn raw component sums into the measures the tenant asked for.
        $rows = [];
        foreach ($raw as $r) {
            $row = ['label' => (string) $r->dim_label, '_c' => $this->components($r)];
            foreach ($cfg['m'] as $m) {
                $row[$m] = $this->measureValue($cfg['ds'], $m, $row['_c']);
            }
            $rows[] = $row;
        }

        // Totals are worked out from the summed components, never by
        // averaging the rows (an average of averages is the wrong number).
        $sum = [];
        if ($cfg['ds'] === 'sales') {
            // One ticket can span several rows (a sale with a tire and a tune-up
            // is in both categories), so the ticket total is counted on its own
            // ungrouped query instead of adding the rows together.
            $all = $this->salesRows($cfg, $from->toDateString(), $to->toDateString(), true);
            $sum = $all ? $this->components($all[0]) : [];
        } elseif ($cfg['ds'] === 'dist') {
            // Days left and arrival are not additive, so the total comes from
            // its own ungrouped pass.
            $all = $this->distRows($cfg, true);
            $sum = $all ? $this->components($all[0]) : [];
        } else {
            foreach ($rows as $row) {
                foreach ($row['_c'] as $k => $v) {
                    $sum[$k] = ($sum[$k] ?? 0) + $v;
                }
            }
        }
        $totals = [];
        foreach ($cfg['m'] as $m) {
            $totals[$m] = $this->measureValue($cfg['ds'], $m, $sum);
        }

        $truncated = count($rows) > ReportCatalog::ROW_CAP;

        if ($isTime) {
            usort($rows, fn ($a, $b) => strcmp($a['label'], $b['label']));
        } else {
            $first = $cfg['m'][0];
            usort($rows, fn ($a, $b) => $cfg['sort'] === 'asc'
                ? ($a[$first] <=> $b[$first])
                : ($b[$first] <=> $a[$first]));
        }
        $rows = array_slice($rows, 0, ReportCatalog::ROW_CAP);
        if ($cfg['limit'] > 0) {
            $rows = array_slice($rows, 0, $cfg['limit']);
        }

        $columns = [['key' => 'label', 'label' => $dimDef['label'], 'type' => 'text']];
        foreach ($cfg['m'] as $m) {
            $columns[] = ['key' => $m, 'label' => $def['measures'][$m]['label'], 'type' => $def['measures'][$m]['type']];
        }

        return [
            'columns'     => $columns,
            'rows'        => array_map(function ($r) { unset($r['_c']); return $r; }, $rows),
            'totals'      => $totals,
            'truncated'   => $truncated,
            'range_label' => $def['snapshot'] ? 'As of today' : $rangeLabel,
            'from'        => $from->toDateString(),
            'to'          => $to->toDateString(),
        ];
    }

    /** Option lists for the "Only include" filters, tenant-scoped. */
    public function filterOptions(string $ds, bool $isManager): array
    {
        $tid = $this->tenant->id;
        $out = [];

        if ($ds === 'sales') {
            $out['loc'] = DB::table('tenant_locations')->where('tenant_id', $tid)->orderBy('sort_order')->orderBy('name')
                ->pluck('name', 'id')->all();
            $out['cat'] = $this->categoryOptions();
            $out['pay'] = DB::table('tenant_sales')->where('tenant_id', $tid)->whereNotNull('payment_method')
                ->where('payment_method', '!=', '')->distinct()->orderBy('payment_method')
                ->pluck('payment_method', 'payment_method')->all();
            if ($isManager) {
                $out['staff'] = DB::table('tenant_users')->where('tenant_id', $tid)->orderBy('name')->pluck('name', 'id')->all();
            }
        } elseif ($ds === 'dist') {
            $v = ReportCatalog::velocity();
            $codes = \Illuminate\Support\Facades\Cache::remember('rb:dist-codes', 600, fn () =>
                DB::table('distributor_stock_summary')->distinct()->orderBy('distributor_code')->pluck('distributor_code')->all());
            if (is_array($v['vendors'])) {
                $codes = array_values(array_intersect($codes, $v['vendors']));
            }
            $out['dist'] = array_combine($codes, $codes);
            $out['brand'] = \Illuminate\Support\Facades\Cache::remember('rb:brands:' . $tid . ':' . md5(json_encode($v['vendors'])), 600, fn () =>
                DB::table('tenant_inventory_items as it')
                    ->join('platform_distributor_catalogs as pc', 'pc.id', '=', 'it.distributor_catalog_id')
                    ->join('distributor_stock_summary as s', 's.distributor_catalog_id', '=', 'it.distributor_catalog_id')
                    ->when(is_array($v['vendors']), fn ($q) => $q->whereIn('s.distributor_code', $v['vendors'] ?: ['-none-']))
                    ->where('it.tenant_id', $tid)->where('it.is_active', 1)->whereNull('it.deleted_at')
                    ->whereNotNull('pc.manufacturer')->where('pc.manufacturer', '!=', '')
                    ->distinct()->orderBy('pc.manufacturer')->pluck('pc.manufacturer', 'pc.manufacturer')->all());
            $out['cat'] = $this->categoryOptions();
            $out['hist'] = ['good' => 'Good', 'building' => 'Building', 'early' => 'Too early'];
        } else {
            $out['cat']  = $this->categoryOptions();
            $out['vend'] = DB::table('tenant_vendors')->where('tenant_id', $tid)->orderBy('name')->pluck('name', 'id')->all();
        }

        return $out;
    }

    private function categoryOptions(): array
    {
        return DB::table('tenant_inventory_categories')->where('tenant_id', $this->tenant->id)
            ->whereNull('deleted_at')->orderBy('name')->pluck('name', 'id')->all();
    }

    // ------------------------------------------------------------------ sales

    private function salesRows(array $cfg, string $from, string $to, bool $totalOnly = false): array
    {
        $tid = $this->tenant->id;

        $dimExpr = $totalOnly ? "'Total'" : match ($cfg['dim']) {
            'day'   => 's.sale_date',
            'week'  => 'DATE_SUB(s.sale_date, INTERVAL WEEKDAY(s.sale_date) DAY)',
            'month' => "DATE_FORMAT(s.sale_date, '%Y-%m')",
            'loc'   => "COALESCE(l.name, 'No location')",
            'staff' => "COALESCE(u.name, 'Unknown')",
            'cat'   => "CASE WHEN i.type = 'product' THEN COALESCE(c.name, 'Uncategorized') WHEN i.type = 'service' THEN 'Services' ELSE 'Other' END",
            'pay'   => "COALESCE(NULLIF(s.payment_method, ''), 'Unknown')",
            default => 'i.name_snapshot',
        };

        $line = '(i.unit_price_cents * i.quantity - i.discount_cents)';

        $q = DB::table('tenant_sale_items as i')
            ->join('tenant_sales as s', 's.id', '=', 'i.sale_id')
            ->leftJoin('tenant_inventory_items as it', 'it.id', '=', 'i.inventory_item_id')
            ->leftJoin('tenant_inventory_categories as c', 'c.id', '=', 'it.category_id')
            ->leftJoin('tenant_locations as l', 'l.id', '=', 's.location_id')
            ->leftJoin('tenant_users as u', 'u.id', '=', 's.rang_up_by_user_id')
            ->where('s.tenant_id', $tid)
            ->where('i.tenant_id', $tid)
            ->where('s.payment_status', 'paid')
            ->whereNull('s.refund_of_sale_id')
            ->whereBetween('s.sale_date', [$from, $to])
            ->selectRaw("$dimExpr as dim_label")
            ->selectRaw("SUM($line) as c_rev")
            ->selectRaw("SUM(CASE WHEN i.type = 'product' THEN i.quantity ELSE 0 END) as c_items")
            ->selectRaw('COUNT(DISTINCT s.id) as c_tickets')
            ->selectRaw('SUM(i.discount_cents) as c_disc')
            ->selectRaw("SUM(CASE WHEN i.cost_cents_snapshot IS NOT NULL THEN $line - i.cost_cents_snapshot * i.quantity ELSE 0 END) as c_margin")
            ->selectRaw("SUM(CASE WHEN i.cost_cents_snapshot IS NOT NULL THEN $line ELSE 0 END) as c_costed")
            ->groupByRaw($dimExpr);

        $this->applyFilters($q, $cfg['f'], [
            'loc'   => 's.location_id',
            'cat'   => 'it.category_id',
            'pay'   => 's.payment_method',
            'staff' => 's.rang_up_by_user_id',
        ]);

        return $q->get()->all();
    }

    // -------------------------------------------------------------- inventory

    private function inventoryRows(array $cfg): array
    {
        $tid = $this->tenant->id;

        $dimExpr = match ($cfg['dim']) {
            'cat'   => "COALESCE(c.name, 'Uncategorized')",
            'vend'  => "COALESCE(v.name, 'No vendor')",
            default => 'it.name',
        };

        $stock = 'GREATEST(it.computed_stock_count, 0)';

        $q = DB::table('tenant_inventory_items as it')
            ->leftJoin('tenant_inventory_categories as c', 'c.id', '=', 'it.category_id')
            ->leftJoin('tenant_vendors as v', 'v.id', '=', 'it.default_vendor_id')
            ->where('it.tenant_id', $tid)
            ->where('it.is_active', 1)
            ->whereNull('it.deleted_at')
            ->selectRaw("$dimExpr as dim_label")
            ->selectRaw("SUM($stock) as c_units")
            ->selectRaw('COUNT(*) as c_items')
            ->selectRaw("SUM($stock * COALESCE(it.received_cost_cents, it.shop_cost_cents, it.catalog_cost_cents, 0)) as c_cost")
            ->selectRaw("SUM($stock * COALESCE(it.shop_sell_price_cents, it.catalog_msrp_cents, 0)) as c_retail")
            ->groupByRaw($dimExpr);

        $this->applyFilters($q, $cfg['f'], [
            'cat'  => 'it.category_id',
            'vend' => 'it.default_vendor_id',
        ]);

        return $q->get()->all();
    }

    // ------------------------------------------------------ distributor stock

    /**
     * Items THIS shop carries, matched to what the distributor they came from
     * has left. Never another shop's data. Pace and days left are hidden for
     * an item until it has enough history when master admin says so.
     */
    private function distRows(array $cfg, bool $totalOnly = false): array
    {
        $v = ReportCatalog::velocity();

        $q = DB::table('tenant_inventory_items as it')
            ->join('distributor_stock_summary as s', 's.distributor_catalog_id', '=', 'it.distributor_catalog_id')
            ->join('platform_distributor_catalogs as pc', 'pc.id', '=', 'it.distributor_catalog_id')
            ->leftJoin('tenant_inventory_categories as c', 'c.id', '=', 'it.category_id')
            ->where('it.tenant_id', $this->tenant->id)
            ->where('it.is_active', 1)
            ->whereNull('it.deleted_at')
            ->select('it.name as item', 'it.computed_stock_count as mine', 'c.name as cat', 'pc.manufacturer as brand',
                's.distributor_code as dist', 's.avail', 's.rate_per_day', 's.next_arrival_on',
                's.first_seen_at', 's.last_reading_at');

        if (is_array($v['vendors'])) {
            $q->whereIn('s.distributor_code', $v['vendors'] ?: ['-none-']);
        }

        $this->applyFilters($q, array_intersect_key($cfg['f'], ['dist' => 1, 'brand' => 1, 'cat' => 1]), [
            'dist'  => 's.distributor_code',
            'brand' => 'pc.manufacturer',
            'cat'   => 'it.category_id',
        ]);

        $today = $this->tenant->localToday()->startOfDay();
        $groups = [];

        foreach ($q->cursor() as $r) {
            $conf = \App\Services\Distributors\StockVelocityRecorder::confidence($r);
            if (!empty($cfg['f']['hist']) && $cfg['f']['hist'] !== $conf) {
                continue;
            }
            $usable = $r->rate_per_day !== null && (!$v['good'] || $conf === 'good');

            $key = $totalOnly ? 'Total' : match ($cfg['dim']) {
                'dist'  => (string) $r->dist,
                'brand' => $r->brand ?: 'No brand',
                'cat'   => $r->cat ?: 'Uncategorized',
                'hist'  => ['good' => 'Good', 'building' => 'Building', 'early' => 'Too early'][$conf],
                default => (string) $r->item,
            };

            if (!isset($groups[$key])) {
                $groups[$key] = (object) ['dim_label' => $key, 'c_avail' => 0.0, 'c_mine' => 0.0, 'c_pace' => 0.0,
                    'c_paceavail' => 0.0, 'c_low' => 0.0, 'c_next' => null];
            }
            $g = $groups[$key];

            $avail = max(0, (int) $r->avail);
            $g->c_avail += $avail;
            $g->c_mine  += max(0, (int) $r->mine);

            if ($usable && (float) $r->rate_per_day > 0) {
                $rate = (float) $r->rate_per_day;
                $g->c_pace      += $rate;
                $g->c_paceavail += $avail;
                if ($avail / $rate < 14) {
                    $g->c_low += 1;
                }
            }

            if ($r->next_arrival_on) {
                $days = max(0, (int) $today->diffInDays(\Carbon\Carbon::parse($r->next_arrival_on)->startOfDay(), false));
                $g->c_next = $g->c_next === null ? $days : min($g->c_next, $days);
            }
        }

        return array_values($groups);
    }

    // ---------------------------------------------------------------- helpers

    private function applyFilters(Builder $q, array $filters, array $columns): void
    {
        foreach ($filters as $key => $value) {
            if (isset($columns[$key])) {
                $q->where($columns[$key], $value);   // column from this file, value bound
            }
        }
    }

    private function components(object $r): array
    {
        $out = [];
        foreach ((array) $r as $k => $v) {
            if (str_starts_with($k, 'c_')) {
                $out[substr($k, 2)] = $v === null ? null : (float) $v;
            }
        }
        return $out;
    }

    private function measureValue(string $ds, string $m, array $c): ?float
    {
        if ($ds === 'dist') {
            $pace = (float) ($c['pace'] ?? 0);
            return match ($m) {
                'dstock' => (float) ($c['avail'] ?? 0),
                'dmine'  => (float) ($c['mine'] ?? 0),
                'dpace'  => $pace > 0 ? $pace : null,
                'ddays'  => $pace > 0 ? (float) ($c['paceavail'] ?? 0) / $pace : null,
                'dlow'   => (float) ($c['low'] ?? 0),
                'dnext'  => isset($c['next']) ? (float) $c['next'] : null,
                default  => null,
            };
        }

        if ($ds === 'inventory') {
            return in_array($m, ['cost', 'retail'], true) ? ($c[$m] ?? 0) / 100 : (float) ($c[$m] ?? 0);
        }

        return match ($m) {
            'rev'     => ($c['rev'] ?? 0) / 100,
            'margin'  => ($c['margin'] ?? 0) / 100,
            'marginp' => ($c['costed'] ?? 0) > 0 ? ($c['margin'] ?? 0) / $c['costed'] * 100 : 0.0,
            'items'   => (float) ($c['items'] ?? 0),
            'tickets' => (float) ($c['tickets'] ?? 0),
            'avg'     => ($c['tickets'] ?? 0) > 0 ? ($c['rev'] ?? 0) / $c['tickets'] / 100 : 0.0,
            'disc'    => ($c['disc'] ?? 0) / 100,
            default   => 0.0,
        };
    }
}
