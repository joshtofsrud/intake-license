<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantResource;
use App\Models\Tenant\TenantServiceItem;
use Illuminate\Http\Request;

/**
 * WalkInController — drives the mobile walk-in counter flow.
 *
 * Single GET endpoint serves a stateful page (SPA-ish). The page calls
 * existing JSON endpoints for customer search and booking submission, so
 * this controller's only job is rendering the initial state with the
 * pre-loaded data the flow needs (recent customers, active services,
 * bookable resources).
 *
 * v1 scope per mockup:
 *   - Search existing customers OR create new (name + phone)
 *   - Pick "Book appointment" or "Quick retail sale"
 *   - Booking path: pick service + time → POST to existing quick-book.store
 *   - Sale path: redirect to /admin/register with customer pre-attached
 *
 * Future (v1.2+): full sequential service picker matching the desktop modal,
 * deposit-required service handling, multiple-services-per-appointment.
 */
class WalkInController extends Controller
{
    public function index(Request $request)
    {
        $tenant = tenant();

        // Recent customers — last 5 by created or updated. Shown as one-tap
        // shortcuts on the walk-in start screen.
        $recentCustomers = TenantCustomer::where('tenant_id', $tenant->id)
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get(['id', 'first_name', 'last_name', 'email', 'phone', 'updated_at'])
            ->map(fn ($c) => [
                'id'    => $c->id,
                'name'  => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')),
                'email' => $c->email,
                'phone' => $c->phone,
                'initials' => strtoupper(substr($c->first_name ?? '', 0, 1) . substr($c->last_name ?? '', 0, 1)),
                'updated' => optional($c->updated_at)->diffForHumans(),
            ]);

        // Active services — used in the service picker step.
        $services = TenantServiceItem::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'duration_minutes', 'price_cents',
                   'prep_before_minutes', 'cleanup_after_minutes'])
            ->map(fn ($s) => [
                'id'        => $s->id,
                'name'      => $s->name,
                'duration'  => $s->duration_minutes,
                'price'     => (int) $s->price_cents,
                'prep'      => (int) $s->prep_before_minutes,
                'cleanup'   => (int) $s->cleanup_after_minutes,
            ]);

        // Bookable resources — used by the time picker (it shows resource
        // alongside slot in the dropdown if there are multiple).
        $resources = TenantResource::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'subtitle', 'color_hex'])
            ->map(fn ($r) => [
                'id'       => $r->id,
                'name'     => $r->name,
                'subtitle' => $r->subtitle,
                'color'    => $r->color_hex,
            ]);

        return view('tenant.walkin.index', [
            'recentCustomers' => $recentCustomers,
            'services'        => $services,
            'resources'       => $resources,
            'tenant'          => $tenant,
        ]);
    }
}
