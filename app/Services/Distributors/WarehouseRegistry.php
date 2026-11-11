<?php

namespace App\Services\Distributors;

use App\Models\DistributionWarehouse;
use App\Models\PlatformSettings;
use App\Models\Tenant;
use App\Services\Tenant\ItemSourcing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * MARKER-DISTRIBUTION-WAREHOUSES
 *
 * Supplier warehouses: found in the feeds, shown or hidden and enabled for dropship by
 * master admin, chosen and ordered by each shop. Also answers the two questions the
 * shop side asks: "what does this shop see?" and "where would this item come from?".
 *
 * Three levels, each only able to narrow the one above it:
 *   1. platform feature switch   off = no shop sees any of it, every order uses the shop's own stock
 *   2. warehouse flags           shown_to_shops (counts visible) / dropship_enabled (may ship from it)
 *   3. the shop                  its override (follow / allow / block), the warehouses it ticked, its own rules
 */
class WarehouseRegistry
{
    public const DEFAULTS = ['feature_on' => false, 'shop_default' => false, 'stale_hours' => 6];

    public static function ready(): bool
    {
        try { return Schema::hasTable('platform_distribution_warehouses'); } catch (\Throwable) { return false; }
    }

    /* ---------------- platform settings ---------------- */

    public static function settings(): array
    {
        $s = (array) (PlatformSettings::current()->distribution_settings ?? []);
        return [
            'feature_on'   => (bool) ($s['feature_on'] ?? self::DEFAULTS['feature_on']),
            'shop_default' => (bool) ($s['shop_default'] ?? self::DEFAULTS['shop_default']),
            'stale_hours'  => max(1, min(168, (int) ($s['stale_hours'] ?? self::DEFAULTS['stale_hours']))),
        ];
    }

    public static function saveSettings(array $in): void
    {
        $ps = PlatformSettings::current();
        $ps->distribution_settings = array_merge((array) ($ps->distribution_settings ?? []), [
            'feature_on'   => (bool) ($in['feature_on'] ?? false),
            'shop_default' => (bool) ($in['shop_default'] ?? false),
            'stale_hours'  => max(1, min(168, (int) ($in['stale_hours'] ?? self::DEFAULTS['stale_hours']))),
        ]);
        $ps->save();
        PlatformSettings::forget();
    }

    /* ---------------- discovery ---------------- */

    /**
     * Record warehouses seen in a feed. New ones start hidden and off.
     * @param array<int,array{c:string,n?:string}> $seen
     */
    public static function record(string $distributorCode, array $seen): int
    {
        if (! self::ready()) return 0;
        $distributorCode = strtoupper(trim($distributorCode));
        $made = 0;
        foreach ($seen as $w) {
            $code = trim((string) ($w['c'] ?? ''));
            if ($distributorCode === '' || $code === '') continue;
            $name = trim((string) ($w['n'] ?? '')) ?: $code;
            $row  = DistributionWarehouse::query()->where('distributor_code', $distributorCode)->where('code', $code)->first();
            if (! $row) {
                DistributionWarehouse::query()->create([
                    'distributor_code' => $distributorCode, 'code' => $code,
                    'name' => $distributorCode . ' — ' . $name, 'first_seen_at' => now(), 'last_seen_at' => now(),
                ]);
                $made++;
            } else {
                $row->forceFill(['last_seen_at' => now()])->saveQuietly();
            }
        }
        return $made;
    }

    /** Read every stored supplier snapshot and register the warehouses in it. Returns how many are new. */
    public static function scan(): int
    {
        if (! self::ready()) return 0;
        $found = [];
        DB::table('tenant_inventory_item_vendors')
            ->whereNotNull('live_warehouses')->whereNotNull('distributor_code')
            ->orderBy('id')
            ->select('id', 'distributor_code', 'live_warehouses')
            ->chunk(2000, function ($rows) use (&$found) {
                foreach ($rows as $r) {
                    foreach ((array) json_decode((string) $r->live_warehouses, true) as $w) {
                        $c = trim((string) ($w['c'] ?? ''));
                        if ($c !== '') $found[strtoupper($r->distributor_code)][$c] = ['c' => $c, 'n' => (string) ($w['n'] ?? $c)];
                    }
                }
            });
        $made = 0;
        foreach ($found as $dist => $list) {
            $made += self::record($dist, array_values($list));
        }
        return $made;
    }

    /* ---------------- what a shop sees ---------------- */

