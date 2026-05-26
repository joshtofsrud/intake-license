<?php
// MARKER-PATCH-152A

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantDeliveryResource;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * DeliveryResourcesController — manage vehicles / driver lanes /
 * in-shop drop slots that deliveries get assigned to.
 *
 * Tenant-only data. Tenant-scoped at every query.
 */
class DeliveryResourcesController extends Controller
{
    public function index(Request $request): View
    {
        $tenant = tenant();
        abort_unless($tenant->deliveries_enabled, 404); // MARKER-PATCH-156

        $resources = TenantDeliveryResource::query()
            ->where('tenant_id', $tenant->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return view('tenant.deliveries.resources', [
            'tenant'    => $tenant,
            'resources' => $resources,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = tenant();
        abort_unless($tenant->deliveries_enabled, 404); // MARKER-PATCH-156
        $data = $request->validate([
            'name'      => ['required', 'string', 'max:120'],
            'subtitle'  => ['nullable', 'string', 'max:160'],
            'color_hex' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $maxSort = (int) TenantDeliveryResource::query()
            ->where('tenant_id', $tenant->id)
            ->max('sort_order');

        TenantDeliveryResource::create([
            'tenant_id'  => $tenant->id,
            'name'       => $data['name'],
            'subtitle'   => $data['subtitle'] ?? null,
            'color_hex'  => $data['color_hex'],
            'sort_order' => $maxSort + 10,
            'is_active'  => true,
        ]);

        return back()->with('success', 'Delivery resource added.');
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $tenant = tenant();
        abort_unless($tenant->deliveries_enabled, 404); // MARKER-PATCH-156
        $res = TenantDeliveryResource::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $data = $request->validate([
            'name'      => ['required', 'string', 'max:120'],
            'subtitle'  => ['nullable', 'string', 'max:160'],
            'color_hex' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $res->update([
            'name'      => $data['name'],
            'subtitle'  => $data['subtitle'] ?? null,
            'color_hex' => $data['color_hex'],
            'is_active' => (bool) ($data['is_active'] ?? $res->is_active),
        ]);

        return back()->with('success', 'Delivery resource updated.');
    }

    public function destroy(string $id): RedirectResponse
    {
        $tenant = tenant();
        abort_unless($tenant->deliveries_enabled, 404); // MARKER-PATCH-156
        $res = TenantDeliveryResource::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $res->update(['is_active' => false]);

        return back()->with('success', 'Delivery resource archived.');
    }
}