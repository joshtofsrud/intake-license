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

    public function __construct(Tenant $tenant, string $window = '30d')
    {
        $this->tenant = $tenant;
        $this->days   = match ($window) {
            '1d'  => 1, // MARKER-TRAFFIC-TODAY
            '7d'  => 7,
            '90d' => 90,
            default => 30,
        };

        // Windows are LEFT-INCLUSIVE, RIGHT-EXCLUSIVE.
        // Current = [now - days, now)
        // Prior   = [now - 2*days, now - days)
        $this->now       = CarbonImmutable::now();
        $this->curEnd    = $this->now;
        $this->curStart  = $this->now->subDays($this->days);
        $this->prevEnd   = $this->curStart;
        $this->prevStart = $this->prevEnd->subDays($this->days);
    }

    public function window(): string
    {
        return $this->days . 'd';
    }

    public function curStart(): CarbonImmutable { return $this->curStart; }
    public function curEnd():   CarbonImmutable { return $this->curEnd; }
    public function prevStart(): CarbonImmutable { return $this->prevStart; }
    public function prevEnd():   CarbonImmutable { return $this->prevEnd; }

    /**
     * Build the 4 top-stat tiles with current value, prior value, and
     * % change. Each tile returns: [label, value, prev, delta_pct].
     */
    public function topStats(): array
    {
        // Unique visitors = distinct sessions in the window. We use
        // session_id as the key; bots are already filtered server-side.
        $curVisitors = $this->distinctSessions($this->curStart, $this->curEnd);
        $prevVisitors = $this->distinctSessions($this->prevStart, $this->prevEnd);

        // Page views = page_view events.
        $curPV  = $this->eventCount('page_view', $this->curStart, $this->curEnd);
        $prevPV = $this->eventCount('page_view', $this->prevStart, $this->prevEnd);

        // Bookings started = booking_started events.
        $curStart  = $this->eventCount('booking_started', $this->curStart, $this->curEnd);
        $prevStartCount = $this->eventCount('booking_started', $this->prevStart, $this->prevEnd);

        // Bookings completed = booking_completed events.
        $curDone  = $this->eventCount('booking_completed', $this->curStart, $this->curEnd);
        $prevDone = $this->eventCount('booking_completed', $this->prevStart, $this->prevEnd);

        return [
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
    public function dailyVisitors(): array
    {
        return [
            'current' => $this->dailySessionSeries($this->curStart, $this->curEnd),
            'prior'   => $this->dailySessionSeries($this->prevStart, $this->prevEnd),
        ];
    }

    // ------------------------------------------------------------------
    // PRIVATE — query helpers
    // ------------------------------------------------------------------

    protected function distinctSessions(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return (int) TenantFunnelEvent::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<',  $end)
            ->distinct('session_id')
            ->count('session_id');
    }

    protected function eventCount(string $eventType, CarbonImmutable $start, CarbonImmutable $end): int
    {
        return (int) TenantFunnelEvent::query()
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
    protected function dailySessionSeries(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = TenantFunnelEvent::query()
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
}