    /** hidden = nothing shown | seen = counts shown, no dropship | on = may dropship */
    public static function access(Tenant $tenant): string
    {
        $s = self::settings();
        if (! $s['feature_on'] || ! self::ready()) return 'hidden';
        $override = (string) (($tenant->settings['distribution']['override'] ?? 'follow'));
        $allowed  = match ($override) { 'allow' => true, 'block' => false, default => $s['shop_default'] };
        return $allowed ? 'on' : 'seen';
    }

    public static function shopConfig(Tenant $tenant): array
    {
        $s = (array) (($tenant->settings['storefront'] ?? []) ?: []);
        return [
            'dropship_on'  => (bool) ($s['dropship_on'] ?? false),
            'own_first'    => (bool) ($s['dropship_own_first'] ?? true),
            'order_mode'   => (($s['dropship_order_mode'] ?? 'list') === 'stock') ? 'stock' : 'list', // MARKER-WH-ORDERMODE-FIX
            'oversell_mode' => ($s['dropship_oversell_mode'] ?? 'supplier') === 'special' ? 'special' : 'supplier',
            'handling_days' => max(0, min(60, (int) ($s['dropship_handling_days'] ?? 2))),
            'label'        => trim((string) ($s['dropship_label'] ?? '')) ?: self::DEFAULT_LABEL,
        ];
    }

    public const DEFAULT_LABEL = 'Ships directly from our supplier';

    /** Warehouses a shop can see, in the shop's own order, each with the shop's choice. */
    public static function forShop(Tenant $tenant): array
    {
        if (self::access($tenant) === 'hidden') return [];
        $sel = DB::table('tenant_warehouse_selections')->where('tenant_id', $tenant->id)->get()->keyBy('warehouse_id');
        $rows = DistributionWarehouse::query()->where('shown_to_shops', true)->orderBy('distributor_code')->orderBy('name')->get();
        $stock = self::stockCounts($tenant);

        $out = $rows->map(function ($w) use ($sel, $stock) {
            $s = $sel[$w->id] ?? null;
            return [
                'id' => $w->id, 'dist' => $w->distributor_code, 'code' => $w->code, 'name' => $w->name,
                'ships_from' => trim(implode(', ', array_filter([$w->ship_from_city, $w->ship_from_state])) ?: ($w->ship_from_zip ?? '')),
                'dropship' => (bool) $w->dropship_enabled,
                'enabled' => (bool) ($s->enabled ?? false),
                'priority' => (int) ($s->priority ?? 9999),
                'in_stock' => (int) ($stock[$w->distributor_code . '|' . $w->code] ?? 0),
                'handling_days' => (int) $w->handling_days,
            ];
        })->all();

        usort($out, fn ($a, $b) => [$a['dist'], $a['priority'], $a['name']] <=> [$b['dist'], $b['priority'], $b['name']]);
        return $out;
    }

    /** How many of the shop's items each warehouse shows stock for ("dist|code" => n). Cached briefly. */
    public static function stockCounts(Tenant $tenant): array
    {
        return Cache::remember('wh_stock_counts:' . $tenant->id, 600, function () use ($tenant) {
            $out = [];
            DB::table('tenant_inventory_item_vendors as iv')
                ->join('tenant_inventory_items as i', 'i.id', '=', 'iv.inventory_item_id')
                ->where('i.tenant_id', $tenant->id)->where('i.is_active', true)
                ->whereNotNull('iv.live_warehouses')->whereNotNull('iv.distributor_code')
                ->select('iv.id', 'iv.distributor_code', 'iv.live_warehouses')
                ->orderBy('iv.id')
                ->chunk(2000, function ($rows) use (&$out) {
                    foreach ($rows as $r) {
                        foreach ((array) json_decode((string) $r->live_warehouses, true) as $w) {
                            if ((int) ($w['q'] ?? 0) > 0) {
                                $k = strtoupper($r->distributor_code) . '|' . trim((string) ($w['c'] ?? ''));
                                $out[$k] = ($out[$k] ?? 0) + 1;
                            }
                        }
                    }
                });
            return $out;
        });
    }

