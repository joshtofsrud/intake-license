<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Tenant\ReportsDataService;
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
        if (!in_array($range, ['today', 'week', 'month', 'custom'], true)) {
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
                'week'  => [$today->copy()->subDays(6), $today->copy()],
                'month' => [$today->copy()->startOfMonth(), $today->copy()],
                default => [$today->copy(), $today->copy()],
            };
        }

        $rangeLabel = match ($range) {
            'week'   => 'Last 7 days',
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
}
