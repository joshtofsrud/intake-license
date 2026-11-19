<?php

namespace App\Services\Distributors;

use App\Models\PlatformDistributorConnection;
use App\Models\Tenant\TenantDistributorCatalogSubscription;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-SUPPLY-RUN: one distributor's cost, stock and warehouse stock per
 * part number, fetched once for every shop by the shared run. Shops copy from
 * here. Cost is the distributor's list cost for the account that fetched it; a
 * shop's own received cost always wins on its items.
 */
class DistributorSupply
{
    public const SUPPLY_TENANT = '00000000-0000-0000-0000-000000000000';

    /** Every part number any shop carries from this distributor. */
    public static function variantsFor(string $code): array
    {
        return DB::table('tenant_inventory_item_vendors as v')
            ->join('platform_distributor_catalogs as c', 'c.id', '=', 'v.distributor_catalog_id')
            ->where('v.distributor_code', strtoupper($code))
            ->whereNotNull('c.distributor_variant_no')
            ->distinct()->orderBy('c.distributor_variant_no')
            ->pluck('c.distributor_variant_no')->map(fn ($v) => (string) $v)->all();
    }

    /**
     * The login the run fetches with: the platform login set in master admin,
     * else the first connected shop's. [adapter, "where the login came from"].
     *
     * @return array{0: ?DistributorAdapter, 1: string}
     */
    public static function adapterFor(string $code): array
    {
        $code = strtoupper($code);
        $registry = app(DistributorRegistry::class);
        $conn = PlatformDistributorConnection::where('distributor_code', $code)->first();
        if ($conn && ($conn->api_key || ! $registry->needsKey($code))) {
            try {
                $a = $registry->make($code, ['api_key' => (string) $conn->api_key, 'region' => $conn->region ?? 'us']);
                if ($conn->auth_style && method_exists($a, 'setAuthStyle')) {
                    $a->setAuthStyle($conn->auth_style);
                }
                return [$a, 'Intake\'s login'];
            } catch (\Throwable) {
                // fall through to a shop's login
            }
        }
        foreach (TenantDistributorCatalogSubscription::where('distributor_code', $code)->where('is_active', true)->orderBy('created_at')->get() as $sub) {
            if ($a = $registry->forSubscription($sub)) {
                $name = DB::table('tenants')->where('id', $sub->tenant_id)->value('name');
                return [$a, ($name ?: 'a shop') . '\'s login'];
            }
        }
        return [null, ''];
    }

    /** @return array<string, array{cost: ?int, avail: ?int, warehouses: ?array}> */
    public static function read(string $code, array $variantNos): array
    {
        $out = [];
        foreach (array_chunk($variantNos, 1000) as $chunk) {
            foreach (DB::table('distributor_supply')->where('distributor_code', strtoupper($code))->whereIn('variant_no', $chunk)
                ->get(['variant_no', 'cost_cents', 'avail', 'warehouses']) as $r) {
                $out[(string) $r->variant_no] = [
                    'cost' => $r->cost_cents !== null ? (int) $r->cost_cents : null,
                    'avail' => $r->avail !== null ? (int) $r->avail : null,
                    'warehouses' => $r->warehouses ? json_decode((string) $r->warehouses, true) : null,
                ];
            }
        }
        return $out;
    }

    /** Save what was fetched for these part numbers (a part number with nothing back is kept, empty). */
    public static function store(string $code, array $variantNos, array $got): void
    {
        $now = now();
        $rows = [];
        foreach ($variantNos as $v) {
            $rows[] = [
                'distributor_code' => strtoupper($code), 'variant_no' => (string) $v,
                'cost_cents' => $got['cost'][$v] ?? null, 'avail' => $got['avail'][$v] ?? null,
                'warehouses' => isset($got['wh'][$v]) ? json_encode($got['wh'][$v]) : null, 'fetched_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 500) as $b) {
            DB::table('distributor_supply')->upsert($b, ['distributor_code', 'variant_no'], ['cost_cents', 'avail', 'warehouses', 'fetched_at']);
        }
    }

    /** When this distributor's shared run last finished, for the shop's box. */
    public static function lastRun(string $code): ?object
    {
        return DB::table('distributor_sync_runs')->where('subscription_id', 'supply')->where('distributor_code', strtoupper($code))
            ->orderByDesc('started_at')->first();
    }
}
