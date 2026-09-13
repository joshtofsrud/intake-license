<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * MARKER-HOLD — how long an unnamed recovered cart is kept.
 *
 * Held carts are never swept: someone said they wanted them. 0 means never
 * sweep anything.
 */
class DraftCleanup
{
    public const DEFAULT_DAYS = 1;

    public static function days(Tenant $tenant): int
    {
        return max(0, (int) ($tenant->settings['register']['recovered_cleanup_days'] ?? self::DEFAULT_DAYS));
    }

    public static function setDays(Tenant $tenant, int $days): void
    {
        $settings = $tenant->settings ?? [];
        $settings['register'] = array_merge(
            (array) ($settings['register'] ?? []),
            ['recovered_cleanup_days' => max(0, min(90, $days))]
        );
        $tenant->update(['settings' => $settings]);
    }

    public static function describe(Tenant $tenant): string
    {
        $d = self::days($tenant);

        return $d === 0
            ? 'Never — recovered carts stay until someone clears them'
            : 'After ' . $d . ' ' . ($d === 1 ? 'day' : 'days');
    }
}
