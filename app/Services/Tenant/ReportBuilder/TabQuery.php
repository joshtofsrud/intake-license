<?php

namespace App\Services\Tenant\ReportBuilder;

use Illuminate\Http\Request;

/**
 * TabQuery — the range and compare controls shared by every Reports tab that runs on the report engine.
 * (MARKER-REPORT-TABS-ENGINE)
 *
 * One reader for the page address, so a tab, "Open in builder" and Explain all talk about the same dates.
 * Older addresses (?range=week, ?range=last_30, ?range=custom&from=&to=) keep working.
 */
class TabQuery
{
    /** address value => the range name the engine uses */
    public const SLUGS = [
        'today' => 'Today', 'last_7' => 'Last 7 days', 'week' => 'Last 7 days', 'month' => 'This month',
        'last_30' => 'Last 30 days', 'last_90' => 'Last 90 days', 'custom' => 'Custom',
    ];

    /** the buttons, in order */
    public const SEGMENTS = [
        ['today', 'Today'], ['last_7', 'Last 7 days'], ['month', 'This month'], ['last_30', 'Last 30 days'], ['last_90', 'Last 90 days'], ['custom', 'Custom…'],
    ];

    public const COMPARE = ['off' => 'Off', 'prev' => 'Period before', 'yoy' => 'Same dates last year'];

    /** @return array{slug: string, range: string, from: string, to: string, compare: string, note: ?string} */
    public static function read(Request $r): array
    {
        $slug = (string) $r->query('range', 'last_30');
        if (!isset(self::SLUGS[$slug])) $slug = 'last_30';
        $compare = (string) $r->query('compare', 'prev');
        if (!isset(self::COMPARE[$compare])) $compare = 'prev';
        $from = (string) $r->query('from', '');
        $to = (string) $r->query('to', '');
        $range = self::SLUGS[$slug];
        $note = null;
        // "Custom" needs two good dates; the engine's own cleaning is the judge, so a bad pair falls back.
        if ($range === 'Custom') {
            $c = ReportCatalog::cleanRangeOnly($range, $from, $to);
            [$range, $from, $to] = [$c[0], $c[1], $c[2]];
            if ($range !== 'Custom') { $slug = 'last_30'; $note = 'Those dates weren\'t usable, so the last 30 days are shown. Pick a start and an end date to try again.'; }
        } else {
            $from = $to = '';
        }
        return ['slug' => $slug === 'week' ? 'last_7' : $slug, 'range' => $range, 'from' => $from, 'to' => $to, 'compare' => $compare, 'note' => $note];
    }

    public static function compareWord(string $compare): string
    {
        return $compare === 'yoy' ? 'on last year' : 'on the period before';
    }
}
