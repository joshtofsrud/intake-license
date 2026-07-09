<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Tenant\ReportsDataService;
use App\Services\Tenant\CustomersReportService;
use App\Services\Tenant\ServicesReportService;
use App\Services\Tenant\RetailReportService;
use App\Services\Tenant\MoneyReportService;
use App\Services\Tenant\StaffReportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * ReportsController
 *
 * Phase 3: single global date range drives every zone.
 *   /admin/reports                          (defaults to today)
 *   /admin/reports?range=today
 *   /admin/reports?range=week
 *   /admin/reports?range=month
 *   /admin/reports?range=custom&from=2026-04-01&to=2026-04-15
 */
class ReportsController extends Controller
{
    public function index(Request $request): View
    {
        $tenant = tenant();
        $svc = new ReportsDataService($tenant);
        $today = $tenant->localToday();

        $range = (string) $request->query('range', 'today');
        if (!in_array($range, ['today', 'week', 'month', 'last_30', 'custom'], true)) {
            $range = 'today';
        }

        // Compute [from, to] based on range
        if ($range === 'custom') {
            $fromStr = (string) $request->query('from', $today->toDateString());
            $toStr   = (string) $request->query('to',   $today->toDateString());
            try {
                $from = Carbon::parse($fromStr)->startOfDay();
                $to   = Carbon::parse($toStr)->startOfDay();
                if ($from->gt($to)) [$from, $to] = [$to, $from]; // swap if reversed
            } catch (\Throwable $e) {
                $from = $today->copy();
                $to   = $today->copy();
                $range = 'today';
            }
        } else {
            [$from, $to] = match ($range) {
                'week'    => [$today->copy()->subDays(6), $today->copy()],
                'month'   => [$today->copy()->startOfMonth(), $today->copy()],
                'last_30' => [$today->copy()->subDays(29), $today->copy()], // MARKER-PATCH-114
                default   => [$today->copy(), $today->copy()],
            };
        }

        $rangeLabel = match ($range) {
            'week'   => 'Last 7 days',
            'last_30' => 'Last 30 days',
            'month'  => $today->format('F'),
            'custom' => $from->isSameDay($to)
                ? $from->format('M j, Y')
                : $from->format('M j') . ' – ' . $to->format('M j, Y'),
            default  => 'Today',
        };

        return view('tenant.reports.index', [
            'tenant'      => $tenant,
            'range'       => $range,
            'range_label' => $rangeLabel,
            'from'        => $from,
            'to'          => $to,
            'kpis'        => $svc->topKpis(),
            'revenue'     => $svc->zoneRevenue($from, $to),
            'bookings'    => $svc->zoneBookings($from, $to),
            'customers'   => $svc->zoneCustomers($from, $to),
            'services'    => $svc->zoneServices($from, $to),
            'staff'       => $svc->zoneStaff($from, $to),
            'capacity'    => $svc->zoneCapacity($from, $to),
            'today_label' => $today->format('l, F j, Y'),
        ]);
    }

    /**
     * Customers tab — whole-database, NOT date-ranged.
     *
     * Three panels:
     *   - missing contact info (customers with no phone on file)
     *   - lapsed customers (no delivered appointment in 180d)
     *   - highest LTV (top customers by lifetime value)
     */
    public function customers(Request $request): View
    {
        $tenant = tenant();
        $svc = new CustomersReportService($tenant);

        // Gate: extended_reports capability gates the list data. Starter
        // tenants see real aggregate counts (cheap, not sensitive) with
        // blurred placeholder list rows and an upsell modal.
        $isLocked = !$tenant->extended_reports_enabled;

        return view('tenant.reports.customers', [
            'tenant'    => $tenant,
            'is_locked' => $isLocked,
            'missing'   => $svc->missingContactInfo($isLocked),
            'lapsed'    => $svc->lapsedCustomers($isLocked),
            'topLtv'    => $svc->highestLtv($isLocked),
        ]);
    }

