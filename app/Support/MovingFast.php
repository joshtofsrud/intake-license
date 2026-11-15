<?php

namespace App\Support;

use App\Models\PlatformSettings;
use App\Models\Tenant;
use App\Models\Tenant\TenantInventoryItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-MOVING-FAST: the "Moving fast" page section. Items selling fastest at the shop's
 * vendors (from distributor stock velocity), whether or not the shop carries them.
 *
 *   pool()    the candidates for one list, grouped into products across vendors, with what the
 *             shop carries. The builder popup filters and orders these in the browser.
 *   shown()   what a saved section shows shoppers: the same filters and order, on the server.
 *   ensure()  an item the shop doesn't carry becomes a catalog item (not stock-tracked) in its
 *             inventory when it is shown, so a shopper can open it and order it.
 *
 * Shops only ever see distributor stock, never another shop's sales. Which distributors, the
 * history rule and the thresholds come from master admin › Distributor velocity › Shop visibility.
 */
class MovingFast
{
    public const LISTS = [
        'fast' => ['Selling fastest', 'units leaving your vendors per week'],
        'low'  => ['Running low at vendors', 'fewest days of stock left at your vendors'],
        'back' => ['Back in stock', 'restocked at a vendor recently'],
        'new'  => ['New at vendors', 'new in your vendors\' catalogs'],
    ];
    public const CARRY = ['all', 'stock', 'in', 'none'];
    public const COUNTS = [4, 8, 12];
    public const POOL = 400;      // summary rows read per list before grouping
    public const SHOWN_TTL = 600; // seconds a section's list is kept

    /** Master admin settings, with defaults. */
    public static function settings(): array
    {
        try {
            $s = (array) (PlatformSettings::current()->velocity_settings ?? []);
        } catch (\Throwable $e) {
            $s = [];
        }
        $m = (array) ($s['moving_fast'] ?? []);
        return [
            'on'        => (bool) ($m['on'] ?? true),
            'few_left'  => max(1, (int) ($m['few_left'] ?? 7)),
            'back_days' => max(1, (int) ($m['back_days'] ?? 14)),
            'new_days'  => max(1, (int) ($m['new_days'] ?? 30)),
            'fast_week' => max(1, (int) ($m['fast_week'] ?? 10)),
            'good'      => (bool) ($s['require_good'] ?? true),
            'vendors'   => isset($s['vendors']) ? array_map('strtoupper', (array) $s['vendors']) : null,
        ];
    }

    /** The section can be added and shown: online store add-on, store switched on, feature on. */
    public static function available(?Tenant $t): bool
    {
        if (! $t || ! self::settings()['on']) return false;
        try {
            $addon = app(\App\Services\FeatureAccessService::class)->hasAddon($t, 'online_store');
        } catch (\Throwable $e) {
            return false;
        }
        return $addon && (bool) (($t->settings['storefront']['enabled'] ?? true));
    }

    /** Distributors this shop is linked to and master admin shows to shops. */
    public static function vendors(Tenant $t): array
    {
        $codes = DB::table('tenant_distributor_catalog_subscriptions')->where('tenant_id', $t->id)->where('is_active', true)
            ->pluck('distributor_code')->map(fn ($c) => strtoupper((string) $c))->unique()->values()->all();
        $allowed = self::settings()['vendors'];
        if (is_array($allowed)) $codes = array_values(array_intersect($codes, $allowed));
        sort($codes);
        return $codes;
    }

