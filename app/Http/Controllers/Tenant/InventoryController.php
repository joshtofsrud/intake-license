<?php

namespace App\Http\Controllers\Tenant;

use App\Exceptions\Pos\InsufficientStockException;
use App\Exceptions\Pos\InvalidQuantityException;
use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantInventoryCategory;
use App\Models\Tenant\TenantInventoryItem;
use App\Models\Tenant\TenantInventoryItemLocation;
use App\Http\Controllers\Tenant\Concerns\GuardsRetailAccess;
use App\Http\Controllers\Tenant\Concerns\GuardsPosAccess;
use App\Services\Pos\InventoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Inventory CRUD for tenants with the `retail` capability.
 *
 * Capability gate: every method calls assertRetailEnabled() at the top.
 * Branded and Scale tiers have retail bundled (free); Starter does not.
 *
 * The catalog/shop column-pair pattern is visualized in the views — the
 * edit form has two distinct sections, "Catalog data (synced)" and
 * "Your settings (never overwritten)", with the lime accent on the shop
 * fields making the architectural keystone visible to the user.
 *
 * Stock adjustments live in a separate flow (see adjustStockForm /
 * adjustStock) — different audit semantics from item metadata edits.
 */
class InventoryController extends Controller
{
    use GuardsRetailAccess;
    use GuardsPosAccess;

    public function __construct(
        protected InventoryService $inventory,
    ) {
    }

    public function index(Request $request): View
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        $search   = trim((string) $request->input('s', ''));
        $category = $request->input('category');
        $stock    = $request->input('stock'); // 'low', 'out', 'all'
        $sort     = $request->input('sort', 'name_asc');
        $page     = max(1, (int) $request->input('page', 1));
        $perPage  = 25;