    /**
     * Services tab — date-ranged service depth analytics.
     *
     * Five real panels (throughput, mix, parts attach, comebacks,
     * production by resource) + two stubs (mechanic productivity,
     * estimate accuracy) that surface "coming soon" until the
     * supporting schema lands.
     */
    public function services(Request $request): View
    {
        $tenant = tenant();
        $today = $tenant->localToday();

        $range = (string) $request->query('range', 'today');
        if (!in_array($range, ['today', 'week', 'month', 'last_30', 'custom'], true)) {
            $range = 'today';
        }

        if ($range === 'custom') {
            $fromStr = (string) $request->query('from', $today->toDateString());
            $toStr   = (string) $request->query('to',   $today->toDateString());
            try {
                $from = Carbon::parse($fromStr)->startOfDay();
                $to   = Carbon::parse($toStr)->startOfDay();
                if ($from->gt($to)) [$from, $to] = [$to, $from];
            } catch (\Throwable $e) {
                $from = $today->copy();
                $to   = $today->copy();
                $range = 'today';
            }
        } else {
            [$from, $to] = match ($range) {
                'week'    => [$today->copy()->subDays(6), $today->copy()],
                'month'   => [$today->copy()->startOfMonth(), $today->copy()],
                'last_30' => [$today->copy()->subDays(29), $today->copy()], // MARKER-PATCH-114
                default   => [$today->copy(), $today->copy()],
            };
        }

        $rangeLabel = match ($range) {
            'week'   => 'Last 7 days',
            'last_30' => 'Last 30 days',
            'month'  => $today->format('F'),
            'custom' => $from->isSameDay($to)
                ? $from->format('M j, Y')
                : $from->format('M j') . ' – ' . $to->format('M j, Y'),
            default  => 'Today',
        };

        $isLocked = !$tenant->extended_reports_enabled;
        $svc = new ServicesReportService($tenant);

        return view('tenant.reports.services', [
            'tenant'              => $tenant,
            'range'               => $range,
            'range_label'         => $rangeLabel,
            'from'                => $from,
            'to'                  => $to,
            'today_label'         => $today->format('l, F j, Y'),
            'is_locked'           => $isLocked,
            'throughput'          => $svc->throughput($from, $to, $isLocked),
            'serviceMix'          => $svc->serviceMix($from, $to, $isLocked),
            'partsAttach'         => $svc->partsAttach($from, $to, $isLocked),
            'comebacks'           => $svc->comebacks($from, $to, $isLocked),
            'productionByResource'=> $svc->productionByResource($from, $to, $isLocked),
            'mechanicProductivity'=> $svc->mechanicProductivity(),
            'estimateAccuracy'    => $svc->estimateAccuracy(),
        ]);
    }

    public function retail(Request $request): View
    {
        $tenant = tenant();
        $today = $tenant->localToday();
        $range = (string) $request->query('range', 'today');
        if (!in_array($range, ['today', 'week', 'month', 'last_30', 'custom'], true)) $range = 'today';
        if ($range === 'custom') {
            try {
                $from = Carbon::parse((string) $request->query('from', $today->toDateString()))->startOfDay();
                $to   = Carbon::parse((string) $request->query('to',   $today->toDateString()))->startOfDay();
                if ($from->gt($to)) [$from, $to] = [$to, $from];
            } catch (\Throwable $e) {
                $from = $today->copy(); $to = $today->copy(); $range = 'today';
            }
        } else {
            [$from, $to] = match ($range) {
                'week'    => [$today->copy()->subDays(6), $today->copy()],
                'month'   => [$today->copy()->startOfMonth(), $today->copy()],
                'last_30' => [$today->copy()->subDays(29), $today->copy()], // MARKER-PATCH-114
                default   => [$today->copy(), $today->copy()],
            };
        }
        $rangeLabel = match ($range) {
            'week'   => 'Last 7 days',
            'last_30' => 'Last 30 days',
            'month'  => $today->format('F'),
            'custom' => $from->isSameDay($to) ? $from->format('M j, Y') : $from->format('M j') . ' – ' . $to->format('M j, Y'),
            default  => 'Today',
        };
        $isLocked = !$tenant->extended_reports_enabled;
        $svc = new RetailReportService($tenant);

        return view('tenant.reports.retail', [
            'tenant'      => $tenant,
            'range'       => $range,
            'range_label' => $rangeLabel,
            'today_label' => $today->format('l, F j, Y'),
            'is_locked'   => $isLocked,
            'salesSummary'    => $svc->salesSummary($from, $to, $isLocked),
            'salesByUser'     => $svc->salesByUser($from, $to, $isLocked),
            'topSkus'         => $svc->topSkus($from, $to, $isLocked),
            'margin'          => $svc->margin($from, $to, $isLocked),
            'inventoryHealth' => $svc->inventoryHealth($isLocked),
            'receiving'       => $svc->receiving($from, $to, $isLocked),
        ]);
    }

    public function money(Request $request): View
    {
        $tenant = tenant();
        $today = $tenant->localToday();
        $range = (string) $request->query('range', 'today');
        if (!in_array($range, ['today', 'week', 'month', 'last_30', 'custom'], true)) $range = 'today';
        if ($range === 'custom') {
            try {
                $from = Carbon::parse((string) $request->query('from', $today->toDateString()))->startOfDay();
                $to   = Carbon::parse((string) $request->query('to',   $today->toDateString()))->startOfDay();
                if ($from->gt($to)) [$from, $to] = [$to, $from];
            } catch (\Throwable $e) {
                $from = $today->copy(); $to = $today->copy(); $range = 'today';
            }
        } else {
            [$from, $to] = match ($range) {
                'week'    => [$today->copy()->subDays(6), $today->copy()],
                'month'   => [$today->copy()->startOfMonth(), $today->copy()],
                'last_30' => [$today->copy()->subDays(29), $today->copy()], // MARKER-PATCH-114
                default   => [$today->copy(), $today->copy()],
            };
        }
        $rangeLabel = match ($range) {
            'week'   => 'Last 7 days',
            'last_30' => 'Last 30 days',
            'month'  => $today->format('F'),
            'custom' => $from->isSameDay($to) ? $from->format('M j, Y') : $from->format('M j') . ' – ' . $to->format('M j, Y'),
            default  => 'Today',
        };
        $isLocked = !$tenant->extended_reports_enabled;
        $svc = new MoneyReportService($tenant);

        return view('tenant.reports.money', [
            'tenant'      => $tenant,
            'range'       => $range,
            'range_label' => $rangeLabel,
            'today_label' => $today->format('l, F j, Y'),
            'is_locked'   => $isLocked,
            'revenueSummary' => $svc->revenueSummary($from, $to, $isLocked),
            'refunds'        => $svc->refunds($from, $to, $isLocked),
            'taxAndFees'     => $svc->taxAndFees($from, $to, $isLocked),
            'drawerAndTill'  => $svc->drawerAndTill(),
            'stripePayouts'  => $svc->stripePayouts(),
        ]);
    }