    /** The section's saved choices, cleaned. */
    public static function config(array $c): array
    {
        $arr = function ($v) {
            if (is_string($v)) $v = json_decode($v, true);
            return array_values(array_filter(array_map('strval', is_array($v) ? $v : []), fn ($x) => $x !== ''));
        };
        $bool = fn ($v, $d) => $v === null || $v === '' ? $d : in_array((string) $v, ['1', 'true', 'show'], true);
        $count = (int) ($c['mf_count'] ?? 8);
        return [
            'list'    => isset(self::LISTS[$c['mf_list'] ?? '']) ? $c['mf_list'] : 'fast',
            'mode'    => ($c['mf_mode'] ?? '') === 'hand' ? 'hand' : 'auto',
            'count'   => in_array($count, self::COUNTS, true) ? $count : 8,
            'layout'  => ($c['mf_layout'] ?? '') === 'row' ? 'row' : 'grid',
            'carry'   => in_array($c['mf_carry'] ?? '', self::CARRY, true) ? $c['mf_carry'] : 'all',
            'vendors' => array_map('strtoupper', $arr($c['mf_vendors'] ?? [])), // empty = every vendor
            'cats'    => $arr($c['mf_cats'] ?? []),
            'brands'  => $arr($c['mf_brands'] ?? []),
            'min'     => is_numeric($c['mf_min'] ?? null) ? (float) $c['mf_min'] : null,
            'max'     => is_numeric($c['mf_max'] ?? null) ? (float) $c['mf_max'] : null,
            'picked'  => $arr($c['mf_picked'] ?? []),
            'badges'  => $bool($c['mf_badges'] ?? null, true),
            'prices'  => $bool($c['mf_prices'] ?? null, true),
            'special' => ($c['mf_special'] ?? '') === 'hide' ? 'hide' : 'show',
        ];
    }

    // ------------------------------------------------------------------ the pool

