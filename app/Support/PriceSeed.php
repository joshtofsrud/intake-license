<?php
// MARKER-PRICE-SEED

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The price a new item starts at, per shop and distributor.
 *
 * MAP is the lowest price a shop may advertise; MSRP is full retail. The
 * import used to take MAP first for everyone, so a shop's whole catalog could
 * sit at the floor. Each distributor's card now carries the choice.
 *
 * Zero is treated as no price: several feeds send MSRP "0.00" for products
 * they hold no retail price for, and seeding that made $0 items.
 */
class PriceSeed
{
    private static array $cache = [];

    public static function preference(string $tenantId, string $code): string
    {
        $key = $tenantId . ':' . strtoupper($code);
        if (! array_key_exists($key, self::$cache)) {
            self::$cache[$key] = DB::table('tenant_distributor_catalog_subscriptions')
                ->where('tenant_id', $tenantId)->where('distributor_code', strtoupper($code))
                ->value('price_seed') ?: 'map';
        }

        return self::$cache[$key];
    }

    /** The seed price itself: the preferred list price, else the other, else none. */
    public static function for(string $tenantId, string $code, $msrpCents, $mapCents): ?int
    {
        $msrp = (int) $msrpCents > 0 ? (int) $msrpCents : null;
        $map  = (int) $mapCents  > 0 ? (int) $mapCents  : null;

        return self::preference($tenantId, $code) === 'msrp'
            ? ($msrp ?? $map)
            : ($map ?? $msrp);
    }
}
