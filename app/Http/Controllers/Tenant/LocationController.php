<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * LocationController
 *
 * Owner-facing CRUD for tenant locations. Lives at /admin/locations.
 *
 * Owner-only — locations are a billing-relevant configuration; staff and
 * managers can see them (via the sidebar switcher when 2+ exist) but
 * cannot add/edit/delete.
 *
 * Subdomain trap: every method takes  first.
 */
class LocationController extends Controller
{
    public function index(Request $request)
    {
        $tenant = tenant();
        $user = Auth::guard('tenant')->user();

        if (! $user || ! $user->isOwner()) {
            return redirect()->route('tenant.dashboard')
                ->with('error', 'Locations are owner-only.');
        }

        $locations = TenantLocation::where('tenant_id', $tenant->id)
            ->orderBy('is_default', 'desc')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return view('tenant.locations.index', [
            'locations' => $locations,
        ]);
    }

    public function store(Request $request)
    {
        $tenant = tenant();
        $user = Auth::guard('tenant')->user();
        if (! $user || ! $user->isOwner()) {
            return back()->with('error', 'Owner only.');
        }

        // NOTE: gate (Branded+ tier / additional_locations addon / quantity)
        // is deferred. When ready, add the check here.

        $validated = $request->validate([
            'name'           => ['required', 'string', 'max:128'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city'           => ['nullable', 'string', 'max:128'],
            'state'          => ['nullable', 'string', 'max:64'],
            'postal_code'    => ['nullable', 'string', 'max:32'],
            'phone'          => ['nullable', 'string', 'max:32'],
            'email'          => ['nullable', 'email', 'max:255'],
            'timezone'       => ['nullable', 'string', 'max:64'],
        ]);

        // Slug from name; ensure tenant-uniqueness.
        $slug = $this->uniqueSlug($tenant->id, $validated['name']);

        $location = TenantLocation::create(array_merge($validated, [
            'tenant_id'  => $tenant->id,
            'slug'       => $slug,
            'is_default' => false, // never default on create
            'is_active'  => true,
            'sort_order' => (int) TenantLocation::where('tenant_id', $tenant->id)->max('sort_order') + 1,
            'country'    => 'US',
        ]));

        Log::info('Location.created', [
            'tenant_id'   => $tenant->id,
            'location_id' => $location->id,
            'by_user'     => $user->id,
        ]);

        return back()->with('success', 'Location "' . $location->name . '" added.');
    }

    public function update(Request $request, string $id)
    {
        $tenant = tenant();
        $user = Auth::guard('tenant')->user();
        if (! $user || ! $user->isOwner()) {
            return back()->with('error', 'Owner only.');
        }

        $location = TenantLocation::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $validated = $request->validate([
            'name'           => ['required', 'string', 'max:128'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city'           => ['nullable', 'string', 'max:128'],
            'state'          => ['nullable', 'string', 'max:64'],
            'postal_code'    => ['nullable', 'string', 'max:32'],
            'phone'          => ['nullable', 'string', 'max:32'],
            'email'          => ['nullable', 'email', 'max:255'],
            'timezone'       => ['nullable', 'string', 'max:64'],
        ]);

        $location->update($validated);

        return back()->with('success', 'Location updated.');
    }

    public function setDefault(Request $request, string $id)
    {
        $tenant = tenant();
        $user = Auth::guard('tenant')->user();
        if (! $user || ! $user->isOwner()) {
            return back()->with('error', 'Owner only.');
        }

        $location = TenantLocation::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        if (! $location->is_active) {
            return back()->with('error', 'Cannot set an inactive location as default. Reactivate it first.');
        }

        DB::transaction(function () use ($tenant, $location) {
            TenantLocation::where('tenant_id', $tenant->id)
                ->update(['is_default' => false]);
            $location->update(['is_default' => true]);
        });

        return back()->with('success', '"' . $location->name . '" is now the default location.');
    }

    public function toggleActive(Request $request, string $id)
    {
        $tenant = tenant();
        $user = Auth::guard('tenant')->user();
        if (! $user || ! $user->isOwner()) {
            return back()->with('error', 'Owner only.');
        }

        $location = TenantLocation::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        if ($location->is_default && $location->is_active) {
            return back()->with('error', 'Cannot deactivate the default location. Set another location as default first.');
        }

        $location->update(['is_active' => ! $location->is_active]);

        return back()->with('success', $location->name . ($location->is_active ? ' reactivated.' : ' deactivated.'));
    }

    public function destroy(Request $request, string $id)
    {
        $tenant = tenant();
        $user = Auth::guard('tenant')->user();
        if (! $user || ! $user->isOwner()) {
            return back()->with('error', 'Owner only.');
        }

        $location = TenantLocation::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        if ($location->is_default) {
            return back()->with('error', 'Cannot delete the default location.');
        }

        // Check for attached records that would orphan if we delete.
        $hasAppointments = DB::table('tenant_appointments')
            ->where('location_id', $location->id)->exists();
        $hasSales = DB::table('tenant_sales')
            ->where('location_id', $location->id)->exists();
        $hasInventory = DB::table('tenant_inventory_item_locations')
            ->where('location_id', $location->id)->exists();

        if ($hasAppointments || $hasSales || $hasInventory) {
            return back()->with('error',
                'This location has appointments, sales, or inventory attached. Deactivate it instead to preserve history.');
        }

        // Also remove user-location grants
        DB::table('tenant_user_locations')->where('location_id', $location->id)->delete();

        $name = $location->name;
        $location->delete();

        Log::info('Location.deleted', [
            'tenant_id'   => $tenant->id,
            'location_id' => $id,
            'by_user'     => $user->id,
        ]);

        return back()->with('success', '"' . $name . '" deleted.');
    }

    /**
     * Generate a tenant-unique slug from a name.
     */
    protected function uniqueSlug(string $tenantId, string $name): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'location';
        }

        $slug = $base;
        $n = 1;
        while (TenantLocation::where('tenant_id', $tenantId)->where('slug', $slug)->exists()) {
            $n++;
            $slug = $base . '-' . $n;
        }
        return $slug;
    }
}
