<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * MARKER-SUPPLY: which supplier warehouses count toward a shop's stock number
 * for each distributor. Stored on the tenant's settings as
 * preferred_warehouses = { "QBP": ["MN", …] }; a distributor with nothing
 * listed counts every warehouse. Set from the register's stars and from the
 * Warehouses list in each distributor box, so both always agree.
 */
class PreferredWarehouses
{
    /** @return array<string,string[]> */
    public static function all(Tenant $tenant): array
    {
        return array_map(fn ($l) => array_values(array_map('strval', (array) $l)),
            (array) (($tenant->settings ?? [])['preferred_warehouses'] ?? []));
    }

    /** @return string[] starred codes for one distributor (empty = all count) */
    public static function for(Tenant $tenant, string $code): array
    {
        return self::all($tenant)[strtoupper($code)] ?? [];
    }

    /** The register's star: add or remove one warehouse from the list. */
    public static function star(Tenant $tenant, string $code, string $wh, bool $on): array
    {
        $list = array_values(array_filter(self::for($tenant, $code), fn ($w) => $w !== $wh));
        if ($on) { $list[] = $wh; }
        return self::save($tenant, $code, $list);
    }

    /**
     * The box's "Count in my stock" switch. Unlike a star it starts from
     * "all count": switching one off lists every other warehouse, and when
     * every warehouse is on again the list is cleared back to "all".
     * Switching off the last counted warehouse is refused (null).
     *
     * @param string[] $allCodes every warehouse the box lists for this distributor
     */
    public static function count(Tenant $tenant, string $code, string $wh, bool $on, array $allCodes): ?array
    {
        $allCodes = array_values(array_unique(array_map('strval', $allCodes)));
        $current = self::for($tenant, $code) ?: $allCodes;
        $list = array_values(array_filter($current, fn ($w) => $w !== $wh));
        if ($on) { $list[] = $wh; }
        if (! $list) return null;
        if (! array_diff($allCodes, $list)) { $list = []; }
        return self::save($tenant, $code, $list);
    }

    private static function save(Tenant $tenant, string $code, array $list): array
    {
        $code = strtoupper($code);
        $settings = (array) ($tenant->settings ?? []);
        $prefs = (array) ($settings['preferred_warehouses'] ?? []);
        if ($list) { $prefs[$code] = array_values(array_unique($list)); } else { unset($prefs[$code]); }
        $settings['preferred_warehouses'] = $prefs;
        $tenant->update(['settings' => $settings]);
        return $prefs;
    }
}
