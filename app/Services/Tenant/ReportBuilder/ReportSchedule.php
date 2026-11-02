<?php

namespace App\Services\Tenant\ReportBuilder;

use App\Models\Tenant;
use App\Services\Tenant\PayPeriodService;
use Carbon\Carbon;

/**
 * When an emailed report goes out, in the shop's own timezone.
 * (MARKER-REPORT-DELIVERY)
 */
class ReportSchedule
{
    public const FREQS = ['never' => 'Never', 'daily' => 'Every day', 'weekly' => 'Every week', 'monthly' => 'Every month', 'payperiod' => 'Each pay period'];
    public const DAYS = [1 => 'Mondays', 2 => 'Tuesdays', 3 => 'Wednesdays', 4 => 'Thursdays', 5 => 'Fridays', 6 => 'Saturdays', 7 => 'Sundays'];

    public static function times(): array
    {
        $out = [];
        for ($h = 5; $h <= 20; $h++) {
            $out[sprintf('%02d:00', $h)] = Carbon::createFromTime($h)->format('g:i a');
        }
        return $out;
    }

    /** The next send after $after, as a UTC instant; null when it never sends. */
    public static function next(array $d, Tenant $tenant, Carbon $after): ?Carbon
    {
        $freq = $d['email_freq'] ?? 'never';
        if (!isset(self::FREQS[$freq]) || $freq === 'never') return null;
        $tz = $tenant->timezone();
        [$hh, $mm] = array_map('intval', explode(':', $d['email_time'] ?? '07:00') + [1 => 0]);
        $local = $after->copy()->setTimezone($tz);
        $day = $local->copy()->startOfDay();
        for ($i = 0; $i < 70; $i++, $day->addDay()) {
            $at = $day->copy()->setTime($hh, $mm);
            if ($at->lte($local)) continue;
            $ok = match ($freq) {
                'daily'     => true,
                'weekly'    => $day->dayOfWeekIso === (int) ($d['email_day'] ?? 1),
                'monthly'   => $day->day === max(1, min(28, (int) ($d['email_day'] ?? 1))),
                'payperiod' => self::dayAfterPeriodEnds($tenant, $day),
                default     => false,
            };
            if ($ok) return $at->setTimezone('UTC');
        }
        return null;
    }

    private static function dayAfterPeriodEnds(Tenant $tenant, Carbon $day): bool
    {
        try {
            $prev = $day->copy()->subDay();
            [, $end] = PayPeriodService::for($tenant)->boundsFor($prev->copy()->setTime(12, 0));
            return $end->toDateString() === $prev->toDateString();
        } catch (\Throwable $e) {
            return $day->day === 1;
        }
    }

    public static function label(array $d): string
    {
        $freq = $d['email_freq'] ?? 'never';
        if ($freq === 'never') return 'Not emailed';
        $time = self::times()[$d['email_time'] ?? '07:00'] ?? ($d['email_time'] ?? '7:00 am');
        return match ($freq) {
            'daily'     => 'Every day, ' . $time,
            'weekly'    => (self::DAYS[(int) ($d['email_day'] ?? 1)] ?? 'Mondays') . ', ' . $time,
            'monthly'   => Carbon::create(2026, 1, max(1, min(28, (int) ($d['email_day'] ?? 1))))->format('jS') . ' of the month, ' . $time,
            'payperiod' => 'Day after each pay period, ' . $time,
            default     => 'Not emailed',
        };
    }
}
