<?php

namespace App\Services\Tenant\ReportBuilder;

use App\Models\Tenant;
use App\Services\Tenant\ReportBuilder\Sources\AppointmentsSource;
use App\Services\Tenant\ReportBuilder\Sources\CustomersSource;
use App\Services\Tenant\ReportBuilder\Sources\DistributorSource;
use App\Services\Tenant\ReportBuilder\Sources\InventorySource;
use App\Services\Tenant\ReportBuilder\Sources\ReceivedSource;
use App\Services\Tenant\ReportBuilder\Sources\SalesSource;
use App\Services\Tenant\ReportBuilder\Sources\Source;
use App\Services\Tenant\ReportBuilder\Sources\StaffHoursSource;

/**
 * ReportCatalog — the ONLY list of what a custom report may ask for.
 * (MARKER-REPORT-BUILDER-CORE MARKER-REPORT-BUILDER-V2)
 *
 * Every dataset, measure, breakdown and filter a tenant can pick comes from a
 * Source here. A config from the page or from a saved report is cleaned
 * against this list before anything runs, so free text never reaches SQL.
 */
class ReportCatalog
{
    public const MAX_MEASURES = 5;
    public const ROW_CAP = 5000;
    public const ORDINAL = ['mband', 'pband', 'bucket', 'hist', 'dow', 'hod'];

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
                'email'     => (bool) ($s['report_email'] ?? false),
                'vendors'   => isset($s['vendors']) ? array_values((array) $s['vendors']) : null,
            ];
        }
        return $v;
    }

    /** @return array<string, Source> in the order they appear in the Data list */
    public static function sources(Tenant $tenant, Periods $p, bool $isManager): array
    {
        $out = [
            'sales'     => new SalesSource($tenant, $p),
            'customers' => new CustomersSource($tenant, $p),
            'appts'     => new AppointmentsSource($tenant, $p),
        ];
        if ($isManager) {
            $out['staff'] = new StaffHoursSource($tenant, $p);
        }
        $out['inv'] = new InventorySource($tenant, $p);
        if (self::velocity()['report_on']) {
            $out['dist'] = new DistributorSource($tenant, $p);
        }
        $out['recv'] = new ReceivedSource($tenant, $p);
        return $out;
    }

    /** The catalog the page works from, with this tenant's own filter values. */
    public static function client(array $sources, bool $isManager): array
    {
        $out = [];
        foreach ($sources as $key => $src) {
            $m = $src->meta();
            $dims = [];
            foreach ($m['dims'] as $dk => $dd) {
                if (($dd[2] ?? null) === 'managers' && !$isManager) continue;
                $dims[] = [$dk, $dd[0], $dd[1]];
            }
            $filters = [];
            foreach ($m['filters'] as $fk => $fl) {
                if ($fk === 'staff' && !$isManager) continue;
                $opts = [];
                foreach ($src->options($fk) as $v => $l) {
                    $opts[] = [(string) $v, (string) $l];
                }
                $filters[] = [$fk, $fl, $opts];
            }
            $measures = [];
            foreach ($m['measures'] as $mk => $md) {
                $measures[] = [$mk, $md[0], $md[1]];
            }
            $out[$key] = ['l' => $m['label'], 'note' => $m['note'], 'snap' => (bool) $m['snap'], 'lock' => $m['lock'] ?? null,
                'cav' => $m['cav'] ?? null, 'm' => $measures, 'd' => $dims, 'f' => $filters];
        }
        return $out;
    }

    /** Clean a config from the page or a saved report. Never trusts it. */
    public static function clean(array $in, array $catalog): array
    {
        // Saved by the first version of the builder: {ds, m, dim, f{}, range, sort, limit}.
        if (isset($in['m']) && !isset($in['measures'])) {
            $ranges = ['today' => 'Today', 'last_7' => 'Last 7 days', 'last_30' => 'Last 30 days', 'last_90' => 'Last 90 days',
                'month' => 'This month', 'last_month' => 'Last month', 'last_12' => 'Last 12 months', 'year' => 'This year'];
            $filters = [];
            foreach ((array) ($in['f'] ?? []) as $k => $v) {
                $filters[] = ['f' => $k, 'op' => 'is', 'v' => $v];
            }
            $ds = ($in['ds'] ?? 'sales') === 'inventory' ? 'inv' : ($in['ds'] ?? 'sales');
            $in = ['ds' => $ds, 'measures' => (array) $in['m'], 'dim' => $in['dim'] ?? '', 'filters' => $filters,
                'range' => $ranges[$in['range'] ?? ''] ?? 'Last 30 days', 'sortDir' => $in['sort'] ?? 'desc', 'limit' => $in['limit'] ?? 0];
        }

        $ds = (string) ($in['ds'] ?? 'sales');
        if (!isset($catalog[$ds])) {
            $ds = array_key_first($catalog);
        }
        $def = $catalog[$ds];
        $mkeys = array_column($def['m'], 0);
        $dkeys = array_column($def['d'], 0);
        $fkeys = array_column($def['f'], 0);

        $mode = ($in['mode'] ?? '') === 'timeline' ? 'timeline' : 'report';

        $calcs = [];
        foreach ((array) ($in['calcs'] ?? []) as $c) {
            if (!is_array($c)) continue;
            $id = (string) ($c['id'] ?? '');
            $f = (string) ($c['f'] ?? '');
            if (!preg_match('/^calc:[a-z0-9]{1,12}$/', $id) || strlen($f) > 300) continue;
            $calcs[] = ['id' => $id, 'name' => mb_substr((string) ($c['name'] ?? 'Calculation'), 0, 80), 'f' => $f,
                't' => in_array($c['t'] ?? '', ['$', 'n', '%', 'd'], true) ? $c['t'] : 'd'];
        }
        $calcIds = array_column($calcs, 'id');

        $measures = [];
        foreach ((array) ($in['measures'] ?? []) as $m) {
            $m = (string) $m;
            $ok = in_array($m, $mkeys, true) || in_array($m, $calcIds, true);
            if (!$ok && str_contains($m, ':')) {
                [$ods, $om] = explode(':', $m, 2);
                $ok = $ods !== $ds && isset($catalog[$ods]) && in_array($om, array_column($catalog[$ods]['m'], 0), true);
            }
            if ($ok && !in_array($m, $measures, true)) $measures[] = $m;
        }
        $measures = array_slice($measures, 0, self::MAX_MEASURES);
        if (!$measures && $mode === 'report') $measures = [$mkeys[0]];

        $dim = (string) ($in['dim'] ?? '');
        if (!in_array($dim, $dkeys, true)) $dim = $dkeys[0];
        $dim2 = (string) ($in['dim2'] ?? '');
        $isT = fn ($k) => ($def['d'][array_search($k, $dkeys, true)][2] ?? 'c') === 't';
        if ($dim2 !== '' && (!in_array($dim2, $dkeys, true) || $dim2 === $dim || ($isT($dim) && $isT($dim2)))) $dim2 = '';

        $filters = [];
        foreach ((array) ($in['filters'] ?? []) as $f) {
            if (!is_array($f) || !in_array($f['f'] ?? '', $fkeys, true)) continue;
            $filters[] = ['f' => (string) $f['f'], 'op' => ($f['op'] ?? 'is') === 'not' ? 'not' : 'is',
                'v' => mb_substr((string) ($f['v'] ?? ''), 0, 120)];
        }

        $range = (string) ($in['range'] ?? 'Last 30 days');
        $okRanges = $mode === 'timeline' ? ['All time', 'Last 12 months', 'Last 90 days'] : Periods::RANGES;
        if (!in_array($range, $okRanges, true)) $range = $mode === 'timeline' ? 'All time' : 'Last 30 days';

        $limit = (int) ($in['limit'] ?? 0);
        if ($limit < 0 || $limit > 500) $limit = 0;

        // Narrowed to one period from a drill-down ("just this week").
        $span = null;
        if (isset($in['span']['dim'], $in['span']['key']) && in_array($in['span']['dim'], ['day', 'week', 'month', 'pp'], true)
            && in_array($in['span']['dim'], $dkeys, true) && preg_match('/^\d{4}-\d{2}(-\d{2})?$/', (string) $in['span']['key'])) {
            $span = ['dim' => $in['span']['dim'], 'key' => (string) $in['span']['key'], 'l' => mb_substr((string) ($in['span']['l'] ?? $in['span']['key']), 0, 60)];
        }

        $ev = [];
        foreach (['sale', 'appt', 'msg', 'note', 'class', 'rental'] as $k) {
            $ev[$k] = (int) (bool) (($in['tevents'][$k] ?? 1));
        }

        return [
            'ds' => $ds, 'mode' => $mode, 'measures' => $measures, 'calcs' => $calcs, 'dim' => $dim, 'dim2' => $dim2,
            'filters' => $filters, 'range' => $range,
            'compare' => in_array($in['compare'] ?? 'off', ['prev', 'yoy'], true) ? $in['compare'] : 'off',
            'sortDir' => ($in['sortDir'] ?? 'desc') === 'asc' ? 'asc' : 'desc', 'limit' => $limit,
            'tcust' => preg_match('/^[A-Za-z0-9-]{1,40}$/', (string) ($in['tcust'] ?? '')) ? (string) $in['tcust'] : '',
            'tevents' => $ev, 'span' => $span,
        ];
    }

    /** Label => "ds:measure", first match wins in Data-list order (calculations refer to measures by name). */
    public static function labelMap(array $catalog): array
    {
        $out = [];
        foreach ($catalog as $ds => $def) {
            foreach ($def['m'] as $m) {
                $out[$m[1]] = $out[$m[1]] ?? $ds . ':' . $m[0];
            }
        }
        return $out;
    }

    /** Ready-made reports. The page only shows those whose data is on for this shop. */
    public static function templates(): array
    {
        return [
            ['Sales by day', 'Revenue, tickets and average ticket for each of the last 30 days.', 'calendar-days', ['ds' => 'sales', 'measures' => ['rev', 'tickets', 'avg'], 'dim' => 'day']],
            ['Margin by category', 'Where the profit actually comes from, last 90 days.', 'percent', ['ds' => 'sales', 'measures' => ['margin', 'marginp', 'rev'], 'dim' => 'cat', 'range' => 'Last 90 days']],
            ['Revenue by location and month', 'Each location side by side, month by month (a pivot).', 'map-pin', ['ds' => 'sales', 'measures' => ['rev'], 'dim' => 'loc', 'dim2' => 'month', 'range' => 'Last 12 months']],
            ['Items per ticket by staff', 'Who sells add-ons: items per ticket, items and tickets for each person.', 'receipt', ['ds' => 'sales', 'measures' => ['ipt', 'items', 'tickets'], 'dim' => 'staff']],
            ['Hours worked by staff', 'Hours and overtime for each person, last 7 days.', 'clock', ['ds' => 'staff', 'measures' => ['hrs', 'ot', 'shifts'], 'dim' => 'staff', 'range' => 'Last 7 days']],
            ['Sales per labor hour', 'Weekly revenue divided by hours worked — a calculation across Sales and Staff hours.', 'timer', ['ds' => 'sales', 'measures' => ['calc:t1', 'rev'], 'dim' => 'week', 'range' => 'Last 90 days', 'calcs' => [['id' => 'calc:t1', 'name' => 'Sales per labor hour', 'f' => '[Revenue] / [Hours worked]', 't' => '$']]]],
            ['New customers by month', 'New customers, total customers and growth rate over a year.', 'user-plus', ['ds' => 'customers', 'measures' => ['newc', 'custs', 'growth'], 'dim' => 'month', 'range' => 'Last 12 months']],
            ['Top customers by lifetime value', 'Your best 15 customers and what they\'ve spent.', 'crown', ['ds' => 'customers', 'measures' => ['ltvt', 'spend', 'visits'], 'dim' => 'cust', 'range' => 'Last 12 months', 'limit' => 15]],
            ['Tax collected by location', 'Taxable sales and the tax collected at each location, last 30 days.', 'landmark', ['ds' => 'sales', 'measures' => ['taxable', 'tax', 'rev'], 'dim' => 'loc']],
            ['Booking density', 'When appointments start: day of the week against hour of the day (a pivot).', 'grid-3x3', ['ds' => 'appts', 'measures' => ['ap'], 'dim' => 'dow', 'dim2' => 'hod', 'range' => 'Last 90 days']],
            ['Parts attach by service', 'How often parts are added to each kind of job, and what they bring in.', 'wrench', ['ds' => 'appts', 'measures' => ['ap', 'partsp', 'parts'], 'dim' => 'svc', 'range' => 'Last 90 days']],
            ['Comebacks by service', 'Customers who booked again within 30 days of a finished job.', 'rotate-ccw', ['ds' => 'appts', 'measures' => ['done', 'back', 'backp'], 'dim' => 'svc', 'range' => 'Last 90 days']],
            ['Customer timeline', 'Everything that happened with one customer, in order.', 'history', ['ds' => 'customers', 'mode' => 'timeline', 'range' => 'All time']],
            ['Inbox contacts by month', 'How many customers got in touch, month by month.', 'inbox', ['ds' => 'customers', 'measures' => ['inbox'], 'dim' => 'month', 'range' => 'Last 12 months']],
            ['Appointments by service and location', 'Volume for each service at each location (a pivot).', 'calendar-check', ['ds' => 'appts', 'measures' => ['ap'], 'dim' => 'svc', 'dim2' => 'loc']],
            ['Received by category', 'Units and cost received in each category, last 90 days.', 'package-plus', ['ds' => 'recv', 'measures' => ['runits', 'rcost', 'rmp'], 'dim' => 'cat', 'range' => 'Last 90 days']],
            ['Received by category and item', 'Which items made up each category\'s receipts (a pivot).', 'boxes', ['ds' => 'recv', 'measures' => ['runits'], 'dim' => 'cat', 'dim2' => 'item', 'range' => 'Last 90 days']],
            ['Received by margin range', 'How much of what you bought came in at each margin, and how much of it sold.', 'banknote', ['ds' => 'recv', 'measures' => ['runits', 'rcost', 'rst'], 'dim' => 'mband', 'range' => 'Last 90 days']],
            ['Sell-through by price band', 'Of what you received, how much sold — at each price point.', 'tags', ['ds' => 'recv', 'measures' => ['runits', 'rsold', 'rst'], 'dim' => 'pband', 'range' => 'Last 90 days']],
            ['Inventory aging by category', 'Dollars sitting in each age band, by category.', 'package-open', ['ds' => 'inv', 'measures' => ['cost', 'a1', 'a2', 'a3', 'a4'], 'dim' => 'cat']],
            ['What\'s moving in my inventory', 'Your 25 fastest-moving items, with how long the stock will last.', 'trending-up', ['ds' => 'inv', 'measures' => ['turns', 'units', 'st', 'dleft'], 'dim' => 'item', 'limit' => 25]],
            ['Distributor stock by brand', 'What the distributors have left of what you carry, and how long it should last.', 'truck', ['ds' => 'dist', 'measures' => ['dstock', 'dpace', 'ddays'], 'dim' => 'brand']],
            ['Low cover by distributor', 'Items you carry that are about to run out, for each distributor.', 'alarm-clock', ['ds' => 'dist', 'measures' => ['dlow', 'dstock', 'ddays'], 'dim' => 'dist']],
            ['Dead stock by vendor', 'What\'s tied up and unsold, and who sold it to you.', 'archive', ['ds' => 'inv', 'measures' => ['dead', 'cost', 'cover'], 'dim' => 'vend']],
        ];
    }
}
