<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantInventoryCategory;
use App\Services\FeatureAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Inventory categories — minimal v1 stub.
 *
 * v1 supports: list, quick-add. Full CRUD (rename, delete, hierarchy,
 * tax classes, sort order) comes in a follow-up session.
 */
class InventoryCategoryController extends Controller
{
    public function __construct(protected FeatureAccessService $featureAccess)
    {
    }

    public function index(Request $request): View
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        $categories = TenantInventoryCategory::where('tenant_id', $tenant->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return view('tenant.inventory.categories.index', compact('categories'));
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:128'],
        ]);

        $slug = Str::slug($data['name']);
        $base = $slug;
        $suffix = 1;
        while (TenantInventoryCategory::where('tenant_id', $tenant->id)->where('slug', $slug)->exists()) {
            $slug = $base . '-' . (++$suffix);
        }

        TenantInventoryCategory::create([
            'tenant_id' => $tenant->id,
            'name' => $data['name'],
            'slug' => $slug,
            'sort_order' => 0,
            'source' => 'manual',
        ]);

        return redirect()->route('tenant.inventory.categories.index')
            ->with('flash', ['type' => 'success', 'message' => "Category '{$data['name']}' added."]);
    }

    protected function assertRetailEnabled($tenant): void
    {
        if (!$this->featureAccess->hasAddon($tenant, 'retail')) {
            abort(403, 'Inventory requires the Retail capability. Upgrade to Branded or Scale to access.');
        }
    }
}
