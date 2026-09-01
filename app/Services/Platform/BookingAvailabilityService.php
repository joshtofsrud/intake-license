<?php

namespace App\Services\Platform;

use App\Models\PlatformBooking;
use App\Models\PlatformBookingSetting;
use App\Models\PlatformBookingType;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * MARKER-SCHED-FOUNDATION
 *
 * Which slots a public page may offer. Everything is computed in the HOST
 * timezone (the Availability page's setting) and returned as UTC instants;
 * the caller renders them in the booker's zone.
 *
 * A slot is offerable when ALL of these hold:
 *   - it sits inside one of that weekday's hour ranges (start AND end)
 *   - the date is not blocked
 *   - start >= now + min notice, and start <= now + booking window
 *   - fewer than max_per_day ACTIVE bookings already start that day
 *   - it does not overlap any active booking, padded by the buffer
 *   - it does not overlap external busy time (Google, later — see busy())
 *
 * Manual appointments added in admin bypass all of this on purpose.
 */
class BookingAvailabilityService
{
    /** Minutes between candidate starts. Short calls step by 15. */
    private function step(PlatformBookingType $type): int
    {
        return $type->length_min < 30 ? 15 : 30;
    }

    private array $rulesCache = [];

    private function rules(): array
    {
        return $this->rulesCache ?: ($this->rulesCache = PlatformBookingSetting::rules());
    }

    public function timezone(): string
    {
        return $this->rules()['timezone'];
    }

    /**
     * Slots for one calendar day (a Y-m-d in the HOST zone).
     * @return CarbonImmutable[] UTC starts
     */
    public function slotsForDay(PlatformBookingType $type, string $ymd): array
    {
        $r    = $this->rules();
        $tz   = $r['timezone'];
        $day  = CarbonImmutable::createFromFormat('Y-m-d', $ymd, $tz)->startOfDay();

        if ($this->isBlocked($day, $r['blocked_dates'])) {
            return [];
        }

        $ranges = $r['hours'][strtolower($day->format('D'))] ?? [];
        if (empty($ranges)) {
            return [];
        }

        $now       = CarbonImmutable::now($tz);
        $earliest  = $now->addHours($r['min_notice_hours']);
        $latest    = $now->addWeeks($r['window_weeks'])->endOfDay();
        if ($day->endOfDay()->lt($earliest) || $day->gt($latest)) {
            return [];
        }

        // Existing active bookings that touch this day (UTC query, padded
        // a day each side so buffers across midnight still count).
        $dayStartUtc = $day->utc();
        $dayEndUtc   = $day->endOfDay()->utc();
        $taken = PlatformBooking::query()->active()
            ->between($dayStartUtc->subDay(), $dayEndUtc->addDay())
            ->get(['starts_at', 'ends_at']);

        if ($r['max_per_day'] > 0) {
            $startingToday = $taken->filter(fn ($b) =>
                $b->starts_at->copy()->setTimezone($tz)->isSameDay($day)
            )->count();
            if ($startingToday >= $r['max_per_day']) {
                return [];
            }
        }

        $busy   = $this->busy($dayStartUtc->subDay(), $dayEndUtc->addDay());
        $buffer = $r['buffer_minutes'];
        $len    = $type->length_min;
        $step   = $this->step($type);
        $out    = [];

        foreach ($ranges as [$from, $to]) {
            $rangeStart = $day->setTimeFromTimeString($from);
            $rangeEnd   = $day->setTimeFromTimeString($to);

            for ($s = $rangeStart; $s->addMinutes($len)->lte($rangeEnd); $s = $s->addMinutes($step)) {
                $e = $s->addMinutes($len);

                if ($s->lt($earliest) || $s->gt($latest)) {
                    continue;
                }
                if ($this->collides($s, $e, $taken, $buffer) || $this->collides($s, $e, $busy, 0)) {
                    continue;
                }
                $out[] = $s->utc();
            }
        }

        return $out;
    }

    /**
     * Days in a month that have at least one slot — for the calendar's
     * "which dates are clickable". $ym is 'Y-m' in the host zone.
     * @return string[] Y-m-d
     */
    public function daysWithSlots(PlatformBookingType $type, string $ym): array
    {
        $tz    = $this->timezone();
        $first = CarbonImmutable::createFromFormat('Y-m-d', $ym . '-01', $tz)->startOfMonth();
        $days  = [];
        for ($d = $first; $d->month === $first->month; $d = $d->addDay()) {
            if (!empty($this->slotsForDay($type, $d->format('Y-m-d')))) {
                $days[] = $d->format('Y-m-d');
            }
        }
        return $days;
    }

    /**
     * The next N offerable slots from now — for the "Next slots" layout.
     * Scans forward day by day, bounded by the booking window.
     * @return CarbonImmutable[] UTC starts
     */
    public function nextSlots(PlatformBookingType $type, int $count = 5): array
    {
        $tz    = $this->timezone();
        $limit = CarbonImmutable::now($tz)->addWeeks($this->rules()['window_weeks']);
        $out   = [];
        for ($d = CarbonImmutable::now($tz)->startOfDay(); $d->lte($limit) && count($out) < $count; $d = $d->addDay()) {
            foreach ($this->slotsForDay($type, $d->format('Y-m-d')) as $slot) {
                $out[] = $slot;
                if (count($out) >= $count) break;
            }
        }
        return $out;
    }

    /**
     * Server-side check for a POST: is this exact UTC start still offerable?
     * Recomputes the day so a slot taken between page load and submit fails.
     */
    public function isOfferable(PlatformBookingType $type, CarbonInterface $startUtc): bool
    {
        $ymd = $startUtc->copy()->setTimezone($this->timezone())->format('Y-m-d');
        foreach ($this->slotsForDay($type, $ymd) as $slot) {
            if ($slot->equalTo($startUtc)) {
                return true;
            }
        }
        return false;
    }

    // ---- helpers -----------------------------------------------------

    /**
     * External busy blocks as [start, end] UTC pairs. Empty until the
     * Google Calendar patch registers a provider here.
     * @return array<int, array{0: CarbonInterface, 1: CarbonInterface}>
     */
    public function busy(CarbonInterface $fromUtc, CarbonInterface $toUtc): array
    {
        return [];
    }

    private function isBlocked(CarbonImmutable $day, array $blocked): bool
    {
        foreach ($blocked as $b) {
            $from = $b['from'] ?? null;
            $to   = $b['to'] ?? $from;
            if (!$from) continue;
            if ($day->format('Y-m-d') >= $from && $day->format('Y-m-d') <= $to) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param iterable $blocks  PlatformBooking rows OR [start,end] pairs
     */
    private function collides(CarbonInterface $s, CarbonInterface $e, iterable $blocks, int $bufferMin): bool
    {
        foreach ($blocks as $blk) {
            [$bs, $be] = $blk instanceof PlatformBooking
                ? [$blk->starts_at, $blk->ends_at]
                : $blk;
            $bs = Carbon::parse($bs)->subMinutes($bufferMin);
            $be = Carbon::parse($be)->addMinutes($bufferMin);
            if ($s->lt($be) && $e->gt($bs)) {
                return true;
            }
        }
        return false;
    }
}
