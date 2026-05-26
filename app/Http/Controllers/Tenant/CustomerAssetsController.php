<?php
// MARKER-PATCH-158-C

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantCustomerAsset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * CRUD for customer-attached assets (bikes, vehicles, pets).
 *
 * Routes are gated by tenant->multi_asset_enabled. abort_unless(404)
 * keeps the feature invisible to tenants who haven't opted in.
 *
 * Archive (set archived_at) instead of hard-delete — preserves the
 * asset history that powers the picker's "last seen" hints.
 */
class CustomerAssetsController extends Controller
{
    public function store(Request $request, string $customerId): RedirectResponse
    {
        $tenant = tenant();
        abort_unless($tenant->multi_asset_enabled, 404);

        $customer = TenantCustomer::where('tenant_id', $tenant->id)
            ->where('id', $customerId)
            ->firstOrFail();

        $data = $request->validate([
            'name'       => ['required', 'string', 'max:200'],
            'identifier' => ['nullable', 'string', 'max:120'],
            'notes'      => ['nullable', 'string', 'max:5000'],
        ]);

        TenantCustomerAsset::create([
            'tenant_id'   => $tenant->id,
            'customer_id' => $customer->id,
            'name'        => $data['name'],
            'identifier'  => $data['identifier'] ?? null,
            'notes'       => $data['notes'] ?? null,
        ]);

        return back()->with('success', 'Asset added.');
    }

    public function update(Request $request, string $customerId, string $id): RedirectResponse
    {
        $tenant = tenant();
        abort_unless($tenant->multi_asset_enabled, 404);

        $asset = TenantCustomerAsset::where('tenant_id', $tenant->id)
            ->where('customer_id', $customerId)
            ->where('id', $id)
            ->firstOrFail();

        $data = $request->validate([
            'name'       => ['required', 'string', 'max:200'],
            'identifier' => ['nullable', 'string', 'max:120'],
            'notes'      => ['nullable', 'string', 'max:5000'],
        ]);

        $asset->update([
            'name'       => $data['name'],
            'identifier' => $data['identifier'] ?? null,
            'notes'      => $data['notes'] ?? null,
        ]);

        return back()->with('success', 'Asset updated.');
    }

    public function archive(string $customerId, string $id): RedirectResponse
    {
        $tenant = tenant();
        abort_unless($tenant->multi_asset_enabled, 404);

        $asset = TenantCustomerAsset::where('tenant_id', $tenant->id)
            ->where('customer_id', $customerId)
            ->where('id', $id)
            ->firstOrFail();

        $asset->archive();

        return back()->with('success', 'Asset archived.');
    }

    public function unarchive(string $customerId, string $id): RedirectResponse
    {
        $tenant = tenant();
        abort_unless($tenant->multi_asset_enabled, 404);

        $asset = TenantCustomerAsset::where('tenant_id', $tenant->id)
            ->where('customer_id', $customerId)
            ->where('id', $id)
            ->firstOrFail();

        $asset->unarchive();

        return back()->with('success', 'Asset restored.');
    }
}
