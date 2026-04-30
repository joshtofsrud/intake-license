<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Tenant\ReportsDataService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * ReportsController
 *
 * Phase 2: per-zone time range toggle (Today / Week / Month) via query params.
 *   /admin/reports?revenue=week&bookings=month&customers=today...
 *
 * Each zone's default is the most natural-fit range for that metric:
 *   - Revenue:  today
 *   - Bookings: week
 *   - Customers: month
 *   - Services: month
 *   - Staff:    week
 *   - Capacity: month
 */
class ReportsController extends Controller
{
    private const ZONE_DEFAULTS = [
        'revenue'   => 'today',
        'bookings'  => 'week',
        'customers' => 'month',
        'services'  => 'month',
        'staff'     => 'week',
        'capacity'  => 'month',
    ];

    public function index(Request $request): View
    {
        $tenant = tenant();
        $svc = new ReportsDataService($tenant);

        $ranges = [];
        foreach (self::ZONE_DEFAULTS as $zone => $default) {
            $val = (string) $request->query($zone, $default);
            $ranges[$zone] = in_array($val, ReportsDataService::RANGES, true) ? $val : $default;
        }

        return view('tenant.reports.index', [
            'tenant'      => $tenant,
            'ranges'      => $ranges,
            'kpis'        => $svc->topKpis(),
            'revenue'     => $svc->zoneRevenue($ranges['revenue']),
            'bookings'    => $svc->zoneBookings($ranges['bookings']),
            'customers'   => $svc->zoneCustomers($ranges['customers']),
            'services'    => $svc->zoneServices($ranges['services']),
            'staff'       => $svc->zoneStaff($ranges['staff']),
            'capacity'    => $svc->zoneCapacity($ranges['capacity']),
            'today_label' => $tenant->localToday()->format('l, F j, Y'),
        ]);
    }
}
