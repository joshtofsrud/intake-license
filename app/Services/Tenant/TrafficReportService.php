<?php
// MARKER-PATCH-151A

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Models\Tenant\TenantFunnelEvent;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * TrafficReportService — windowed traffic analytics over
 * tenant_funnel_events for a single tenant.
 *
 * Window options: 7d / 30d / 90d (default 30d). The service exposes a
 * current window AND a same-length prior window for delta math.
 *
 * Everything here is aggregate counts — no PII, no IPs, no
 * fingerprinting (we don't store any of that).
 *
 * Performance notes:
 *   - All queries are tenant-scoped first via composite index
 *     (tenant_id, created_at) and (tenant_id, event_type, created_at)
 *     added in the migration from patch 149.
 *   - 7d window for an active tenant is probably a few thousand rows;
 *     90d window worst-case maybe 100k rows. All counts/groups happen
 *     in MySQL, not PHP.
 *   - 90-day retention means storage stays bounded.
 */
class TrafficReportService
{
    protected Tenant $tenant;
    protected int $days;
    protected CarbonImmutable $now;

    /** @var CarbonImmutable */
    protected $curStart;
    /** @var CarbonImmutable */
    protected $curEnd;
    /** @var CarbonImmutable */
    protected $prevStart;
    /** @var CarbonImmutable */
    protected $prevEnd;

    // MARKER-PATCH-475 — true when an explicit from/to range is in effect.
    protected bool $isCustom = false;

    public function __construct(Tenant $tenant, string $window = '30d', $from = null, $to = null)
    {
        $this->tenant = $tenant;

        $tz        = $tenant->timezone ?? config('app.timezone', 'UTC');
        $this->now = CarbonImmutable::now($tz)->utc();

        // MARKER-PATCH-475 — an explicit from/to range (from the shared calendar
        // picker) overrides the preset window. Days are tenant-local and inclusive
        // of both ends; the prior period is the same-length span immediately before,
        // so every "vs prior" delta on the report stays meaningful.
        if ($from !== null && $to !== null && $from !== '' && $to !== '') {
            $f = CarbonImmutable::parse($from, $tz)->startOfDay();
            $t = CarbonImmutable::parse($to, $tz)->startOfDay();
            if ($t->lessThan($f)) {
                [$f, $t] = [$t, $f];
            }

            $this->days      = $f->diffInDays($t) + 1;
            $this->curStart  = $f->utc();
            $this->curEnd    = $t->addDay()->utc();
            $this->prevEnd   = $this->curStart;
            $this->prevStart = $f->subDays($this->days)->utc();
            $this->isCustom  = true;

            return;
        }

        $this->days   = match ($window) {
            '1d'  => 1, // MARKER-TRAFFIC-TODAY
            '7d'  => 7,
            '90d' => 90,
            default => 30,
        };

        // MARKER-PATCH-400 — day-aligned to the tenant's local calendar, so "1d"
        // means "since local midnight today" rather than a rolling 24h window.
        // Current = [local midnight (today - (days-1)), now); prior = same length before.
        $localStartToday = CarbonImmutable::now($tz)->startOfDay();
        $this->curEnd    = $this->now;
        $this->curStart  = $localStartToday->subDays($this->days - 1)->utc();
        $this->prevEnd   = $this->curStart;
        $this->prevStart = $this->curStart->subDays($this->days)->utc();
    }

    public function window(): string
    {
        return $this->days . 'd';
    }

    // MARKER-PATCH-475
    public function isCustom(): bool
    {
        return $this->isCustom;
    }

    // MARKER-PATCH-475 — human label for the "Showing …" line.
    public function rangeLabel(): string
    {
        $tz = $this->tenant->timezone ?? config('app.timezone', 'UTC');

        if ($this->isCustom) {
            $s = $this->curStart->setTimezone($tz);
            $e = $this->curEnd->setTimezone($tz)->subDay(); // inclusive last day
            return $s->format('M j') . ' – ' . $e->format('M j, Y');
        }

        return match ($this->days) {
            1  => 'today',
            7  => 'last 7 days',
            90 => 'last 90 days',
            default => 'last ' . $this->days . ' days',
        };
    }

    public function curStart(): CarbonImmutable { return $this->curStart; }
    public function curEnd():   CarbonImmutable { return $this->curEnd; }
    public function prevStart(): CarbonImmutable { return $this->prevStart; }
    public function prevEnd():   CarbonImmutable { return $this->prevEnd; }

    /**
     * Build the 4 top-stat tiles with current value, prior value, and
     * % change. Each tile returns: [label, value, prev, delta_pct].
     */
    /**
     * MARKER-MKTCONV — opt-in, and off by default so tenant reports are
     * untouched. Tenant events never contained bots (FunnelTrackController
     * drops them server-side), but the marketing endpoint did until
     * MARKER-MKTBOTFIX, so the platform tenant still carries historical
     * crawler rows that inflate its visitor counts.
     */
    protected bool $excludeBots = false;

    public function excludeBots(bool $on = true): static
    {
        $this->excludeBots = $on;
        return $this;
    }

    protected function applyBotFilter($query)
    {
        if ($this->excludeBots) {
            $query->where(function ($w) {
                $w->whereNull('device')->orWhere('device', '!=', 'bot');
            });
        }
        return $query;
    }

    public function topStats(): array
    {
        // Unique visitors = distinct sessions in the window. We use
        // session_id as the key; bots are already filtered server-side.
        $curVisitors = $this->distinctSessions($this->curStart, $this->curEnd);
        $prevVisitors = $this->distinctSessions($this->prevStart, $this->prevEnd);

        // Page views = page_view events.
        $curPV  = $this->eventCount('page_view', $this->curStart, $this->curEnd);
        $prevPV = $this->eventCount('page_view', $this->prevStart, $this->prevEnd);

        // Bookings started = DISTINCT SESSIONS that fired booking_started.
        // MARKER-PATCH-632B — the choice-page beacon (patch-632) fires per
        // click, so raw event counts inflate when a shopper backs up and
        // picks the other path. Matches the funnel section's semantics.
        $curStart  = $this->sessionEventCount('booking_started', $this->curStart, $this->curEnd);
        $prevStartCount = $this->sessionEventCount('booking_started', $this->prevStart, $this->prevEnd);

        // Bookings completed = booking_completed events.
        $curDone  = $this->eventCount('booking_completed', $this->curStart, $this->curEnd);
        $prevDone = $this->eventCount('booking_completed', $this->prevStart, $this->prevEnd);

        return [
            'sessions'   => $this->bookingSessions(), // MARKER-SESSIONS-EXPLORER
            'visitors'   => $this->tile('Visitors',           $curVisitors,  $prevVisitors),
            'page_views' => $this->tile('Page views',         $curPV,        $prevPV),
            'started'    => $this->tile('Bookings started',   $curStart,     $prevStartCount),
            'completed'  => $this->tile('Bookings completed', $curDone,      $prevDone, true),
        ];
    }

    /**
     * Daily-visitors time-series for both windows.
     * Returns ['current' => [int, ...], 'prior' => [int, ...]]
     * Each list has exactly $this->days entries (one per day).
     */
    /**
     * MARKER-PATCH-621 — top searches in the current window: query, count,
     * and average result count (a low avg on a popular query = weak catalog fit).
     */
    public function topSearches(int $limit = 8): array
    {
        return \App\Models\Tenant\TenantSearchQuery::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('created_at', '>=', $this->curStart)
            ->where('created_at', '<',  $this->curEnd)
            ->selectRaw('LOWER(query) as q, COUNT(*) as n, ROUND(AVG(results_count), 1) as avg_results')
            ->groupBy('q')
            ->orderByDesc('n')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => ['q' => $r->q, 'n' => (int) $r->n, 'avg' => (float) $r->avg_results])
            ->all();
    }

    /** MARKER-PATCH-621 — zero-result searches: what customers wanted and missed. */
    public function zeroResultSearches(int $limit = 8): array
    {
        return \App\Models\Tenant\TenantSearchQuery::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('created_at', '>=', $this->curStart)
            ->where('created_at', '<',  $this->curEnd)
            ->where('results_count', 0)
            ->selectRaw('LOWER(query) as q, COUNT(*) as n')
            ->groupBy('q')
            ->orderByDesc('n')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => ['q' => $r->q, 'n' => (int) $r->n])
            ->all();
    }

    public function dailyVisitors(): array
    {
        // MARKER-PATCH-619 — a 1-day window renders as a single point on a daily
        // chart (one dot, empty plot). Bucket single-day windows by HOUR instead:
        // 24 tenant-local hours, today vs the same hours yesterday.
        if ($this->days === 1) {
            return [
                'current' => $this->hourlySessionSeries($this->curStart, $this->curEnd),
                'prior'   => $this->hourlySessionSeries($this->prevStart, $this->prevEnd),
                'hourly'  => true,
            ];
        }

        return [
            'current' => $this->dailySessionSeries($this->curStart, $this->curEnd),
            'prior'   => $this->dailySessionSeries($this->prevStart, $this->prevEnd),
            'hourly'  => false,
        ];
    }

    /**
     * MARKER-PATCH-619 — per-hour distinct sessions over a single day.
     * Hour index is computed as offset from the window start (which is
     * tenant-local midnight stored as UTC), so buckets align to the tenant's
     * clock without CONVERT_TZ.
     */
    protected function hourlySessionSeries(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = TenantFunnelEvent::query()
            ->tap(fn ($q) => $this->applyBotFilter($q)) // MARKER-MKTCONV
            ->where('tenant_id', $this->tenant->id)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<',  $end)
            ->selectRaw('FLOOR(TIMESTAMPDIFF(MINUTE, ?, created_at) / 60) as h, COUNT(DISTINCT session_id) as n', [$start->toDateTimeString()])
            ->groupBy('h')
            ->pluck('n', 'h')
            ->all();

        $series = [];
        for ($i = 0; $i < 24; $i++) {
            $series[] = (int) ($rows[$i] ?? 0);
        }
        return $series;
    }

    // ------------------------------------------------------------------
    // PRIVATE — query helpers
    // ------------------------------------------------------------------

    protected function distinctSessions(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return (int) TenantFunnelEvent::query()
            ->tap(fn ($q) => $this->applyBotFilter($q)) // MARKER-MKTCONV
            ->where('tenant_id', $this->tenant->id)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<',  $end)
            ->distinct('session_id')
            ->count('session_id');
    }

    // MARKER-PATCH-632B — distinct-session count for intent metrics.
    protected function sessionEventCount(string $eventType, CarbonImmutable $start, CarbonImmutable $end): int
    {
        return (int) TenantFunnelEvent::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('event_type', $eventType)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<',  $end)
            ->distinct('session_id')
            ->count('session_id');
    }

    protected function eventCount(string $eventType, CarbonImmutable $start, CarbonImmutable $end): int
    {
        return (int) TenantFunnelEvent::query()
            ->tap(fn ($q) => $this->applyBotFilter($q)) // MARKER-MKTCONV
            ->where('tenant_id', $this->tenant->id)
            ->where('event_type', $eventType)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<',  $end)
            ->count();
    }

    /**
     * Per-day distinct session counts. Returns int[] of length $days,
     * indexed 0..days-1 from the START of the window.
     *
     * Uses a left join against a date series so days with zero visits
     * still show up as 0 rather than missing entries.
     */
    /**
     * MARKER-TRAFFIC-V3 — the same daily buckets, for a chosen metric.
     *
     * visitors    distinct sessions        (what the old series always drew)
     * page_views  page_view events
     * started     booking_started sessions
     * completed   booking_completed sessions
     *
     * Counting sessions rather than events for the booking steps keeps them
     * comparable with the funnel, where a reloaded page is still one person.
     */
    public function dailyMetricSeries(string $metric, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $q = TenantFunnelEvent::query()
            ->tap(fn ($qq) => $this->applyBotFilter($qq))
            ->where('tenant_id', $this->tenant->id)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<',  $end);

        $count = 'COUNT(DISTINCT session_id) as n';

        switch ($metric) {
            case 'page_views':
                $q->where('event_type', 'page_view');
                $count = 'COUNT(*) as n';
                break;
            case 'started':
                $q->where('event_type', 'booking_started');
                break;
            case 'completed':
                $q->where('event_type', 'booking_completed');
                break;
            default: // visitors
                break;
        }

        $rows = $q->selectRaw('DATE(created_at) as d, ' . $count)
            ->groupBy('d')->pluck('n', 'd')->all();

        $series = [];
        for ($i = 0; $i < $this->days; $i++) {
            $series[] = (int) ($rows[$start->addDays($i)->toDateString()] ?? 0);
        }
        return $series;
    }

    /** MARKER-TRAFFIC-V3 — labels for the chart's x axis. */
    public function dayLabels(): array
    {
        $out = [];
        for ($i = 0; $i < $this->days; $i++) {
            $out[] = $this->curStart->addDays($i)->format('M j');
        }
        return $out;
    }

    protected function dailySessionSeries(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = TenantFunnelEvent::query()
            ->tap(fn ($q) => $this->applyBotFilter($q)) // MARKER-MKTCONV
            ->where('tenant_id', $this->tenant->id)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<',  $end)
            ->selectRaw('DATE(created_at) as d, COUNT(DISTINCT session_id) as n')
            ->groupBy('d')
            ->pluck('n', 'd')
            ->all();

        $series = [];
        for ($i = 0; $i < $this->days; $i++) {
            $day = $start->addDays($i)->toDateString();
            $series[] = (int) ($rows[$day] ?? 0);
        }
        return $series;
    }

    protected function tile(string $label, int $cur, int $prev, bool $accent = false): array
    {
        $delta = null;
        if ($prev > 0) {
            $delta = round((($cur - $prev) / $prev) * 100, 1);
        } elseif ($cur > 0) {
            // Going from 0 to anything is technically infinite growth — show "new"
            $delta = null;
        }
        return [
            'label'  => $label,
            'value'  => $cur,
            'prev'   => $prev,
            'delta'  => $delta,        // float % or null
            'accent' => $accent,
        ];
    }

    // ------------------------------------------------------------------
    // MARKER-PATCH-151B — funnel + sources + devices + pages + new/returning
    // ------------------------------------------------------------------

    /**
     * 3-step funnel with drop-off math between each step.
     *
     * Step 1 — Viewed booking page  (booking_page_viewed event)
     * Step 2 — Started booking      (booking_started event)
     * Step 3 — Completed booking    (booking_completed event)
     *
     * We use DISTINCT session_id per step rather than raw event counts.
     * Same session firing booking_started twice (e.g. they backed up and
     * tried again) shouldn't double-count.
     */
    public function funnel(): array
    {
        // MARKER-PATCH-357 — cumulative cohort counts: distinct sessions that
        // reached a stage OR BEYOND. Guarantees a monotonic funnel
        // (viewed >= started >= completed) even when a session fires a later
        // event without the earlier one (tracking gap). Previously this
        // produced >100% steps and negative drop-offs.
        $reached = function (array $eventTypes) {
            return (int) TenantFunnelEvent::query()
                ->where('tenant_id', $this->tenant->id)
                ->whereIn('event_type', $eventTypes)
                ->where('created_at', '>=', $this->curStart)
                ->where('created_at', '<',  $this->curEnd)
                ->distinct('session_id')
                ->count('session_id');
        };

        $viewed    = $reached(['booking_page_viewed', 'booking_started', 'booking_completed']);
        $started   = $reached(['booking_started', 'booking_completed']);
        $completed = $reached(['booking_completed']);

        // Drop-off rates between adjacent steps
        $dropViewToStart  = $viewed   > 0 ? round((($viewed   - $started)   / $viewed)   * 100, 1) : 0.0;
        $dropStartToDone  = $started  > 0 ? round((($started  - $completed) / $started)  * 100, 1) : 0.0;

        return [
            'steps' => [
                ['label' => 'Viewed booking page', 'count' => $viewed,    'pct' => 100.0],
                ['label' => 'Started booking',     'count' => $started,   'pct' => $viewed > 0 ? round(($started / $viewed) * 100, 1) : 0.0],
                ['label' => 'Completed booking',   'count' => $completed, 'pct' => $viewed > 0 ? round(($completed / $viewed) * 100, 1) : 0.0],
            ],
            'dropoffs' => [
                ['from' => 'Viewed → Started',   'pct' => $dropViewToStart,  'lost' => max(0, $viewed  - $started)],
                ['from' => 'Started → Completed','pct' => $dropStartToDone,  'lost' => max(0, $started - $completed)],
            ],
            // Overall page-view-to-completion conversion (the headline funnel metric)
            'overall_pct' => $viewed > 0 ? round(($completed / $viewed) * 100, 1) : 0.0,
        ];
    }

    /**
     * MARKER-PATCH-453 — granular booking funnel + per-step drop diagnosis.
     *
     * One pass over the window's booking events builds, per session, the
     * furthest stage it reached plus its device / source / new-or-returning.
     * Stages: Opened booking → each wizard step (booking_step, in flow order)
     * → Booked (booking_completed). Counts are cohort "reached this stage or
     * beyond", so the funnel is always monotonic. The detail array carries the
     * device/source/new-vs-returning split of whoever dropped at each stage —
     * that's what localizes a cliff (e.g. "this drop is 81% mobile").
     */
    public function bookingFunnelData(): array
    {
        $rows = TenantFunnelEvent::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('created_at', '>=', $this->curStart)
            ->where('created_at', '<',  $this->curEnd)
            ->whereIn('event_type', ['booking_page_viewed', 'booking_started', 'booking_step', 'booking_completed'])
            ->get(['session_id', 'event_type', 'step', 'device', 'utm_source', 'referrer_domain', 'is_new_session']);

        // Ordered wizard-step keys ("NN heading" — sorts into flow order).
        $stepKeys = $rows->where('event_type', 'booking_step')
            ->pluck('step')->filter()->unique()->sort()->values()->all();
        $k = count($stepKeys);
        $stepIndex = array_flip($stepKeys);              // key => 0-based slot
        $S = $k + 2;                                     // Opened + steps + Booked

        // Per session: furthest stage + first-seen attributes.
        $sessions = [];
        foreach ($rows as $r) {
            $sid = $r->session_id;
            if (!isset($sessions[$sid])) {
                $sessions[$sid] = [
                    'stage'  => 0,
                    'device' => $r->device ?: 'unknown',
                    // MARKER-TRAFFIC-POLISH — our own host is not a referral.
                    // Someone moving from /pricing to /features arrives with a
                    // referrer of intake.works, and counting that as a source
                    // inflates a channel that does not exist.
                    'source' => $r->utm_source ?: (
                        ($r->referrer_domain && ! str_ends_with(
                            strtolower($r->referrer_domain),
                            strtolower((string) config('intake.domain', 'intake.works'))
                        ))
                            ? $r->referrer_domain
                            : '(direct)'
                    ),
                    'new'    => (bool) $r->is_new_session,
                ];
            }
            $st = 0;
            if ($r->event_type === 'booking_completed') {
                $st = $k + 1;
            } elseif ($r->event_type === 'booking_step' && isset($stepIndex[$r->step])) {
                $st = $stepIndex[$r->step] + 1;
            }
            if ($st > $sessions[$sid]['stage']) {
                $sessions[$sid]['stage'] = $st;
            }
        }

        // Stage labels.
        // MARKER-PATCH-619 — stage 0 includes booking_page_viewed sessions, so
        // name it what it is. 'Opened booking' read like the 'Bookings started'
        // tile (booking_started events) and the two showed different numbers.
        $labels = ['Viewed booking page'];
        foreach ($stepKeys as $key) {
            $clean = trim(preg_replace('/^\d+\s/', '', $key));
            $labels[] = $clean !== '' ? $clean : $key;
        }
        $labels[] = 'Booked';

        // dropAt[s] = sessions whose furthest stage is exactly s.
        // reached[s] = sessions at stage s or beyond (monotonic).
        $dropAt = array_fill(0, $S, 0);
        foreach ($sessions as $s) {
            $dropAt[$s['stage']]++;
        }
        $reached = array_fill(0, $S, 0);
        $run = 0;
        for ($s = $S - 1; $s >= 0; $s--) {
            $run += $dropAt[$s];
            $reached[$s] = $run;
        }

        $opened = $reached[0];
        $steps = [];
        for ($s = 0; $s < $S; $s++) {
            $steps[] = [
                'label' => $labels[$s],
                'count' => $reached[$s],
                'pct'   => $opened > 0 ? round($reached[$s] / $opened * 100, 1) : 0.0,
            ];
        }

        $dropoffs = [];
        for ($s = 0; $s < $S - 1; $s++) {
            $lost = $reached[$s] - $reached[$s + 1];
            $dropoffs[] = [
                'from' => $labels[$s] . ' → ' . $labels[$s + 1],
                'pct'  => $reached[$s] > 0 ? round($lost / $reached[$s] * 100, 1) : 0.0,
                'lost' => max(0, $lost),
            ];
        }

        // Per-stage drop diagnosis (who left at each stage, not counting Booked).
        $detail = [];
        for ($s = 0; $s < $S - 1; $s++) {
            $drop = array_filter($sessions, fn ($x) => $x['stage'] === $s);
            $n = count($drop);
            $device = $this->tallyFixed($drop, 'device', ['mobile', 'desktop', 'tablet', 'unknown']);
            $detail[] = [
                'label'   => $labels[$s],
                'left'    => $n,
                'device'  => $device,
                'source'  => $this->tallyTop($drop, 'source', 4),
                'newret'  => $this->tallyNewReturning($drop),
                'insight' => $this->dropInsight($device, $n),
            ];
        }

        return [
            'funnel' => [
                'steps'       => $steps,
                'dropoffs'    => $dropoffs,
                'overall_pct' => $opened > 0 ? round($reached[$S - 1] / $opened * 100, 1) : 0.0,
            ],
            'detail' => $detail,
        ];
    }

    /** Tally a fixed set of keys → [['k'=>label,'pct'=>int], ...] sorted desc, zeros dropped. */
    private function tallyFixed(array $sessions, string $field, array $order): array
    {
        $n = count($sessions);
        if ($n === 0) return [];
        $counts = [];
        foreach ($order as $key) $counts[$key] = 0;
        foreach ($sessions as $s) {
            $v = $s[$field] ?? 'unknown';
            if (!isset($counts[$v])) $counts[$v] = 0;
            $counts[$v]++;
        }
        $out = [];
        foreach ($counts as $key => $c) {
            if ($c === 0) continue;
            $out[] = ['k' => ucfirst($key), 'pct' => (int) round($c / $n * 100)];
        }
        usort($out, fn ($a, $b) => $b['pct'] <=> $a['pct']);
        return $out;
    }

    /** Tally free-form values, keep top N → [['k'=>label,'pct'=>int], ...]. */
    private function tallyTop(array $sessions, string $field, int $limit): array
    {
        $n = count($sessions);
        if ($n === 0) return [];
        $counts = [];
        foreach ($sessions as $s) {
            $v = $s[$field] ?: '(direct)';
            $counts[$v] = ($counts[$v] ?? 0) + 1;
        }
        arsort($counts);
        $out = [];
        foreach (array_slice($counts, 0, $limit, true) as $key => $c) {
            $out[] = ['k' => $key, 'pct' => (int) round($c / $n * 100)];
        }
        return $out;
    }

    /** New vs returning split → [['k'=>'New','pct'=>x], ['k'=>'Returning','pct'=>y]]. */
    private function tallyNewReturning(array $sessions): array
    {
        $n = count($sessions);
        if ($n === 0) return [];
        $new = 0;
        foreach ($sessions as $s) if (!empty($s['new'])) $new++;
        $ret = $n - $new;
        return [
            ['k' => 'New',       'pct' => (int) round($new / $n * 100)],
            ['k' => 'Returning', 'pct' => (int) round($ret / $n * 100)],
        ];
    }

    /** A one-line read on a drop, or null when nothing stands out. */
    private function dropInsight(array $device, int $n): ?string
    {
        if ($n < 5 || empty($device)) return null;
        $top = $device[0];
        if ($top['k'] === 'Mobile' && $top['pct'] >= 60) {
            return 'Most of this drop is on mobile (' . $top['pct'] . '%) — worth walking the step on a real phone.';
        }
        if ($top['k'] === 'Desktop' && $top['pct'] >= 70) {
            return 'This drop is mostly desktop (' . $top['pct'] . '%) — unusual for booking, may point to a layout issue.';
        }
        return null;
    }

    /**
     * Top sources — UTM source preferred, falls back to referrer domain,
     * falls back to '(direct)' when both are absent.
     *
     * Returns rows: [name, visits, conversions, conv_pct].
     */
    public function topSources(int $limit = 8): array
    {
        // Visits and bookings completed, both grouped by best-available source.
        // We compute the source label in PHP after fetching session-level rows.
        $sourceExpr = "COALESCE(NULLIF(utm_source, ''), NULLIF(referrer_domain, ''), '(direct)')";

        $visits = TenantFunnelEvent::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('created_at', '>=', $this->curStart)
            ->where('created_at', '<',  $this->curEnd)
            ->selectRaw("$sourceExpr as source, COUNT(DISTINCT session_id) as n")
            ->groupBy('source')
            ->orderByDesc('n')
            ->limit($limit)
            ->pluck('n', 'source')
            ->all();

        if (empty($visits)) return [];

        // Conversions by source (booking_completed sessions)
        $conv = TenantFunnelEvent::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('event_type', 'booking_completed')
            ->where('created_at', '>=', $this->curStart)
            ->where('created_at', '<',  $this->curEnd)
            ->whereIn(DB::raw($sourceExpr), array_keys($visits))
            ->selectRaw("$sourceExpr as source, COUNT(DISTINCT session_id) as n")
            ->groupBy('source')
            ->pluck('n', 'source')
            ->all();

        $rows = [];
        foreach ($visits as $source => $n) {
            $convCount = (int) ($conv[$source] ?? 0);
            $rows[] = [
                'name'        => (string) $source,
                'visits'      => (int) $n,
                'conversions' => $convCount,
                'conv_pct'    => $n > 0 ? round(($convCount / $n) * 100, 1) : 0.0,
            ];
        }
        return $rows;
    }

    /**
     * Device split — distinct sessions per device class.
     * Returns rows: [device, count, pct].
     */
    public function deviceSplit(): array
    {
        $rows = TenantFunnelEvent::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('created_at', '>=', $this->curStart)
            ->where('created_at', '<',  $this->curEnd)
            ->whereNotNull('device')
            ->where('device', '!=', 'bot')
            ->selectRaw('device, COUNT(DISTINCT session_id) as n')
            ->groupBy('device')
            ->pluck('n', 'device')
            ->all();

        $total = array_sum($rows);
        if ($total === 0) return [];

        $out = [];
        foreach (['mobile', 'desktop', 'tablet', 'unknown'] as $d) {
            if (!isset($rows[$d])) continue;
            $n = (int) $rows[$d];
            $out[] = [
                'device' => $d,
                'count'  => $n,
                'pct'    => round(($n / $total) * 100, 1),
            ];
        }
        return $out;
    }

    /**
     * Top pages — most-visited paths.
     * Returns rows: [path, views, unique_visitors].
     */
    public function topPages(int $limit = 8): array
    {
        return TenantFunnelEvent::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('event_type', 'page_view')
            ->where('created_at', '>=', $this->curStart)
            ->where('created_at', '<',  $this->curEnd)
            ->whereNotNull('path')
            ->selectRaw('path, COUNT(*) as views, COUNT(DISTINCT session_id) as unique_visitors')
            ->groupBy('path')
            ->orderByDesc('views')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'path' => $r->path,
                'views' => (int) $r->views,
                'unique_visitors' => (int) $r->unique_visitors,
            ])
            ->all();
    }

    /**
     * New vs returning — split by is_new_session, with conversion math.
     *
     * is_new_session was captured per event at write time. We define a
     * session as "new" if any event in that session was marked new (which
     * for the first request to land = always the first event).
     */
    public function newVsReturning(): array
    {
        // Distinct (session_id, is_new_session) — collapse to one row per session.
        // A session that was new on its first request stays "new" for the report.
        $rows = DB::table('tenant_funnel_events')
            ->where('tenant_id', $this->tenant->id)
            ->where('created_at', '>=', $this->curStart)
            ->where('created_at', '<',  $this->curEnd)
            ->select('session_id', DB::raw('MAX(is_new_session) as is_new'))
            ->groupBy('session_id')
            ->get();

        $newCount = $rows->where('is_new', 1)->count();
        $retCount = $rows->where('is_new', 0)->count();
        $total = $newCount + $retCount;

        if ($total === 0) {
            return [
                'new' => ['count' => 0, 'pct' => 0.0, 'conv_pct' => null],
                'returning' => ['count' => 0, 'pct' => 0.0, 'conv_pct' => null],
            ];
        }

        // Conversion rates per cohort
        $newSessions = $rows->where('is_new', 1)->pluck('session_id')->all();
        $retSessions = $rows->where('is_new', 0)->pluck('session_id')->all();

        $convFor = function (array $sessionIds) {
            if (empty($sessionIds)) return 0;
            return (int) TenantFunnelEvent::query()
                ->where('tenant_id', $this->tenant->id)
                ->where('event_type', 'booking_completed')
                ->where('created_at', '>=', $this->curStart)
                ->where('created_at', '<',  $this->curEnd)
                ->whereIn('session_id', $sessionIds)
                ->distinct('session_id')
                ->count('session_id');
        };

        $newConv = $convFor($newSessions);
        $retConv = $convFor($retSessions);

        return [
            'new' => [
                'count'    => $newCount,
                'pct'      => round(($newCount / $total) * 100, 1),
                'conv_pct' => $newCount > 0 ? round(($newConv / $newCount) * 100, 1) : 0.0,
            ],
            'returning' => [
                'count'    => $retCount,
                'pct'      => round(($retCount / $total) * 100, 1),
                'conv_pct' => $retCount > 0 ? round(($retConv / $retCount) * 100, 1) : 0.0,
            ],
        ];
    }

    /**
     * MARKER-SESSIONS-EXPLORER — per-session booking activity for the
     * explorer panel under the funnel. Groups this window's booking events
     * by session; times are returned pre-formatted in the tenant timezone.
     */
    protected function bookingSessions(): array
    {
        $tz = $this->tenant->timezone ?? config('app.timezone', 'UTC');

        $events = TenantFunnelEvent::query()
            ->where('tenant_id', $this->tenant->id)
            ->whereIn('event_type', ['booking_started', 'booking_step', 'booking_completed'])
            ->where('created_at', '>=', $this->curStart)
            ->where('created_at', '<',  $this->curEnd)
            ->orderBy('created_at')
            ->limit(3000)
            ->get(['session_id', 'event_type', 'step', 'device', 'referrer_domain', 'created_at']);

        $sessions = [];
        foreach ($events as $e) {
            $sid = $e->session_id ?: 'unknown';
            $sessions[$sid] ??= [
                'session'   => substr($sid, 0, 4) . '…' . substr($sid, -2),
                'first_at'  => $e->created_at,
                'last_at'   => $e->created_at,
                'device'    => $e->device,
                'referrer'  => $e->referrer_domain,
                'via_choice'=> false,
                'booked'    => false,
                'steps'     => [],
                'timeline'  => [],
            ];
            $sess = &$sessions[$sid];
            $sess['last_at'] = $e->created_at;
            if ($e->device && ! $sess['device'])     $sess['device']   = $e->device;
            if ($e->referrer_domain && ! $sess['referrer']) $sess['referrer'] = $e->referrer_domain;
            // MARKER-FUNNEL-SESSION-FIX — started now fires for every entry;
            // "via choice page" means the session clicked a fork card.
            if ($e->event_type === 'booking_step' && str_starts_with((string) $e->step, '00 Chose')) $sess['via_choice'] = true;
            if ($e->event_type === 'booking_completed') $sess['booked'] = true;
            if ($e->step !== null && $e->step !== '') $sess['steps'][] = $e->step;
            $sess['timeline'][] = [
                'at'   => $e->created_at->copy()->setTimezone($tz)->format('g:i:s A'),
                'what' => $e->event_type === 'booking_step'
                    ? preg_replace('/^\\d+\\s*/', '', (string) $e->step)
                    : ($e->event_type === 'booking_started' ? 'Entered the booking flow' : 'Booked'),
            ];
            unset($sess);
        }

        $stepCount = 6; // choice/entry → items → services → schedule → details → review
        $out = [];
        foreach ($sessions as $sess) {
            // Furthest step index from the numeric step prefixes (00, 01, …).
            $furthest = $sess['via_choice'] ? 1 : 1; // entering the flow at all = segment 1
            foreach ($sess['steps'] as $st) {
                if (preg_match('/^(\\d+)/', $st, $m)) {
                    $furthest = max($furthest, min($stepCount, (int) $m[1] + 1));
                }
            }
            if ($sess['booked']) $furthest = $stepCount;

            $activeCutoff = now()->subMinutes(10);
            $status = $sess['booked'] ? 'booked'
                : ($sess['last_at']->gt($activeCutoff) ? 'active' : 'dropped');

            $lastStep = null;
            if (! empty($sess['steps'])) {
                $lastStep = preg_replace('/^\\d+\\s*/', '', end($sess['steps']));
            }

            $out[] = [
                'session'   => $sess['session'],
                'time'      => $sess['first_at']->copy()->setTimezone($tz)->format('g:i A'),
                'day'       => $sess['first_at']->copy()->setTimezone($tz)->format('D n/j'),
                'entry'     => $sess['via_choice'] ? 'choice' : 'direct',
                'device'    => $sess['device'],
                'referrer'  => $sess['referrer'],
                'status'    => $status,
                'furthest'  => $furthest,
                'step_count'=> $stepCount,
                'last_step' => $lastStep,
                'duration'  => $sess['first_at']->diffForHumans($sess['last_at'], ['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE, 'short' => true]),
                'sort'      => $sess['first_at']->timestamp,
                'timeline'  => $sess['timeline'],
            ];
        }
        usort($out, fn ($a, $b) => $b['sort'] <=> $a['sort']);

        return array_slice($out, 0, 100);
    }
}
