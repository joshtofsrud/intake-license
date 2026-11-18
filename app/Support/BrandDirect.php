<?php

namespace App\Support;

use App\Models\PlatformDistributorConnection;
use App\Services\Distributors\DistributorRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-BRAND-DIRECT
 *
 * Parts distributors are shared by every shop. A brand that sells direct to
 * its dealers (Kona) is not: only that brand's dealers may see it. A
 * distributor marked brand-direct in master admin is OFF for every shop until
 * master admin switches that shop on (Brand suppliers page). Switching a shop
 * off later hides the box and new imports; items the shop already has stay.
 */
class BrandDirect
{
    /** @return string[] codes marked brand-direct */
    public static function codes(): array
    {
        return Cache::remember('brand_direct.codes', 300, function () {
            try {
                if (! Schema::hasColumn('platform_distributor_connections', 'brand_direct')) {
                    return [];
                }
                return PlatformDistributorConnection::query()->where('brand_direct', true)
                    ->pluck('distributor_code')->map(fn ($c) => strtoupper((string) $c))->all();
            } catch (\Throwable) {
                return [];
            }
        });
    }

    public static function is(string $code): bool
    {
        return in_array(strtoupper($code), self::codes(), true);
    }

    /** May this shop use this distributor at all? */
    public static function allows(string $tenantId, string $code): bool
    {
        $code = strtoupper($code);
        if (! self::is($code)) {
            return true;
        }
        if (! self::shopSideReady($code)) {
            return false;
        }
        return in_array($code, self::enabledFor($tenantId), true);
    }

    /** Supported codes this shop may see, in registry order. */
    public static function visibleCodes(string $tenantId): array
    {
        return array_values(array_filter(
            app(DistributorRegistry::class)->supported(),
            fn ($c) => self::allows($tenantId, $c)
        ));
    }

    /** @return string[] brand-direct codes switched on for this shop */
    public static function enabledFor(string $tenantId): array
    {
        try {
            return DB::table('tenant_distributor_access')->where('tenant_id', $tenantId)
                ->pluck('distributor_code')->map(fn ($c) => strtoupper((string) $c))->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<string,string> tenant id => switched on at */
    public static function shopsFor(string $code): array
    {
        try {
            return DB::table('tenant_distributor_access')->where('distributor_code', strtoupper($code))
                ->pluck('created_at', 'tenant_id')->map(fn ($v) => (string) $v)->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public static function set(string $tenantId, string $code, bool $on, ?string $by = null): void
    {
        $code = strtoupper($code);
        if ($on) {
            $exists = DB::table('tenant_distributor_access')
                ->where('tenant_id', $tenantId)->where('distributor_code', $code)->exists();
            if (! $exists) {
                DB::table('tenant_distributor_access')->insert([
                    'id' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => $tenantId,
                    'distributor_code' => $code, 'enabled_by' => $by,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        } else {
            DB::table('tenant_distributor_access')
                ->where('tenant_id', $tenantId)->where('distributor_code', $code)->delete();
        }
    }

    public static function forget(): void
    {
        Cache::forget('brand_direct.codes');
    }

    /**
     * Whether shops can use this source yet. An adapter that declares
     * shopSideReady() false (Kona, until its stock-file box ships) stays
     * hidden even for shops switched on.
     */
    public static function shopSideReady(string $code): bool
    {
        $class = app(DistributorRegistry::class)->adapterClass($code);
        return ! ($class && method_exists($class, 'shopSideReady')) || $class::shopSideReady();
    }
}