        $q = TenantInventoryItem::with(['category'])
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true);

        if ($search !== '') {
            $q->where(function ($q2) use ($search) {
                $q2->where('name', 'like', "%{$search}%")
                   ->orWhere('sku',  'like', "%{$search}%")
                   ->orWhere('catalog_upc', 'like', "%{$search}%");
            });
        }

        if ($category) {
            $q->where('category_id', $category);
        }

        // Stock filter — items below threshold or fully out
        if ($stock === 'low') {
            $q->whereNotNull('shop_reorder_threshold')
              ->whereColumn('computed_stock_count', '<=', 'shop_reorder_threshold');
        } elseif ($stock === 'out') {
            $q->where('computed_stock_count', '<=', 0);
        }

        switch ($sort) {
            case 'name_desc': $q->orderBy('name', 'desc'); break;
            case 'sku_asc':   $q->orderBy('sku', 'asc'); break;
            case 'sku_desc':  $q->orderBy('sku', 'desc'); break;
            case 'stock_asc': $q->orderBy('computed_stock_count', 'asc'); break;
            case 'stock_desc':$q->orderBy('computed_stock_count', 'desc'); break;
            case 'name_asc':
            default:          $q->orderBy('name', 'asc');
        }

        $total = (clone $q)->count();
        $items = $q->forPage($page, $perPage)->get();

        $categories = TenantInventoryCategory::where('tenant_id', $tenant->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $hasCategories = $categories->isNotEmpty();

        $posCap = $this->inventoryCapContext($tenant);

        return view('tenant.inventory.index', compact(
            'items', 'categories', 'hasCategories',
            'total', 'search', 'category', 'stock', 'sort', 'page', 'perPage',
            'posCap'
        ));
    }

    public function create(Request $request): View|RedirectResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        // POS hard cap: block reaching the form when at or over.
        // Edits to existing items remain unaffected (only adds blocked).
        if (!$this->inventoryAddIsAllowed($tenant)) {
            return redirect()->route('tenant.inventory.index')
                ->with('flash', [
                    'type'    => 'error',
                    'message' => 'You\'ve reached the ' . self::POS_INVENTORY_HARD_CAP . '-item inventory cap on your current plan. Add the POS add-on for unlimited inventory; existing items keep working.',
                ]);
        }

        $categories = TenantInventoryCategory::where('tenant_id', $tenant->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        if ($categories->isEmpty()) {
            return redirect()->route('tenant.inventory.categories.index')
                ->with('flash', ['type' => 'info', 'message' => 'Create at least one category before adding items.']);
        }

        return view('tenant.inventory.create', compact('categories'));
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        // POS hard cap: defense in depth — even if create() was bypassed,
        // refuse to actually write the 121st item.
        if (!$this->inventoryAddIsAllowed($tenant)) {
            return redirect()->route('tenant.inventory.index')
                ->with('flash', [
                    'type'    => 'error',
                    'message' => 'You\'ve reached the ' . self::POS_INVENTORY_HARD_CAP . '-item inventory cap on your current plan. Add the POS add-on for unlimited inventory; existing items keep working.',
                ]);
        }

        $data = $request->validate([
            'category_id'           => ['required', 'uuid', 'exists:tenant_inventory_categories,id'],
            'sku'                   => ['required', 'string', 'max:64'],
            'name'                  => ['required', 'string', 'max:255'],
            'description'           => ['nullable', 'string'],
            'shop_cost_dollars'     => ['nullable', 'numeric', 'min:0'],
            'shop_sell_price_dollars' => ['nullable', 'numeric', 'min:0'],
            'shop_case_quantity'    => ['nullable', 'integer', 'min:1'],
            'shop_reorder_threshold' => ['nullable', 'integer', 'min:0'],
            'shop_reorder_quantity' => ['nullable', 'integer', 'min:1'],
            'shop_bin_location'     => ['nullable', 'string', 'max:50'],
            'allow_oversell'        => ['nullable', 'boolean'],
            'initial_stock'         => ['nullable', 'integer', 'min:0'],
        ]);

        // Verify category belongs to tenant (defense in depth)
        $category = TenantInventoryCategory::where('tenant_id', $tenant->id)
            ->where('id', $data['category_id'])
            ->firstOrFail();

        // Enforce SKU uniqueness within tenant
        $skuTaken = TenantInventoryItem::where('tenant_id', $tenant->id)
            ->where('sku', $data['sku'])
            ->exists();
        if ($skuTaken) {
            return back()->withInput()->withErrors([
                'sku' => "SKU '{$data['sku']}' already exists for this tenant.",
            ]);
        }

        $item = TenantInventoryItem::create([
            'tenant_id'              => $tenant->id,
            'category_id'            => $data['category_id'],
            'sku'                    => $data['sku'],
            'name'                   => $data['name'],
            'description'            => $data['description'] ?? null,
            'shop_cost_cents'        => isset($data['shop_cost_dollars']) ? (int) round($data['shop_cost_dollars'] * 100) : null,
            'shop_sell_price_cents'  => isset($data['shop_sell_price_dollars']) ? (int) round($data['shop_sell_price_dollars'] * 100) : null,
            'shop_case_quantity'     => $data['shop_case_quantity'] ?? null,
            'shop_reorder_threshold' => $data['shop_reorder_threshold'] ?? null,
            'shop_reorder_quantity'  => $data['shop_reorder_quantity'] ?? null,
            'shop_bin_location'      => $data['shop_bin_location'] ?? null,
            'allow_oversell'         => (bool) ($data['allow_oversell'] ?? true),
            'is_active'              => true,
        ]);

        // Optional initial stock — record at the default location
        if (!empty($data['initial_stock']) && $data['initial_stock'] > 0) {
            $defaultLocation = $tenant->defaultLocation;
            if ($defaultLocation) {
                $this->inventory->recordInitialStock(
                    tenant: $tenant,
                    item: $item,
                    location: $defaultLocation,
                    quantity: (int) $data['initial_stock'],
                    tenantUser: Auth::guard('tenant')->user(),
                );
            }
        }

        return redirect()->route('tenant.inventory.show', $item->id)
            ->with('flash', ['type' => 'success', 'message' => "Item '{$item->name}' created."]);
    }

    public function show(string $subdomain, string $id): View
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        $item = TenantInventoryItem::with(['category', 'distributorCatalog', 'locations.location', 'specialOrders.vendor', 'specialOrders.customer', 'specialOrders.appointment', 'vendors'])
            ->where('tenant_id', $tenant->id)
            ->findOrFail($id);

        // Most recent 50 movements for the activity log
        $recentMovements = $item->movements()
            ->with('location')
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        $locations = $tenant->activeLocations()->get();

        $vendors = \App\Models\Tenant\TenantVendor::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('tenant.inventory.show', compact('item', 'recentMovements', 'locations', 'vendors'));
    }

    public function edit(string $subdomain, string $id): View
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        $item = TenantInventoryItem::with(['category', 'distributorCatalog'])
            ->where('tenant_id', $tenant->id)
            ->findOrFail($id);

        $categories = TenantInventoryCategory::where('tenant_id', $tenant->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return view('tenant.inventory.edit', compact('item', 'categories'));
    }

    public function update(Request $request, string $subdomain, string $id): RedirectResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        $item = TenantInventoryItem::where('tenant_id', $tenant->id)->findOrFail($id);

        $data = $request->validate([
            'category_id'             => ['required', 'uuid', 'exists:tenant_inventory_categories,id'],
            'sku'                     => ['required', 'string', 'max:64'],
            'name'                    => ['required', 'string', 'max:255'],
            'description'             => ['nullable', 'string'],
            'shop_cost_dollars'       => ['nullable', 'numeric', 'min:0'],
            'shop_sell_price_dollars' => ['nullable', 'numeric', 'min:0'],
            'shop_case_quantity'      => ['nullable', 'integer', 'min:1'],
            'shop_reorder_threshold'  => ['nullable', 'integer', 'min:0'],
            'shop_reorder_quantity'   => ['nullable', 'integer', 'min:1'],
            'shop_bin_location'       => ['nullable', 'string', 'max:50'],
            'allow_oversell'          => ['nullable', 'boolean'],
            'is_active'               => ['nullable', 'boolean'],
        ]);

        // Tenant-bound category check
        TenantInventoryCategory::where('tenant_id', $tenant->id)
            ->where('id', $data['category_id'])
            ->firstOrFail();

        // SKU uniqueness — exclude current item
        $skuTaken = TenantInventoryItem::where('tenant_id', $tenant->id)
            ->where('sku', $data['sku'])
            ->where('id', '!=', $item->id)
            ->exists();
        if ($skuTaken) {
            return back()->withInput()->withErrors([
                'sku' => "SKU '{$data['sku']}' already exists for this tenant.",
            ]);
        }

        $item->update([
            'category_id'            => $data['category_id'],
            'sku'                    => $data['sku'],
            'name'                   => $data['name'],
            'description'            => $data['description'] ?? null,
            'shop_cost_cents'        => isset($data['shop_cost_dollars']) ? (int) round($data['shop_cost_dollars'] * 100) : null,
            'shop_sell_price_cents'  => isset($data['shop_sell_price_dollars']) ? (int) round($data['shop_sell_price_dollars'] * 100) : null,
            'shop_case_quantity'     => $data['shop_case_quantity'] ?? null,
            'shop_reorder_threshold' => $data['shop_reorder_threshold'] ?? null,
            'shop_reorder_quantity'  => $data['shop_reorder_quantity'] ?? null,
            'shop_bin_location'      => $data['shop_bin_location'] ?? null,
            'allow_oversell'         => (bool) ($data['allow_oversell'] ?? true),
            'is_active'              => (bool) ($data['is_active'] ?? true),
        ]);

        return redirect()->route('tenant.inventory.show', $item->id)
            ->with('flash', ['type' => 'success', 'message' => 'Item updated.']);
    }

    /**
     * POST /admin/inventory/{id}/stock — manual stock adjustment.
     *
     * Writes a movement of type 'adjustment' with a required reason from
     * the v1 reason taxonomy. The InventoryService refuses empty reasons
     * via InvalidQuantityException; we surface that as a validation error.
     */
    public function adjustStock(Request $request, string $subdomain, string $id): RedirectResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        $item = TenantInventoryItem::where('tenant_id', $tenant->id)->findOrFail($id);

        $data = $request->validate([
            'location_id' => ['required', 'uuid', 'exists:tenant_locations,id'],
            'new_count'   => ['required', 'integer', 'min:0'],
            'reason_code' => ['required', 'string', 'in:damaged,expired,theft_shrinkage,count_correction,found,vendor_credit,donation,internal_use,display,sample,other'],
            'reason_text' => ['nullable', 'string', 'max:500'],
            'notes'       => ['nullable', 'string', 'max:1000'],
        ]);

        // 'other' requires a reason_text
        if ($data['reason_code'] === 'other' && empty(trim((string) ($data['reason_text'] ?? '')))) {
            return back()->withInput()->withErrors([
                'reason_text' => 'Please provide a reason when selecting "Other".',
            ]);
        }

        $location = $tenant->activeLocations()->where('id', $data['location_id'])->firstOrFail();

        // Build the reason string: if 'other', use the typed text; otherwise use the code
        $reasonString = $data['reason_code'] === 'other'
            ? trim($data['reason_text'])
            : $data['reason_code'];

        try {
            $this->inventory->adjustStock(
                tenant: $tenant,
                item: $item,
                location: $location,
                newCount: (int) $data['new_count'],
                reason: $reasonString,
                tenantUser: Auth::guard('tenant')->user(),
                notes: $data['notes'] ?? null,
            );
        } catch (InvalidQuantityException $e) {
            return back()->withInput()->withErrors(['reason_code' => $e->getMessage()]);
        }

        return redirect()->route('tenant.inventory.show', $item->id)
            ->with('flash', ['type' => 'success', 'message' => 'Stock adjusted.']);
    }

    public function destroy(string $subdomain, string $id): RedirectResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        $item = TenantInventoryItem::where('tenant_id', $tenant->id)->findOrFail($id);
        $item->update(['is_active' => false]);
        $item->delete(); // soft-delete

        return redirect()->route('tenant.inventory.index')
            ->with('flash', ['type' => 'success', 'message' => "Item '{$item->name}' archived."]);
    }

}
