<?php

namespace App\Support;

use App\Models\Addon;
use App\Models\AddonPrice;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-ADDON-CATALOG — what each add-on costs today.
 *
 * The newest row not in the future wins, so a price set for next month waits
 * its turn. Falls back to the addons table's own price_cents when no dated row
 * exists, which keeps a fresh install and the moment before the migration both
 * working.
 */
class AddonPricing
{
    private static ?array $memo = null;

    /** @return array<string,int> addon code => cents */
    public static function all(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        $fallback = Addon::query()->pluck('price_cents', 'code')
            ->map(fn ($c) => (int) $c)->all();

        if (! Schema::hasTable('addon_prices')) {
            return self::$memo = $fallback;
        }

        $rows = AddonPrice::query()
            ->whereDate('effective_from', '<=', now()->toDateString())
            ->orderBy('addon_code')->orderBy('effective_from')
            ->get();

        $out = $fallback;
        foreach ($rows as $row) {
            $out[$row->addon_code] = (int) $row->price_cents;   // later wins
        }

        return self::$memo = $out;
    }

    public static function for(string $code): int
    {
        return (int) (self::all()[$code] ?? 0);
    }

    public static function scheduled()
    {
        if (! Schema::hasTable('addon_prices')) {
            return collect();
        }

        return AddonPrice::query()
            ->whereDate('effective_from', '>', now()->toDateString())
            ->orderBy('effective_from')->get();
    }

    public static function forget(): void
    {
        self::$memo = null;
    }
}
