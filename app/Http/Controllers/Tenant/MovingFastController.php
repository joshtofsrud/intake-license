<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Support\MovingFast;
use Illuminate\Http\Request;

/**
 * MARKER-MOVING-FAST: what the builder's Moving fast popup reads. Distributor stock only: how fast
 * it is leaving the shop's vendors. Never another shop's sales.
 */
class MovingFastController extends Controller
{
    /** GET /admin/pages/moving-fast/items?list=fast — the products for one list, plus the filters. */
    public function items(Request $request)
    {
        $t = tenant();
        abort_unless(MovingFast::available($t), 403);
        $list = isset(MovingFast::LISTS[$request->query('list')]) ? $request->query('list') : 'fast';
        $pool = MovingFast::pool($t, $list);

        // hand-picked or pinned products from other lists stay visible in the popup
        $want = array_filter(array_map('strval', (array) $request->query('keep', [])));
        if ($want) {
            $have = array_column($pool, 'key');
            foreach (array_keys(MovingFast::LISTS) as $l) {
                if ($l === $list || ! array_diff($want, $have)) continue;
                foreach (MovingFast::pool($t, $l) as $p) {
                    if (in_array($p['key'], $want, true) && ! in_array($p['key'], $have, true)) { $p['other'] = true; $pool[] = $p; $have[] = $p['key']; }
                }
            }
        }

        $set = MovingFast::settings();
        return response()->json([
            'list'     => $list,
            'vendors'  => MovingFast::vendors($t),
            'items'    => array_map(fn ($p) => array_diff_key($p, ['map' => 1]), $pool),
            'settings' => ['few_left' => $set['few_left'], 'back_days' => $set['back_days'], 'new_days' => $set['new_days'], 'fast_week' => $set['fast_week']],
            'shop'     => ['name' => $t->name, 'special' => \App\Services\Tenant\OrderService::wording($t)['special_label']],
        ]);
    }

    /** GET /admin/pages/moving-fast/detail?key=… — the last 8 weeks for one product. */
    public function detail(Request $request)
    {
        $t = tenant();
        abort_unless(MovingFast::available($t), 403);
        $cids = array_filter(array_map('strval', (array) $request->query('cids', [])));
        return response()->json(['weeks' => MovingFast::weeks(array_slice($cids, 0, 6), MovingFast::vendors($t))]);
    }

    /** POST /admin/pages/moving-fast/add — add one product to the shop's inventory now. */
    public function add(Request $request)
    {
        $t = tenant();
        abort_unless(MovingFast::available($t), 403);
        $data = $request->validate(['code' => 'required|string|max:32', 'cid' => 'required|string|max:64', 'key' => 'nullable|string|max:120']);
        abort_unless(in_array(strtoupper($data['code']), MovingFast::vendors($t), true), 422);
        $p = ['you' => null, 'code0' => strtoupper($data['code']), 'cids' => [$data['cid']]];
        $list = [$p];
        MovingFast::ensure($t, $list);
        $you = $list[0]['you'];
        if (! $you) return response()->json(['ok' => false, 'error' => 'That item could not be added. Its brand may be switched off for your shop.'], 422);
        return response()->json(['ok' => true, 'you' => $you, 'edit' => route('tenant.inventory.edit', $you['id'])]);
    }
}