    /**
     * Products for one list at this shop's vendors. Each:
     *   key, name, brand, cat (vendor category path, " › " separated), top, img, pn, msrp,
     *   wk (units a week across vendors), left (fewest days left), back (days since a restock),
     *   seen (days since it appeared in a catalog), v: [code => [wk, left, arrival, back]],
     *   cids (catalog ids), you: null | {id, st, pr, url}, price (what shoppers pay, cents)
     */
    public static function pool(Tenant $t, string $list): array
    {
        $codes = self::vendors($t);
        if (! $codes) return [];
        $set = self::settings();

        $q = DB::table('distributor_stock_summary as s')
            ->join('platform_distributor_catalogs as c', 'c.id', '=', 's.distributor_catalog_id')
            ->whereIn('s.distributor_code', $codes)
            ->where('c.is_active', true)
            ->whereNull('c.deleted_at')
            ->where(fn ($w) => $w->whereNull('c.is_sellable')->orWhere('c.is_sellable', true))
            ->select('s.distributor_code', 's.distributor_catalog_id', 's.avail', 's.rate_per_day', 's.days_left', 's.next_arrival_on',
                's.last_restock_at', 's.first_seen_at', 's.last_reading_at', 'c.product_key', 'c.display_name', 'c.name', 'c.manufacturer',
                'c.category', 'c.category_path', 'c.images', 'c.msrp_cents', 'c.map_cents', 'c.manufacturer_sku', 'c.distributor_variant_no', 'c.created_at as c_created');

        // brands switched off for this shop (or by the platform) stay out, per distributor
        $q->where(function ($w) use ($codes, $t) {
            foreach ($codes as $code) {
                $w->orWhere(function ($x) use ($code, $t) {
                    $x->where('s.distributor_code', $code);
                    $sub = DB::table('platform_distributor_catalogs')->select('id')->where('distributor_code', $code);
                    app(\App\Services\Distributors\BrandControl::class)->constrain($sub, $code, (string) $t->id);
                    $x->whereIn('c.id', $sub);
                });
            }
        });

        $goodSince = now()->subDays(\App\Services\Distributors\StockVelocityRecorder::GOOD_SPAN_DAYS);
        $good = fn ($w) => $set['good'] ? $w->where('s.first_seen_at', '<=', $goodSince) : $w;
        switch ($list) {
            case 'low':
                $good($q)->where('s.avail', '>', 0)->where('s.rate_per_day', '>', 0)->whereNotNull('s.days_left')->orderBy('s.days_left');
                break;
            case 'back':
                $q->where('s.avail', '>', 0)->where('s.last_restock_at', '>=', now()->subDays($set['back_days']))->orderByDesc('s.last_restock_at');
                break;
            case 'new':
                // the first catalog load is not "new": only rows that arrived after a distributor's first week
                $q->where('c.created_at', '>=', now()->subDays($set['new_days']))
                  ->whereRaw('c.created_at > (SELECT DATE_ADD(MIN(c0.created_at), INTERVAL 7 DAY) FROM platform_distributor_catalogs c0 WHERE c0.distributor_code = c.distributor_code)')
                  ->orderByDesc('c.created_at');
                break;
            default:
                $good($q)->where('s.rate_per_day', '>', 0)->orderByDesc('s.rate_per_day');
        }
        $rows = $q->limit(self::POOL)->get();
        if ($rows->isEmpty()) return [];

        // the other vendors' rows for the same products, so every product shows each vendor
        $keys = $rows->pluck('product_key')->filter()->unique()->values();
        if ($keys->isNotEmpty()) {
            $more = DB::table('distributor_stock_summary as s')
                ->join('platform_distributor_catalogs as c', 'c.id', '=', 's.distributor_catalog_id')
                ->whereIn('s.distributor_code', $codes)->whereIn('c.product_key', $keys)->where('c.is_active', true)
                ->select('s.distributor_code', 's.distributor_catalog_id', 's.avail', 's.rate_per_day', 's.days_left', 's.next_arrival_on',
                    's.last_restock_at', 's.first_seen_at', 's.last_reading_at', 'c.product_key', 'c.display_name', 'c.name', 'c.manufacturer',
                    'c.category', 'c.category_path', 'c.images', 'c.msrp_cents', 'c.map_cents', 'c.manufacturer_sku', 'c.distributor_variant_no', 'c.created_at as c_created')
                ->get();
            $rows = $rows->concat($more)->unique(fn ($r) => $r->distributor_code . '|' . $r->distributor_catalog_id)->values();
        }

        $now = now();
        $out = [];
        foreach ($rows as $r) {
            $key = $r->product_key ?: ('c:' . $r->distributor_catalog_id);
            $code = strtoupper($r->distributor_code);
            $wk = $r->rate_per_day !== null ? round((float) $r->rate_per_day * 7, 1) : 0.0;
            $left = $r->days_left !== null ? (float) $r->days_left : null;
            $back = $r->last_restock_at ? (int) Carbon::parse($r->last_restock_at)->diffInDays($now) : null;
            $seen = $r->c_created ? (int) Carbon::parse($r->c_created)->diffInDays($now) : null;
            if (! isset($out[$key])) {
                $img = CatalogImages::urls($r->images, $code, (string) $t->id, 1)[0] ?? null;
                $path = trim((string) ($r->category_path ?: $r->category));
                $cat = implode(' › ', array_slice(array_values(array_filter(array_map('trim', preg_split('/\s*[>›\/|]\s*/', $path)))), 0, 3));
                $out[$key] = [
                    'key' => $key, 'name' => \App\Services\Distributors\CatalogTitleComposer::collapseRepeats((string) ($r->display_name ?: $r->name)),
                    'brand' => (string) $r->manufacturer, 'cat' => $cat !== '' ? $cat : 'Other', 'img' => $img,
                    'pn' => (string) ($r->manufacturer_sku ?: $r->distributor_variant_no), 'msrp' => $r->msrp_cents !== null ? (int) $r->msrp_cents : null,
                    'map' => $r->map_cents !== null ? (int) $r->map_cents : null, 'code0' => $code,
                    'wk' => 0.0, 'left' => null, 'back' => null, 'seen' => $seen, 'v' => [], 'cids' => [],
                ];
                $out[$key]['top'] = explode(' › ', $out[$key]['cat'])[0];
            }
            $p = &$out[$key];
            $p['cids'][] = (string) $r->distributor_catalog_id;
            $p['wk'] += $wk;
            if ($left !== null && ($p['left'] === null || $left < $p['left'])) $p['left'] = $left;
            if ($back !== null && ($p['back'] === null || $back < $p['back'])) $p['back'] = $back;
            if ($seen !== null && ($p['seen'] === null || $seen < $p['seen'])) $p['seen'] = $seen;
            $p['v'][$code] = [
                'wk' => $wk, 'left' => $left !== null ? round($left) : null, 'avail' => (int) $r->avail,
                'arrival' => $r->next_arrival_on ? Carbon::parse($r->next_arrival_on)->format('M j') : null, 'back' => $back,
            ];
            unset($p);
        }

        // what the shop carries: an item linked to any of the product's catalog rows
        $cids = collect($out)->pluck('cids')->flatten()->unique()->values()->all();
        $byCat = [];
        if ($cids) {
            $direct = TenantInventoryItem::where('tenant_id', $t->id)->where('is_active', true)->whereIn('distributor_catalog_id', $cids)
                ->get(['id', 'distributor_catalog_id']);
            foreach ($direct as $i) $byCat[(string) $i->distributor_catalog_id] = (string) $i->id;
            $via = DB::table('tenant_inventory_item_vendors as v')->join('tenant_inventory_items as i', 'i.id', '=', 'v.inventory_item_id')
                ->where('i.tenant_id', $t->id)->where('i.is_active', true)->whereNull('i.deleted_at')->whereIn('v.distributor_catalog_id', $cids)
                ->pluck('v.inventory_item_id', 'v.distributor_catalog_id');
            foreach ($via as $cid => $iid) $byCat[(string) $cid] ??= (string) $iid;
        }
        $itemIds = array_values(array_unique($byCat));
        $items = $itemIds ? TenantInventoryItem::whereIn('id', $itemIds)->get()->keyBy(fn ($i) => (string) $i->id) : collect();

        foreach ($out as &$p) {
            $iid = null;
            foreach ($p['cids'] as $cid) { if (isset($byCat[$cid])) { $iid = $byCat[$cid]; break; } }
            $item = $iid ? ($items[$iid] ?? null) : null;
            if ($item) {
                $p['you'] = ['id' => (string) $item->id, 'st' => max(0, (int) $item->computed_stock_count), 'online' => (bool) $item->show_online,
                             'pr' => $item->effectiveSellPriceCents(), 'url' => $item->shopPath()];
                $p['price'] = $p['you']['pr'];
            } else {
                $p['you'] = null;
                $p['price'] = PriceSeed::for((string) $t->id, $p['code0'], $p['msrp'], $p['map']);
            }
            $p['wk'] = round($p['wk'], 1);
            $p['left'] = $p['left'] !== null ? round($p['left']) : null;
            ksort($p['v']);
        }
        unset($p);
        return array_values($out);
    }

