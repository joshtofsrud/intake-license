<?php
// MARKER-PATCH-229

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\LeasePackage;
use App\Models\Tenant\LeasePackageSlot;
use App\Models\Tenant\TenantRentalCategory;
use App\Models\Tenant\TenantRentalUnit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Lease packages — the tier builder. Gated on leases_enabled (tier >= Scale
 * AND the settings toggle); a tenant without leasing can't reach any of it.
 */
class LeasePackageController extends Controller
{
    private function guard(): void
    {
        abort_unless(tenant()->leases_enabled, 403, 'Leasing is not enabled.');
    }

    public function index()
    {
        $this->guard();
        $tenant = tenant();

        $packages = LeasePackage::active()
            ->where('tenant_id', $tenant->id)
            ->with(['slots.category'])
            ->orderBy('sort_order')->orderBy('name')
            ->get();

        // Live "free in fleet" per slot: available units in the slot's
        // category whose size matches the filter (substring match, the same
        // loose contract fulfillment uses). Counts are a building aid, not a
        // reservation.
        $slotFree = [];
        foreach ($packages as $pkg) {
            foreach ($pkg->slots as $slot) {
                $slotFree[$slot->id] = $this->freeForSlot($tenant->id, $slot);
            }
        }

        $categories = TenantRentalCategory::whereNull('archived_at')
            ->where('tenant_id', $tenant->id)
            ->orderBy('name')->get();

        return view('tenant.rentals.lease-packages', [
            'packages'   => $packages,
            'slotFree'   => $slotFree,
            'categories' => $categories,
        ]);
    }

    private function freeForSlot(string $tenantId, LeasePackageSlot $slot): int
    {
        $q = TenantRentalUnit::where('tenant_id', $tenantId)
            ->where('category_id', $slot->category_id)
            ->whereNull('archived_at')
            ->where('status', 'available')
            ->where('available_for_rent', true);

        if ($slot->size_filter) {
            $q->where('size', 'like', '%' . $slot->size_filter . '%');
        }

        return $q->count();
    }

    public function store(Request $request)
    {
        $this->guard();

        $data = $request->validate([
            'name'           => ['required', 'string', 'max:120'],
            'subtitle'       => ['nullable', 'string', 'max:120'],
            'season_price'   => ['required', 'numeric', 'min:0'],
            'deposit'        => ['nullable', 'numeric', 'min:0'],
        ]);

        LeasePackage::create([
            'tenant_id'          => tenant()->id,
            'name'               => $data['name'],
            'subtitle'           => $data['subtitle'] ?? null,
            'season_price_cents' => (int) round($data['season_price'] * 100),
            'deposit_cents'      => (int) round(($data['deposit'] ?? 0) * 100),
            'active'             => true,
        ]);

        return redirect()->route('tenant.rentals.leases.packages')->with('flash', 'Package created.');
    }

    public function update(Request $request, string $id)
    {
        $this->guard();

        $pkg = LeasePackage::where('tenant_id', tenant()->id)->findOrFail($id);

        $data = $request->validate([
            'name'         => ['required', 'string', 'max:120'],
            'subtitle'     => ['nullable', 'string', 'max:120'],
            'season_price' => ['required', 'numeric', 'min:0'],
            'deposit'      => ['nullable', 'numeric', 'min:0'],
            'active'       => ['nullable', 'boolean'],
        ]);

        $pkg->update([
            'name'               => $data['name'],
            'subtitle'           => $data['subtitle'] ?? null,
            'season_price_cents' => (int) round($data['season_price'] * 100),
            'deposit_cents'      => (int) round(($data['deposit'] ?? 0) * 100),
            'active'             => (bool) ($data['active'] ?? $pkg->active),
        ]);

        return redirect()->route('tenant.rentals.leases.packages')->with('flash', 'Package updated.');
    }

    public function destroy(Request $request, string $id)
    {
        $this->guard();

        $pkg = LeasePackage::where('tenant_id', tenant()->id)->findOrFail($id);
        $pkg->update(['archived_at' => now()]);

        return redirect()->route('tenant.rentals.leases.packages')->with('flash', 'Package archived.');
    }

    public function addSlot(Request $request, string $id)
    {
        $this->guard();

        $pkg = LeasePackage::where('tenant_id', tenant()->id)->findOrFail($id);

        $data = $request->validate([
            'category_id' => ['required', 'string'],
            'size_filter' => ['nullable', 'string', 'max:60'],
            'quantity'    => ['required', 'integer', 'min:1', 'max:20'],
        ]);

        // Category must belong to this tenant.
        TenantRentalCategory::where('tenant_id', tenant()->id)
            ->whereNull('archived_at')->findOrFail($data['category_id']);

        LeasePackageSlot::create([
            'tenant_id'   => tenant()->id,
            'package_id'  => $pkg->id,
            'category_id' => $data['category_id'],
            'size_filter' => $data['size_filter'] ?: null,
            'quantity'    => $data['quantity'],
        ]);

        return redirect()->route('tenant.rentals.leases.packages')->with('flash', 'Slot added.');
    }

    public function removeSlot(Request $request, string $id, string $slotId)
    {
        $this->guard();

        LeasePackageSlot::where('tenant_id', tenant()->id)
            ->where('package_id', $id)
            ->where('id', $slotId)
            ->delete();

        return redirect()->route('tenant.rentals.leases.packages')->with('flash', 'Slot removed.');
    }
}
