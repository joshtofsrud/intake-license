<?php

namespace App\Support\Dashboard;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The figures and small renderers the Brief blocks share.
 *
 * These used to sit in one @php block at the top of dashboard-brief.blade.php.
 * Now that each part of Brief is its own block (so a template can use any of
 * them on its own), every block asks this class for what it needs instead of
 * relying on variables another block happened to set.
 */
class BriefView
{
    private const LATE_KEYS  = ['overdue_unstarted', 'overdue_in_progress', 'so_overdue'];
    private const LATER_KEYS = ['so_needed', 'low_stock', 'catalog_attention', 'custom_items', 'win_back', 'transfers_arriving'];

    /**
     * The attention cards grouped by urgency: Late, Today, When you can.
     *
     * @return array{cards: Collection, groups: array, lateN: int, todayN: int}
     */
    public static function queue(?array $attention): array
    {
        $cards  = collect($attention['cards'] ?? [])->filter(fn ($c) => (int) ($c['count'] ?? 0) > 0);
        $groups = [
            'late'  => ['Late',         $cards->filter(fn ($c) => in_array($c['key'] ?? '', self::LATE_KEYS, true))],
            'today' => ['Today',        $cards->reject(fn ($c) => in_array($c['key'] ?? '', array_merge(self::LATE_KEYS, self::LATER_KEYS), true))],
            'later' => ['When you can', $cards->filter(fn ($c) => in_array($c['key'] ?? '', self::LATER_KEYS, true))],
        ];

        return [
            'cards'  => $cards,
            'groups' => $groups,
            'lateN'  => (int) $groups['late'][1]->sum('count'),
            'todayN' => (int) $groups['today'][1]->sum('count'),
        ];
    }

    public static function hello(array $greeting): string
    {
        return 'Good ' . $greeting['time_of_day'] . ($greeting['name'] ? ', ' . $greeting['name'] : '') . '.';
    }

    public static function dayLine(?array $today): string
    {
        $booked = $today['today_count'] ?? 0;
        if ($booked > 0) {
            return $booked . ' ' . Str::plural('booking', $booked) . ' today.';
        }
        if (! empty($today['next_day'])) {
            return 'Nothing is booked until ' . ($today['next_day']->isTomorrow() ? 'tomorrow' : $today['next_day']->format('l')) . '.';
        }

        return 'Nothing is booked in the next 30 days.';
    }

    /**
     * Setup progress: how many of the three steps are done and the next one.
     *
     * @return array{done: int, next: ?array}
     */
    public static function setup(?array $progress): array
    {
        $steps = [
            'services' => ['Add your services', route('tenant.services.index')],
            'hours'    => ['Set your hours', route('tenant.capacity.index')],
            'branding' => ['Add your logo and colours', route('tenant.settings.index')],
        ];

        return [
            'done' => collect($steps)->keys()->filter(fn ($k) => ! empty($progress[$k]))->count(),
            'next' => collect($steps)->first(fn ($v, $k) => empty($progress[$k])),
        ];
    }

    /** The day the week opens on: today if it has bookings, else the first day that does. */
    public static function firstBusyDay(array $days): int
    {
        if (empty($days[0]['bookings']) && empty($days[0]['untimed'])) {
            foreach ($days as $i => $d) {
                if ($d['bookings'] || $d['untimed']) {
                    return $i;
                }
            }
        }

        return 0;
    }

    /** 9 → "9a", 13.5 → "1:30p". */
    public static function hour(float $h): string
    {
        $H = (int) floor($h);
        $M = (int) round(($h - $H) * 60);
        if ($M === 60) { $H++; $M = 0; }

        return ((($H + 11) % 12) + 1) . ($M ? ':' . str_pad((string) $M, 2, '0', STR_PAD_LEFT) : '') . ($H < 12 ? 'a' : 'p');
    }

    /** A 4-day moving-average sparkline, or an empty box when there's nothing to draw. */
    public static function spark(array $vals): string
    {
        $vals = array_values($vals);
        $n = count($vals);
        if ($n < 2 || max($vals) == min($vals)) return '<svg viewBox="0 0 110 28" aria-hidden="true"></svg>';
        $avg = [];
        for ($i = 0; $i < $n; $i++) { $w = array_slice($vals, max(0, $i - 3), min(4, $i + 1)); $avg[] = array_sum($w) / count($w); }
        $mn = min($avg); $mx = max($avg); $pts = [];
        foreach ($avg as $i => $v) { $pts[] = round($i * 110 / ($n - 1), 1) . ',' . round(25 - ($mx > $mn ? ($v - $mn) / ($mx - $mn) : .5) * 22, 1); }
        $last = explode(',', end($pts));

        return '<svg viewBox="0 0 110 28" aria-hidden="true"><path d="M' . implode(' L', $pts) . '" fill="none" stroke="currentColor" stroke-opacity=".75" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round"/><circle cx="' . $last[0] . '" cy="' . $last[1] . '" r="2.2" fill="currentColor"/></svg>';
    }

    public static function delta($pct): string
    {
        if ($pct === null) return '';

        return '<small class="' . ($pct >= 0 ? 'up' : 'down') . '">' . ($pct >= 0 ? '▲ ' : '▼ ') . abs($pct) . '% vs the 30 days before</small>';
    }
}
