<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantInventoryCategory;
use App\Services\FeatureAccessService;
use Illuminate\Http\JsonResponse;
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

        $counts = \App\Models\Tenant\TenantInventoryItem::where('tenant_id', $tenant->id)
            ->where('is_active', true)->whereNotNull('category_id')
            ->selectRaw('category_id, count(*) as n')->groupBy('category_id')->pluck('n', 'category_id');

        $tree = $this->treeFor($categories, $counts);

        return view('tenant.inventory.categories.index', compact('categories', 'tree'));
    }

    /** Flatten tenant categories into a pre-ordered tree with depth + path + count. */
    private function treeFor($cats, $counts, $parentId = null, int $depth = 0, string $parentPath = ''): array
    {
        $out = [];
        $children = $parentId === null ? $cats->whereNull('parent_id') : $cats->where('parent_id', $parentId);
        foreach ($children as $c) {
            $path = $parentPath === '' ? $c->name : ($parentPath . ' › ' . $c->name);
            $out[] = ['id' => $c->id, 'name' => $c->name, 'parent_id' => $c->parent_id,
                      'depth' => $depth, 'path' => $path, 'count' => (int) ($counts[$c->id] ?? 0)];
            $out = array_merge($out, $this->treeFor($cats, $counts, $c->id, $depth + 1, $path));
        }
        return $out;
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            // MARKER-CAT-TREE — ownership, not mere existence (same hole closed on transfers)
            'parent_id' => ['nullable', 'uuid', \Illuminate\Validation\Rule::exists('tenant_inventory_categories', 'id')
                ->where(fn ($q) => $q->where('tenant_id', $tenant->id))],
        ]);

        TenantInventoryCategory::create([
            'tenant_id' => $tenant->id,
            'name' => $data['name'],
            'slug' => $this->uniqueSlug($tenant, $data['name']),
            'parent_id' => $this->ownedParentId($tenant, $data['parent_id'] ?? null),
            'sort_order' => 0,
            'source' => 'manual',
        ]);

        return redirect()->route('tenant.inventory.categories.index')
            ->with('flash', ['type' => 'success', 'message' => "Category '{$data['name']}' added."]);
    }

    /** MARKER-PATCH-HLC25 — inline create from the mapping worklist (no navigation). */
    public function quickStore(Request $request): JsonResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            // MARKER-CAT-TREE — ownership, not mere existence (same hole closed on transfers)
            'parent_id' => ['nullable', 'uuid', \Illuminate\Validation\Rule::exists('tenant_inventory_categories', 'id')
                ->where(fn ($q) => $q->where('tenant_id', $tenant->id))],
        ]);

        $cat = TenantInventoryCategory::create([
            'tenant_id' => $tenant->id,
            'name' => $data['name'],
            'slug' => $this->uniqueSlug($tenant, $data['name']),
            'parent_id' => $this->ownedParentId($tenant, $data['parent_id'] ?? null),
            'sort_order' => 0,
            'source' => 'manual',
        ]);

        $path = $this->categoryPath($cat);

        return response()->json([
            'id' => $cat->id,
            'name' => $cat->name,
            'path' => $path,
            'depth' => substr_count($path, ' › '),
        ]);
    }

    /** MARKER-PATCH-HLC25 — turn an existing category into a child (or move to top). */
    public function reparent(Request $request, string $id): RedirectResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        $cat = TenantInventoryCategory::where('tenant_id', $tenant->id)->findOrFail($id);

        $data = $request->validate([
            // MARKER-CAT-TREE — ownership, not mere existence (same hole closed on transfers)
            'parent_id' => ['nullable', 'uuid', \Illuminate\Validation\Rule::exists('tenant_inventory_categories', 'id')
                ->where(fn ($q) => $q->where('tenant_id', $tenant->id))],
        ]);
        $newParent = $this->ownedParentId($tenant, $data['parent_id'] ?? null);

        if ($newParent === $cat->id) {
            return back()->with('flash', ['type' => 'error', 'message' => "A category can't be its own parent."]);
        }
        if ($newParent && $this->isDescendant($tenant, $newParent, $cat->id)) {
            return back()->with('flash', ['type' => 'error', 'message' => "Can't move \"{$cat->name}\" under its own sub-category."]);
        }

        $cat->update(['parent_id' => $newParent]);

        return back()->with('flash', ['type' => 'success', 'message' => "Moved \"{$cat->name}\"."]);
    }

    private function uniqueSlug($tenant, string $name): string
    {
        $slug = Str::slug($name);
        $base = $slug;
        $suffix = 1;
        while (TenantInventoryCategory::where('tenant_id', $tenant->id)->where('slug', $slug)->exists()) {
            $slug = $base . '-' . (++$suffix);
        }
        return $slug;
    }

    private function ownedParentId($tenant, ?string $parentId): ?string
    {
        if (! $parentId) {
            return null;
        }
        return TenantInventoryCategory::where('tenant_id', $tenant->id)->where('id', $parentId)->value('id');
    }

    private function categoryPath(TenantInventoryCategory $cat): string
    {
        $names = [];
        $cur = $cat;
        $guard = 0;
        while ($cur && $guard++ < 100) {
            array_unshift($names, $cur->name);
            $cur = $cur->parent_id ? TenantInventoryCategory::find($cur->parent_id) : null;
        }
        return implode(' › ', $names);
    }

    /** Is $candidateId inside the subtree of $ancestorId? (walk up from candidate). */
    private function isDescendant($tenant, string $candidateId, string $ancestorId): bool
    {
        $map = TenantInventoryCategory::where('tenant_id', $tenant->id)->pluck('parent_id', 'id');
        $cur = $candidateId;
        $guard = 0;
        while ($cur !== null && $guard++ < 100) {
            if ($cur === $ancestorId) {
                return true;
            }
            $cur = $map[$cur] ?? null;
        }
        return false;
    }

    protected function assertRetailEnabled($tenant): void
    {
        if (!$this->featureAccess->hasAddon($tenant, 'retail')) {
            abort(403, 'Inventory requires the Retail capability. Upgrade to Branded or Scale to access.');
        }
    }
}