    /** True when a product passes the section's filters (the browser popup mirrors this). */
    public static function matches(array $cfg, array $p, array $set): bool
    {
        $you = $p['you'];
        if ($cfg['carry'] === 'stock' && ! $you) return false;
        if ($cfg['carry'] === 'in' && (! $you || $you['st'] <= 0)) return false;
        if ($cfg['carry'] === 'none' && $you) return false;
        if ($cfg['vendors'] && ! array_intersect(array_keys($p['v']), $cfg['vendors'])) return false;
        if ($cfg['cats']) {
            $ok = false;
            foreach ($cfg['cats'] as $c) { if ($p['cat'] === $c || $p['top'] === $c || str_starts_with($p['cat'], $c . ' › ')) { $ok = true; break; } }
            if (! $ok) return false;
        }
        if ($cfg['brands'] && ! in_array($p['brand'], $cfg['brands'], true)) return false;
        $price = $p['price'] !== null ? $p['price'] / 100 : null;
        if ($cfg['min'] !== null && ($price === null || $price < $cfg['min'])) return false;
        if ($cfg['max'] !== null && ($price === null || $price > $cfg['max'])) return false;
        if ($cfg['special'] === 'hide' && ! $you) return false;
        if ($you && ! $you['online']) return false; // the shop keeps it off its online store
        if ($price === null) return false; // nothing to sell it for
        return true;
    }

