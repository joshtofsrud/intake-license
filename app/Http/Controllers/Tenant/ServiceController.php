<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantAddon;
use App\Models\Tenant\TenantServiceAddon;
use App\Models\Tenant\TenantServiceCategory;
use App\Models\Tenant\TenantServiceItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServiceController extends Controller
{
    public function index()
    {
        $tenant = tenant();

        $categories = TenantServiceCategory::where('tenant_id', $tenant->id)
            ->orderBy('sort_order')
            ->with(['items' => function ($q) {
                $q->orderBy('sort_order')
                  ->with(['serviceAddons' => function ($sa) {
                      $sa->orderBy('sort_order')->with('addon');
                  }, 'eligibleResources']);
            }])
            ->get();

        $library = TenantAddon::where('tenant_id', $tenant->id)
            ->orderBy('sort_order')
            ->withCount('serviceAddons as usage_count')
            ->get();

        $jsCategories = $categories->map(fn($cat) => [
            'id'         => $cat->id,
            'name'       => $cat->name,
            'slug'       => $cat->slug,
            'is_active'  => (bool) $cat->is_active,
            'sort_order' => (int) $cat->sort_order,
            'services'   => $cat->items->map(fn($item) => [
                'id'                    => $item->id,
                'category_id'           => $item->category_id,
                'name'                  => $item->name,
                'slug'                  => $item->slug,
                'description'           => $item->description,
                'image_url'             => $item->image_url,
                'price_cents'           => (int) $item->price_cents,
                'prep_before_minutes'   => (int) $item->prep_before_minutes,
                'duration_minutes'      => (int) $item->duration_minutes,
                'cleanup_after_minutes' => (int) $item->cleanup_after_minutes,
                'slot_weight'           => (int) $item->slot_weight,
                'is_active'             => (bool) $item->is_active,
                'quick_only'            => (bool) $item->quick_only, // MARKER-PATCH-546
                'sort_order'            => (int) $item->sort_order,
                'eligible_resource_ids' => $item->eligibleResources->pluck('id')->values()->toArray(),
                'addons'                => $item->serviceAddons->map(fn($pivot) => [
                    'attachment_id'             => $pivot->id,
                    'addon_id'                  => $pivot->addon_id,
                    'name'                      => $pivot->addon?->name ?? '',
                    'description'               => $pivot->addon?->description ?? '',
                    'override_duration_minutes' => $pivot->override_duration_minutes,
                    'override_price_cents'      => $pivot->override_price_cents,
                    'default_duration_minutes'  => (int) ($pivot->addon?->default_duration_minutes ?? 0),
                    'default_price_cents'       => (int) ($pivot->addon?->price_cents ?? 0),
                    'effective_duration_minutes'=> $pivot->effectiveDuration(),
                    'effective_price_cents'     => $pivot->effectivePriceCents(),
                    'sort_order'                => (int) $pivot->sort_order,
                ])->values()->toArray(),
            ])->values()->toArray(),
        ])->values()->toArray();

        $jsLibrary = $library->map(fn($a) => [
            'id'                       => $a->id,
            'name'                     => $a->name,
            'description'              => $a->description,
            'price_cents'              => (int) $a->price_cents,
            'default_duration_minutes' => (int) $a->default_duration_minutes,
            'is_active'                => (bool) $a->is_active,
            'sort_order'               => (int) $a->sort_order,
            'usage_count'              => (int) $a->usage_count,
        ])->values()->toArray();

        $mode = $tenant->booking_mode ?? 'drop_off';

        // Active resources for the eligibility selector. Empty array if no
        // active resources — services.js handles the empty case.
        $jsResources = \App\Models\Tenant\TenantResource::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'color_hex'])
            ->map(fn($r) => [
                'id'        => $r->id,
                'name'      => $r->name,
                'color_hex' => $r->color_hex,
            ])->values()->toArray();

        return view('tenant.services.index', [
            'jsCategories' => $jsCategories,
            'jsLibrary'    => $jsLibrary,
            'jsMode'       => $mode,
            'jsResources'  => $jsResources,
        ]);
    }

    public function store(Request $request)
    {
        return $this->handleOp($request);
    }

    public function update(Request $request, string $id)
    {
        return $this->handleOp($request, $id);
    }

    public function destroy(Request $request, string $id)
    {
        $tenant = tenant();
        $op = $request->input('op', 'delete_service');

        if ($op === 'delete_category') {
            // MARKER-SVC-CAT — tenant_service_items.category_id is
            // cascadeOnDelete. Deleting a category with services in it would
            // destroy them and their add-on links, silently. Never cascade:
            // the services move somewhere first, or nothing happens.
            $cat = TenantServiceCategory::where('tenant_id', $tenant->id)
                ->where('id', $id)->first();
            if (! $cat) return response()->json(['ok' => true]);

            $count = TenantServiceItem::where('tenant_id', $tenant->id)
                ->where('category_id', $cat->id)->count();

            if ($count > 0) {
                $moveTo = $request->input('move_to');
                if (! $moveTo) {
                    return $this->err(
                        'That category still has ' . $count . ' ' .
                        \Illuminate\Support\Str::plural('service', $count) .
                        ' in it. Choose where they should move first.'
                    );
                }

                $dest = TenantServiceCategory::where('tenant_id', $tenant->id)
                    ->where('id', $moveTo)->where('id', '!=', $cat->id)->first();
                if (! $dest) return $this->err('Pick a different category to move them to.');

                \Illuminate\Support\Facades\DB::transaction(function () use ($tenant, $cat, $dest) {
                    TenantServiceItem::where('tenant_id', $tenant->id)
                        ->where('category_id', $cat->id)
                        ->update(['category_id' => $dest->id]);
                    $cat->delete();
                });

                return response()->json([
                    'ok' => true, 'moved' => $count, 'moved_to' => $dest->id,
                ]);
            }

            $cat->delete();
            return response()->json(['ok' => true, 'moved' => 0]);
        }

        if ($op === 'delete_service') {
            TenantServiceItem::where('tenant_id', $tenant->id)->where('id', $id)->delete();
            return response()->json(['ok' => true]);
        }

        return $this->err('Unknown delete operation.');
    }

    private function handleOp(Request $request, ?string $id = null)
    {
        $tenant = tenant();
        $op = $request->input('op');

        if ($op === 'save_category')        return $this->saveCategory($request, $tenant, $id);
        if ($op === 'update_category')      return $this->updateCategory($request, $tenant, $id);
        if ($op === 'save_service')         return $this->saveService($request, $tenant, $id);
        if ($op === 'update_field')         return $this->updateField($request, $tenant, $id);
        if ($op === 'attach_addon')         return $this->attachAddon($request, $tenant, $id);
        if ($op === 'detach_addon')         return $this->detachAddon($request, $tenant, $id);
        if ($op === 'update_addon_override')return $this->updateAddonOverride($request, $tenant, $id);
        if ($op === 'duplicate_service')    return $this->duplicateService($tenant, $id);
        if ($op === 'reorder_services')     return $this->reorderServices($request, $tenant);
        if ($op === 'reorder_categories')   return $this->reorderCategories($request, $tenant);
        if ($op === 'set_eligibility')      return $this->setEligibility($request, $tenant, $id);

        return $this->err('Unknown operation.');
    }

    private function saveCategory(Request $request, $tenant, ?string $id)
    {
        $name = trim((string) $request->input('name', ''));
        if ($name === '') return $this->err('Category name is required.');

        $slug = $this->uniqueSlug('tenant_service_categories', $name, $tenant->id, $id);

        if ($id) {
            $cat = TenantServiceCategory::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();
            $cat->update(['name' => $name, 'slug' => $slug]);
        } else {
            $max = TenantServiceCategory::where('tenant_id', $tenant->id)->max('sort_order') ?? 0;
            $cat = TenantServiceCategory::create([
                'tenant_id'  => $tenant->id,
                'name'       => $name,
                'slug'       => $slug,
                'is_active'  => true,
                'sort_order' => $max + 1,
            ]);
        }

        return response()->json(['ok' => true, 'data' => [
            'id' => $cat->id, 'name' => $cat->name, 'slug' => $cat->slug,
            'is_active' => (bool) $cat->is_active, 'sort_order' => (int) $cat->sort_order,
        ]]);
    }

    private function updateCategory(Request $request, $tenant, ?string $id)
    {
        if (!$id) return $this->err('Category id is required.');
        $cat = TenantServiceCategory::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();

        $field = (string) $request->input('field', '');
        $value = $request->input('value');

        if ($field === 'name') {
            $name = trim((string) $value);
            if ($name === '') return $this->err('Name is required.');
            $cat->name = $name;
            $cat->slug = $this->uniqueSlug('tenant_service_categories', $name, $tenant->id, $id);
        } elseif ($field === 'is_active') {
            $cat->is_active = (bool) $value;
        } else {
            return $this->err("Field '{$field}' is not editable on a category.");
        }

        $cat->save();
        return response()->json(['ok' => true, 'data' => [
            'id' => $cat->id, 'name' => $cat->name, 'slug' => $cat->slug,
            'is_active' => (bool) $cat->is_active,
        ]]);
    }

    private function saveService(Request $request, $tenant, ?string $id)
    {
        $name       = trim((string) $request->input('name', ''));
        $categoryId = $request->input('category_id');
        if ($name === '')       return $this->err('Service name is required.');
        if (!$categoryId)       return $this->err('Category is required.');

        TenantServiceCategory::where('tenant_id', $tenant->id)
            ->where('id', $categoryId)->firstOrFail();

        $slug = $this->uniqueSlug('tenant_service_items', $name, $tenant->id, $id);

        $payload = [
            'name'                  => $name,
            'slug'                  => $slug,
            'description'           => $request->input('description'),
            'category_id'           => $categoryId,
            'price_cents'           => (int) $request->input('price_cents', 0),
            'prep_before_minutes'   => (int) $request->input('prep_before_minutes', 0),
            'duration_minutes'      => max(1, (int) $request->input('duration_minutes', 30)),
            'cleanup_after_minutes' => (int) $request->input('cleanup_after_minutes', 0),
            'slot_weight'           => max(1, min(4, (int) $request->input('slot_weight', 1))),
            'quick_only'            => (bool) ((int) $request->input('quick_only', 0)), // MARKER-PATCH-546
        ];

        if ($id) {
            $service = TenantServiceItem::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();
            $service->update($payload);
        } else {
            $max = TenantServiceItem::where('tenant_id', $tenant->id)
                ->where('category_id', $categoryId)->max('sort_order') ?? 0;
            $service = TenantServiceItem::create(array_merge($payload, [
                'tenant_id'  => $tenant->id,
                'is_active'  => true,
                'sort_order' => $max + 1,
            ]));
        }

        return response()->json(['ok' => true, 'data' => [
            'id'                    => $service->id,
            'name'                  => $service->name,
            'slug'                  => $service->slug,
            'category_id'           => $service->category_id,
            'price_cents'           => (int) $service->price_cents,
            'prep_before_minutes'   => (int) $service->prep_before_minutes,
            'duration_minutes'      => (int) $service->duration_minutes,
            'cleanup_after_minutes' => (int) $service->cleanup_after_minutes,
            'slot_weight'           => (int) $service->slot_weight,
            'is_active'             => (bool) $service->is_active,
            'sort_order'            => (int) $service->sort_order,
        ]]);
    }

    private function updateField(Request $request, $tenant, ?string $id)
    {
        if (!$id) return $this->err('Service id is required.');
        $service = TenantServiceItem::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();

        $field = (string) $request->input('field', '');
        $value = $request->input('value');

        $allowed = [
            'name', 'description',
            'price_cents', 'prep_before_minutes', 'duration_minutes',
            'cleanup_after_minutes', 'slot_weight', 'is_active', 'category_id',
        ];
        if (!in_array($field, $allowed, true)) {
            return $this->err("Field '{$field}' is not editable on a service.");
        }

        if ($field === 'name') {
            $name = trim((string) $value);
            if ($name === '') return $this->err('Name is required.');
            $service->name = $name;
            $service->slug = $this->uniqueSlug('tenant_service_items', $name, $tenant->id, $id);
        } elseif ($field === 'description') {
            $service->description = $value !== null ? (string) $value : null;
        } elseif ($field === 'is_active') {
            $service->is_active = (bool) $value;
        } elseif ($field === 'category_id') {
            if (!$value) return $this->err('Category is required.');
            TenantServiceCategory::where('tenant_id', $tenant->id)->where('id', $value)->firstOrFail();
            $service->category_id = (string) $value;
        } elseif ($field === 'slot_weight') {
            $service->slot_weight = max(1, min(4, (int) $value));
        } elseif ($field === 'duration_minutes') {
            $service->duration_minutes = max(1, (int) $value);
        } else {
            $service->{$field} = max(0, (int) $value);
        }

        $service->save();

        return response()->json(['ok' => true, 'data' => [
            'id' => $service->id, 'field' => $field, 'value' => $service->{$field},
        ]]);
    }

    private function attachAddon(Request $request, $tenant, ?string $id)
    {
        if (!$id) return $this->err('Service id is required.');
        $service = TenantServiceItem::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();

        $addonId = (string) $request->input('addon_id', '');
        if ($addonId === '') return $this->err('addon_id is required.');

        $addon = TenantAddon::where('tenant_id', $tenant->id)->where('id', $addonId)->firstOrFail();

        $existing = TenantServiceAddon::where('service_item_id', $service->id)
            ->where('addon_id', $addon->id)->first();
        if ($existing) {
            return response()->json(['ok' => true, 'data' => $this->pivotPayload($existing->fresh('addon'))]);
        }

        $maxOrder = TenantServiceAddon::where('service_item_id', $service->id)->max('sort_order') ?? 0;

        $pivot = TenantServiceAddon::create([
            'service_item_id' => $service->id,
            'addon_id'        => $addon->id,
            'sort_order'      => $maxOrder + 1,
        ]);
        $pivot->load('addon');

        return response()->json(['ok' => true, 'data' => $this->pivotPayload($pivot)]);
    }

    private function detachAddon(Request $request, $tenant, ?string $id)
    {
        if (!$id) return $this->err('Service id is required.');
        $service = TenantServiceItem::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();

        $addonId = (string) $request->input('addon_id', '');
        if ($addonId === '') return $this->err('addon_id is required.');

        TenantServiceAddon::where('service_item_id', $service->id)
            ->where('addon_id', $addonId)->delete();

        return response()->json(['ok' => true]);
    }

    private function updateAddonOverride(Request $request, $tenant, ?string $id)
    {
        if (!$id) return $this->err('Service id is required.');
        $service = TenantServiceItem::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();

        $addonId = (string) $request->input('addon_id', '');
        $field   = (string) $request->input('field', '');
        $valueRaw = $request->input('value');

        if ($addonId === '') return $this->err('addon_id is required.');
        if (!in_array($field, ['duration', 'price'], true)) {
            return $this->err("Field must be 'duration' or 'price'.");
        }

        $pivot = TenantServiceAddon::where('service_item_id', $service->id)
            ->where('addon_id', $addonId)->with('addon')->firstOrFail();

        $clear = ($valueRaw === null || $valueRaw === '' || $valueRaw === 'null');

        if ($field === 'duration') {
            $column = 'override_duration_minutes';
            $default = (int) ($pivot->addon?->default_duration_minutes ?? 0);
            $value = $clear ? null : max(0, (int) $valueRaw);
        } else {
            $column = 'override_price_cents';
            $default = (int) ($pivot->addon?->price_cents ?? 0);
            $value = $clear ? null : max(0, (int) $valueRaw);
        }

        if ($value !== null && $value === $default) {
            $value = null;
        }

        $pivot->{$column} = $value;
        $pivot->save();

        return response()->json(['ok' => true, 'data' => $this->pivotPayload($pivot->fresh('addon'))]);
    }

    private function duplicateService($tenant, ?string $id)
    {
        if (!$id) return $this->err('Service id is required.');
        $service = TenantServiceItem::where('tenant_id', $tenant->id)
            ->where('id', $id)->with('serviceAddons')->firstOrFail();

        return DB::transaction(function () use ($service, $tenant) {
            $newName = $service->name . ' (copy)';
            $slug = $this->uniqueSlug('tenant_service_items', $newName, $tenant->id, null);
            $maxOrder = TenantServiceItem::where('tenant_id', $tenant->id)
                ->where('category_id', $service->category_id)->max('sort_order') ?? 0;

            $copy = TenantServiceItem::create([
                'tenant_id'             => $tenant->id,
                'category_id'           => $service->category_id,
                'name'                  => $newName,
                'slug'                  => $slug,
                'description'           => $service->description,
                'image_url'             => $service->image_url,
                'price_cents'           => $service->price_cents,
                'prep_before_minutes'   => $service->prep_before_minutes,
                'duration_minutes'      => $service->duration_minutes,
                'cleanup_after_minutes' => $service->cleanup_after_minutes,
                'slot_weight'           => $service->slot_weight,
                'is_active'             => $service->is_active,
                'sort_order'            => $maxOrder + 1,
            ]);

            foreach ($service->serviceAddons as $pivot) {
                TenantServiceAddon::create([
                    'service_item_id'           => $copy->id,
                    'addon_id'                  => $pivot->addon_id,
                    'override_duration_minutes' => $pivot->override_duration_minutes,
                    'override_price_cents'      => $pivot->override_price_cents,
                    'sort_order'                => $pivot->sort_order,
                ]);
            }

            return response()->json(['ok' => true, 'data' => ['id' => $copy->id]]);
        });
    }

    private function reorderServices(Request $request, $tenant)
    {
        $order = $request->input('order', []);
        if (!is_array($order)) return $this->err('order must be an array of service ids.');

        foreach ($order as $i => $serviceId) {
            TenantServiceItem::where('tenant_id', $tenant->id)
                ->where('id', $serviceId)
                ->update(['sort_order' => (int) $i]);
        }
        return response()->json(['ok' => true]);
    }

    private function reorderCategories(Request $request, $tenant)
    {
        $order = $request->input('order', []);
        if (!is_array($order)) return $this->err('order must be an array of category ids.');

        foreach ($order as $i => $catId) {
            TenantServiceCategory::where('tenant_id', $tenant->id)
                ->where('id', $catId)
                ->update(['sort_order' => (int) $i]);
        }
        return response()->json(['ok' => true]);
    }

    private function setEligibility(Request $request, $tenant, ?string $id)
    {
        if (!$id) return $this->err('Service id is required.');
        $service = TenantServiceItem::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();

        $resourceIds = $request->input('resource_ids', []);
        if (!is_array($resourceIds)) return $this->err('resource_ids must be an array.');

        // Validate every requested ID belongs to this tenant and is active.
        // We allow empty array (means "all resources eligible" by convention).
        if (!empty($resourceIds)) {
            $validCount = \App\Models\Tenant\TenantResource::where('tenant_id', $tenant->id)
                ->where('is_active', true)
                ->whereIn('id', $resourceIds)
                ->count();
            if ($validCount !== count($resourceIds)) {
                return $this->err('One or more resource IDs are invalid or inactive.');
            }
        }

        // sync() replaces the entire pivot for this service in one transaction.
        // Pass tenant_id alongside each row so the pivot table's tenant_id
        // column gets populated (otherwise sync() leaves it null and the
        // not-null constraint blows up).
        $syncPayload = [];
        foreach ($resourceIds as $rid) {
            $syncPayload[$rid] = ['tenant_id' => $tenant->id];
        }
        $service->eligibleResources()->sync($syncPayload);

        return response()->json(['ok' => true, 'data' => [
            'id'                    => $service->id,
            'eligible_resource_ids' => $resourceIds,
        ]]);
    }

    private function pivotPayload(TenantServiceAddon $pivot): array
    {
        return [
            'attachment_id'              => $pivot->id,
            'addon_id'                   => $pivot->addon_id,
            'name'                       => $pivot->addon?->name ?? '',
            'description'                => $pivot->addon?->description ?? '',
            'override_duration_minutes'  => $pivot->override_duration_minutes,
            'override_price_cents'       => $pivot->override_price_cents,
            'default_duration_minutes'   => (int) ($pivot->addon?->default_duration_minutes ?? 0),
            'default_price_cents'        => (int) ($pivot->addon?->price_cents ?? 0),
            'effective_duration_minutes' => $pivot->effectiveDuration(),
            'effective_price_cents'      => $pivot->effectivePriceCents(),
            'sort_order'                 => (int) $pivot->sort_order,
        ];
    }

    private function uniqueSlug(string $table, string $name, string $tenantId, ?string $excludeId): string
    {
        $base = Str::slug($name);
        if ($base === '') $base = 'item';
        $slug = $base;
        $i = 1;
        while (DB::table($table)->where('tenant_id', $tenantId)->where('slug', $slug)
            ->when($excludeId, fn($q) => $q->where('id', '!=', $excludeId))
            ->exists()) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }

    private function err(string $msg, int $status = 422)
    {
        return response()->json(['ok' => false, 'error' => $msg], $status);
    }
}
