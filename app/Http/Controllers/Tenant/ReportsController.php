<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Tenant\ReportsDataService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * ReportsController
 *
 * Phase 1: single index() route that renders the daily-ops view of all
 * six zones. The Daily/Monthly toggle and drilldowns ship in later phases.
 */
class ReportsController extends Controller
{
    public function index(Request $request): View
    {
        $tenant = tenant();
        $service = new ReportsDataService($tenant);

        return view('tenant.reports.index', [
            'tenant'       => $tenant,
            'kpis'         => $service->topKpis(),
            'revenue'      => $service->zoneRevenue(),
            'bookings'     => $service->zoneBookings(),
            'customers'    => $service->zoneCustomers(),
            'services'     => $service->zoneServices(),
            'staff'        => $service->zoneStaff(),
            'capacity'     => $service->zoneCapacity(),
            'today_label'  => $tenant->localToday()->format('l, F j, Y'),
        ]);
    }
}
