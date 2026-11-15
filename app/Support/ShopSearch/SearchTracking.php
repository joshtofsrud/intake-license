<?php

namespace App\Support\ShopSearch;

use App\Models\Tenant;
use App\Models\Tenant\TenantOrder;
use App\Models\Tenant\TenantSearchEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-SHOP-SEARCH-V2: from search to purchase, for Reports › Search.
 *
 * It rides on the visit the shop already keeps for the cart (the session cookie),
 * so no new cookie is set. A click from search is remembered for 30 minutes; if
 * that item goes into the cart in that time, the add is tied to the search and
 * to the cart. The cart row becomes the order, so "bought" is simply an add whose
 * order was paid. Searches from shoppers whose browser sends Do Not Track or
 * Global Privacy Control are counted, but their clicks are not followed.
 */
class SearchTracking
{
    public const WINDOW_MIN = 30;

    public static function tracking(Request $r): bool
    {
        return $r->header('DNT') !== '1' && $r->header('Sec-GPC') !== '1';
    }

    private static function sid(Request $r): ?string
    {
        return $r->hasSession() ? mb_substr((string) $r->session()->getId(), 0, 64) : null;
    }

    public static function click(Tenant $t, Request $r, string $itemId, ?string $qid, string $q = ''): void
    {
        if (! self::tracking($r)) return;
        try {
            if ($qid && ! \Illuminate\Support\Str::isUuid($qid)) $qid = null;
            $query = $qid ? DB::table('tenant_search_queries')->where('tenant_id', $t->id)->where('id', $qid)->value('query') : null;
            if (! $query) return; // only clicks from a logged search count
            TenantSearchEvent::create([
                'tenant_id' => $t->id, 'query_id' => $qid, 'query' => mb_substr($query, 0, 190), 'kind' => 'click',
                'item_id' => $itemId, 'session_id' => self::sid($r), 'created_at' => now(),
            ]);
            $attr = (array) $r->session()->get('shop_search_attr', []);
            $attr[$itemId] = ['qid' => $qid, 'q' => $query, 'at' => time()];
            $r->session()->put('shop_search_attr', array_slice($attr, -20, null, true));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function promo(Tenant $t, Request $r, $promo): void
    {
        if (! self::tracking($r)) return;
        try {
            TenantSearchEvent::create([
                'tenant_id' => $t->id, 'kind' => 'promo_click', 'promotion_id' => $promo->id,
                'session_id' => self::sid($r), 'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Called after an item goes into the cart. $qid comes with an Add to cart pressed on a result card. */
    public static function added(Tenant $t, Request $r, string $itemId, TenantOrder $cart, ?string $qid = null): void
    {
        if (! self::tracking($r) || ! $r->hasSession()) return;
        try {
            if ($qid && \Illuminate\Support\Str::isUuid($qid)) {
                self::click($t, $r, $itemId, $qid); // adding from the card is opening it and adding it at once
            }
            $attr = (array) $r->session()->get('shop_search_attr', []);
            $a = $attr[$itemId] ?? null;
            if (! $a || (time() - (int) $a['at']) > self::WINDOW_MIN * 60) return;
            $dupe = TenantSearchEvent::where('tenant_id', $t->id)->where('kind', 'add')
                ->where('order_id', $cart->id)->where('item_id', $itemId)->exists();
            if (! $dupe) {
                TenantSearchEvent::create([
                    'tenant_id' => $t->id, 'query_id' => $a['qid'], 'query' => mb_substr((string) $a['q'], 0, 190), 'kind' => 'add',
                    'item_id' => $itemId, 'order_id' => $cart->id, 'session_id' => self::sid($r), 'created_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