    public static function order(array $list, string $by): array
    {
        usort($list, match ($by) {
            'low'  => fn ($a, $b) => ($a['left'] ?? 1e9) <=> ($b['left'] ?? 1e9),
            'back' => fn ($a, $b) => ($a['back'] ?? 1e9) <=> ($b['back'] ?? 1e9),
            'new'  => fn ($a, $b) => ($a['seen'] ?? 1e9) <=> ($b['seen'] ?? 1e9),
            default => fn ($a, $b) => $b['wk'] <=> $a['wk'],
        });
        return $list;
    }

    /** The products a section shows, in order. Hand-picked keys keep their order. */
    public static function pick(array $cfg, array $pool, array $set): array
    {
        $by = collect($pool)->keyBy('key');
        if ($cfg['mode'] === 'hand') {
            return collect($cfg['picked'])->map(fn ($k) => $by[$k] ?? null)->filter()
                ->filter(fn ($p) => $cfg['special'] !== 'hide' || $p['you'])->filter(fn ($p) => $p['price'] !== null && (! $p['you'] || $p['you']['online']))
                ->take($cfg['count'])->values()->all();
        }
        $pins = collect($cfg['picked'])->map(fn ($k) => $by[$k] ?? null)->filter()->filter(fn ($p) => self::matches($cfg, $p, $set))->values()->all();
        $pinKeys = array_column($pins, 'key');
        $rest = self::order(array_values(array_filter($pool, fn ($p) => ! in_array($p['key'], $pinKeys, true) && self::matches($cfg, $p, $set))), $cfg['list']);
        return array_slice(array_merge($pins, $rest), 0, $cfg['count']);
    }

    /**
     * What a saved section shows shoppers, with each product backed by an inventory item they
     * can open. Kept for a few minutes per section and settings.
     *
     * @return array<int, array{p: array, item: TenantInventoryItem}>
     */
    public static function shown(Tenant $t, array $content): array
    {
        $cfg = self::config($content);
        $set = self::settings();
        $ckey = 'mf:' . $t->id . ':' . md5(json_encode($cfg) . json_encode($set));
        $keys = Cache::remember($ckey, self::SHOWN_TTL, function () use ($t, $cfg, $set) {
            $pool = self::pool($t, $cfg['list']);
            // hand-picked items can come from any list; add the ones this list didn't bring
            if ($cfg['picked']) {
                $have = array_column($pool, 'key');
                foreach (array_keys(self::LISTS) as $l) {
                    if ($l === $cfg['list'] || ! array_diff($cfg['picked'], $have)) continue;
                    foreach (self::pool($t, $l) as $p) { if (! in_array($p['key'], $have, true)) { $pool[] = $p; $have[] = $p['key']; } }
                }
            }
            $list = self::pick($cfg, $pool, $set);
            self::ensure($t, $list);
            return array_map(fn ($p) => ['p' => $p, 'id' => $p['you']['id'] ?? null], $list);
        });
        $ids = array_filter(array_column($keys, 'id'));
        $items = $ids ? TenantInventoryItem::whereIn('id', $ids)->where('is_active', true)->with(['distributorCatalog', 'itemImages.media'])->get()->keyBy(fn ($i) => (string) $i->id) : collect();
        $out = [];
        foreach ($keys as $k) {
            $item = $k['id'] ? ($items[$k['id']] ?? null) : null;
            if ($item && $item->show_online) $out[] = ['p' => $k['p'], 'item' => $item];
        }
        return $out;
    }

