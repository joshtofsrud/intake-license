<?php

namespace App\Services\Tenant\ReportBuilder;

use Carbon\Carbon;

/**
 * ReportCatalog — the ONLY list of what a custom report may ask for.
 *
 * Every dataset, measure, breakdown and filter a tenant can pick is named
 * here. ReportRunner refuses anything not in this catalog, so a saved or
 * posted config can never reach SQL as free text. (MARKER-REPORT-BUILDER-CORE)
 *
 * Measure types: money (cents), count, pct.
 * Breakdown types: time (sorted by date), cat (sorted by the first measure).
 */
class ReportCatalog
{
    public const MAX_MEASURES = 5;
    public const ROW_CAP = 5000;

    /** What master admin allows shops to see (Distributor velocity > Shop visibility). */
    public static function velocity(): array
    {
        static $v = null;
        if ($v === null) {
            try {
                $s = (array) (\App\Models\PlatformSettings::current()->velocity_settings ?? []);
            } catch (\Throwable $e) {
                $s = [];
            }
            $v = [
                'report_on' => (bool) ($s['report_on'] ?? false),
                'pace'      => (bool) ($s['report_pace'] ?? false),
                'days'      => (bool) ($s['show_days_left'] ?? false),
                'arrival'   => (bool) ($s['show_arrival'] ?? false),
                'good'      => (bool) ($s['require_good'] ?? true),
                'vendors'   => isset($s['vendors']) ? array_values((array) $s['vendors']) : null,
            ];
        }
        return $v;
    }

    public static function datasets(): array
    {
        $all = self::base();
        $v = self::velocity();
        if ($v['report_on']) {
            $all['dist'] = self::distributorDataset($v);
        }
        return $all;
    }

    private static function distributorDataset(array $v): array
    {
        $m = [
            'dstock' => ['label' => 'In stock at distributor', 'type' => 'count'],
            'dmine'  => ['label' => 'Your stock on hand',      'type' => 'count'],
        ];
        if ($v['pace'])    { $m['dpace'] = ['label' => 'Pace per day', 'type' => 'rate']; }
        if ($v['days'])    { $m['ddays'] = ['label' => 'Days left', 'type' => 'rate']; $m['dlow'] = ['label' => 'Items under 14 days left', 'type' => 'count']; }
        if ($v['arrival']) { $m['dnext'] = ['label' => 'Days until next arrival', 'type' => 'rate']; }

        $cav = [
            'Pace is how fast the distributor\'s stock is dropping. It is not your sales.',
            'Covers only items already in your inventory.',
        ];
        if ($v['good']) { $cav[] = 'Items with under 3 weeks of readings show a dash for pace and days left.'; }
        if ($v['arrival']) { $cav[] = 'Some distributors do not send arrival dates; those show a dash.'; }
        $cav[] = 'Which figures appear is set by Intake. Figures it hides are not offered here.';

        return [
            'label'    => 'Distributor stock',
            'note'     => 'What distributors have left of items you carry, and how fast it is going. Read from each distributor every 4 hours.',
            'snapshot' => true,
            'cav'      => $cav,
            'measures' => $m,
            'dims'     => [
                'dist'  => ['label' => 'Distributor', 'type' => 'cat'],
                'brand' => ['label' => 'Brand',       'type' => 'cat'],
                'cat'   => ['label' => 'Category',    'type' => 'cat'],
                'item'  => ['label' => 'Item',        'type' => 'cat'],
                'hist'  => ['label' => 'History',     'type' => 'cat'],
            ],
            'filters'  => ['dist' => 'Distributor', 'brand' => 'Brand', 'cat' => 'Category', 'hist' => 'History'],
        ];
    }

