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

    public static function datasets(): array
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
