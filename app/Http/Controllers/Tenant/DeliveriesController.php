<?php
// MARKER-PATCH-152A

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantDelivery;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * DeliveriesController — internal pickup/dropoff scheduling.
 *
 * Phase 152-a: scaffold only. Index view shows an empty-state stub
 * with the correct sub-toggle context. Real day/week timelines and
 * create flow ship in 152-b.
 */
class DeliveriesController extends Controller
{
    public function index(Request $request): View
    {
        $tenant = tenant();
        $view   = $request->query('view', 'day');
        if (!in_array($view, ['day', 'week'], true)) $view = 'day';

        $dateStr = $request->query('date');
        $date    = $dateStr ? Carbon::parse($dateStr) : Carbon::today();

        $deliveries = collect();

        return view('tenant.deliveries.index', [
            'tenant'     => $tenant,
            'view'       => $view,
            'date'       => $date,
            'deliveries' => $deliveries,
        ]);
    }
}