    /**
     * Products the shop doesn't carry become catalog items in its inventory (not stock-tracked,
     * online), the same way a catalog import adds them, so shoppers can open and order them.
     * Fills each product's 'you' with the item.
     */
    public static function ensure(Tenant $t, array &$list): void
    {
        $need = [];
        foreach ($list as $i => $p) {
            if (! $p['you']) $need[$p['code0']][] = $p['cids'][0];
        }
        if (! $need) return;
        $svc = app(\App\Services\Distributors\DistributorCatalogImportService::class);
        $start = now()->subSecond();
        foreach ($need as $code => $ids) {
            try {
                $svc->import((string) $t->id, $code, ['ids' => $ids]);
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $all = collect($list)->filter(fn ($p) => ! $p['you'])->pluck('cids')->flatten()->unique()->all();
        // items made just now exist for this section, so they go online; an item the shop already
        // had (a catalog match) keeps the shop's own choice
        TenantInventoryItem::where('tenant_id', $t->id)->whereIn('distributor_catalog_id', $all)->where('created_at', '>=', $start)
            ->where('show_online', false)->update(['show_online' => true]);
        $made = TenantInventoryItem::where('tenant_id', $t->id)->whereIn('distributor_catalog_id', $all)->get()->keyBy(fn ($i) => (string) $i->distributor_catalog_id);
        $via = DB::table('tenant_inventory_item_vendors as v')->join('tenant_inventory_items as i', 'i.id', '=', 'v.inventory_item_id')
            ->where('i.tenant_id', $t->id)->whereIn('v.distributor_catalog_id', $all)->pluck('v.inventory_item_id', 'v.distributor_catalog_id');
        foreach ($list as &$p) {
            if ($p['you']) continue;
            foreach ($p['cids'] as $cid) {
                $item = $made[$cid] ?? (isset($via[$cid]) ? TenantInventoryItem::find($via[$cid]) : null);
                if ($item) {
                    $p['you'] = ['id' => (string) $item->id, 'st' => max(0, (int) $item->computed_stock_count), 'online' => (bool) $item->show_online,
                                 'pr' => $item->effectiveSellPriceCents(), 'url' => $item->shopPath(), 'added' => true];
                    break;
                }
            }
        }
        unset($p);
    }

    /** Weekly units leaving each vendor for one product, last 8 weeks, from the stock change log. */
    public static function weeks(array $cids, array $codes): array
    {
        if (! $cids) return [];
        $rows = DB::table('distributor_stock_summary')->whereIn('distributor_catalog_id', $cids)->whereIn('distributor_code', $codes)
            ->get(['distributor_code', 'distributor_variant_no']);
        $w = array_fill(0, 8, 0);
        $since = now()->subWeeks(8);
        foreach ($rows as $r) {
            $ch = DB::table('distributor_stock_changes')->where('distributor_code', $r->distributor_code)->where('distributor_variant_no', $r->distributor_variant_no)
                ->where('recorded_at', '>=', $since)->orderBy('recorded_at')->get(['avail', 'prev_avail', 'recorded_at']);
            foreach ($ch as $c) {
                $drop = max(0, (int) $c->prev_avail - (int) $c->avail);
                if (! $drop) continue;
                $idx = 7 - min(7, (int) floor(Carbon::parse($c->recorded_at)->diffInDays(now()) / 7));
                $w[$idx] += $drop;
            }
        }
        return $w;
    }

    /** Shopper badges for one product. */
    public static function badges(array $p, array $cfg, array $set, ?TenantInventoryItem $item): array
    {
        $b = [];
        if ($cfg['badges']) {
            if ($cfg['list'] === 'back' && $p['back'] !== null && $p['back'] <= $set['back_days']) $b[] = ['Back in stock', ''];
            elseif ($cfg['list'] === 'new' && $p['seen'] !== null && $p['seen'] <= $set['new_days']) $b[] = ['New', ''];
            elseif ($p['wk'] >= $set['fast_week']) $b[] = ['Selling fast', ''];
            $onShelf = $item && (int) $item->computed_stock_count > 0;
            if (! $onShelf && $p['left'] !== null && $p['left'] <= $set['few_left']) $b[] = ['Few left', ''];
        }
        if ($item && (int) $item->computed_stock_count <= 0) $b[] = ['Special order', 'so'];
        return $b;
    }
}
