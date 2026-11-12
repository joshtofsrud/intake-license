<?php

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Models\Tenant\TenantInventoryItem;

/**
 * MARKER-GOOGLE-FEED: the one rule for "this item is for sale online". The shop pages and the
 * Google feed both ask here, so they can never disagree about what is listed.
 */
class OnlineItems
{
    public static function query(Tenant $tenant)
    {
        $q = TenantInventoryItem::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->where('show_online', true);

        // the shop may hide items that are out of stock and can't be oversold
        if (OrderService::wording($tenant)['hide_out']) {
            // supplier stock the shop may ship counts too
            $supplier = OrderStock::forTenant($tenant)->supplierUsable(OrderStock::MODE_CART);
            $q->where(function ($w) use ($supplier) {
                $w->where('computed_stock_count', '>', 0)->orWhere('allow_oversell', true);
                if ($supplier) {
                    $w->orWhereExists(fn ($e) => $e->selectRaw('1')->from('tenant_inventory_item_vendors as v')
                        ->whereColumn('v.inventory_item_id', 'tenant_inventory_items.id')->where('v.live_avail', '>', 0));
                }
            });
        }

        return $q;
    }
}