    /**
     * Save the shop's choices: ticked warehouses and the order they are tried in.
     * @param array<string,mixed> $ticked warehouse id => truthy
     * @param string[]            $order  warehouse ids, top first
     */
    public static function saveShopChoices(Tenant $tenant, array $ticked, array $order): void
    {
        $valid = DistributionWarehouse::query()->where('shown_to_shops', true)->where('dropship_enabled', true)->pluck('id')->all();
        $pos = 0;
        $seen = [];
        foreach ($order as $id) {
            if (! in_array($id, $valid, true) || isset($seen[$id])) continue;
            $seen[$id] = true;
            self::putSelection($tenant, $id, ! empty($ticked[$id]), $pos++);
        }
        foreach ($valid as $id) {
            if (! isset($seen[$id])) self::putSelection($tenant, $id, ! empty($ticked[$id]), $pos++);
        }
        Cache::forget('wh_stock_counts:' . $tenant->id);
    }

    private static function putSelection(Tenant $tenant, string $wid, bool $on, int $pos): void
    {
        $row = DB::table('tenant_warehouse_selections')->where('tenant_id', $tenant->id)->where('warehouse_id', $wid)->first();
        if ($row) {
            DB::table('tenant_warehouse_selections')->where('id', $row->id)->update(['enabled' => $on, 'priority' => $pos, 'updated_at' => now()]);
        } else {
            DB::table('tenant_warehouse_selections')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'warehouse_id' => $wid,
                'enabled' => $on, 'priority' => $pos, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /* ---------------- where is it, and where would it come from ---------------- */

    /**
     * For a page of items: each item's supplier stock chips and the warehouses it may be
     * ordered from, ready for ItemSourcing.
     *
     * @param  string[] $itemIds
     * @return array<string,array{chips:array,offers:array}> itemId => ...
     */
    public static function forItems(Tenant $tenant, array $itemIds): array
    {
        $access = self::access($tenant);
        if ($access === 'hidden' || ! $itemIds) return [];

        $cfg    = self::shopConfig($tenant);
        $stale  = now()->subHours(self::settings()['stale_hours']);
        $whs    = DistributionWarehouse::query()->where('shown_to_shops', true)->get()->keyBy(fn ($w) => $w->distributor_code . '|' . $w->code);
        $sel    = DB::table('tenant_warehouse_selections')->where('tenant_id', $tenant->id)->get()->keyBy('warehouse_id');
        $usable = fn ($w) => $access === 'on' && $cfg['dropship_on'] && $w->dropship_enabled && ($sel[$w->id]->enabled ?? false);

        $out = [];
        $pivots = DB::table('tenant_inventory_item_vendors')
            ->whereIn('inventory_item_id', $itemIds)->whereNotNull('live_warehouses')->whereNotNull('distributor_code')
            ->get(['inventory_item_id', 'distributor_code', 'live_warehouses', 'live_checked_at']);

        foreach ($pivots as $p) {
            $fresh = $p->live_checked_at && \Carbon\Carbon::parse($p->live_checked_at)->gte($stale);
            foreach ((array) json_decode((string) $p->live_warehouses, true) as $w) {
                $wh = $whs[strtoupper($p->distributor_code) . '|' . trim((string) ($w['c'] ?? ''))] ?? null;
                if (! $wh) continue;
                $q = (int) ($w['q'] ?? 0);
                $use = $usable($wh);
                $out[$p->inventory_item_id]['chips'][] = [
                    'id' => $wh->id, 'name' => $wh->name, 'qty' => $fresh ? $q : null, 'on' => $use,
                ];
                if ($use && $fresh && $q > 0) {
                    $out[$p->inventory_item_id]['offers'][] = [
                        'id' => $wh->id, 'name' => $wh->name, 'qty' => $q,
                        'priority' => (int) ($sel[$wh->id]->priority ?? 9999),
                    ];
                }
            }
        }

        foreach ($out as $id => &$o) {
            $o['chips'] = $o['chips'] ?? [];
            $offers = $o['offers'] ?? [];
            usort($offers, $cfg['order_mode'] === 'stock'
                ? fn ($a, $b) => $b['qty'] <=> $a['qty']
                : fn ($a, $b) => $a['priority'] <=> $b['priority']);
            $o['offers'] = $offers;
        }
        return $out;
    }

    /** Badge for the Availability tool: where one unit would come from now. */
    public static function headline(Tenant $tenant, int $shopStock, array $offers, bool $oversell): array
    {
        $cfg = self::shopConfig($tenant);
        $plan = ItemSourcing::plan(1, $shopStock, $offers, [
            'ownFirst' => $cfg['own_first'], 'oversell' => $oversell, 'oversellMode' => $cfg['oversell_mode'],
        ]);
        return ItemSourcing::headline($plan);
    }
}
