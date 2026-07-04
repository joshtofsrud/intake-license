<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantServiceCategory;
use App\Models\Tenant\TenantServiceItem;
use App\Services\Tenant\BookingFlowService;
use Illuminate\Http\Request;

/**
 * MARKER-FLOW-5 — Booking Mode admin.
 * Sets the tenant's booking flow (advanced | simple | choice) and curates the
 * Simple-mode menu (which service items appear, in what order, with what tagline).
 */
class BookingModesController extends Controller
{
    public function index()
    {
        $tenant  = tenant();
        $mode    = app(BookingFlowService::class)->mode($tenant);

        $categories = TenantServiceCategory::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->with(['items' => function ($q) {
                $q->where('is_active', true)->orderBy('sort_order');
            }])
            ->get();

        // MARKER-PATCH-510 — Pickup & delivery section data
        $routeWindows = $tenant->deliveries_enabled
            ? \App\Models\Tenant\TenantRouteWindow::where('tenant_id', $tenant->id)
                ->orderBy('sort_order')->orderBy('starts_at')->get()
            : collect();
        $s = (array) ($tenant->settings ?? []);
        $pd = [
            'flavor'            => $s['pd_flavor'] ?? 'queue',
            'auto_propose'      => (bool) ($s['pd_auto_propose'] ?? true),
            'windows_offered'   => (int) ($s['pd_windows_offered'] ?? 3),
            'assume_first_hour' => (int) ($s['pd_assume_first_hour'] ?? 20),
            'pay_before'        => (bool) ($s['pd_pay_before_delivery'] ?? false),
            'online_pay'        => (bool) ($s['pd_online_pay_at_booking'] ?? true),
            'need_by'           => (bool) ($s['pd_need_by_enabled'] ?? true),
            'turnaround'        => $s['pd_turnaround_label'] ?? '2–3 days',
        ];

        return view('tenant.booking-modes', compact('mode', 'categories', 'routeWindows', 'pd'));
    }

    public function save(Request $request)
    {
        $tenant = tenant();

        $data = $request->validate([
            'booking_flow_mode'      => ['required', 'in:advanced,simple,choice'],
            'items'                  => ['nullable', 'array'],
            'items.*.simple_sort'    => ['nullable', 'integer', 'min:0', 'max:9999'],
            'items.*.simple_tagline' => ['nullable', 'string', 'max:160'],
        ]);

        $tenant->update(['booking_flow_mode' => $data['booking_flow_mode']]);

        foreach (($request->input('items') ?? []) as $id => $row) {
            $item = TenantServiceItem::where('tenant_id', $tenant->id)->where('id', $id)->first();
            if (! $item) {
                continue;
            }
            $item->update([
                'simple_enabled' => ! empty($row['simple_enabled']),
                'simple_sort'    => (int) ($row['simple_sort'] ?? 0),
                'simple_tagline' => ($row['simple_tagline'] ?? '') !== '' ? $row['simple_tagline'] : null,
            ]);
        }

        return redirect()
            ->route('tenant.booking_modes.index')
            ->with('status', 'Booking mode updated.');
    }
}
