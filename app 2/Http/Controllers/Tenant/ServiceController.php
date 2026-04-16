<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantServiceCategory;
use App\Models\Tenant\TenantServiceTier;
use App\Models\Tenant\TenantServiceItem;
use App\Models\Tenant\TenantItemTierPrice;
use App\Models\Tenant\TenantAddon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ServiceController extends Controller
{
    // ----------------------------------------------------------------
    // Index — load full catalog for WYSIWYG editor
    // ----------------------------------------------------------------
    public function index()
    {
        $tenant = tenant();

        $tiers = TenantServiceTier::where('tenant_id', $tenant->id)
            ->orderBy('sort_order')->get();

        $categories = TenantServiceCategory::where('tenant_id', $tenant->id)
            ->orderBy('sort_order')
            ->with(['items' => function ($q) {
                $q->orderBy('sort_order')->with('tierPrices');
            }])
            ->get();

        $addons = TenantAddon::where('tenant_id', $tenant->id)
            ->orderBy('sort_order')->get();

        // Build JS-ready catalog
        $jsTiers = $tiers->map(fn($t) => [
            'id'          => $t->id,
            'name'        => $t->name,
            'slug'        => $t->slug,
            'description' => $t->description,
            'is_active'   => (bool) $t->is_active,
            'sort_order'  => $t->sort_order,
        ])->values()->toArray();

        $jsCatalog = $categories->map(fn($cat) => [
            'id'          => $cat->id,
            'name'        => $cat->name,
            'slug'        => $cat->slug,
            'is_active'   => (bool) $cat->is_active,
            'sort_order'  => $cat->sort_order,
            'items'       => $cat->items->map(fn($item) => [
                'id'          => $item->id,
                'name'        => $item->name,
                'slug'        => $item->slug,
                'description' => $item->description,
                'image_url'   => $item->image_url,
                'is_active'   => (bool) $item->is_active,
                'sort_order'  => $item->sort_order,
                'tier_prices' => $item->tierPrices->mapWithKeys(fn($p) => [
                    $p->tier_id => $p->price_cents,
                ])->toArray(),
            ])->values()->toArray(),
        ])->values()->toArray();

        $jsAddons = $addons->map(fn($a) => [
            'id'          => $a->id,
            'name'        => $a->name,
            'description' => $a->description,
            'price_cents' => $a->price_cents,
            'is_active'   => (bool) $a->is_active,
        ])->values()->toArray();

        return view('tenant.services.index', compact(
            'jsTiers', 'jsCatalog', 'jsAddons'
        ));
    }

    // ----------------------------------------------------------------
    // Store / Update / Destroy — all via AJAX op parameter
    // ----------------------------------------------------------------
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
        $op     = $request->input('op', 'delete_item');

        if ($op === 'delete_category') {
            TenantServiceCategory::where('tenant_id', $tenant->id)->where('id', $id)->delete();
        } elseif ($op === 'delete_tier') {
            TenantServiceTier::where('tenant_id', $tenant->id)->where('id', $id)->delete();
        } elseif ($op === 'delete_item') {
            TenantServiceItem::whereHas('category', fn($q) => $q->where('tenant_id', $tenant->id))
                ->where('id', $id)->delete();
        } elseif ($op === 'delete_addon') {
            TenantAddon::where('tenant_id', $tenant->id)->where('id', $id)->delete();
        }

        return response()->json(['success' => true]);
    }

    // ----------------------------------------------------------------
    private function handleOp(Request $request, ?string $id = null)
    {
        $tenant = tenant();
        $op     = $request->input('op');

        // ---- Category ----
        if ($op === 'save_category') {
            $name = trim($request->input('name', ''));
            if (!$name) return $this->err('Name is required.');
            $slug = $this->uniqueSlug('tenant_service_categories', $name, $tenant->id, $id);
            if ($id) {
                $cat = TenantServiceCategory::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();
                $cat->update(['name' => $name, 'slug' => $slug, 'is_active' => (bool) $request->input('is_active', 1)]);
            } else {
                $max = TenantServiceCategory::where('tenant_id', $tenant->id)->max('sort_order') ?? 0;
                $cat = TenantServiceCategory::create([
                    'tenant_id' => $tenant->id, 'name' => $name,
                    'slug' => $slug, 'is_active' => true, 'sort_order' => $max + 1,
                ]);
            }
            return response()->json(['success' => true, 'id' => $cat->id, 'slug' => $cat->slug]);
        }

        // ---- Tier ----
        if ($op === 'save_tier') {
            $name = trim($request->input('name', ''));
            if (!$name) return $this->err('Name is required.');
            $slug = $this->uniqueSlug('tenant_service_tiers', $name, $tenant->id, $id);
            if ($id) {
                $tier = TenantServiceTier::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();
                $tier->update([
                    'name' => $name, 'slug' => $slug,
                    'description' => $request->input('description', ''),
                    'is_active' => (bool) $request->input('is_active', 1),
                ]);
            } else {
                $max = TenantServiceTier::where('tenant_id', $tenant->id)->max('sort_order') ?? 0;
                $tier = TenantServiceTier::create([
                    'tenant_id' => $tenant->id, 'name' => $name,
                    'slug' => $slug, 'is_active' => true, 'sort_order' => $max + 1,
                ]);
            }
            return response()->json(['success' => true, 'id' => $tier->id, 'slug' => $tier->slug]);
        }

        // ---- Item ----
        if ($op === 'save_item') {
            $name       = trim($request->input('name', ''));
            $categoryId = $request->input('category_id');
            if (!$name || !$categoryId) return $this->err('Name and category are required.');

            $cat = TenantServiceCategory::where('tenant_id', $tenant->id)->where('id', $categoryId)->firstOrFail();
            $slug = $this->uniqueSlug('tenant_service_items', $name, $tenant->id, $id);

            if ($id) {
                $item = TenantServiceItem::where('id', $id)->whereHas('category', fn($q) => $q->where('tenant_id', $tenant->id))->firstOrFail();
                $item->update([
                    'name' => $name, 'slug' => $slug,
                    'description' => $request->input('description', ''),
                    'is_active'   => (bool) $request->input('is_active', 1),
                    'category_id' => $categoryId,
                ]);
            } else {
                $max = TenantServiceItem::where('category_id', $categoryId)->max('sort_order') ?? 0;
                $item = TenantServiceItem::create([
                    'tenant_id' => $tenant->id, 'category_id' => $categoryId,
                    'name' => $name, 'slug' => $slug, 'is_active' => true, 'sort_order' => $max + 1,
                ]);
            }

            // Save tier prices
            $tierPrices = $request->input('tier_prices', []);
            foreach ($tierPrices as $tierId => $centsRaw) {
                $cents = $centsRaw === '' || $centsRaw === null ? null : (int) $centsRaw;
                TenantItemTierPrice::updateOrCreate(
                    ['item_id' => $item->id, 'tier_id' => $tierId],
                    ['tenant_id' => $tenant->id, 'price_cents' => $cents]
                );
            }

            $prices = TenantItemTierPrice::where('item_id', $item->id)
                ->get()->mapWithKeys(fn($p) => [$p->tier_id => $p->price_cents]);

            return response()->json(['success' => true, 'id' => $item->id, 'slug' => $item->slug, 'tier_prices' => $prices]);
        }

        // ---- Reorder items ----
        if ($op === 'reorder_items') {
            $order = $request->input('item_order', []);
            foreach ($order as $i => $itemId) {
                TenantServiceItem::whereHas('category', fn($q) => $q->where('tenant_id', $tenant->id))
                    ->where('id', $itemId)
                    ->update(['sort_order' => $i]);
            }
            return response()->json(['success' => true]);
        }

        // ---- Reorder categories ----
        if ($op === 'reorder_categories') {
            $order = $request->input('category_order', []);
            foreach ($order as $i => $catId) {
                TenantServiceCategory::where('tenant_id', $tenant->id)->where('id', $catId)
                    ->update(['sort_order' => $i]);
            }
            return response()->json(['success' => true]);
        }

        return $this->err('Unknown operation.');
    }

    private function uniqueSlug(string $table, string $name, string $tenantId, ?string $excludeId): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i    = 1;
        while (\DB::table($table)->where('tenant_id', $tenantId)->where('slug', $slug)
            ->when($excludeId, fn($q) => $q->where('id', '!=', $excludeId))
            ->exists()) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }

    private function err(string $msg)
    {
        return response()->json(['success' => false, 'message' => $msg], 422);
    }
}
