<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\PlatformDistributorCatalog;
use App\Models\Tenant\TenantInventoryItem;
use App\Services\Distributors\DistributorCatalogImportService;
use App\Support\CatalogPreviewLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-CATALOG-PREVIEW: one catalog row shown as this shop would get it,
 * opened from master admin with a signed link. The row is imported the way
 * the shop's own import would do it, inside a transaction, the real page is
 * rendered, and the transaction is rolled back: nothing is saved, and the
 * page says so.
 */
class CatalogPreviewController extends Controller
{
    /** The shop's item page (needs a signed-in staff member of this shop). */
    public function inventory(Request $request, string $catalogId)
    {
        return $this->preview($request, 'inventory', $catalogId, function (string $itemId) use ($request) {
            return app(InventoryController::class)->show($request, $itemId);
        });
    }

    /** The shop's online store page for the item. */
    public function shop(Request $request, string $catalogId)
    {
        return $this->preview($request, 'shop', $catalogId, function (string $itemId) {
            $item = TenantInventoryItem::with(['distributorCatalog', 'category:id,name'])->findOrFail($itemId);
            return app(StorefrontController::class)->renderItem($item);
        });
    }

    private function preview(Request $request, string $kind, string $catalogId, callable $render)
    {
        $tenant = tenant();
        abort_unless(CatalogPreviewLink::valid((string) $tenant->id, $kind, $catalogId, $request->query('expires'), $request->query('sig')), 403, 'This preview link has expired. Open it again from master admin.');
        $cat = PlatformDistributorCatalog::query()->whereKey($catalogId)->firstOrFail();

        DB::beginTransaction();
        try {
            $res = app(DistributorCatalogImportService::class)
                ->import((string) $tenant->id, (string) $cat->distributor_code, ['ids' => [$catalogId], 'include_unsellable' => true]);
            $itemId = TenantInventoryItem::query()->where('tenant_id', $tenant->id)
                ->where(function ($w) use ($catalogId) {
                    $w->where('distributor_catalog_id', $catalogId)
                      ->orWhereHas('vendors', fn ($v) => $v->where('tenant_inventory_item_vendors.distributor_catalog_id', $catalogId));
                })->value('id');
            abort_unless($itemId, 422, 'This catalog row could not be turned into an item for ' . $tenant->name . ' (its brand may be switched off for the shop).');

            if ($kind === 'shop') {
                // as if the shop had put it online
                TenantInventoryItem::query()->whereKey($itemId)->update(['show_online' => true, 'is_active' => true]);
            }

            request()->attributes->set('catalog_preview', true);
            $response = $render((string) $itemId);
            $html = $response instanceof \Illuminate\Contracts\View\View ? $response->render()
                : (method_exists($response, 'getContent') ? $response->getContent() : (string) $response);
            $merged = ($res['merged'] ?? 0) > 0;
        } finally {
            DB::rollBack();
        }

        $banner = '<div style="position:sticky;top:0;z-index:99999;background:#7c3aed;color:#fff;font:600 13px/1.4 system-ui,sans-serif;padding:9px 16px;text-align:center">'
            . 'Preview from master admin: ' . e($cat->display_name ?: $cat->name) . ' (' . e($cat->distributor_variant_no) . ') as ' . e($tenant->name) . ' would get it'
            . ($merged ? ', added to an item the shop already has' : '')
            . '. Nothing was saved; buttons on this page do nothing useful here.</div>';
        // a bike read before the reader learned geometry, sizing and story has none of them to show
        $raw = (array) ($cat->source_raw ?? []);
        if (empty($raw['geometry_all']) && empty($raw['sizing']) && empty($raw['story'])) {
            $banner .= '<div style="position:sticky;top:38px;z-index:99998;background:#b45309;color:#fff;font:600 13px/1.4 system-ui,sans-serif;padding:8px 16px;text-align:center">'
                . 'This bike has no geometry, sizing chart or story stored yet: it was read before those were added, or its page has none. Read it again from Brand suppliers (Read one bike), then reload this preview.</div>';
        }
        $html = preg_replace('~<body([^>]*)>~i', '<body$1>' . $banner, $html, 1) ?? $html;

        return response($html)->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
