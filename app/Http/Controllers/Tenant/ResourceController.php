<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantResource;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ResourceController extends Controller
{
    /**
     * Curated color swatches available for resource selection.
     * Lime (#BEF264) is reserved for system signals — not in this list.
     * The color picker UI greys it out as "Reserved" so users see why.
     */
    public const SWATCHES = [
        '#59D3E6', '#3B82F6', '#A78BFA', '#EC4899',
        '#F472B6', '#FB923C', '#F59E0B', '#EF4444',
        '#34D399', '#14B8A6', '#06B6D4', '#8B5CF6',
        '#6366F1', '#D946EF', '#64748B',
    ];

    public function index()
    {
        $tenant = tenant();
        $resources = TenantResource::where('tenant_id', $tenant->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return view('tenant.resources.index', [
            'resources' => $resources,
            'swatches'  => self::SWATCHES,
        ]);
    }

    public function store(Request $request)
    {
        $tenant = tenant();

        $request->validate([
            'name'      => ['required', 'string', 'max:120'],
            'subtitle'  => ['nullable', 'string', 'max:120'],
            'color_hex' => ['required', 'string', 'in:' . implode(',', self::SWATCHES)],
            'type'      => ['nullable', 'in:staff,slot,space'],
        ]);

        $maxSort = TenantResource::where('tenant_id', $tenant->id)->max('sort_order') ?? -1;

        $resource = TenantResource::create([
            'id'         => (string) Str::uuid(),
            'tenant_id'  => $tenant->id,
            'name'       => $request->input('name'),
            'subtitle'   => $request->input('subtitle'),
            'color_hex'  => $request->input('color_hex'),
            'type'       => $request->input('type', 'staff'),
            'sort_order' => $maxSort + 1,
            'is_active'  => true,
        ]);

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'resource' => $resource]);
        }

        return redirect()->route('tenant.resources.index')->with('flash', 'Resource added.');
    }

    public function update(Request $request, string $subdomain, string $id)
    {
        $tenant   = tenant();
        $resource = TenantResource::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $request->validate([
            'name'      => ['sometimes', 'required', 'string', 'max:120'],
            'subtitle'  => ['sometimes', 'nullable', 'string', 'max:120'],
            'color_hex' => ['sometimes', 'required', 'string', 'in:' . implode(',', self::SWATCHES)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $resource->update($request->only(['name', 'subtitle', 'color_hex', 'is_active']));

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'resource' => $resource->fresh()]);
        }

        return redirect()->route('tenant.resources.index')->with('flash', 'Resource updated.');
    }

    /**
     * Soft-delete (deactivate) a resource. We never hard-delete because
     * appointments reference resource_id with nullOnDelete; hard-deleting
     * would orphan historical bookings from their resource context.
     */
    public function destroy(string $subdomain, string $id)
    {
        $tenant   = tenant();
        $resource = TenantResource::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $resource->update(['is_active' => false]);

        if (request()->expectsJson()) {
            return response()->json(['success' => true]);
        }

        return redirect()->route('tenant.resources.index')->with('flash', 'Resource deactivated.');
    }

    /**
     * Drag-to-reorder. Accepts a JSON array of resource IDs in their new order.
     */
    public function reorder(Request $request)
    {
        $tenant = tenant();
        $ids    = $request->input('order', []);
        if (!is_array($ids)) {
            return response()->json(['success' => false, 'message' => 'Invalid order payload'], 422);
        }

        foreach ($ids as $sortOrder => $id) {
            TenantResource::where('tenant_id', $tenant->id)
                ->where('id', $id)
                ->update(['sort_order' => $sortOrder]);
        }

        return response()->json(['success' => true]);
    }
}
