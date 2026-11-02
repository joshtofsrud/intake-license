<?php

namespace App\Services\Tenant\ReportBuilder;

use App\Models\Tenant;
use Carbon\Carbon;

/**
 * Periods — date ranges and time buckets for custom reports, in the tenant's
 * own timezone. (MARKER-REPORT-BUILDER-V2)
 *
 * Bucket keys sort as strings in date order (Y-m-d, Y-m, 00-23), so rows from
 * different data sources line up on the same key.
 */
class Periods
{
    public const RANGES = ['Today', 'Last 7 days', 'Last 30 days', 'Last 90 days', 'This month', 'Last month', 'Last 12 months', 'This year', 'Custom'];
    public const TIME_DIMS = ['day', 'week', 'month', 'hour', 'pp'];

    private array $ppCache = [];

    public function __construct(private readonly Tenant $tenant) {}

    public function tz(): string
    {
        return $this->tenant->timezone();
    }

    public function today(): Carbon
    {
        return $this->tenant->localToday()->copy()->startOfDay();
    }

    /** @return array{0: Carbon, 1: Carbon} local dates, inclusive */
    public function range(string $label): array
    {
        $t = $this->today();
        return match ($label) {
            'Today'          => [$t->copy(), $t->copy()],
            'Last 7 days'    => [$t->copy()->subDays(6), $t->copy()],
            'Last 90 days'   => [$t->copy()->subDays(89), $t->copy()],
            'This month'     => [$t->copy()->startOfMonth(), $t->copy()],
            'Last month'     => [$t->copy()->subMonthNoOverflow()->startOfMonth(), $t->copy()->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'Last 12 months' => [$t->copy()->subMonthsNoOverflow(12)->addDay(), $t->copy()],
            'This year'      => [$t->copy()->startOfYear(), $t->copy()],
            'All time'       => [Carbon::create(2000, 1, 1, 0, 0, 0, $this->tz()), $t->copy()],
            default          => [$t->copy()->subDays(29), $t->copy()],
        };
    }

    /**
     * The dates a cleaned config covers. "Custom" uses the two dates picked; anything else is a named range.
     * @return array{0: Carbon, 1: Carbon}
     */
    public function rangeFor(array $cfg): array
    {
        if (($cfg['range'] ?? '') === 'Custom' && !empty($cfg['from']) && !empty($cfg['to'])) {
            try {
                return [Carbon::parse($cfg['from'], $this->tz())->startOfDay(), Carbon::parse($cfg['to'], $this->tz())->startOfDay()];
            } catch (\Throwable $e) {
                // fall through to the default range
            }
        }
        return $this->range(($cfg['range'] ?? 'Custom') === 'Custom' ? 'Last 30 days' : $cfg['range']);
    }

    /** The range to compare with: the same length just before, or the same dates a year earlier. */
    public function previous(Carbon $from, Carbon $to, string $compare): array
    {
        if ($compare === 'yoy') {
            return [$from->copy()->subYearNoOverflow(), $to->copy()->subYearNoOverflow()];
        }
        $days = $from->diffInDays($to) + 1;
        return [$from->copy()->subDays($days), $from->copy()->subDay()];
    }

    public function days(Carbon $from, Carbon $to): int
    {
        return (int) $from->diffInDays($to) + 1;
    }

    /** UTC instants bounding a local date range, for timestamp columns. */
    public function utcBounds(Carbon $from, Carbon $to): array
    {
        $a = Carbon::parse($from->toDateString() . ' 00:00:00', $this->tz())->utc();
        $b = Carbon::parse($to->toDateString() . ' 23:59:59', $this->tz())->utc();
        return [$a->toDateTimeString(), $b->toDateTimeString()];
    }

    /** A UTC "Y-m-d H" bucket to [local Y-m-d, local hour]. */
    public function localFromUtcHour(string $utcHour): array
    {
        $c = Carbon::createFromFormat('Y-m-d H', $utcHour, 'UTC')->setTimezone($this->tz());
        return [$c->toDateString(), (int) $c->format('G')];
    }

    public function key(string $dim, string $localDate, ?int $hour = null): string
    {
        return match ($dim) {
            'day'   => $localDate,
            'week'  => Carbon::parse($localDate)->startOfWeek(Carbon::MONDAY)->toDateString(),
            'month' => substr($localDate, 0, 7),
            'hour'  => sprintf('%02d', (int) $hour),
            'pp'    => $this->payPeriodEnd($localDate),
            default => $localDate,
        };
    }

    public function label(string $dim, string $key): string
    {
        return match ($dim) {
            'day'   => Carbon::parse($key)->format('M j, Y'),
            'week'  => 'Wk of ' . Carbon::parse($key)->format('M j, Y'),
            'month' => Carbon::parse($key . '-01')->format('M Y'),
            'hour'  => Carbon::createFromTime((int) $key)->format('g a'),
            'pp'    => 'Ends ' . Carbon::parse($key)->format('M j, Y'),
            default => $key,
        };
    }

    /** The dates one bucket covers, so a report can be narrowed to "just this week". */
    public function bucketRange(string $dim, string $key): ?array
    {
        try {
            return match ($dim) {
                'day'   => [Carbon::parse($key)->startOfDay(), Carbon::parse($key)->startOfDay()],
                'week'  => [Carbon::parse($key)->startOfDay(), Carbon::parse($key)->addDays(6)->startOfDay()],
                'month' => [Carbon::parse($key . '-01')->startOfDay(), Carbon::parse($key . '-01')->endOfMonth()->startOfDay()],
                'pp'    => [Carbon::parse(\App\Services\Tenant\PayPeriodService::for($this->tenant)->boundsFor(Carbon::parse($key . ' 12:00:00', $this->tz()))[0]->toDateString())->startOfDay(), Carbon::parse($key)->startOfDay()],
                default => null,
            };
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Every bucket in a range, in order, so a quiet day still shows as zero. */
    public function buckets(string $dim, Carbon $from, Carbon $to): array
    {
        if ($dim === 'hour') {
            return array_map(fn ($h) => sprintf('%02d', $h), range(0, 23));
        }
        $out = [];
        $d = $from->copy();
        $guard = 0;
        while ($d->lte($to) && $guard++ < 4000) {
            $k = $this->key($dim, $d->toDateString());
            $out[$k] = true;
            $d->addDay();
        }
        return array_keys($out);
    }

    private function payPeriodEnd(string $localDate): string
    {
        if (!isset($this->ppCache[$localDate])) {
            try {
                [, $end] = \App\Services\Tenant\PayPeriodService::for($this->tenant)
                    ->boundsFor(Carbon::parse($localDate . ' 12:00:00', $this->tz()));
                $this->ppCache[$localDate] = $end->toDateString();
            } catch (\Throwable $e) {
                $this->ppCache[$localDate] = Carbon::parse($localDate)->endOfMonth()->toDateString();
            }
        }
        return $this->ppCache[$localDate];
    }
}
