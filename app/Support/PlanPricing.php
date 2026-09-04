<?php

namespace App\Support;

use App\Models\PlanPrice;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-PLAN-PRICING — what each plan costs, from the database.
 *
 * The current price for a tier is the newest row whose effective_from is not
 * in the future. A price scheduled for next month sits in the table and is
 * ignored until its date arrives, which is what makes "set it and forget it"
 * safe rather than a diary entry.
 *
 * Falls back to config when the table is missing or empty, so a fresh install
 * and the moment before the migration both behave.
 */
class PlanPricing
{
    private static ?array $memo = null;

    /** @return array<string,int> tier => cents */
    public static function all(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        $fallback = array_map('intval', (array) config('intake.plan_prices', []));

        if (! Schema::hasTable('plan_prices')) {
            return self::$memo = $fallback;
        }

        $rows = PlanPrice::query()
            ->whereDate('effective_from', '<=', now()->toDateString())
            ->orderBy('tier')
            ->orderBy('effective_from')
            ->get();

        if ($rows->isEmpty()) {
            return self::$memo = $fallback;
        }

        // Later rows overwrite earlier ones, so the newest applicable wins.
        $out = $fallback;
        foreach ($rows as $row) {
            $out[$row->tier] = (int) $row->price_cents;
        }

        return self::$memo = $out;
    }

    public static function for(string $tier): int
    {
        return (int) (self::all()[$tier] ?? 0);
    }

    /** Prices dated ahead of today, for the editor to show as pending. */
    public static function scheduled()
    {
        if (! Schema::hasTable('plan_prices')) {
            return collect();
        }

        return PlanPrice::query()
            ->whereDate('effective_from', '>', now()->toDateString())
            ->orderBy('effective_from')
            ->get();
    }

    public static function forget(): void
    {
        self::$memo = null;
    }
}