    private static function base(): array
    {
        return [
            'sales' => [
                'label' => 'Sales',
                'note'  => 'Paid register and online-store sales, refunds left out. Revenue is before tax and tips.',
                'snapshot' => false,
                'measures' => [
                    'rev'     => ['label' => 'Revenue',          'type' => 'money'],
                    'margin'  => ['label' => 'Margin $',         'type' => 'money'],
                    'marginp' => ['label' => 'Margin %',         'type' => 'pct'],
                    'items'   => ['label' => 'Items sold',       'type' => 'count'],
                    'tickets' => ['label' => 'Tickets',          'type' => 'count'],
                    'avg'     => ['label' => 'Average ticket',   'type' => 'money'],
                    'disc'    => ['label' => 'Discounts given',  'type' => 'money'],
                ],
                'dims' => [
                    'day'   => ['label' => 'Day',            'type' => 'time'],
                    'week'  => ['label' => 'Week',           'type' => 'time'],
                    'month' => ['label' => 'Month',          'type' => 'time'],
                    'loc'   => ['label' => 'Location',       'type' => 'cat'],
                    'staff' => ['label' => 'Staff (rang up by)', 'type' => 'cat', 'managers' => true],
                    'cat'   => ['label' => 'Category',       'type' => 'cat'],
                    'pay'   => ['label' => 'Payment method', 'type' => 'cat'],
                    'item'  => ['label' => 'Item',           'type' => 'cat'],
                ],
                'filters' => [
                    'loc'   => 'Location',
                    'cat'   => 'Category',
                    'pay'   => 'Payment method',
                    'staff' => 'Staff',
                ],
            ],
            'inventory' => [
                'label' => 'Inventory',
                'note'  => 'A snapshot as of today. Units are what Intake counts on hand; cost uses the cost you paid, then your set cost, then the distributor cost.',
                'snapshot' => true,
                'measures' => [
                    'units'  => ['label' => 'Units on hand',   'type' => 'count'],
                    'cost'   => ['label' => 'Value at cost',   'type' => 'money'],
                    'retail' => ['label' => 'Value at retail', 'type' => 'money'],
                    'items'  => ['label' => 'Items stocked',   'type' => 'count'],
                ],
                'dims' => [
                    'cat'  => ['label' => 'Category', 'type' => 'cat'],
                    'vend' => ['label' => 'Vendor',   'type' => 'cat'],
                    'item' => ['label' => 'Item',     'type' => 'cat'],
                ],
                'filters' => [
                    'cat'  => 'Category',
                    'vend' => 'Vendor',
                ],
            ],
        ];
    }

    /**
     * Ready-made reports for the landing page. A template is offered only when
     * the data it needs is on for this shop, so no tile ever opens an error.
     * (MARKER-REPORT-TEMPLATES)
     */
    public static function templates(bool $isManager): array
    {
        $all = self::datasets();
        $t = [
            ['Sales by week', 'Revenue, tickets and average ticket for each week, last 90 days.', 'calendar-days',
                ['ds' => 'sales', 'm' => ['rev', 'tickets', 'avg'], 'dim' => 'week', 'range' => 'last_90']],
            ['Margin by category', 'Revenue, margin dollars and margin percent in each category, last 90 days.', 'percent',
                ['ds' => 'sales', 'm' => ['rev', 'margin', 'marginp'], 'dim' => 'cat', 'range' => 'last_90']],
            ['Revenue by location', 'How each location did over the last 30 days.', 'map-pin',
                ['ds' => 'sales', 'm' => ['rev', 'tickets', 'avg'], 'dim' => 'loc', 'range' => 'last_30']],
            ['Top 25 items', 'Your best sellers by revenue, last 30 days.', 'crown',
                ['ds' => 'sales', 'm' => ['rev', 'items'], 'dim' => 'item', 'range' => 'last_30', 'limit' => 25]],
            ['How customers paid', 'Revenue and tickets for each payment method, last 30 days.', 'wallet',
                ['ds' => 'sales', 'm' => ['rev', 'tickets'], 'dim' => 'pay', 'range' => 'last_30']],
            ['Sales by staff', 'Revenue, tickets and average ticket for each person, last 30 days.', 'receipt',
                ['ds' => 'sales', 'm' => ['rev', 'tickets', 'avg'], 'dim' => 'staff', 'range' => 'last_30']],
            ['Stock value by category', 'Units on hand, cost and retail value in each category.', 'boxes',
                ['ds' => 'inventory', 'm' => ['units', 'cost', 'retail'], 'dim' => 'cat']],
            ['Stock value by vendor', 'Where your money is tied up, vendor by vendor.', 'package-open',
                ['ds' => 'inventory', 'm' => ['units', 'cost', 'retail'], 'dim' => 'vend']],
            ['Fastest-moving at the distributor', 'The 25 items dropping fastest at the distributor, with your stock beside it.', 'truck',
                ['ds' => 'dist', 'm' => ['dpace', 'dstock', 'dmine'], 'dim' => 'item', 'limit' => 25]],
            ['Low cover by distributor', 'How many items are about to run out, for each distributor.', 'alarm-clock',
                ['ds' => 'dist', 'm' => ['dlow', 'ddays', 'dstock'], 'dim' => 'dist']],
            ['Distributor stock by brand', 'What distributors hold of the brands you carry, next to your own stock.', 'tags',
                ['ds' => 'dist', 'm' => ['dstock', 'dmine'], 'dim' => 'brand']],
        ];

        $out = [];
        foreach ($t as [$name, $desc, $icon, $cfg]) {
            $def = $all[$cfg['ds']] ?? null;
            if (!$def) {
                continue;
            }
            foreach ($cfg['m'] as $m) {
                if (!isset($def['measures'][$m])) {
                    continue 2;
                }
            }
            if (!isset(self::allowedDims($cfg['ds'], $isManager)[$cfg['dim']])) {
                continue;
            }
            $out[] = ['name' => $name, 'desc' => $desc, 'icon' => $icon, 'data' => $def['label'], 'cfg' => $cfg + ['go' => 1]];
        }
        return $out;
    }

