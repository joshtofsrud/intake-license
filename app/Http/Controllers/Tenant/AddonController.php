<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantAddon;
use App\Models\Tenant\TenantServiceAddon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AddonController extends Controller
{
    // ================================================================
    // STORE — POST /addons  (create new addon)
    // ================================================================
    public function store(Request $request)
    {
        return $this->handleOp($request);
    }

    // ================================================================
    // UPDATE — PATCH /addons/{id}
    // ================================================================
    public function update(Request $request, string $subdomain, string $id)
    {
        return $this->handleOp($request, $id);
    }

    // ================================================================
    // DESTROY — DELETE /addons/{id}
    // ================================================================
    public function destroy(Request $request, string $subdomain, string $id)
    {
        $tenant = tenant();

        // Warn if in use
        $usage = TenantServiceAddon::where('addon_id', $id)->count();

        try {
            TenantAddon::where('tenant_id', $tenant->id)
                ->where('id', $id)
                ->delete();
        } catch (\Throwable $e) {
            Log::error('Addon delete failed', ['id' => $id, 'error' => $e->getMessage()]);
            return $this->err($e->getMessage(), 500);
        }

        return response()->json(['success' => true, 'detached_from_services' => $usage]);
    }

    // ================================================================
    private function handleOp(Request $request, ?string $id = null)
    {
        $tenant = tenant();
        $op     = $request->input('op');

        try {
            return match ($op) {
                'save_addon'   => $this->saveAddon($request, $tenant, $id),
                'update_field' => $this->updateField($request, $tenant, $id),
                default        => $this->err('Unknown op: ' . $op),
            };
        } catch (\Throwable $e) {
            Log::error('Addon op failed', [
                'op' => $op, 'id' => $id, 'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->err('Error: ' . $e->getMessage(), 500);
        }
    }

    // ================================================================
    private function saveAddon(Request $request, $tenant, ?string $id)
    {
        $name = trim($request->input('name', ''));
        if (!$name) return $this->err('Name is required.');

        if ($id) {
            $addon = TenantAddon::where('tenant_id', $tenant->id)
                ->where('id', $id)->firstOrFail();
            $addon->update([
                'name'                     => $name,
                'description'              => $request->input('description', ''),
                'price_cents'              => max(0, (int) $request->input('price_cents', 0)),
                'default_duration_minutes' => max(0, min(240, (int) $request->input('default_duration_minutes', 0))),
                'is_active'                => (bool) $request->input('is_active', 1),
            ]);
        } else {
            $max = TenantAddon::where('tenant_id', $tenant->id)->max('sort_order') ?? 0;
            $addon = TenantAddon::create([
                'tenant_id'                => $tenant->id,
                'name'                     => $name,
                'description'              => $request->input('description', ''),
                'price_cents'              => max(0, (int) $request->input('price_cents', 0)),
                'default_duration_minutes' => max(0, min(240, (int) $request->input('default_duration_minutes', 0))),
                'is_active'                => true,
                'sort_order'               => $max + 1,
            ]);
        }

        return response()->json([
            'success' => true,
            'id'      => $addon->id,
            'addon'   => array_merge($addon->fresh()->toArray(), [
                'usage_count' => $addon->usageCount(),
            ]),
        ]);
    }

    // ================================================================
    private function updateField(Request $request, $tenant, ?string $id)
    {
        if (!$id) return $this->err('ID is required.');

        $addon = TenantAddon::where('tenant_id', $tenant->id)
            ->where('id', $id)->first();
        if (!$addon) return $this->err('Addon not found.', 404);

        $field = $request->input('field');
        $value = $request->input('value');

        $allowed = ['name', 'description', 'price_cents', 'default_duration_minutes', 'is_active'];
        if (!in_array($field, $allowed, true)) {
            return $this->err('Field not editable: ' . $field);
        }

        if ($field === 'name') {
            $value = trim((string) $value);
            if ($value === '') return $this->err('Name cannot be empty.');
            $addon->name = $value;
        } elseif ($field === 'price_cents') {
            $addon->price_cents = max(0, (int) $value);
        } elseif ($field === 'default_duration_minutes') {
            $addon->default_duration_minutes = max(0, min(240, (int) $value));
        } elseif ($field === 'is_active') {
            $addon->is_active = (bool) $value;
        } elseif ($field === 'description') {
            $addon->description = (string) $value;
        }

        $addon->save();

        return response()->json([
            'success' => true,
            'id'      => $addon->id,
            'field'   => $field,
            'value'   => $addon->{$field},
        ]);
    }

    // ================================================================
    private function err(string $msg, int $status = 422)
    {
        return response()->json(['success' => false, 'message' => $msg], $status);
    }
}
