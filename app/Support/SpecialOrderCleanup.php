<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * MARKER-SO-ORPHANS — how long an orphaned special order is kept.
 *
 * An orphan is one whose sale, work order or line has gone. It cannot be
 * fulfilled and nobody is waiting for it, but it is not deleted on sight:
 * a person may want to see what happened, and one that was already ordered
 * from a vendor may have money attached.
 *
 * 0 means never sweep.
 */
class SpecialOrderCleanup
{
    public const DEFAULT_DAYS = 7;

    public static function days(Tenant $tenant): int
    {
        $v = $tenant->settings['special_orders']['orphan_cleanup_days'] ?? self::DEFAULT_DAYS;

        return max(0, (int) $v);
    }

    public static function setDays(Tenant $tenant, int $days): void
    {
        $settings = $tenant->settings ?? [];
        $settings['special_orders'] = array_merge(
            (array) ($settings['special_orders'] ?? []),
            ['orphan_cleanup_days' => max(0, min(365, $days))]
        );
        $tenant->update(['settings' => $settings]);
    }

    public static function describe(Tenant $tenant): string
    {
        $d = self::days($tenant);

        return $d === 0
            ? 'Never — orphans stay until someone clears them'
            : 'After ' . $d . ' ' . ($d === 1 ? 'day' : 'days');
    }
}