    public static function ranges(): array
    {
        return [
            'today'      => 'Today',
            'last_7'     => 'Last 7 days',
            'last_30'    => 'Last 30 days',
            'last_90'    => 'Last 90 days',
            'month'      => 'This month',
            'last_month' => 'Last month',
            'last_12'    => 'Last 12 months',
            'year'       => 'This year',
        ];
    }

    /** @return array{0: Carbon, 1: Carbon, 2: string} from, to, label */
    public static function resolveRange(string $key, Carbon $today): array
    {
        $ranges = self::ranges();
        if (!isset($ranges[$key])) {
            $key = 'last_30';
        }

        [$from, $to] = match ($key) {
            'today'      => [$today->copy(), $today->copy()],
            'last_7'     => [$today->copy()->subDays(6), $today->copy()],
            'last_90'    => [$today->copy()->subDays(89), $today->copy()],
            'month'      => [$today->copy()->startOfMonth(), $today->copy()],
            'last_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'last_12'    => [$today->copy()->subMonthsNoOverflow(12)->addDay(), $today->copy()],
            'year'       => [$today->copy()->startOfYear(), $today->copy()],
            default      => [$today->copy()->subDays(29), $today->copy()],
        };

        return [$from->startOfDay(), $to->startOfDay(), $ranges[$key]];
    }

    /** Pull a clean config out of any array (query string, saved JSON). Never trusts it. */
    public static function clean(array $in, bool $isManager): array
    {
        $all = self::datasets();
        $ds = (string) ($in['ds'] ?? 'sales');
        if (!isset($all[$ds])) {
            $ds = 'sales';
        }
        $def = $all[$ds];

        $measures = [];
        foreach ((array) ($in['m'] ?? []) as $m) {
            if (is_string($m) && isset($def['measures'][$m]) && !in_array($m, $measures, true)) {
                $measures[] = $m;
            }
        }
        $measures = array_slice($measures, 0, self::MAX_MEASURES);
        if (!$measures) {
            $measures = [array_key_first($def['measures'])];
        }

        $dim = (string) ($in['dim'] ?? '');
        $dims = self::allowedDims($ds, $isManager);
        if (!isset($dims[$dim])) {
            $dim = array_key_first($dims);
        }

        $filters = [];
        foreach ((array) ($in['f'] ?? []) as $k => $v) {
            if (is_string($k) && isset($def['filters'][$k]) && is_string($v) && $v !== '' && strlen($v) <= 80) {
                if ($k === 'staff' && !$isManager) {
                    continue;
                }
                $filters[$k] = $v;
            }
        }

        $range = (string) ($in['range'] ?? 'last_30');
        if (!isset(self::ranges()[$range])) {
            $range = 'last_30';
        }

        $limit = (int) ($in['limit'] ?? 0);
        if (!in_array($limit, [0, 5, 10, 15, 25], true)) {
            $limit = 0;
        }

        return [
            'ds'      => $ds,
            'm'       => $measures,
            'dim'     => $dim,
            'f'       => $filters,
            'range'   => $range,
            'sort'    => (($in['sort'] ?? 'desc') === 'asc') ? 'asc' : 'desc',
            'limit'   => $limit,
        ];
    }

    public static function allowedDims(string $ds, bool $isManager): array
    {
        $dims = self::datasets()[$ds]['dims'] ?? [];
        return array_filter($dims, fn ($d) => $isManager || empty($d['managers']));
    }
}
