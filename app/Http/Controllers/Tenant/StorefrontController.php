<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantInventoryCategory;
use App\Models\Tenant\TenantInventoryItem;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * MARKER-PATCH-561 — Online Retail Wave 2: the read-only storefront.
 * Grid + product page over inventory the tenant has opted online.
 * No cart, no writes — Wave 3 adds the cart on top of these views.
 */
class StorefrontController extends Controller
{
    /**
     * MARKER-PATCH-562 — storefront floor: Branded and above only.
     * Starter gets a 404 (indistinguishable from "no store"), matching
     * how tierRank() treats unknown tiers as most-restrictive.
     */
    private function guardTier(): void
    {
        $rank = match (tenant()->plan_tier ?? 'starter') {
            'branded' => 1, 'scale' => 2, 'custom' => 3, default => 0,
        };
        abort_if($rank < 1, 404);
    }

    /** Base query for anything the store may show. */
    private function visible()
    {
        $this->guardTier();
        return TenantInventoryItem::query()
            ->where('tenant_id', tenant()->id)
            ->where('is_active', true)
            ->where('show_online', true);
    }

    public function index(Request $request): View
    {
        $tenant = tenant();
        $q   = trim((string) $request->query('q', ''));
        $cat = $request->query('category');

        $items = $this->visible()
            ->with(['distributorCatalog:id,manufacturer,images', 'category:id,name'])
            ->when($q !== '', function ($w) use ($q) {
                // Same tokenized any-field match as the register (patch-552).
                $w->where(function ($x) use ($q) {
                    foreach (array_filter(preg_split('/\s+/', $q)) as $t) {
                        $x->whereRaw("CONCAT_WS(' ', name, display_subtitle, sku, catalog_upc) LIKE ?", ['%' . $t . '%']);
                    }
                });
            })
            ->when($cat, fn ($w) => $w->where('category_id', $cat))
            ->orderBy('name')
            ->paginate(24)
            ->withQueryString();

        // Category chips: only categories that actually contain visible items.
        $categories = TenantInventoryCategory::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('id', $this->visible()->select('category_id'))
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('public.shop.index', [
            'tenant'     => $tenant,
            'items'      => $items,
            'categories' => $categories,
            'q'          => $q,
            'activeCat'  => $cat,
        ]);
    }

    public function show(string $id): View
    {
        $item = $this->visible()
            ->with(['distributorCatalog', 'category:id,name'])
            ->where('id', $id)
            ->firstOrFail();

        $cat = $item->distributorCatalog;

        $images = collect((array) ($cat?->images ?? []))
            ->map(function ($im) {
                if (is_string($im)) return $im;
                if (is_array($im)) return $im['Url'] ?? $im['url'] ?? $im['src'] ?? null;
                return null;
            })->filter()->values()->take(6)->all();

        $attrs = collect((array) ($cat?->attributes ?? []))
            ->filter(fn ($a) => is_array($a) && !empty($a['Name']) && trim((string) ($a['Value'] ?? '')) !== '')
            ->map(fn ($a) => ['name' => $a['Name'], 'value' => $a['Value']])
            ->values()->all();

        return view('public.shop.show', [
            'tenant' => tenant(),
            'item'   => $item,
            'images' => $images,
            'attrs'  => $attrs,
            'brand'  => $cat?->manufacturer,
        ]);
    }
}
