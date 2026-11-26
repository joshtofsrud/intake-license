<?php

namespace App\Support\Rentals;

use App\Models\Tenant\TenantRentalUnit;
use Carbon\Carbon;
use RuntimeException;

/**
 * MARKER-RENTAL-PRICING
 *
 * One place that turns a unit + mode + window into money, used by the staff
 * desk, the public reserve flow and the extension offers.
 *
 * Daily is tiered: the first day at the daily rate, every day after at the
 * "each additional day" rate when the model has one (falls back to daily).
 * Hourly is ceil(hours) x rate. Weekend and seasonal are flat.
 *
 * Online renters can't pick a mode, so online() prices every mode the model
 * offers that fits the window and takes the cheapest — a Saturday pickup
 * returned Sunday pays the weekend rate if the shop set one, a 3-hour ride
 * pays hourly if the shop set one.
 */
class RentalPricing
{
    /** @return array{0:string,1:int,2:int,3:int} [mode, rate_cents, duration_units, total_cents] */
    public static function price(TenantRentalUnit $unit, string $mode, Carbon $start, Carbon $due): array
    {
        $rate = match ($mode) {
            'hourly'   => $unit->effectiveHourlyCents(),
            'daily'    => $unit->effectiveDailyCents(),
            'weekend'  => $unit->effectiveWeekendCents(),
            'seasonal' => $unit->effectiveSeasonalCents(),
            default    => null,
        };
        if ($rate === null) {
            throw new RuntimeException("{$unit->name} has no {$mode} rate configured.");
        }

        $minutes = $start->diffInMinutes($due);
        $units = match ($mode) {
            'hourly' => max(1, (int) ceil($minutes / 60)),
            'daily'  => max(1, (int) ceil($minutes / 1440)),
            default  => 1,
        };

        $total = $mode === 'daily'
            ? self::dailyTotal((int) $rate, $unit->effectiveAdditionalDayCents(), $units)
            : (int) $rate * $units;

        return [$mode, (int) $rate, $units, $total];
    }

    public static function dailyTotal(int $firstDay, ?int $additional, int $days): int
    {
        $days = max(1, $days);
        return $firstDay + ($days - 1) * ($additional ?? $firstDay);
    }

    /** Cheapest mode that fits the window, for renters booking themselves. */
    public static function online(TenantRentalUnit $unit, Carbon $start, Carbon $due, string $tz): array
    {
        $minutes = $start->diffInMinutes($due);
        $candidates = [];

        if ($unit->effectiveDailyCents()) {
            $candidates[] = self::price($unit, 'daily', $start, $due);
        }
        if ($unit->effectiveHourlyCents() && $minutes <= 1440) {
            $candidates[] = self::price($unit, 'hourly', $start, $due);
        }
        if ($unit->effectiveWeekendCents() && self::fitsWeekend($start, $due, $tz)) {
            $candidates[] = self::price($unit, 'weekend', $start, $due);
        }

        if (! $candidates) {
            throw new RuntimeException('This model has no rate configured for online booking.');
        }

        usort($candidates, fn ($a, $b) => $a[3] <=> $b[3]);
        return $candidates[0];
    }

    /** Pickup Friday or Saturday, back by the end of the following Monday. */
    public static function fitsWeekend(Carbon $start, Carbon $due, string $tz): bool
    {
        $s = $start->copy()->setTimezone($tz);
        $d = $due->copy()->setTimezone($tz);
        if (! in_array($s->dayOfWeek, [Carbon::FRIDAY, Carbon::SATURDAY], true)) {
            return false;
        }
        $mondayEnd = $s->copy()->next(Carbon::MONDAY)->endOfDay();
        return $d->lessThanOrEqualTo($mondayEnd);
    }

    /** "1st day $120 + 2 × $100" when tiered, else "3 days × $120". */
    public static function lineLabel(?string $mode, int $rate, int $units, int $total, callable $money): string
    {
        $mode = $mode ?: 'daily';
        if ($mode === 'daily' && $units > 1 && $total !== $rate * $units) {
            $extra = intdiv($total - $rate, $units - 1);
            return '1st day ' . $money($rate) . ' + ' . ($units - 1) . ' × ' . $money($extra);
        }
        $word = ['hourly' => 'hr', 'daily' => 'day', 'weekend' => 'weekend', 'seasonal' => 'season', 'flat' => ''][$mode] ?? $mode;
        if ($word === '') return ($units > 1 ? $units . ' × ' : '') . $money($rate);
        return $units . ' ' . \Illuminate\Support\Str::plural($word, $units) . ' × ' . $money($rate);
    }
}
