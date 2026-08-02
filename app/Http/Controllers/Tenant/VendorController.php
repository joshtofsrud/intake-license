<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantVendor;
use App\Models\Tenant\TenantSpecialOrder;
use App\Models\Tenant\TenantInventoryReceiveShipment;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class VendorController extends Controller
{
    /**
     * Vendor list — tenant-scoped, with optional search + sort.
     * Augments each vendor row with item_count, open_so_count, and
     * recent activity for the list table's at-a-glance columns.
     */
    public function index(Request $request): View
    {
        $tenant  = tenant();
        $search  = trim((string) $request->input('s', ''));
        $sort    = $request->input('sort', 'name_asc');
        $page    = max(1, (int) $request->input('page', 1));
        $perPage = 25;

        $q = TenantVendor::where('tenant_id', $tenant->id);

        if ($search !== '') {
            $q->where(function ($q2) use ($search) {
                $q2->where('name', 'like', "%{$search}%")
                   ->orWhere('contact_email', 'like', "%{$search}%")
                   ->orWhere('contact_phone', 'like', "%{$search}%")
                   ->orWhere('account_number', 'like', "%{$search}%");
            });
        }

        switch ($sort) {
            case 'name_desc':  $q->orderByDesc('name'); break;
            case 'added_desc': $q->orderByDesc('created_at'); break;
            case 'added_asc':  $q->orderBy('created_at'); break;
            default:           $q->orderBy('name'); // name_asc
        }

        $total      = $q->count();
        $totalPages = max(1, (int) ceil($total / $perPage));
        $vendors    = $q->offset(($page - 1) * $perPage)
                        ->limit($perPage)
                        ->get();

        // Per-vendor counts. One query per metric, keyed by vendor id.
        // For ~10 vendors per tenant this is trivially cheap.
        $vendorIds = $vendors->pluck('id')->all();

        $itemCounts = collect();
        if (!empty($vendorIds)) {
            $itemCounts = \DB::table('tenant_inventory_item_vendors')
                ->select('vendor_id', \DB::raw('COUNT(*) as cnt'))
                ->whereIn('vendor_id', $vendorIds)
                ->groupBy('vendor_id')
                ->pluck('cnt', 'vendor_id');
        }

        $openSoCounts = TenantSpecialOrder::query()
            ->whereIn('vendor_id', $vendorIds ?: ['__none__'])
            ->whereIn('status', TenantSpecialOrder::STATUSES_OPEN)
            ->selectRaw('vendor_id, COUNT(*) as cnt')
            ->groupBy('vendor_id')
            ->pluck('cnt', 'vendor_id');

        return view('tenant.vendors.index', [
            'vendors'       => $vendors,
            'total'         => $total,
            'page'          => $page,
            'totalPages'    => $totalPages,
            'search'        => $search,
            'sort'          => $sort,
            'itemCounts'    => $itemCounts,
            'openSoCounts'  => $openSoCounts,
        ]);
    }

    /**
     * Inline create from the index page. POSTs from the new-vendor
     * card. Returns to the list with a flash message.
     */
    public function store(Request $request): RedirectResponse
    {
        $tenant = tenant();
        $data   = $this->validatedPayload($request, $tenant->id);

        TenantVendor::create($data + ['tenant_id' => $tenant->id]);

        return redirect()->route('tenant.vendors.index')
            ->with('flash', ['type' => 'success', 'message' => 'Vendor saved.']);
    }

    /**
     * Vendor detail page. Loads stat counts, related items via the
     * pivot, open SOs, and recent receive shipments — all tenant-
     * scoped through the vendor relationship.
     */
    public function show(Request $request, string $id): View
    {
        $tenant = tenant();

        $vendor = TenantVendor::where('tenant_id', $tenant->id)
            ->findOrFail($id);

        // Items sourced — through the pivot
        $items = $vendor->items()
            ->orderBy('name')
            ->limit(50)
            ->get();

        // Open SOs for this vendor
        $openSos = TenantSpecialOrder::where('tenant_id', $tenant->id)
            ->where('vendor_id', $vendor->id)
            ->whereIn('status', TenantSpecialOrder::STATUSES_OPEN)
            ->orderBy('expected_arrival_date')
            ->limit(20)
            ->get();

        // Recent receive shipments, most-recent first
        $recentShipments = TenantInventoryReceiveShipment::where('tenant_id', $tenant->id)
            ->where('vendor_id', $vendor->id)
            ->orderByDesc('received_date')
            ->limit(10)
            ->get();

        // Stat tile aggregates
        $itemCount      = $vendor->items()->count();
        $openSoCount    = TenantSpecialOrder::where('tenant_id', $tenant->id)
            ->where('vendor_id', $vendor->id)
            ->whereIn('status', TenantSpecialOrder::STATUSES_OPEN)
            ->count();

        // Avg lead-time across this vendor's arrived SOs.
        // ordered_at → arrived_at in days, averaged. Null-safe.
        $avgLeadDays = TenantSpecialOrder::where('tenant_id', $tenant->id)
            ->where('vendor_id', $vendor->id)
            ->whereNotNull('ordered_at')
            ->whereNotNull('arrived_at')
            ->selectRaw('AVG(DATEDIFF(arrived_at, ordered_at)) as avg_days')
            ->value('avg_days');

        return view('tenant.vendors.show', [
            'vendor'          => $vendor,
            'items'           => $items,
            'openSos'         => $openSos,
            'recentShipments' => $recentShipments,
            'itemCount'       => $itemCount,
            'openSoCount'     => $openSoCount,
            'avgLeadDays'     => $avgLeadDays !== null ? round((float) $avgLeadDays, 1) : null,
        ]);
    }

    public function edit(Request $request, string $id): View
    {
        $tenant = tenant();
        $vendor = TenantVendor::where('tenant_id', $tenant->id)->findOrFail($id);

        return view('tenant.vendors.edit', compact('vendor'));
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $tenant = tenant();
        $vendor = TenantVendor::where('tenant_id', $tenant->id)->findOrFail($id);

        $data = $this->validatedPayload($request, $tenant->id, $vendor->id);
        $vendor->update($data);

        return redirect()->route('tenant.vendors.show', ['id' => $vendor->id])
            ->with('flash', ['type' => 'success', 'message' => 'Vendor updated.']);
    }

    /**
     * Soft-delete a vendor. Items in the pivot are NOT detached —
     * the soft-deleted vendor row remains queryable through
     * withTrashed() so existing pivot rows and SOs still resolve
     * their vendor relationship correctly. To fully unlink, staff
     * would have to remove items + cancel SOs first; the UI
     * defends against deleting a vendor with open SOs (see the
     * controller check below).
     */
    public function destroy(Request $request, string $id): RedirectResponse
    {
        $tenant = tenant();
        $vendor = TenantVendor::where('tenant_id', $tenant->id)->findOrFail($id);

        $openSoCount = TenantSpecialOrder::where('tenant_id', $tenant->id)
            ->where('vendor_id', $vendor->id)
            ->whereIn('status', TenantSpecialOrder::STATUSES_OPEN)
            ->count();

        if ($openSoCount > 0) {
            return redirect()->route('tenant.vendors.show', ['id' => $vendor->id])
                ->with('flash', [
                    'type'    => 'error',
                    'message' => "Cannot delete — {$openSoCount} open special order(s) reference this vendor. Cancel or complete them first.",
                ]);
        }

        $vendor->delete();

        return redirect()->route('tenant.vendors.index')
            ->with('flash', ['type' => 'success', 'message' => 'Vendor removed.']);
    }

    /**
     * Common validation for store + update. Vendor name uniqueness
     * is enforced per-tenant at the database level; this validation
     * catches the conflict before it becomes a SQL error.
     */
    private function validatedPayload(Request $request, string $tenantId, ?string $exceptId = null): array
    {
        $data = $request->validate([
            'name'              => ['required', 'string', 'max:128'],
            'contact_email'     => ['nullable', 'email', 'max:128'],
            'contact_phone'     => ['nullable', 'string', 'max:32'],
            'website'           => ['nullable', 'string', 'max:255'],
            'account_number'    => ['nullable', 'string', 'max:64'],
            // MARKER-SO-PLACEMENT — entered in dollars, stored in cents. Blank
            // means "no threshold", and the placement board simply shows no
            // freight bar for this vendor rather than inventing one.
            'free_freight'      => ['nullable', 'numeric', 'min:0', 'max:100000'],
            // MARKER-VENDOR-NET-COST
            'program_discount_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'distributor_code'     => ['nullable', 'string', 'max:32'],
            'notes'             => ['nullable', 'string'],
            'is_active'         => ['nullable'],
        ]);

        // MARKER-SO-PLACEMENT — dollars in, cents stored.
        $freight = $request->input('free_freight');
        $data['free_freight_cents'] = ($freight === null || $freight === '')
            ? null
            : (int) round(((float) $freight) * 100);
        unset($data['free_freight']);

        // MARKER-VENDOR-NET-COST — blank means "no program", not zero.
        $pct = $request->input('program_discount_pct');
        $data['program_discount_pct'] = ($pct === null || $pct === '') ? null : (float) $pct;

        // Only accept a code the registry actually knows, so a typo can't
        // orphan the link from the importer.
        $code = strtolower(trim((string) $request->input('distributor_code')));
        $data['distributor_code'] = ($code !== '' && array_key_exists($code, (array) config('distributors', [])))
            ? $code
            : null;

        // Soft-cast is_active. Checkboxes send 'on'/null; explicit
        // boolean values come through from API/edit form.
        $data['is_active'] = filter_var(
            $request->input('is_active', true),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        );
        if ($data['is_active'] === null) {
            $data['is_active'] = true;
        }

        // Uniqueness check (case-insensitive) within tenant
        $exists = TenantVendor::where('tenant_id', $tenantId)
            ->whereRaw('LOWER(name) = ?', [strtolower($data['name'])])
            ->when($exceptId, fn($q) => $q->where('id', '!=', $exceptId))
            ->exists();

        if ($exists) {
            abort(redirect()->back()
                ->withInput()
                ->with('flash', [
                    'type'    => 'error',
                    'message' => 'A vendor with that name already exists.',
                ]));
        }

        return $data;
    }
}
