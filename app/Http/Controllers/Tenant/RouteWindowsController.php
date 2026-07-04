<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantRouteWindow;
use Illuminate\Http\Request;

/**
 * MARKER-PATCH-510 — Pickup & delivery admin knobs.
 * Route windows CRUD + behavior settings. Consumed by the P2 booking
 * flow and P4 Ready→propose flow; until then this is inert config.
 */
class RouteWindowsController extends Controller
{
    public function store(Request $request)
    {
        $tenant = tenant();
        abort_unless($tenant->deliveries_enabled, 404);

        $data = $request->validate([
            'label'     => ['required', 'string', 'max:40'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at'   => ['required', 'date_format:H:i', 'after:starts_at'],
            'days'      => ['required', 'array', 'min:1'],
            'days.*'    => ['integer', 'min:1', 'max:7'],
            'max_stops' => ['required', 'integer', 'min:1', 'max:50'],
        ]);

        $data['days'] = array_values(array_unique(array_map('intval', $data['days'])));
        sort($data['days']);

        TenantRouteWindow::create($data + [
            'tenant_id'  => $tenant->id,
            'sort_order' => (int) TenantRouteWindow::where('tenant_id', $tenant->id)->max('sort_order') + 1,
        ]);

        return back()->with('success', 'Route window added.');
    }

    public function update(Request $request, string $id)
    {
        $tenant = tenant();
        abort_unless($tenant->deliveries_enabled, 404);

        $window = TenantRouteWindow::where('tenant_id', $tenant->id)->findOrFail($id);

        $data = $request->validate([
            'max_stops' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $window->update($data);
        return back()->with('success', 'Route window updated.');
    }

    public function destroy(string $id)
    {
        $tenant = tenant();
        abort_unless($tenant->deliveries_enabled, 404);

        TenantRouteWindow::where('tenant_id', $tenant->id)->findOrFail($id)->delete();
        return back()->with('success', 'Route window removed.');
    }

    /** The behavior/promise knobs — tenant settings JSON, no migration. */
    public function saveSettings(Request $request)
    {
        $tenant = tenant();
        abort_unless($tenant->deliveries_enabled, 404);

        $data = $request->validate([
            'pd_flavor'            => ['required', 'in:queue,anchored'],
            'pd_windows_offered'   => ['required', 'integer', 'min:1', 'max:6'],
            'pd_assume_first_hour' => ['required', 'integer', 'min:12', 'max:23'],
            'pd_turnaround_label'  => ['nullable', 'string', 'max:30'],
        ]);

        $settings = (array) ($tenant->settings ?? []);
        $settings['pd_flavor']                = $data['pd_flavor'];
        $settings['pd_windows_offered']       = (int) $data['pd_windows_offered'];
        $settings['pd_assume_first_hour']     = (int) $data['pd_assume_first_hour'];
        $settings['pd_turnaround_label']      = $data['pd_turnaround_label'] ?: '2–3 days';
        $settings['pd_auto_propose']          = (bool) $request->input('pd_auto_propose');
        $settings['pd_pay_before_delivery']   = (bool) $request->input('pd_pay_before_delivery');
        $settings['pd_online_pay_at_booking'] = (bool) $request->input('pd_online_pay_at_booking');
        $settings['pd_need_by_enabled']       = (bool) $request->input('pd_need_by_enabled');
        // MARKER-PATCH-520 — how many days before the service date pickup can happen
        $settings['pd_pickup_lead_days']      = max(0, min(7, (int) $request->input('pd_pickup_lead_days', 1)));
        $settings['pd_allow_day_of']          = (bool) $request->input('pd_allow_day_of'); // MARKER-PATCH-524
        $tenant->update(['settings' => $settings]);

        return back()->with('success', 'Pickup & delivery settings saved.');
    }
}