    /**
     * Traffic tab — site usage analytics over tenant_funnel_events.
     * Free for all tenants. Window: 7d / 30d (default) / 90d.
     * MARKER-PATCH-151A
     */
    public function traffic(Request $request): View
    {
        $tenant = tenant();

        // MARKER-PATCH-475 — a custom date range (shared calendar picker) wins over
        // the preset windows when both `from` and `to` are supplied and valid.
        $fromStr = trim((string) $request->query('from', ''));
        $toStr   = trim((string) $request->query('to', ''));

        $svc = null;
        if ($fromStr !== '' && $toStr !== '') {
            try {
                $svc = new \App\Services\Tenant\TrafficReportService($tenant, '30d', $fromStr, $toStr);
            } catch (\Throwable $e) {
                $svc     = null;
                $fromStr = '';
                $toStr   = '';
            }
        }

        $window = $request->query('window', '30d');
        if (!in_array($window, ['1d', '7d', '30d', '90d'], true)) {
            $window = '30d';
        }
        if ($svc === null) {
            $svc = new \App\Services\Tenant\TrafficReportService($tenant, $window);
        }

        $bf = $svc->bookingFunnelData();

        return view('tenant.reports.traffic', [
            'tenant'         => $tenant,
            'window'         => $svc->window(),
            'isCustom'       => $svc->isCustom(),
            'rangeText'      => $svc->rangeLabel(),
            'from'           => $fromStr,
            'to'             => $toStr,
            'topStats'       => $svc->topStats(),
            'dailyVisitors'  => $svc->dailyVisitors(),
            'dailyStart'     => $svc->curStart(),
            'topSearches'    => $svc->topSearches(),      // MARKER-PATCH-621
            'zeroSearches'   => $svc->zeroResultSearches(),
            'searchRules'    => \App\Models\Tenant\TenantSearchRule::where('tenant_id', tenant()->id)
                                    ->orderBy('type')->orderBy('from_term')->get(), // MARKER-PATCH-622
            // MARKER-PATCH-151B — additional panels
            'funnel'         => $bf['funnel'],
            'funnelDetail'   => $bf['detail'],
            'topSources'     => $svc->topSources(),
            'deviceSplit'    => $svc->deviceSplit(),
            'topPages'       => $svc->topPages(),
            'newVsReturning' => $svc->newVsReturning(),
        ]);
    }

    public function staff(Request $request): View
    {
        $tenant = tenant();
        $today = $tenant->localToday();
        $range = (string) $request->query('range', 'today');
        if (!in_array($range, ['today', 'week', 'month', 'last_30', 'custom'], true)) $range = 'today';
        if ($range === 'custom') {
            try {
                $from = Carbon::parse((string) $request->query('from', $today->toDateString()))->startOfDay();
                $to   = Carbon::parse((string) $request->query('to',   $today->toDateString()))->startOfDay();
                if ($from->gt($to)) [$from, $to] = [$to, $from];
            } catch (\Throwable $e) {
                $from = $today->copy(); $to = $today->copy(); $range = 'today';
            }
        } else {
            [$from, $to] = match ($range) {
                'week'    => [$today->copy()->subDays(6), $today->copy()],
                'month'   => [$today->copy()->startOfMonth(), $today->copy()],
                'last_30' => [$today->copy()->subDays(29), $today->copy()], // MARKER-PATCH-114
                default   => [$today->copy(), $today->copy()],
            };
        }
        $rangeLabel = match ($range) {
            'week'   => 'Last 7 days',
            'last_30' => 'Last 30 days',
            'month'  => $today->format('F'),
            'custom' => $from->isSameDay($to) ? $from->format('M j, Y') : $from->format('M j') . ' – ' . $to->format('M j, Y'),
            default  => 'Today',
        };
        $isLocked = !$tenant->extended_reports_enabled;
        $svc = new StaffReportService($tenant);

        return view('tenant.reports.staff', [
            'tenant'      => $tenant,
            'range'       => $range,
            'range_label' => $rangeLabel,
            'today_label' => $today->format('l, F j, Y'),
            'is_locked'   => $isLocked,
            'bookingDensity'  => $svc->bookingDensity($from, $to, $isLocked),
            'revenueByStaff'  => $svc->revenueByStaff($from, $to, $isLocked),
            'utilization'     => $svc->utilization(),
            'servicesByStaff' => $svc->servicesByStaff(),
            'tipsByStaff'     => $svc->tipsByStaff(),
        ]);
    }
}

