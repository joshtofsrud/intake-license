<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantReceivingMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ReceivingMethodController extends Controller
{
    public function index()
    {
        $tenant = tenant();
        $methods = TenantReceivingMethod::where('tenant_id', $tenant->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return view('tenant.receiving-methods.index', [
            'methods' => $methods,
        ]);
    }

    public function store(Request $request)
    {
        $tenant = tenant();

        $request->validate([
            'name'             => ['required', 'string', 'max:120'],
            'description'      => ['nullable', 'string', 'max:500'],
            'ask_for_time'     => ['nullable', 'boolean'],
            'ask_for_tracking' => ['nullable', 'boolean'],
        ]);

        $name = trim($request->input('name'));
        $slug = $this->uniqueSlug($tenant->id, $name);

        $maxSort = TenantReceivingMethod::where('tenant_id', $tenant->id)->max('sort_order') ?? -1;

        $method = TenantReceivingMethod::create([
            'id'               => (string) Str::uuid(),
            'tenant_id'        => $tenant->id,
            'name'             => $name,
            'slug'             => $slug,
            'description'      => $request->input('description'),
            'ask_for_time'     => (bool) $request->input('ask_for_time', false),
            'ask_for_tracking' => (bool) $request->input('ask_for_tracking', false),
            'sort_order'       => $maxSort + 1,
            'is_active'        => true,
        ]);

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'method' => $method]);
        }

        return redirect()->route('tenant.receiving-methods.index')->with('flash', 'Drop-off method added.');
    }

    public function update(Request $request, string $subdomain, string $id)
    {
        $tenant = tenant();
        $method = TenantReceivingMethod::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $request->validate([
            'name'             => ['sometimes', 'required', 'string', 'max:120'],
            'description'      => ['sometimes', 'nullable', 'string', 'max:500'],
            'ask_for_time'     => ['sometimes', 'boolean'],
            'ask_for_tracking' => ['sometimes', 'boolean'],
            'is_active'        => ['sometimes', 'boolean'],
        ]);

        // If name changed, regenerate slug to keep it readable. Old slug is fine
        // to preserve since nothing in the system references it externally.
        $payload = $request->only(['name', 'description', 'ask_for_time', 'ask_for_tracking', 'is_active']);
        if (isset($payload['name']) && $payload['name'] !== $method->name) {
            $payload['slug'] = $this->uniqueSlug($tenant->id, $payload['name'], $method->id);
        }

        $method->update($payload);

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'method' => $method->fresh()]);
        }

        return redirect()->route('tenant.receiving-methods.index')->with('flash', 'Drop-off method updated.');
    }

    /**
     * Soft-delete (deactivate). Past appointments reference receiving_method_snapshot
     * by name, not by FK, so they're never affected by a delete here.
     */
    public function destroy(string $subdomain, string $id)
    {
        $tenant = tenant();
        $method = TenantReceivingMethod::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $method->update(['is_active' => false]);

        if (request()->expectsJson()) {
            return response()->json(['success' => true]);
        }

        return redirect()->route('tenant.receiving-methods.index')->with('flash', 'Drop-off method deactivated.');
    }

    public function reorder(Request $request)
    {
        $tenant = tenant();
        $ids    = $request->input('order', []);
        if (!is_array($ids)) {
            return response()->json(['success' => false, 'message' => 'Invalid order payload'], 422);
        }

        foreach ($ids as $sortOrder => $id) {
            TenantReceivingMethod::where('tenant_id', $tenant->id)
                ->where('id', $id)
                ->update(['sort_order' => $sortOrder]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Generate a unique slug scoped to the tenant. Suffixes -2, -3, etc. as needed.
     * Excludes a given id so an updating record doesn't collide with itself.
     */
    private function uniqueSlug(string $tenantId, string $name, ?string $excludeId = null): string
    {
        $base = Str::slug(substr($name, 0, 50));
        if ($base === '') $base = 'method';

        $candidate = $base;
        $i = 2;
        while (TenantReceivingMethod::where('tenant_id', $tenantId)
            ->where('slug', $candidate)
            ->when($excludeId, fn($q) => $q->where('id', '!=', $excludeId))
            ->exists()) {
            $candidate = $base . '-' . $i;
            $i++;
        }
        return $candidate;
    }
}
