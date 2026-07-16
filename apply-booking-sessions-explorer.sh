#!/bin/bash
# booking-sessions-explorer — per the approved mockup.
#   · "Booking sessions" panel under the funnel on the traffic report:
#     scrollable, newest first, entry badge (via choice page / direct),
#     6-segment progress with red drop point, device, status chip
#     (booked / dropped / active now = last event <10 min), client-side
#     status filters, expandable per-session event timeline
#   · every timestamp rendered in the tenant timezone (window math already
#     used tenant-tz day boundaries — audited, correct)
#   · direct entries to /book now fire booking_started (once per session),
#     so the started tile and the funnel finally reconcile
# NOTE: touches TrafficReportService, ReportsController, traffic blade,
# booking.js — no routes, no migrations.
set -e
cd "$(git rev-parse --show-toplevel)"
if grep -q "MARKER-SESSIONS-EXPLORER" app/Services/Tenant/TrafficReportService.php; then
  echo "sessions explorer already applied — aborting."; exit 1
fi
if ! grep -q "MARKER-RETURNING-PREFILL" public/js/booking.js; then
  echo "returning-prefill fix not applied — wrong base, aborting."; exit 1
fi
if ! grep -q "MARKER-PATCH-632B" app/Services/Tenant/TrafficReportService.php; then
  echo "booking-count fix (632b) not present — wrong base, aborting."; exit 1
fi

cat > 'app/Services/Tenant/TrafficReportService.php' <<'SESSEXP_0_EOF'
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
                    'source' => $r->utm_source ?: ($r->referrer_domain ?: '(direct)'),
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
            if ($e->event_type === 'booking_started') $sess['via_choice'] = true;
            if ($e->event_type === 'booking_completed') $sess['booked'] = true;
            if ($e->step !== null && $e->step !== '') $sess['steps'][] = $e->step;
            $sess['timeline'][] = [
                'at'   => $e->created_at->copy()->setTimezone($tz)->format('g:i:s A'),
                'what' => $e->event_type === 'booking_step'
                    ? preg_replace('/^\\d+\\s*/', '', (string) $e->step)
                    : ($e->event_type === 'booking_started' ? 'Started — chose a path on the choice page' : 'Booked'),
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
SESSEXP_0_EOF

cat > 'app/Http/Controllers/Tenant/ReportsController.php' <<'SESSEXP_1_EOF'
<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Tenant\ReportsDataService;
use App\Services\Tenant\CustomersReportService;
use App\Services\Tenant\ServicesReportService;
use App\Services\Tenant\RetailReportService;
use App\Services\Tenant\MoneyReportService;
use App\Services\Tenant\StaffReportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * ReportsController
 *
 * Phase 3: single global date range drives every zone.
 *   /admin/reports                          (defaults to today)
 *   /admin/reports?range=today
 *   /admin/reports?range=week
 *   /admin/reports?range=month
 *   /admin/reports?range=custom&from=2026-04-01&to=2026-04-15
 */
class ReportsController extends Controller
{
    public function index(Request $request): View
    {
        $tenant = tenant();
        $svc = new ReportsDataService($tenant);
        $today = $tenant->localToday();

        $range = (string) $request->query('range', 'today');
        if (!in_array($range, ['today', 'week', 'month', 'last_30', 'custom'], true)) {
            $range = 'today';
        }

        // Compute [from, to] based on range
        if ($range === 'custom') {
            $fromStr = (string) $request->query('from', $today->toDateString());
            $toStr   = (string) $request->query('to',   $today->toDateString());
            try {
                $from = Carbon::parse($fromStr)->startOfDay();
                $to   = Carbon::parse($toStr)->startOfDay();
                if ($from->gt($to)) [$from, $to] = [$to, $from]; // swap if reversed
            } catch (\Throwable $e) {
                $from = $today->copy();
                $to   = $today->copy();
                $range = 'today';
            }
        } else {
            [$from, $to] = match ($range) {
                'week'    => [$today->copy()->subDays(6), $today->copy()],
                'month'   => [$today->copy()->startOfMonth(), $today->copy()],
                'last_30' => [$today->copy()->subDays(29), $today->copy()], // MARKER-PATCH-114
                default   => [$today->copy(), $today->copy()],
            };
        }

        $rangeLabel = match ($range) {
            'week'   => 'Last 7 days',
            'last_30' => 'Last 30 days',
            'month'  => $today->format('F'),
            'custom' => $from->isSameDay($to)
                ? $from->format('M j, Y')
                : $from->format('M j') . ' – ' . $to->format('M j, Y'),
            default  => 'Today',
        };

        return view('tenant.reports.index', [
            'tenant'      => $tenant,
            'range'       => $range,
            'range_label' => $rangeLabel,
            'from'        => $from,
            'to'          => $to,
            'kpis'        => $svc->topKpis(),
            'revenue'     => $svc->zoneRevenue($from, $to),
            'bookings'    => $svc->zoneBookings($from, $to),
            'customers'   => $svc->zoneCustomers($from, $to),
            'services'    => $svc->zoneServices($from, $to),
            'staff'       => $svc->zoneStaff($from, $to),
            'capacity'    => $svc->zoneCapacity($from, $to),
            'today_label' => $today->format('l, F j, Y'),
        ]);
    }

    /**
     * Customers tab — whole-database, NOT date-ranged.
     *
     * Three panels:
     *   - missing contact info (customers with no phone on file)
     *   - lapsed customers (no delivered appointment in 180d)
     *   - highest LTV (top customers by lifetime value)
     */
    public function customers(Request $request): View
    {
        $tenant = tenant();
        $svc = new CustomersReportService($tenant);

        // Gate: extended_reports capability gates the list data. Starter
        // tenants see real aggregate counts (cheap, not sensitive) with
        // blurred placeholder list rows and an upsell modal.
        $isLocked = !$tenant->extended_reports_enabled;

        return view('tenant.reports.customers', [
            'tenant'    => $tenant,
            'is_locked' => $isLocked,
            'missing'   => $svc->missingContactInfo($isLocked),
            'lapsed'    => $svc->lapsedCustomers($isLocked),
            'topLtv'    => $svc->highestLtv($isLocked),
        ]);
    }

    /**
     * Services tab — date-ranged service depth analytics.
     *
     * Five real panels (throughput, mix, parts attach, comebacks,
     * production by resource) + two stubs (mechanic productivity,
     * estimate accuracy) that surface "coming soon" until the
     * supporting schema lands.
     */
    public function services(Request $request): View
    {
        $tenant = tenant();
        $today = $tenant->localToday();

        $range = (string) $request->query('range', 'today');
        if (!in_array($range, ['today', 'week', 'month', 'last_30', 'custom'], true)) {
            $range = 'today';
        }

        if ($range === 'custom') {
            $fromStr = (string) $request->query('from', $today->toDateString());
            $toStr   = (string) $request->query('to',   $today->toDateString());
            try {
                $from = Carbon::parse($fromStr)->startOfDay();
                $to   = Carbon::parse($toStr)->startOfDay();
                if ($from->gt($to)) [$from, $to] = [$to, $from];
            } catch (\Throwable $e) {
                $from = $today->copy();
                $to   = $today->copy();
                $range = 'today';
            }
        } else {
            [$from, $to] = match ($range) {
                'week'    => [$today->copy()->subDays(6), $today->copy()],
                'month'   => [$today->copy()->startOfMonth(), $today->copy()],
                'last_30' => [$today->copy()->subDays(29), $today->copy()], // MARKER-PATCH-114
                default   => [$today->copy(), $today->copy()],
            };
        }

        $rangeLabel = match ($range) {
            'week'   => 'Last 7 days',
            'last_30' => 'Last 30 days',
            'month'  => $today->format('F'),
            'custom' => $from->isSameDay($to)
                ? $from->format('M j, Y')
                : $from->format('M j') . ' – ' . $to->format('M j, Y'),
            default  => 'Today',
        };

        $isLocked = !$tenant->extended_reports_enabled;
        $svc = new ServicesReportService($tenant);

        return view('tenant.reports.services', [
            'tenant'              => $tenant,
            'range'               => $range,
            'range_label'         => $rangeLabel,
            'from'                => $from,
            'to'                  => $to,
            'today_label'         => $today->format('l, F j, Y'),
            'is_locked'           => $isLocked,
            'throughput'          => $svc->throughput($from, $to, $isLocked),
            'serviceMix'          => $svc->serviceMix($from, $to, $isLocked),
            'partsAttach'         => $svc->partsAttach($from, $to, $isLocked),
            'comebacks'           => $svc->comebacks($from, $to, $isLocked),
            'productionByResource'=> $svc->productionByResource($from, $to, $isLocked),
            'mechanicProductivity'=> $svc->mechanicProductivity(),
            'estimateAccuracy'    => $svc->estimateAccuracy(),
        ]);
    }

    public function retail(Request $request): View
    {
        $tenant = tenant();
        $today = $tenant->localToday();
        $range = (string) $request->query('range', 'today');
        if (!in_array($range, ['today', 'week', 'month', 'last_30', 'custom'], true)) $range = 'today';
        if ($range === 'custom') {
            try {
                $from = Carbon::parse((string) $request->query('from', $today->toDateString()))->startOfDay();
                $to   = Carbon::parse((string) $request->query('to',   $today->toDateString()))->startOfDay();
                if ($from->gt($to)) [$from, $to] = [$to, $from];
            } catch (\Throwable $e) {
                $from = $today->copy(); $to = $today->copy(); $range = 'today';
            }
        } else {
            [$from, $to] = match ($range) {
                'week'    => [$today->copy()->subDays(6), $today->copy()],
                'month'   => [$today->copy()->startOfMonth(), $today->copy()],
                'last_30' => [$today->copy()->subDays(29), $today->copy()], // MARKER-PATCH-114
                default   => [$today->copy(), $today->copy()],
            };
        }
        $rangeLabel = match ($range) {
            'week'   => 'Last 7 days',
            'last_30' => 'Last 30 days',
            'month'  => $today->format('F'),
            'custom' => $from->isSameDay($to) ? $from->format('M j, Y') : $from->format('M j') . ' – ' . $to->format('M j, Y'),
            default  => 'Today',
        };
        $isLocked = !$tenant->extended_reports_enabled;
        $svc = new RetailReportService($tenant);

        return view('tenant.reports.retail', [
            'tenant'      => $tenant,
            'range'       => $range,
            'range_label' => $rangeLabel,
            'today_label' => $today->format('l, F j, Y'),
            'is_locked'   => $isLocked,
            'salesSummary'    => $svc->salesSummary($from, $to, $isLocked),
            'salesByUser'     => $svc->salesByUser($from, $to, $isLocked),
            'topSkus'         => $svc->topSkus($from, $to, $isLocked),
            'margin'          => $svc->margin($from, $to, $isLocked),
            'inventoryHealth' => $svc->inventoryHealth($isLocked),
            'receiving'       => $svc->receiving($from, $to, $isLocked),
        ]);
    }

    public function money(Request $request): View
    {
        $tenant = tenant();
        $today = $tenant->localToday();
        $range = (string) $request->query('range', 'today');
        if (!in_array($range, ['today', 'week', 'month', 'last_30', 'custom'], true)) $range = 'today';
        if ($range === 'custom') {
            try {
                $from = Carbon::parse((string) $request->query('from', $today->toDateString()))->startOfDay();
                $to   = Carbon::parse((string) $request->query('to',   $today->toDateString()))->startOfDay();
                if ($from->gt($to)) [$from, $to] = [$to, $from];
            } catch (\Throwable $e) {
                $from = $today->copy(); $to = $today->copy(); $range = 'today';
            }
        } else {
            [$from, $to] = match ($range) {
                'week'    => [$today->copy()->subDays(6), $today->copy()],
                'month'   => [$today->copy()->startOfMonth(), $today->copy()],
                'last_30' => [$today->copy()->subDays(29), $today->copy()], // MARKER-PATCH-114
                default   => [$today->copy(), $today->copy()],
            };
        }
        $rangeLabel = match ($range) {
            'week'   => 'Last 7 days',
            'last_30' => 'Last 30 days',
            'month'  => $today->format('F'),
            'custom' => $from->isSameDay($to) ? $from->format('M j, Y') : $from->format('M j') . ' – ' . $to->format('M j, Y'),
            default  => 'Today',
        };
        $isLocked = !$tenant->extended_reports_enabled;
        $svc = new MoneyReportService($tenant);

        return view('tenant.reports.money', [
            'tenant'      => $tenant,
            'range'       => $range,
            'range_label' => $rangeLabel,
            'today_label' => $today->format('l, F j, Y'),
            'is_locked'   => $isLocked,
            'revenueSummary' => $svc->revenueSummary($from, $to, $isLocked),
            'refunds'        => $svc->refunds($from, $to, $isLocked),
            'taxAndFees'     => $svc->taxAndFees($from, $to, $isLocked),
            'drawerAndTill'  => $svc->drawerAndTill(),
            'stripePayouts'  => $svc->stripePayouts(),
        ]);
    }

    /**
     * Traffic tab — site usage analytics over tenant_funnel_events.
     * Free for all tenants. Window: 7d / 30d (default) / 90d.
     * MARKER-PATCH-151A
     */
    public function traffic(Request $request): View
    {
        $tenant = tenant();

        // MARKER-PATCH-475 — a custom date range (shared calendar picker) wins over
        // the preset windows when both `from` and `to` are supplied and valid.
        $fromStr = trim((string) $request->query('from', ''));
        $toStr   = trim((string) $request->query('to', ''));

        $svc = null;
        if ($fromStr !== '' && $toStr !== '') {
            try {
                $svc = new \App\Services\Tenant\TrafficReportService($tenant, '30d', $fromStr, $toStr);
            } catch (\Throwable $e) {
                $svc     = null;
                $fromStr = '';
                $toStr   = '';
            }
        }

        $window = $request->query('window', '30d');
        if (!in_array($window, ['1d', '7d', '30d', '90d'], true)) {
            $window = '30d';
        }
        if ($svc === null) {
            $svc = new \App\Services\Tenant\TrafficReportService($tenant, $window);
        }

        $bf = $svc->bookingFunnelData();

        return view('tenant.reports.traffic', [
            'tenant'         => $tenant,
            'window'         => $svc->window(),
            'isCustom'       => $svc->isCustom(),
            'rangeText'      => $svc->rangeLabel(),
            'from'           => $fromStr,
            'to'             => $toStr,
            'topStats'       => $topStats = $svc->topStats(),
            'sessions'       => $topStats['sessions'] ?? [], // MARKER-SESSIONS-EXPLORER
            'dailyVisitors'  => $svc->dailyVisitors(),
            'dailyStart'     => $svc->curStart(),
            'topSearches'    => $svc->topSearches(),      // MARKER-PATCH-621
            'zeroSearches'   => $svc->zeroResultSearches(),
            'searchRules'    => \App\Models\Tenant\TenantSearchRule::where('tenant_id', tenant()->id)
                                    ->orderBy('type')->orderBy('from_term')->get(), // MARKER-PATCH-622
            // MARKER-PATCH-151B — additional panels
            'funnel'         => $bf['funnel'],
            'funnelDetail'   => $bf['detail'],
            'topSources'     => $svc->topSources(),
            'deviceSplit'    => $svc->deviceSplit(),
            'topPages'       => $svc->topPages(),
            'newVsReturning' => $svc->newVsReturning(),
        ]);
    }

    public function staff(Request $request): View
    {
        $tenant = tenant();
        $today = $tenant->localToday();
        $range = (string) $request->query('range', 'today');
        if (!in_array($range, ['today', 'week', 'month', 'last_30', 'custom'], true)) $range = 'today';
        if ($range === 'custom') {
            try {
                $from = Carbon::parse((string) $request->query('from', $today->toDateString()))->startOfDay();
                $to   = Carbon::parse((string) $request->query('to',   $today->toDateString()))->startOfDay();
                if ($from->gt($to)) [$from, $to] = [$to, $from];
            } catch (\Throwable $e) {
                $from = $today->copy(); $to = $today->copy(); $range = 'today';
            }
        } else {
            [$from, $to] = match ($range) {
                'week'    => [$today->copy()->subDays(6), $today->copy()],
                'month'   => [$today->copy()->startOfMonth(), $today->copy()],
                'last_30' => [$today->copy()->subDays(29), $today->copy()], // MARKER-PATCH-114
                default   => [$today->copy(), $today->copy()],
            };
        }
        $rangeLabel = match ($range) {
            'week'   => 'Last 7 days',
            'last_30' => 'Last 30 days',
            'month'  => $today->format('F'),
            'custom' => $from->isSameDay($to) ? $from->format('M j, Y') : $from->format('M j') . ' – ' . $to->format('M j, Y'),
            default  => 'Today',
        };
        $isLocked = !$tenant->extended_reports_enabled;
        $svc = new StaffReportService($tenant);

        return view('tenant.reports.staff', [
            'tenant'      => $tenant,
            'range'       => $range,
            'range_label' => $rangeLabel,
            'today_label' => $today->format('l, F j, Y'),
            'is_locked'   => $isLocked,
            'bookingDensity'  => $svc->bookingDensity($from, $to, $isLocked),
            'revenueByStaff'  => $svc->revenueByStaff($from, $to, $isLocked),
            'utilization'     => $svc->utilization(),
            'servicesByStaff' => $svc->servicesByStaff(),
            'tipsByStaff'     => $svc->tipsByStaff(),
        ]);
    }
}

SESSEXP_1_EOF

cat > 'resources/views/tenant/reports/traffic.blade.php' <<'SESSEXP_2_EOF'
@extends('layouts.tenant.app')
@section('title', 'Reports · Traffic')

{{-- MARKER-PATCH-151A — traffic reports tab --}}

@push('styles')
<style>
  /* Reuses .rep-* tokens from the other reports tabs. */
  .rep-h1 { font-size: 28px; font-weight: 700; letter-spacing: -0.02em; margin-bottom: 4px; }
  .rep-sub { color: var(--ia-text-dim, rgba(255,255,255,.42)); font-size: 13.5px; margin-bottom: 24px; }

  .rep-toggle { display: inline-flex; gap: 4px; background: rgba(255,255,255,0.02);
    border: 1px solid var(--ia-border); border-radius: 8px; padding: 3px; margin-bottom: 18px; }
  .rep-toggle a {
    padding: 7px 14px; font-size: 12.5px; font-weight: 600;
    color: var(--ia-text-dim, rgba(255,255,255,.42)); text-decoration: none; border-radius: 5px;
    transition: all .12s;
  }
  .rep-toggle a:hover { color: var(--ia-text); }
  .rep-toggle a.active { background: var(--ia-accent, #BEF264); color: var(--ia-accent-text, #0a0a0a); }

  .rep-zone {
    background: var(--ia-surface);
    border: 1px solid var(--ia-border);
    border-radius: 14px;
    padding: 22px;
    margin-bottom: 18px;
  }
  .rep-zone-head {
    display: flex; align-items: flex-start; justify-content: space-between;
    gap: 14px; flex-wrap: wrap; margin-bottom: 18px;
  }
  .rep-zone-title { font-size: 15px; font-weight: 700; letter-spacing: -0.01em; }
  .rep-zone-sub { font-size: 12px; color: var(--ia-text-dim, rgba(255,255,255,.42)); font-weight: 500; margin-top: 2px; }

  .rep-window {
    display: inline-flex; gap: 4px;
    background: var(--ia-surface-2); border: 1px solid var(--ia-border);
    border-radius: 6px; padding: 2px;
    font-size: 12px;
  }
  .rep-window a {
    padding: 4px 10px;
    color: var(--ia-text-dim, rgba(255,255,255,.42));
    text-decoration: none;
    border-radius: 4px;
  }
  .rep-window a.active {
    background: var(--ia-accent, #BEF264);
    color: var(--ia-accent-text, #0a0a0a);
    font-weight: 600;
  }
  .rep-window a:hover:not(.active) { color: var(--ia-text); }

  .rep-stat-strip {
    display: grid; grid-template-columns: repeat(4, 1fr); gap: 0;
    border-top: 0.5px solid var(--ia-border);
    border-bottom: 0.5px solid var(--ia-border);
    margin: 14px 0 0;
  }
  @media (max-width: 880px) {
    .rep-stat-strip { grid-template-columns: repeat(2, 1fr); }
  }
  .rep-stat-cell {
    padding: 16px 18px;
    border-right: 0.5px solid var(--ia-border);
  }
  .rep-stat-cell:last-child { border-right: none; }
  @media (max-width: 880px) {
    .rep-stat-cell:nth-child(2) { border-right: none; }
  }
  .rep-stat-cell .lbl {
    font-size: 10.5px; letter-spacing: 0.08em; text-transform: uppercase;
    color: var(--ia-text-dim, rgba(255,255,255,.42)); font-weight: 700; margin-bottom: 8px;
  }
  .rep-stat-cell .val {
    font-size: 24px; font-weight: 700; letter-spacing: -0.02em; line-height: 1;
    font-feature-settings: 'tnum';
  }
  .rep-stat-cell.feat .val { color: var(--ia-accent, #BEF264); }
  .rep-stat-cell .delta { font-size: 11px; margin-top: 6px; }
  .rep-stat-cell .delta.up   { color: var(--ia-ok,  #86EFAC); }
  .rep-stat-cell .delta.down { color: var(--ia-bad, #F87171); }
  .rep-stat-cell .delta.flat { color: var(--ia-text-dim, rgba(255,255,255,.42)); }

  .rep-chart {
    width: 100%;
    height: 180px;
    display: block;
    margin-top: 8px;
  }
  /* MARKER-PATCH-454 — chart hover */
  .rep-chart-wrap { position: relative; }
  .rep-chart-guide { position: absolute; top: 0; bottom: 0; width: 1px; background: rgba(255,255,255,.18); opacity: 0; pointer-events: none; transition: opacity .1s; }
  .rep-chart-dot { position: absolute; width: 8px; height: 8px; border-radius: 50%; background: #BEF264; box-shadow: 0 0 0 3px rgba(190,242,100,.2); transform: translate(-50%, -50%); opacity: 0; pointer-events: none; transition: opacity .1s; }
  .rep-chart-tip { position: absolute; transform: translate(-50%, calc(-100% - 12px)); background: #1B1B1F; border: .5px solid rgba(255,255,255,.14); border-radius: 8px; padding: 7px 10px; font-size: 12px; line-height: 1.3; white-space: nowrap; pointer-events: none; opacity: 0; transition: opacity .1s; z-index: 5; box-shadow: 0 8px 24px rgba(0,0,0,.45); }
  .rep-chart-tip .tip-d { font-weight: 600; margin-bottom: 2px; }
  .rep-chart-tip .tip-c { font-family: 'JetBrains Mono', ui-monospace, monospace; color: #BEF264; }
  .rep-chart-tip .tip-p { font-family: 'JetBrains Mono', ui-monospace, monospace; color: rgba(255,255,255,.5); font-size: 11px; }
  .rep-chart-wrap.show .rep-chart-guide,
  .rep-chart-wrap.show .rep-chart-dot,
  .rep-chart-wrap.show .rep-chart-tip { opacity: 1; }
  .rep-chart-legend {
    display: flex; gap: 20px;
    margin-top: 8px;
    font-size: 11px;
    color: var(--ia-text-dim, rgba(255,255,255,.42));
  }
  .rep-chart-legend i {
    display: inline-block;
    width: 14px; height: 2px;
    vertical-align: middle;
    margin-right: 6px;
  }

  .rep-empty {
    padding: 32px 20px;
    text-align: center;
    color: var(--ia-text-dim, rgba(255,255,255,.42));
    font-size: 13px;
    line-height: 1.6;
  }

  /* MARKER-PATCH-151B — panel-specific styles */
  .rep-two-col {
    display: grid;
    grid-template-columns: 1.4fr 1fr;
    gap: 18px;
    align-items: start;
  }
  @media (max-width: 980px) {
    .rep-two-col { grid-template-columns: 1fr; }
  }

  /* Funnel */
  .rep-funnel { padding: 6px 0; }
  .rep-funnel-step {
    display: grid;
    grid-template-columns: 200px 1fr 130px;
    gap: 14px;
    align-items: center;
    padding: 10px 0;
    border-bottom: 1px solid var(--ia-border);
  }
  .rep-funnel-step:last-of-type { border-bottom: none; }
  .rep-funnel-label { font-size: 13px; color: var(--ia-text-2, rgba(255,255,255,.78)); }
  .rep-funnel-bar-track {
    background: rgba(255,255,255,.04);
    border-radius: 4px;
    height: 22px;
    overflow: hidden;
  }
  .rep-funnel-bar {
    height: 100%;
    background: linear-gradient(90deg, var(--ia-accent, #BEF264), rgba(190,242,100,.55));
    border-radius: 4px;
    min-width: 4px;
  }
  .rep-funnel-count {
    text-align: right;
    font-family: 'JetBrains Mono', ui-monospace, monospace;
    font-size: 13px;
    font-feature-settings: 'tnum';
  }
  .rep-funnel-count small {
    color: var(--ia-text-dim, rgba(255,255,255,.42));
    font-size: 11px;
    margin-left: 2px;
  }
  .rep-funnel-drop {
    font-size: 11.5px;
    padding: 8px 12px;
    margin: 0;
    text-align: center;
    font-family: 'JetBrains Mono', ui-monospace, monospace;
    background: rgba(255,255,255,.02);
    border-bottom: 1px solid var(--ia-border);
  }
  .rep-funnel-drop:last-of-type { border-bottom: none; }
  .rep-funnel-drop-pct {
    color: var(--ia-bad, #F87171);
    font-weight: 600;
  }
  @media (max-width: 700px) {
    .rep-funnel-step { grid-template-columns: 130px 1fr 90px; }
  }

  /* Tables (shared with other reports) */
  table.rep-tbl { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 8px; }
  table.rep-tbl th {
    text-align: left; padding: 10px 12px;
    font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.08em;
    color: var(--ia-text-dim, rgba(255,255,255,.42)); font-weight: 700;
    border-bottom: 1px solid var(--ia-border);
  }
  table.rep-tbl th.right { text-align: right; }
  table.rep-tbl td {
    padding: 11px 12px;
    border-bottom: 1px solid var(--ia-border);
    vertical-align: top;
  }
  table.rep-tbl td.right { text-align: right; font-feature-settings: 'tnum'; font-weight: 600; }
  table.rep-tbl tr:last-child td { border-bottom: none; }
  table.rep-tbl tr:hover td { background: rgba(255,255,255,0.02); }
  .rep-cell-name { color: var(--ia-text, #f0f0f0); font-weight: 600; }
  .rep-cell-meta { color: var(--ia-text-dim, rgba(255,255,255,.42)); font-size: 11px; margin-top: 2px; }

  /* Device bars */
  .rep-bar-track {
    background: rgba(255,255,255,.05);
    border-radius: 99px;
    height: 6px;
    overflow: hidden;
    margin: 6px 0 2px;
  }
  .rep-bar-track > span {
    display: block;
    height: 100%;
    background: var(--ia-accent, #BEF264);
    border-radius: 99px;
  }

  /* MARKER-PATCH-151C — link-out panel CTAs */
  .rep-link-out { display: flex; flex-direction: column; }
  .rep-link-out p { flex: 1; }
  .rep-link-out-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    align-self: flex-start;
    padding: 9px 14px;
    font-size: 12.5px;
    font-weight: 600;
    color: var(--ia-accent, #BEF264);
    background: rgba(190, 242, 100, 0.08);
    border: 1px solid rgba(190, 242, 100, 0.2);
    border-radius: 8px;
    text-decoration: none;
    transition: background .12s ease, border-color .12s ease;
  }
  .rep-link-out-btn:hover {
    background: rgba(190, 242, 100, 0.14);
    border-color: rgba(190, 242, 100, 0.35);
  }
  .rep-link-out-btn--ghost {
    color: var(--ia-text-2, rgba(255, 255, 255, 0.78));
    background: rgba(255, 255, 255, 0.04);
    border-color: var(--ia-border);
  }
  .rep-link-out-btn--ghost:hover {
    background: rgba(255, 255, 255, 0.07);
    border-color: rgba(255, 255, 255, 0.16);
  }

  /* MARKER-PATCH-432 — range controls handled centrally in mobile-nav.css */
</style>
@endpush

@section('content')
{{-- MARKER-PATCH-164 — match the other reports tabs' padding wrapper --}}
<div style="padding: 32px 40px;">
  <h1 class="rep-h1">Reports</h1>
  <div class="rep-sub">How your business is performing.</div>

  {{-- MARKER-PATCH-431 — report picker + range share one row on phones --}}
  <div class="rep-controls">
    @include('tenant.reports._tab_subnav', ['active' => 'traffic'])

    {{-- Window switcher --}}
    <div class="rep-window-wrap" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; flex-wrap: wrap; gap: 10px;">
      <div class="rep-showing" style="font-size: 13px; color: var(--ia-text-dim, rgba(255,255,255,.42));">
        Showing <strong style="color: var(--ia-text);">{{ $rangeText ?? $window }}</strong> · compared to prior {{ $window }}
      </div>
      {{-- MARKER-PATCH-475 — preset windows + shared calendar picker for custom ranges --}}
      <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
        <div class="rep-window">
          <a href="?window=1d"  class="{{ (empty($isCustom) && $window === '1d')  ? 'active' : '' }}">Today</a>
          <a href="?window=7d"  class="{{ (empty($isCustom) && $window === '7d')  ? 'active' : '' }}"><span class="rw-lg">7 days</span><span class="rw-sm">7d</span></a>
          <a href="?window=30d" class="{{ (empty($isCustom) && $window === '30d') ? 'active' : '' }}"><span class="rw-lg">30 days</span><span class="rw-sm">30d</span></a>
          <a href="?window=90d" class="{{ (empty($isCustom) && $window === '90d') ? 'active' : '' }}"><span class="rw-lg">90 days</span><span class="rw-sm">90d</span></a>
        </div>
        <form method="GET" action="{{ route('tenant.reports.traffic') }}" style="margin: 0;">
          <x-tenant.date-range
            fromName="from"
            toName="to"
            :fromValue="$from ?? ''"
            :toValue="$to ?? ''"
            placeholder="Custom range" />
        </form>
      </div>
    </div>
  </div>

  {{-- No data state --}}
  @if(($topStats['visitors']['value'] ?? 0) === 0 && ($topStats['visitors']['prev'] ?? 0) === 0)
    <div class="rep-zone">
      <div class="rep-zone-head">
        <div>
          <div class="rep-zone-title">No traffic data yet</div>
          <div class="rep-zone-sub">Your traffic dashboard starts populating as soon as customers visit your public pages.</div>
        </div>
      </div>
      <div class="rep-empty">
        <div style="font-size: 32px; opacity: .35; margin-bottom: 8px;">📊</div>
        <div style="font-size: 14px; color: var(--ia-text); font-weight: 500; margin-bottom: 6px;">Nothing to show yet</div>
        <div style="max-width: 480px; margin: 0 auto;">
          Tracking starts as soon as someone visits your public booking page or storefront. Share your shop's link and check back in a few days.
        </div>
      </div>
    </div>
  @else

  {{-- Top stat strip + chart --}}
  <div class="rep-zone">
    <div class="rep-zone-head">
      <div>
        <div class="rep-zone-title">Site overview</div>
        <div class="rep-zone-sub">Last {{ $window }} vs prior {{ $window }}</div>
      </div>
    </div>

    <div class="rep-stat-strip">
      @foreach(['visitors', 'page_views', 'started', 'completed'] as $key)
        @php $s = $topStats[$key]; @endphp
        <div class="rep-stat-cell {{ $s['accent'] ? 'feat' : '' }}">
          <div class="lbl">{{ $s['label'] }}</div>
          <div class="val">{{ number_format($s['value']) }}</div>
          @if($s['delta'] !== null)
            @php
              $cls = $s['delta'] > 0 ? 'up' : ($s['delta'] < 0 ? 'down' : 'flat');
              $sign = $s['delta'] > 0 ? '+' : '';
            @endphp
            <div class="delta {{ $cls }}">{{ $sign }}{{ $s['delta'] }}% vs prior {{ $window }}</div>
          @elseif($s['value'] > 0)
            <div class="delta up">new this period</div>
          @else
            <div class="delta flat">no data</div>
          @endif
        </div>
      @endforeach
    </div>

    {{-- Daily visitors chart --}}
    <div style="margin-top: 22px;">
      @php $isHourly = (bool) ($dailyVisitors['hourly'] ?? false); /* MARKER-PATCH-619 */ @endphp
      <div style="font-size: 11px; text-transform: uppercase; letter-spacing: .08em; color: var(--ia-text-dim, rgba(255,255,255,.42)); font-weight: 700; margin-bottom: 6px;">
        {{ $isHourly ? 'Visitors by hour' : 'Daily visitors' }}
      </div>
      @php
        $cur = $dailyVisitors['current'];
        $prior = $dailyVisitors['prior'];
        $n = count($cur);
        $maxVal = max(max($cur ?: [0]), max($prior ?: [0]), 1);
        $vbW = 800; $vbH = 180; $padL = 4; $padR = 4; $padT = 10; $padB = 14;
        $w = $vbW - $padL - $padR;
        $h = $vbH - $padT - $padB;
        // Build SVG paths
        $stepX = $n > 1 ? ($w / ($n - 1)) : 0;
        $pathCur = ''; $pathPrior = '';
        foreach ($cur as $i => $v) {
            $x = $padL + ($i * $stepX);
            $y = $padT + $h - (($v / $maxVal) * $h);
            $pathCur .= ($i === 0 ? 'M ' : 'L ') . round($x, 1) . ' ' . round($y, 1) . ' ';
        }
        foreach ($prior as $i => $v) {
            $x = $padL + ($i * $stepX);
            $y = $padT + $h - (($v / $maxVal) * $h);
            $pathPrior .= ($i === 0 ? 'M ' : 'L ') . round($x, 1) . ' ' . round($y, 1) . ' ';
        }
        // Grid lines at 0, 50%, 100%
        $gridYs = [
          $padT + $h,                     // 0
          $padT + ($h / 2),               // 50%
          $padT,                          // top
        ];
        // MARKER-PATCH-454 — per-point data for hover tooltips
        $points = [];
        foreach ($cur as $i => $v) {
            // MARKER-PATCH-619 — hour labels for single-day windows (tenant-local)
            if ($isHourly) {
                $label = isset($dailyStart) ? tlocal($dailyStart->addHours($i), 'g A') : ('Hour ' . $i);
            } else {
                $label = isset($dailyStart) ? $dailyStart->addDays($i)->format('M j') : ('Day ' . ($i + 1));
            }
            $xPct  = (($padL + $i * $stepX) / $vbW) * 100;
            $yv    = $padT + $h - (($v / $maxVal) * $h);
            $points[] = ['l' => $label, 'c' => (int) $v, 'p' => (int) ($prior[$i] ?? 0), 'x' => round($xPct, 2), 'y' => round(($yv / $vbH) * 100, 2)];
        }
      @endphp
      <div class="rep-chart-wrap" id="rep-chart-wrap">
      <svg class="rep-chart" viewBox="0 0 {{ $vbW }} {{ $vbH }}" preserveAspectRatio="none" aria-label="{{ $isHourly ? 'Hourly' : 'Daily' }} visitors line chart">
        {{-- Grid --}}
        @foreach($gridYs as $gy)
          <line x1="0" y1="{{ $gy }}" x2="{{ $vbW }}" y2="{{ $gy }}" stroke="rgba(255,255,255,.04)" stroke-width="1" />
        @endforeach
        {{-- Prior period (muted, dashed) --}}
        <path d="{{ $pathPrior }}" stroke="rgba(255,255,255,.32)" stroke-width="1.2" fill="none" stroke-dasharray="3 3" stroke-linecap="round" stroke-linejoin="round" />
        {{-- Current period (lime, solid) --}}
        <path d="{{ $pathCur }}" stroke="#BEF264" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" />
      </svg>
      <div class="rep-chart-guide" id="rep-chart-guide"></div>
      <div class="rep-chart-dot" id="rep-chart-dot"></div>
      <div class="rep-chart-tip" id="rep-chart-tip"><div class="tip-d"></div><div class="tip-c"></div><div class="tip-p"></div></div>
      </div>{{-- /rep-chart-wrap --}}
      <script>
      (function(){
        var pts = @json($points ?? []);
        var wrap = document.getElementById('rep-chart-wrap');
        if(!wrap || !pts.length) return;
        var guide = document.getElementById('rep-chart-guide');
        var dot = document.getElementById('rep-chart-dot');
        var tip = document.getElementById('rep-chart-tip');
        var tipD = tip.querySelector('.tip-d'), tipC = tip.querySelector('.tip-c'), tipP = tip.querySelector('.tip-p');
        function show(i){
          var p = pts[i]; if(!p) return;
          wrap.classList.add('show');
          guide.style.left = p.x + '%';
          dot.style.left = p.x + '%'; dot.style.top = p.y + '%';
          tip.style.left = p.x + '%'; tip.style.top = p.y + '%';
          tipD.textContent = p.l;
          tipC.textContent = p.c + (p.c === 1 ? ' visitor' : ' visitors');
          tipP.textContent = 'prior: ' + p.p;
        }
        function nearest(clientX){
          var r = wrap.getBoundingClientRect();
          var frac = (clientX - r.left) / r.width;
          var i = Math.round(frac * (pts.length - 1));
          return i < 0 ? 0 : (i > pts.length - 1 ? pts.length - 1 : i);
        }
        wrap.addEventListener('mousemove', function(e){ show(nearest(e.clientX)); });
        wrap.addEventListener('mouseleave', function(){ wrap.classList.remove('show'); });
        wrap.addEventListener('touchstart', function(e){ show(nearest(e.touches[0].clientX)); }, {passive:true});
      })();
      </script>
      <div class="rep-chart-legend">
        <span><i style="background: #BEF264;"></i> {{ $isHourly ? 'Today' : 'Last ' . $window }} · peak {{ number_format(max($cur ?: [0])) }}{{ $isHourly ? '/hr' : '/day' }}</span>
        <span><i style="background: rgba(255,255,255,.32);"></i> {{ $isHourly ? 'Yesterday' : 'Prior ' . $window }} · peak {{ number_format(max($prior ?: [0])) }}{{ $isHourly ? '/hr' : '/day' }}</span>
      </div>
    </div>
  </div>

  {{-- MARKER-PATCH-151B — full panel set --}}

  {{-- Booking funnel --}}
  <div class="rep-zone">
    <div class="rep-zone-head">
      <div>
        <div class="rep-zone-title">Booking funnel</div>
        <div class="rep-zone-sub">Visitors → completed bookings · last {{ $window }}</div>
      </div>
      <div style="text-align: right;">
        <div style="font-size: 10.5px; text-transform: uppercase; letter-spacing: .08em; color: var(--ia-text-dim, rgba(255,255,255,.42)); font-weight: 700;">Overall conversion</div>
        <div style="font-size: 22px; font-weight: 700; color: var(--ia-accent, #BEF264);">{{ $funnel['overall_pct'] }}%</div>
      </div>
    </div>

    @php
      // MARKER-PATCH-151B-FIX1 — max() of step counts, with 1 as floor
      $stepCounts = array_map(fn ($s) => (int) $s['count'], $funnel['steps']);
      $maxFunnel = !empty($stepCounts) ? max($stepCounts) : 0;
      $maxFunnel = max($maxFunnel, 1);
    @endphp

    <div class="rep-funnel">
      @foreach($funnel['steps'] as $i => $step)
        <div class="rep-funnel-step rep-fn-click" data-fi="{{ $i }}" onclick="repSelStep({{ $i }})">
          <div class="rep-funnel-label">{{ $step['label'] }}</div>
          <div class="rep-funnel-bar-track">
            <div class="rep-funnel-bar" style="width: {{ max(2, ($step['count'] / $maxFunnel) * 100) }}%;"></div>
          </div>
          <div class="rep-funnel-count">
            <strong>{{ number_format($step['count']) }}</strong>
            <small>· {{ $step['pct'] }}%</small>
          </div>
        </div>
        @if(isset($funnel['dropoffs'][$i]))
          <div class="rep-funnel-drop">
            <span class="rep-funnel-drop-pct">↓ {{ $funnel['dropoffs'][$i]['pct'] }}% drop-off</span>
            <span style="color: var(--ia-text-dim, rgba(255,255,255,.42));">· {{ number_format($funnel['dropoffs'][$i]['lost']) }} {{ Str::lower($funnel['dropoffs'][$i]['from']) }}</span>
          </div>
        @endif
      @endforeach
    </div>
  </div>

  {{-- MARKER-SESSIONS-EXPLORER — per-session booking activity --}}
  <style>
    .rse-scroll{max-height:430px;overflow-y:auto;border:.5px solid var(--ia-border);border-radius:12px;background:rgba(0,0,0,.18)}
    .rse-row{display:flex;align-items:center;gap:13px;padding:12px 15px;border-bottom:.5px solid rgba(255,255,255,.05);cursor:pointer;flex-wrap:wrap}
    .rse-row:hover{background:rgba(255,255,255,.03)}
    .rse-time{width:82px;flex:none;font-size:12.5px;font-weight:700}
    .rse-time span{display:block;font-size:10.5px;font-weight:400;color:var(--ia-text-dim,rgba(255,255,255,.42))}
    .rse-entry{font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;border-radius:100px;padding:4px 9px;flex:none}
    .rse-entry.choice{background:rgba(190,242,100,.09);color:var(--ia-lime,#BEF264);border:.5px solid rgba(190,242,100,.3)}
    .rse-entry.direct{background:rgba(245,197,107,.09);color:#F5C56B;border:.5px solid rgba(245,197,107,.32)}
    .rse-prog{display:flex;gap:4px;align-items:center;flex:1;min-width:170px}
    .rse-p{width:20px;height:5px;border-radius:100px;background:rgba(255,255,255,.08)}
    .rse-p.done{background:var(--ia-lime,#BEF264)}
    .rse-p.drop{background:#F09595}
    .rse-lbl{font-size:11px;color:var(--ia-text-dim,rgba(255,255,255,.5));margin-left:7px;white-space:nowrap}
    .rse-dev{color:var(--ia-text-dim,rgba(255,255,255,.42));font-size:11px;flex:none;width:52px;text-align:right}
    .rse-status{flex:none;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;border-radius:100px;padding:4px 10px}
    .rse-status.booked{background:rgba(127,217,143,.11);color:#7FD98F;border:.5px solid rgba(127,217,143,.32)}
    .rse-status.dropped{background:rgba(240,149,149,.09);color:#F09595;border:.5px solid rgba(240,149,149,.28)}
    .rse-status.active{background:rgba(190,242,100,.09);color:var(--ia-lime,#BEF264);border:.5px solid rgba(190,242,100,.32)}
    .rse-detail{display:none;flex-basis:100%;background:rgba(0,0,0,.25);border:.5px solid var(--ia-border);border-radius:10px;margin-top:9px;padding:12px 15px}
    .rse-row.open .rse-detail{display:block}
    .rse-dmeta{display:flex;gap:16px;flex-wrap:wrap;font-size:11px;color:var(--ia-text-dim,rgba(255,255,255,.42));margin-bottom:9px;border-bottom:.5px solid rgba(255,255,255,.06);padding-bottom:9px}
    .rse-ev{display:flex;gap:11px;font-size:12px;padding:4px 0;align-items:baseline}
    .rse-ev .t{width:76px;flex:none;color:var(--ia-text-dim,rgba(255,255,255,.42));font-size:11px}
    .rse-ev .w{color:var(--ia-text-2,rgba(255,255,255,.72))}
    .rse-filters{display:flex;gap:7px;margin:12px 0}
    .rse-chip{font-size:11.5px;font-weight:600;border:.5px solid var(--ia-border);border-radius:100px;padding:5px 12px;color:var(--ia-text-2,rgba(255,255,255,.6));cursor:pointer}
    .rse-chip.on{background:var(--ia-lime,#BEF264);color:#0B0B0B;border-color:var(--ia-lime,#BEF264);font-weight:700}
  </style>
  <div class="rep-zone">
    <div class="rep-zone-head">
      <div>
        <div class="rep-zone-title">Booking sessions</div>
        <div class="rep-zone-sub">Every session that entered the booking flow · newest first · times in {{ tenant()->timezone ?? config('app.timezone') }}</div>
      </div>
    </div>
    <div class="rse-filters" id="rseFilters">
      <span class="rse-chip on" data-f="all">All ({{ count($sessions) }})</span>
      <span class="rse-chip" data-f="booked">Booked</span>
      <span class="rse-chip" data-f="dropped">Dropped</span>
      <span class="rse-chip" data-f="active">Active now</span>
    </div>
    <div class="rse-scroll">
      @forelse($sessions as $sess)
        <div class="rse-row" data-status="{{ $sess['status'] }}" onclick="this.classList.toggle('open')">
          <span class="rse-time">{{ $sess['time'] }}<span>{{ $sess['day'] }}</span></span>
          <span class="rse-entry {{ $sess['entry'] }}">{{ $sess['entry'] === 'choice' ? 'via choice page' : 'direct entry' }}</span>
          <span class="rse-prog">
            @for($i = 0; $i < $sess['step_count']; $i++)
              <span class="rse-p {{ $i < $sess['furthest'] ? ($i === $sess['furthest'] - 1 && $sess['status'] === 'dropped' ? 'drop' : 'done') : '' }}"></span>
            @endfor
            <span class="rse-lbl">
              @if($sess['status'] === 'booked') completed
              @elseif($sess['last_step']) {{ $sess['status'] === 'active' ? 'on' : 'left at' }} {{ Str::limit($sess['last_step'], 28) }}
              @else entered only @endif
            </span>
          </span>
          <span class="rse-dev">{{ $sess['device'] ?? '' }}</span>
          <span class="rse-status {{ $sess['status'] }}">{{ $sess['status'] === 'active' ? 'active now' : $sess['status'] }}</span>
          <div class="rse-detail" onclick="event.stopPropagation()">
            <div class="rse-dmeta">
              <span>Session {{ $sess['session'] }}</span>
              @if($sess['referrer'])<span>From {{ $sess['referrer'] }}</span>@endif
              <span>Duration {{ $sess['duration'] }}</span>
            </div>
            @foreach($sess['timeline'] as $ev)
              <div class="rse-ev"><span class="t">{{ $ev['at'] }}</span><span class="w">{{ $ev['what'] }}</span></div>
            @endforeach
          </div>
        </div>
      @empty
        <div style="padding:22px;text-align:center;font-size:13px;color:var(--ia-text-dim,rgba(255,255,255,.42))">No booking sessions in this window yet.</div>
      @endforelse
    </div>
  </div>
  <script>
    (function () {
      var wrap = document.getElementById('rseFilters');
      if (!wrap) return;
      wrap.addEventListener('click', function (e) {
        var chip = e.target.closest('.rse-chip');
        if (!chip) return;
        wrap.querySelectorAll('.rse-chip').forEach(function (c) { c.classList.remove('on'); });
        chip.classList.add('on');
        var f = chip.getAttribute('data-f');
        document.querySelectorAll('.rse-row').forEach(function (r) {
          r.style.display = (f === 'all' || r.getAttribute('data-status') === f) ? 'flex' : 'none';
        });
      });
    })();
  </script>

  {{-- MARKER-PATCH-453 — per-step drop diagnosis --}}
  <style>
   .rep-seg-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:4px}
   .rep-seg{background:rgba(255,255,255,.03);border:.5px solid var(--ia-border);border-radius:10px;padding:13px 15px}
   .rep-seg-t{font-size:10.5px;text-transform:uppercase;letter-spacing:.07em;color:var(--ia-text-dim,rgba(255,255,255,.42));margin-bottom:11px;font-weight:700}
   .rep-seg-row{display:grid;grid-template-columns:78px 1fr 38px;align-items:center;gap:9px;margin-bottom:9px}
   .rep-seg-row:last-child{margin-bottom:0}
   .rep-seg-k{font-size:12px;color:var(--ia-text-2,rgba(255,255,255,.78))}
   .rep-seg-bar{height:6px;background:rgba(255,255,255,.06);border-radius:3px;overflow:hidden}
   .rep-seg-bar i{display:block;height:100%;background:rgba(255,255,255,.4);border-radius:3px}
   .rep-seg-bar.hot i{background:#E0A23B}
   .rep-seg-v{font-family:'JetBrains Mono',ui-monospace,monospace;font-size:11px;color:var(--ia-text-dim,rgba(255,255,255,.42));text-align:right}
   .rep-diag-sum{font-size:14px;margin-bottom:14px}
   .rep-diag-sum b{font-family:'JetBrains Mono',ui-monospace,monospace;color:var(--ia-accent,#BEF264);font-weight:600}
   .rep-diag-sum b.s{color:var(--ia-text)}
   .rep-ins{display:flex;gap:9px;align-items:flex-start;background:rgba(224,162,59,.12);border:.5px solid rgba(224,162,59,.4);border-radius:10px;padding:10px 13px;margin-top:14px;font-size:12.5px;line-height:1.45;color:var(--ia-text-2,rgba(255,255,255,.82))}
   .rep-ins svg{flex:none;margin-top:1px;color:#E0A23B;width:15px;height:15px}
   .rep-diag-foot{margin-top:14px}
   .rep-diag-foot a{color:var(--ia-accent,#BEF264);font-size:12.5px;text-decoration:none}
   .rep-diag-foot a:hover{text-decoration:underline}
   .rep-fn-click{cursor:pointer}
   .rep-fn-click:hover{background:rgba(255,255,255,.03)}
   .rep-fn-click.on{background:rgba(190,242,100,.07)}
  </style>

  <div class="rep-zone">
    <div class="rep-zone-head">
      <div>
        <div class="rep-zone-title">Step diagnosis</div>
        <div class="rep-zone-sub">Click a funnel step above — see who left there and why</div>
      </div>
    </div>
    <div id="rep-diag"></div>
  </div>

  <script>
  (function(){
    var DETAIL = @json($funnelDetail ?? []);
    var box = document.getElementById('rep-diag');
    if (!box) return;
    if (!DETAIL.length) { box.innerHTML = '<div style="color:rgba(255,255,255,.42);font-size:13px;padding:6px 0">No step data yet — this fills in as people move through booking.</div>'; return; }

    var ICON_WARN = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';

    function seg(title, rows, hot){
      var r = (rows||[]).map(function(row,i){
        var h = (hot && i===0) ? ' hot' : '';
        return '<div class="rep-seg-row"><span class="rep-seg-k">'+row.k+'</span><span class="rep-seg-bar'+h+'"><i style="width:'+row.pct+'%"></i></span><span class="rep-seg-v">'+row.pct+'%</span></div>';
      }).join('');
      return '<div class="rep-seg"><div class="rep-seg-t">'+title+'</div>'+(r||'<div class="rep-seg-v">—</div>')+'</div>';
    }

    function render(i){
      var d = DETAIL[i];
      if(!d) return;
      var hot = !!d.insight;
      var word = d.left===1 ? 'session' : 'sessions';
      var html = '<div class="rep-diag-sum"><b>'+d.left+'</b> '+word+' reached <b class="s">'+d.label+'</b> and left here</div>';
      html += '<div class="rep-seg-grid">'+seg('By device', d.device, hot)+seg('By source', d.source, false)+seg('New vs returning', d.newret, false)+'</div>';
      if(d.insight){ html += '<div class="rep-ins">'+ICON_WARN+'<span>'+d.insight+'</span></div>'; }
      html += '<div class="rep-diag-foot"><a href="/recovery">Follow up with people who left contact info →</a></div>';
      box.innerHTML = html;
      document.querySelectorAll('.rep-fn-click').forEach(function(el){ el.classList.toggle('on', (+el.dataset.fi) === i); });
    }

    var def = 0, best = -1;
    DETAIL.forEach(function(d,i){ if(i>=1 && d.left > best){ best = d.left; def = i; } });
    if(best < 0){ DETAIL.forEach(function(d,i){ if(d.left > best){ best = d.left; def = i; } }); }

    window.repSelStep = render;
    render(def);
  })();
  </script>


  {{-- MARKER-PATCH-621 — shop search analytics: top + zero-result searches --}}
  @if(!empty($topSearches) || !empty($zeroSearches))
  <div class="rep-two-col">
    <div class="rep-zone">
      <div class="rep-zone-head">
        <div>
          <div class="rep-zone-title">Top shop searches</div>
          <div class="rep-zone-sub">What customers look for · avg results per search</div>
        </div>
      </div>
      @forelse($topSearches as $ts)
        <div style="display:flex;justify-content:space-between;align-items:center;padding:9px 0;border-bottom:.5px dashed var(--ia-border);font-size:12.5px">
          <span>{{ $ts['q'] }}</span>
          <span style="color:var(--ia-text-muted);font-variant-numeric:tabular-nums">{{ $ts['n'] }} · {{ $ts['avg'] }} results avg</span>
        </div>
      @empty
        <div style="padding:16px 0;color:var(--ia-text-muted);font-size:12px">No shop searches in this range yet.</div>
      @endforelse
    </div>
    <div class="rep-zone">
      <div class="rep-zone-head">
        <div>
          <div class="rep-zone-title">Zero-result searches</div>
          <div class="rep-zone-sub">Customers wanted these and found nothing — stocking or naming gaps</div>
        </div>
      </div>
      @forelse($zeroSearches as $zs)
        <div style="display:flex;justify-content:space-between;align-items:center;padding:9px 0;border-bottom:.5px dashed var(--ia-border);font-size:12.5px">
          <span style="color:#f87171">{{ $zs['q'] }}</span>
          <span style="color:var(--ia-text-muted);font-variant-numeric:tabular-nums">{{ $zs['n'] }} search{{ $zs['n'] > 1 ? 'es' : '' }} · 0
            <button type="button" class="rep-rule-act" onclick="repRulePrefill('synonym', @js($zs['q']))">+ synonym</button>
            <button type="button" class="rep-rule-act" onclick="repRulePrefill('redirect', @js($zs['q']))">+ redirect</button>
          </span>
        </div>
      @empty
        <div style="padding:16px 0;color:var(--ia-text-muted);font-size:12px">None — every search found something.</div>
      @endforelse
    </div>
  </div>
  @endif

  {{-- MARKER-PATCH-622 — Search rules: synonyms + redirects, managed here --}}
  <div class="rep-zone" id="rep-search-rules">
    <div class="rep-zone-head">
      <div>
        <div class="rep-zone-title">Search rules</div>
        <div class="rep-zone-sub">Synonyms and redirects applied instantly to the shop search</div>
      </div>
    </div>

    <form method="POST" action="{{ route('tenant.reports.search-rules.store') }}" id="rep-rule-form"
          style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;padding:4px 0 14px;border-bottom:.5px solid var(--ia-border)">
      @csrf
      <select name="type" id="rep-rule-type" class="rep-rule-inp" onchange="repRuleMode()">
        <option value="synonym">Synonym</option>
        <option value="redirect">Redirect</option>
      </select>
      <input type="text" name="from_term" id="rep-rule-from" required maxlength="120" class="rep-rule-inp" placeholder="customers type…">
      <span style="color:var(--ia-text-muted);font-size:12px" id="rep-rule-arrow">=</span>
      <input type="text" name="to_value" id="rep-rule-to" required maxlength="300" class="rep-rule-inp" placeholder="means…">
      <input type="text" name="label" id="rep-rule-label" maxlength="120" class="rep-rule-inp" placeholder="link label (optional)" style="display:none">
      <button type="submit" class="rep-rule-act" style="border-color:var(--ia-accent);color:var(--ia-accent)">Add rule</button>
    </form>

    @forelse($searchRules as $rule)
      <div style="display:flex;justify-content:space-between;align-items:center;padding:9px 0;border-bottom:.5px dashed var(--ia-border);font-size:12.5px">
        <span>
          <span style="font-size:9.5px;text-transform:uppercase;letter-spacing:.05em;color:var(--ia-text-muted);margin-right:8px">{{ $rule->type }}</span>
          "{{ $rule->from_term }}" <span style="color:var(--ia-text-muted)">{{ $rule->type === 'synonym' ? '=' : '→' }}</span> {{ $rule->to_value }}
        </span>
        <span style="color:var(--ia-text-muted);font-variant-numeric:tabular-nums">
          @if($rule->type === 'redirect'){{ $rule->hits }} use{{ $rule->hits === 1 ? '' : 's' }} · @endif
          <form method="POST" action="{{ route('tenant.reports.search-rules.delete', $rule->id) }}" style="display:inline">
            @csrf<button type="submit" class="rep-rule-act" onclick="return confirm('Remove this rule?')">×</button>
          </form>
        </span>
      </div>
    @empty
      <div style="padding:14px 0;color:var(--ia-text-muted);font-size:12px">No custom rules yet — bike-domain synonyms (mtb = mountain, derailer = derailleur…) are built in. Add redirects for queries like "financing" or "gift card".</div>
    @endforelse
  </div>

  <style>
    .rep-rule-act { font-size:10.5px;border:.5px solid var(--ia-border-2,rgba(255,255,255,.2));border-radius:999px;padding:2px 9px;cursor:pointer;color:var(--ia-text-muted);background:none;margin-left:5px; }
    .rep-rule-act:hover { border-color:var(--ia-accent);color:var(--ia-text); }
    .rep-rule-inp { background:var(--ia-surface-2,#1a1a1a);border:1px solid var(--ia-border);color:var(--ia-text);border-radius:7px;padding:7px 10px;font-size:12px; }
  </style>
  <script>
    function repRuleMode() {
      var t = document.getElementById('rep-rule-type').value;
      document.getElementById('rep-rule-arrow').textContent = t === 'synonym' ? '=' : '→';
      document.getElementById('rep-rule-to').placeholder = t === 'synonym' ? 'means…' : '/page-url';
      document.getElementById('rep-rule-label').style.display = t === 'redirect' ? '' : 'none';
    }
    function repRulePrefill(type, q) {
      document.getElementById('rep-rule-type').value = type; repRuleMode();
      document.getElementById('rep-rule-from').value = q;
      document.getElementById('rep-rule-to').focus();
      document.getElementById('rep-search-rules').scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  </script>

  {{-- Two-column row: sources + devices --}}
  <div class="rep-two-col">
    {{-- Top sources --}}
    <div class="rep-zone">
      <div class="rep-zone-head">
        <div>
          <div class="rep-zone-title">Top sources</div>
          <div class="rep-zone-sub">Where your visitors came from</div>
        </div>
      </div>

      @if(empty($topSources))
        <div class="rep-empty">No source data yet.</div>
      @else
        <table class="rep-tbl">
          <thead><tr><th>Source</th><th class="right">Visits</th><th class="right">Conv.</th></tr></thead>
          <tbody>
            @foreach($topSources as $src)
              <tr>
                <td>
                  <div class="rep-cell-name">{{ $src['name'] === '(direct)' ? 'Direct' : $src['name'] }}</div>
                  @if($src['name'] === '(direct)')
                    <div class="rep-cell-meta">Typed URL or bookmark</div>
                  @endif
                </td>
                <td class="right">{{ number_format($src['visits']) }}</td>
                <td class="right" style="color: {{ $src['conv_pct'] >= 5 ? 'var(--ia-accent, #BEF264)' : 'var(--ia-text-dim, rgba(255,255,255,.42))' }};">
                  {{ $src['conv_pct'] }}%
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      @endif
    </div>

    {{-- Devices --}}
    <div class="rep-zone">
      <div class="rep-zone-head">
        <div>
          <div class="rep-zone-title">Devices</div>
          <div class="rep-zone-sub">How visitors browse your site</div>
        </div>
      </div>

      @if(empty($deviceSplit))
        <div class="rep-empty">No device data yet.</div>
      @else
        <div style="padding: 6px 0;">
          @foreach($deviceSplit as $d)
            <div style="font-size: 12.5px; margin-bottom: 14px;">
              <div style="display: flex; justify-content: space-between; align-items: baseline;">
                <span class="rep-cell-name">{{ ucfirst($d['device']) }}</span>
                <span style="color: var(--ia-text-dim, rgba(255,255,255,.42)); font-size: 11.5px; font-family: 'JetBrains Mono', ui-monospace, monospace;">
                  {{ number_format($d['count']) }} {{ Str::plural('visitor', $d['count']) }}
                </span>
              </div>
              <div class="rep-bar-track"><span style="width: {{ $d['pct'] }}%;"></span></div>
              <div style="font-size: 11px; color: var(--ia-text-dim, rgba(255,255,255,.42)); margin-top: 2px;">{{ $d['pct'] }}%</div>
            </div>
          @endforeach
        </div>
      @endif
    </div>
  </div>

  {{-- Top pages (full width) --}}
  <div class="rep-zone">
    <div class="rep-zone-head">
      <div>
        <div class="rep-zone-title">Top pages</div>
        <div class="rep-zone-sub">Most-visited paths · last {{ $window }}</div>
      </div>
    </div>

    @if(empty($topPages))
      <div class="rep-empty">No page-view data yet.</div>
    @else
      <table class="rep-tbl">
        <thead><tr><th>Page</th><th class="right">Views</th><th class="right">Unique</th></tr></thead>
        <tbody>
          @foreach($topPages as $page)
            <tr>
              <td>
                <div class="rep-cell-name" style="font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: 12.5px;">{{ $page['path'] }}</div>
              </td>
              <td class="right">{{ number_format($page['views']) }}</td>
              <td class="right" style="color: var(--ia-text-dim, rgba(255,255,255,.42));">{{ number_format($page['unique_visitors']) }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>

  {{-- New vs returning (full width) --}}
  <div class="rep-zone">
    <div class="rep-zone-head">
      <div>
        <div class="rep-zone-title">New vs returning</div>
        <div class="rep-zone-sub">Are you reaching new people, or retaining existing customers?</div>
      </div>
    </div>

    <div class="rep-stat-strip" style="grid-template-columns: 1fr 1fr;">
      <div class="rep-stat-cell">
        <div class="lbl">New visitors</div>
        <div class="val">{{ number_format($newVsReturning['new']['count']) }}</div>
        <div class="delta flat">{{ $newVsReturning['new']['pct'] }}% of total · {{ $newVsReturning['new']['conv_pct'] }}% booking rate</div>
      </div>
      <div class="rep-stat-cell feat">
        <div class="lbl">Returning</div>
        <div class="val">{{ number_format($newVsReturning['returning']['count']) }}</div>
        <div class="delta flat">{{ $newVsReturning['returning']['pct'] }}% of total · {{ $newVsReturning['returning']['conv_pct'] }}% booking rate</div>
      </div>
    </div>

    @if($newVsReturning['returning']['conv_pct'] > 0 && $newVsReturning['new']['conv_pct'] > 0)
      @php
        $ratio = $newVsReturning['new']['conv_pct'] > 0 ? round($newVsReturning['returning']['conv_pct'] / $newVsReturning['new']['conv_pct'], 1) : 0;
      @endphp
      @if($ratio >= 1.5)
        <div style="margin-top: 16px; padding: 12px 14px; background: rgba(190,242,100,.06); border-radius: 8px; font-size: 12.5px; line-height: 1.6; color: var(--ia-text-2, rgba(255,255,255,.78));">
          Returning visitors book at <strong style="color: var(--ia-accent, #BEF264);">{{ $newVsReturning['returning']['conv_pct'] }}%</strong> vs new at <strong>{{ $newVsReturning['new']['conv_pct'] }}%</strong> — about <strong>{{ $ratio }}×</strong> higher. Retention (email, follow-up) is paying off.
        </div>
      @endif
    @endif
  </div>

  {{-- MARKER-PATCH-151C — link-out panels for data we deliberately don't track --}}
  <div class="rep-two-col">
    {{-- Top search terms — Search Console link --}}
    <div class="rep-zone rep-link-out">
      <div class="rep-zone-head">
        <div>
          <div class="rep-zone-title">Top search terms</div>
          <div class="rep-zone-sub">What people searched before finding you</div>
        </div>
      </div>
      <p style="font-size: 12.5px; line-height: 1.6; color: var(--ia-text-2, rgba(255,255,255,.78)); margin: 0 0 16px;">
        We don't track search referrer query strings — Google strips them from referrer headers for privacy, and accurate search-term data is only available through Search Console.
      </p>
      <a href="https://search.google.com/search-console" target="_blank" rel="noopener noreferrer" class="rep-link-out-btn">
        Open Search Console
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M7 17L17 7M7 7h10v10"/>
        </svg>
      </a>
    </div>

    {{-- Top locations — GA-4 link --}}
    <div class="rep-zone rep-link-out">
      <div class="rep-zone-head">
        <div>
          <div class="rep-zone-title">Top locations</div>
          <div class="rep-zone-sub">Where your visitors are based</div>
        </div>
      </div>
      <p style="font-size: 12.5px; line-height: 1.6; color: var(--ia-text-2, rgba(255,255,255,.78)); margin: 0 0 16px;">
        We deliberately don't store IP addresses or geolocate visitors. If you've connected GA-4 in Settings &rarr; Communication, Google Analytics breaks visits down by country, region, and city.
      </p>
      @if(!empty($tenant->settings['analytics_ga4_id'] ?? null))
        <a href="https://analytics.google.com" target="_blank" rel="noopener noreferrer" class="rep-link-out-btn">
          Open GA-4 ({{ $tenant->settings['analytics_ga4_id'] }})
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M7 17L17 7M7 7h10v10"/>
          </svg>
        </a>
      @else
        <a href="{{ route('tenant.settings.index') }}#communication" class="rep-link-out-btn rep-link-out-btn--ghost">
          Connect Google Analytics &rarr;
        </a>
      @endif
    </div>
  </div>

  @endif
</div>
@endsection

SESSEXP_2_EOF

cat > 'public/js/booking.js' <<'SESSEXP_3_EOF'
/**
 * Intake SaaS — Booking Form JS
 * 4-step flow: Services → Schedule → Details → Review + Payment
 */
(function () {
  'use strict';

  var d    = window.BkData || {};
  var csrf = d.csrf   || '';

  // =========================================================================
  // State
  // =========================================================================
  var state = {
    step:       1,
    // Services
    selections: {},   // { serviceId: {...} } — multi-asset: the ACTIVE bike's set
    assetSel: {},     // MARKER-PATCH-214b — multi-asset: { assetKey: { serviceId: {...} } }
    activeAsset: null,// MARKER-PATCH-214b — multi-asset: active bike clientKey
    // Schedule
    date:       null,
    appointmentTime: null,
    resourceId: null,
    receivingMethod: null,
    // Details
    firstName:  '',
    lastName:   '',
    email:      '',
    phone:      '',
    responses:  {},   // { fieldKey: value }
    responseLabels: {}, // { fieldKey: label }
    // Payment
    paymentMethod: d.stripeEnabled ? 'stripe' : (d.paypalEnabled ? 'paypal' : 'none'),
  };

  // Calendar state
  var calYear, calMonth, calAvailable = {}, calUnavailable = {}, calEarliest = null, calTimeSlots = {}, calSlotResources = {};
  var calPdWindows = {}; // MARKER-PATCH-512 — pickup & delivery route windows per date
  var calCapacity = {}, calView = 'month'; // MARKER-PATCH-518 — day/week/month
  var calPdNeedBy = false; // MARKER-PATCH-519
  var calPdLead = 1, calWeekStart = null; // MARKER-PATCH-520
  var calPdAllowDayOf = false; // MARKER-PATCH-524
  var bookingMode = d.bookingMode || 'drop_off';
  var today = new Date();
  calYear  = today.getFullYear();
  calMonth = today.getMonth() + 1;

  // MARKER-PATCH-526 — refresh persistence
  var bkStoreKey = 'bk-state:' + location.host + ':' + location.pathname + ':' + (location.search || '');

  // MARKER-SESSIONS-EXPLORER — sessions that land directly on the booking
  // flow (shared links, bookmarks) skipped the choice page and never counted
  // as "started". Same once-per-session guard as the choice page.
  try {
    if (!sessionStorage.getItem('ia_booking_started')) {
      sessionStorage.setItem('ia_booking_started', '1');
      navigator.sendBeacon('/funnel/track', new Blob([JSON.stringify({event_type:'booking_started'})], {type:'application/json'}));
    }
  } catch (e) {}
  var bkRestoring = false;

  function bkSnap() {
    if (bkRestoring) return;
    try {
      sessionStorage.setItem(bkStoreKey, JSON.stringify({
        v: 1,
        assets: window.BkAssets || null,
        customer: window.BkCustomer || null,
        assetSel: state.assetSel,
        activeAsset: state.activeAsset,
        selections: state.selections,
        date: state.date,
        appointmentTime: state.appointmentTime,
        resourceId: state.resourceId,
        receivingMethod: state.receivingMethod,
        pdWindowId: state.pdWindowId || null,
        pdPickupDate: state.pdPickupDate || null,
        pdOutreach: state.pdOutreach || false, // MARKER-WINDOW-MINISTEP
        needBy: state.needBy || null,
        step: state.step,
      }));
    } catch (e) {}
  }
  window.__bkClearSnap = function () { try { sessionStorage.removeItem(bkStoreKey); } catch (e) {} };

  function bkRestore() {
    var raw = null;
    try { raw = sessionStorage.getItem(bkStoreKey); } catch (e) {}
    if (!raw) return;
    var snap = null;
    try { snap = JSON.parse(raw); } catch (e) { return; }
    if (!snap || snap.v !== 1) return;
    var hasServices = snap.selections && Object.keys(snap.selections).length;
    var hasAssetSel = snap.assetSel && Object.keys(snap.assetSel).some(function (k) { return Object.keys(snap.assetSel[k] || {}).length; });
    if (!hasServices && !hasAssetSel && !snap.date) return;

    bkRestoring = true;
    try {
      if (snap.assets && snap.assets.length) { window.BkAssets = snap.assets; }
      if (snap.customer) {
        window.BkCustomer = snap.customer;
        // MARKER-RETURNING-PREFILL — a mid-flow refresh restored the customer
        // for submit but left the Details fields blank; re-apply the prefill.
        if (typeof window.bkApplyReturningCustomer === 'function') window.bkApplyReturningCustomer(snap.customer);
      }
      state.assetSel = snap.assetSel || {};
      state.activeAsset = snap.activeAsset || null;
      state.selections = snap.selections || {};
      state.receivingMethod = snap.receivingMethod || null;
      state.pdWindowId = snap.pdWindowId || null;
      state.pdPickupDate = snap.pdPickupDate || null;
      state.pdOutreach = snap.pdOutreach || false; // MARKER-WINDOW-MINISTEP
      state.needBy = snap.needBy || null;

      // Skip the preflow when it was already completed.
      var pastPre = (d.multiAsset && snap.assets && snap.assets.length) || (parseInt(snap.step, 10) || 1) > 1 || hasServices || hasAssetSel;
      if (pastPre) {
        var pre = document.getElementById('bk-preflow');
        if (pre) pre.classList.remove('active');
        document.querySelectorAll('.bk-step--pre').forEach(function (dot) { dot.classList.remove('active'); dot.classList.add('done'); });
      }
      if (d.multiAsset && snap.assets && snap.assets.length) {
        if (typeof initAssetServices === 'function') initAssetServices();
      } else if (typeof syncRowsToSelections === 'function') {
        syncRowsToSelections();
      }
      updateSidebar();
      if (typeof updateNext1 === 'function') updateNext1();

      // Aim the first availability fetch at the saved date's month, and
      // finish the date/window/slot restore once it lands.
      if (snap.date) {
        var sd = new Date(snap.date + 'T12:00:00');
        calYear = sd.getFullYear(); calMonth = sd.getMonth() + 1;
        window.__bkPending = {
          date: snap.date,
          time: snap.appointmentTime || null,
          resourceId: snap.resourceId || null,
          winId: snap.pdWindowId || null,
          outreach: snap.pdOutreach || false, // MARKER-WINDOW-MINISTEP
          winDate: snap.pdPickupDate || null,
        };
      }

      var rcv = document.getElementById('bk-receiving');
      if (rcv && snap.receivingMethod) rcv.value = snap.receivingMethod;

      var step = Math.min(Math.max(parseInt(snap.step, 10) || 1, 1), 3);
      if (pastPre) setStep(step);
    } finally {
      bkRestoring = false;
    }
  }

  function bkApplyPending() {
    var pend = window.__bkPending;
    if (!pend) return;
    window.__bkPending = null;
    if (!calAvailable[pend.date]) { bkSnap(); return; }
    selectDate(pend.date);
    if (pend.winId) {
      // MARKER-WINDOW-MINISTEP — windows are cards (divs) now, not buttons
      var wb = document.querySelector('#bk-pd-windows [data-win-id="' + pend.winId + '"][data-win-date="' + (pend.winDate || '') + '"]');
      if (wb && !wb.classList.contains('full')) wb.click();
    } else if (pend.outreach) {
      var sk = document.querySelector('#bk-pd-windows .bk-pdw-skip');
      if (sk) sk.click();
    }
    if (pend.time) {
      var tb = null;
      document.querySelectorAll('#bk-time-slots button').forEach(function (b) { if (b.dataset.slot === pend.time) tb = b; });
      if (tb) tb.click();
      if (pend.resourceId) {
        var rb = document.querySelector('#bk-resource-picker button[data-resource-id="' + pend.resourceId + '"]');
        if (rb) rb.click();
      }
    }
  }

  // Stripe state
  var stripe, stripeElements, stripeCard;

  // =========================================================================
  // Boot
  // =========================================================================
  document.addEventListener('DOMContentLoaded', function () {
    bindAddButtons();
    bindServiceAddonCheckboxes();
    bindSearch();
    bindCatPills();
    bindCalNav();
    bindReceiving();
    bkRestore(); // MARKER-PATCH-526 — before initCalendar so the fetch targets the saved month
    initCalendar();
    initS2Rail(); // MARKER-PATCH-525
    if (d.multiAsset) window.__bkInitAssetServices = initAssetServices; // MARKER-PATCH-214c (run at pre-flow handoff, not boot)
    if (d.stripeEnabled && d.stripePk) initStripe();
    if (d.paypalEnabled && window.paypal) initPayPal();
  });

  // =========================================================================
  // Step navigation
  // =========================================================================
  window.goTo = function (step) {
    if (step === 2 && !canProceedStep1()) return;
    if (step === 3 && !canProceedStep2()) return;
    if (step === 4) return; // use goToReview()
    setStep(step);
  };

  window.goToReview = function () {
    if (!canProceedStep3()) return;
    collectDetails();
    renderReview();
    setStep(4);
  };

  function setStep(step) {
    state.step = step;

    // Sections
    document.querySelectorAll('.bk-section').forEach(function (s) {
      s.classList.remove('active');
    });
    var el = document.getElementById('bk-step-' + step);
    if (el) el.classList.add('active');

    if (step === 3) populateStep3Recap();
    bkSnap(); // MARKER-PATCH-526

    // Progress dots
    document.querySelectorAll('.bk-step').forEach(function (dot) {
      var ds = parseInt(dot.getAttribute('data-step'), 10);
      dot.classList.remove('active', 'done');
      if (ds === step) dot.classList.add('active');
      if (ds < step)  dot.classList.add('done');
    });

    window.scrollTo({ top: 0, behavior: 'smooth' });

    // MARKER-BOOKING-RESET — start-over appears once there's progress to lose.
    var rst = document.getElementById('bk-reset');
    if (rst) rst.style.display = (step > 1) ? 'inline-flex' : 'none';
  }

  // MARKER-BOOKING-RESET
  window.bkResetToggle = function (e) {
    if (e) e.stopPropagation();
    var c = document.getElementById('bk-reset-confirm');
    if (c) c.classList.toggle('open');
  };
  window.bkResetConfirm = function () {
    try { sessionStorage.removeItem(bkStoreKey); } catch (err) {}
    // Same session — the once-per-session booking_started guard stays.
    location.reload();
  };
  document.addEventListener('click', function (e) {
    var c = document.getElementById('bk-reset-confirm');
    if (c && c.classList.contains('open') && !e.target.closest('#bk-reset-confirm') && !e.target.closest('#bk-reset')) {
      c.classList.remove('open');
    }
  });

  // =========================================================================
  // Step 1 — Services
  // =========================================================================
  function bindAddButtons() {
    document.querySelectorAll('.bk-service-add-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var serviceId = btn.getAttribute('data-service-id');
        if (!serviceId) return;
        if (state.selections[serviceId]) {
          deselectService(serviceId);
        } else {
          selectService(btn);
        }
      });
    });
  }

  function bindServiceAddonCheckboxes() {
    document.querySelectorAll('.bk-service-addon-check').forEach(function (cb) {
      cb.addEventListener('change', function () {
        var serviceId = cb.getAttribute('data-service-id');
        var addonId   = cb.getAttribute('data-addon-id');
        if (!serviceId || !addonId) return;

        if (cb.checked && !state.selections[serviceId]) {
          var row = document.querySelector('.bk-service-row[data-service-id="' + serviceId + '"]');
          var btn = row ? row.querySelector('.bk-service-add-btn') : null;
          if (btn) selectService(btn);
        }

        var sel = state.selections[serviceId];
        if (!sel) return;

        if (cb.checked) {
          if (sel.addonIds.indexOf(addonId) === -1) sel.addonIds.push(addonId);
        } else {
          sel.addonIds = sel.addonIds.filter(function (id) { return id !== addonId; });
        }
        updateSidebar();
      });
    });
  }

  function selectService(btn) {
    var serviceId   = btn.getAttribute('data-service-id');
    var serviceName = btn.getAttribute('data-service-name');
    var priceCents  = parseInt(btn.getAttribute('data-service-price-cents'), 10) || 0;
    var row         = btn.closest('.bk-service-row');
    var duration    = row ? parseInt(row.getAttribute('data-service-duration'), 10) || 0 : 0;

    state.selections[serviceId] = {
      serviceId: serviceId, serviceName: serviceName,
      priceCents: priceCents, durationMinutes: duration, addonIds: [],
    };
    if (row) row.classList.add('is-selected');
    btn.textContent = '✓ Added';
    updateNext1();
    updateSidebar();
  }

  function deselectService(serviceId) {
    delete state.selections[serviceId];
    var row = document.querySelector('.bk-service-row[data-service-id="' + serviceId + '"]');
    if (row) {
      row.classList.remove('is-selected');
      var btn = row.querySelector('.bk-service-add-btn');
      if (btn) btn.textContent = 'Add to booking';
      row.querySelectorAll('.bk-service-addon-check').forEach(function (cb) { cb.checked = false; });
    }
    updateNext1();
    updateSidebar();
  }

  // MARKER-PATCH-265 — category pills + search share one filter.
  var bkActiveCat = 'all';

  function applyCatalogFilter() {
    var input = document.getElementById('bk-search');
    var q = input ? input.value.toLowerCase().trim() : '';
    document.querySelectorAll('.bk-cat-group').forEach(function (group) {
      var gcat = group.getAttribute('data-cat') || '';
      if (bkActiveCat !== 'all' && gcat !== bkActiveCat) {
        group.style.display = 'none';
        return;
      }
      var anyVisible = false;
      group.querySelectorAll('.bk-service-row').forEach(function (row) {
        var name = (row.getAttribute('data-service-name') || '').toLowerCase();
        var show = (!q || name.includes(q));
        row.style.display = show ? '' : 'none';
        if (show) anyVisible = true;
      });
      group.style.display = anyVisible ? '' : 'none';
    });
  }

  function bindSearch() {
    var input = document.getElementById('bk-search');
    if (!input) return;
    input.addEventListener('input', applyCatalogFilter);
  }

  function bindCatPills() {
    var rail = document.getElementById('bk-cat-rail');
    if (!rail) return;
    rail.querySelectorAll('.bk-cat-pill').forEach(function (pill) {
      pill.addEventListener('click', function () {
        bkActiveCat = pill.getAttribute('data-cat') || 'all';
        rail.querySelectorAll('.bk-cat-pill').forEach(function (p) { p.classList.remove('is-active'); });
        pill.classList.add('is-active');
        applyCatalogFilter();
      });
    });
  }

  function canProceedStep1() {
    if (d.multiAsset) {
      if (Object.keys(state.selections).length) return true; // active bike's live picks (pre-sync)
      var any = false;
      Object.keys(state.assetSel).forEach(function (k) { if (Object.keys(state.assetSel[k]).length) any = true; });
      return any;
    }
    return Object.keys(state.selections).length > 0;
  }

  function updateNext1() {
    var btn = document.getElementById('bk-next-1');
    if (btn) btn.disabled = !canProceedStep1();
  }

  // =========================================================================
  // Step 2 — Calendar
  // =========================================================================
  function bindCalNav() {
    var prev = document.getElementById('cal-prev');
    var next = document.getElementById('cal-next');
    // MARKER-PATCH-520 — arrows follow the active view
    function stepMonth(dir) {
      calMonth += dir;
      if (calMonth < 1)  { calMonth = 12; calYear--; }
      if (calMonth > 12) { calMonth = 1;  calYear++; }
      state.date = null;
      updateNext2();
      loadMonth();
    }
    function syncMonthTo(ds) {
      var d = new Date(ds + 'T12:00:00');
      if (d.getFullYear() !== calYear || (d.getMonth() + 1) !== calMonth) {
        calYear = d.getFullYear(); calMonth = d.getMonth() + 1;
        loadMonth();
        return true;
      }
      return false;
    }
    function stepView(dir) {
      if (calView === 'week') {
        var w = new Date((calWeekStart || (calYear + '-' + pad(calMonth) + '-01')) + 'T12:00:00');
        w.setDate(w.getDate() + 7 * dir);
        var tm = new Date(); tm.setHours(0,0,0,0);
        if (w < tm) w = new Date();
        calWeekStart = w.getFullYear() + '-' + pad(w.getMonth() + 1) + '-' + pad(w.getDate());
        if (!syncMonthTo(calWeekStart)) renderCalendar();
      } else if (calView === 'day') {
        var keys = Object.keys(calAvailable).sort();
        if (!keys.length) return stepMonth(dir);
        var cur = state.date || keys[0];
        var idx = keys.indexOf(cur);
        var nxt = (idx === -1) ? keys[0] : keys[idx + dir];
        if (!nxt) return stepMonth(dir);
        selectDate(nxt);
        if (!syncMonthTo(nxt)) renderCalendar();
      } else {
        stepMonth(dir);
      }
    }
    if (prev) prev.addEventListener('click', function () { stepView(-1); });
    if (next) next.addEventListener('click', function () { stepView(1); });
  }

  function populateStep3Recap() {
    var card = document.getElementById('bk-step3-recap');
    var whenEl = document.getElementById('bk-step3-recap-when');
    var metaEl = document.getElementById('bk-step3-recap-meta');
    var changeBtn = document.getElementById('bk-step3-recap-change');
    if (!card || !whenEl || !metaEl) return;

    if (!state.date) {
      card.style.display = 'none';
      return;
    }

    // Format the primary line: 'Wednesday, April 30 at 9:00 AM' (time-slot)
    // or 'Wednesday, April 30' (drop-off without time).
    var dt = parseDateString(state.date);
    var dayLabel = dt.toLocaleDateString(undefined, {
      weekday: 'long', month: 'long', day: 'numeric'
    });
    var primary = dayLabel;
    if (state.appointmentTime) {
      primary += ' at ' + formatTime12h(state.appointmentTime);
    }
    whenEl.textContent = primary;

    // Meta line: receiving method (drop-off) and/or selected service summary.
    var metaParts = [];
    if (state.receivingMethod) metaParts.push(state.receivingMethod);
    if (d.multiAsset) {
      // MARKER-PATCH-214e — aggregate across all bikes, not just the active one
      var bikeCount = (window.BkAssets || []).length;
      var svcCount = 0;
      Object.keys(state.assetSel).forEach(function (k) { svcCount += Object.keys(state.assetSel[k]).length; });
      if (bikeCount) metaParts.push(bikeCount + ' bike' + (bikeCount > 1 ? 's' : ''));
      if (svcCount)  metaParts.push(svcCount + ' service' + (svcCount > 1 ? 's' : ''));
    } else {
      var sels = Object.values(state.selections || {});
      if (sels.length) {
        var firstName = sels[0].serviceName || '';
        if (firstName) {
          if (sels.length === 1) metaParts.push(firstName);
          else                    metaParts.push(firstName + ' + ' + (sels.length - 1) + ' more');
        }
      }
    }
    metaEl.textContent = metaParts.join(' · ') || ' ';

    card.style.display = '';

    // Wire Change button once. Goes back to step 2.
    if (changeBtn && !changeBtn.__bkBound) {
      changeBtn.__bkBound = true;
      changeBtn.addEventListener('click', function () {
        window.goTo(2);
      });
    }
  }

  function renderEarliestPill() {
    var pill = document.getElementById('bk-earliest');
    var text = document.getElementById('bk-earliest-text');
    var legend = document.getElementById('bk-cal-legend');
    if (!pill || !text) return;

    if (legend) legend.style.display = (calEarliest || Object.keys(calAvailable).length) ? '' : 'none';

    if (!calEarliest || state.date) {
      pill.style.display = 'none';
      return;
    }

    var dt = parseDateString(calEarliest.date);
    var dayLabel = dt.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' });
    var label;
    if (calEarliest.time) {
      var timeLabel = formatTime12h(calEarliest.time);
      label = 'Earliest available: <strong>' + dayLabel + ' at ' + timeLabel + '</strong>';
    } else {
      label = 'Earliest available: <strong>' + dayLabel + '</strong>';
    }
    text.innerHTML = label;
    pill.style.display = '';

    if (!pill.__bkBound) {
      pill.__bkBound = true;
      pill.addEventListener('click', function () {
        if (!calEarliest) return;
        var targetDt = parseDateString(calEarliest.date);
        if (targetDt.getFullYear() !== calYear || (targetDt.getMonth() + 1) !== calMonth) {
          calYear = targetDt.getFullYear();
          calMonth = targetDt.getMonth() + 1;
          loadMonth();
          // Wait for loadMonth to finish before selecting + advancing.
          setTimeout(function () {
            selectDate(calEarliest.date);
            applyEarliestTime();
            tryAdvanceFromPill();
          }, 250);
          return;
        }
        selectDate(calEarliest.date);
        applyEarliestTime();
        // applyEarliestTime sets a 50ms timer for time-slot picking, so we
        // wait a bit longer here so the time has actually been applied
        // before we check whether Continue is unblocked.
        setTimeout(tryAdvanceFromPill, 100);
      });
    }
  }

  function tryAdvanceFromPill() {
    var nextBtn = document.getElementById('bk-next-2');
    if (nextBtn && !nextBtn.disabled) {
      nextBtn.click();
      return;
    }
    // Continue is blocked — most likely because a receiving method is
    // required and not yet picked. Scroll the dropdown into view and
    // pulse it so the customer sees what's blocking them.
    var receiving = document.getElementById('bk-receiving');
    if (receiving) {
      receiving.scrollIntoView({ behavior: 'smooth', block: 'center' });
      receiving.classList.add('bk-flash-attention');
      receiving.focus({ preventScroll: true });
      setTimeout(function () { receiving.classList.remove('bk-flash-attention'); }, 1800);

      // Show a brief inline note above the dropdown so the reason is
      // explicit, not just a flash. Replace any existing note first.
      var existingNote = document.getElementById('bk-earliest-blocker-note');
      if (existingNote) existingNote.remove();
      var note = document.createElement('div');
      note.id = 'bk-earliest-blocker-note';
      note.className = 'bk-earliest-blocker-note';
      note.textContent = 'Pick how you\'re dropping off to continue.';
      receiving.parentNode.insertBefore(note, receiving);
      setTimeout(function () {
        if (note && note.parentNode) note.parentNode.removeChild(note);
      }, 4000);
    }
  }

  function applyEarliestTime() {
    if (calEarliest && calEarliest.time && bookingMode === 'time_slots') {
      setTimeout(function () {
        var btn = document.querySelector('[data-bk-time="' + calEarliest.time + '"]');
        if (btn) btn.click();
      }, 50);
    }
  }

  function parseDateString(s) {
    var parts = s.split('-');
    return new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
  }

  function formatTime12h(hhmm) {
    var parts = hhmm.split(':');
    var h = parseInt(parts[0], 10);
    var m = parts[1];
    var ampm = h >= 12 ? 'PM' : 'AM';
    var h12 = h % 12;
    if (h12 === 0) h12 = 12;
    return h12 + ':' + m + ' ' + ampm;
  }

  function initCalendar() {
    loadMonth();
  }

  function loadMonth() {
    var label = document.getElementById('cal-month-label');
    var loading = document.getElementById('cal-loading');
    var grid    = document.getElementById('cal-grid');
    if (!label || !grid) return;

    var months = ['January','February','March','April','May','June',
                  'July','August','September','October','November','December'];
    label.textContent = months[calMonth - 1] + ' ' + calYear;

    if (loading) loading.style.display = 'block';

    // Clear day cells (keep day name headers)
    var headers = Array.from(grid.querySelectorAll('.bk-cal-day-name'));
    grid.innerHTML = '';
    headers.forEach(function (h) { grid.appendChild(h); });

    fetch(d.availUrl + '?year=' + calYear + '&month=' + calMonth, {
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf }
    })
    .then(function (r) { return r.json(); })
    .then(function (resp) {
      if (loading) loading.style.display = 'none';
      calAvailable = {};
      (resp.dates || []).forEach(function (dt) { calAvailable[dt] = true; });
      calUnavailable = {};
      (resp.unavailable_dates || []).forEach(function (dt) { calUnavailable[dt] = true; });
      calEarliest = resp.earliest || null;
      calTimeSlots = resp.slots || {};
      calPdWindows = resp.pd_windows || {}; // MARKER-PATCH-512
      calCapacity  = resp.capacity || {};   // MARKER-PATCH-518
      calPdNeedBy  = !!resp.pd_need_by;     // MARKER-PATCH-519
      calPdLead    = (resp.pd_lead_days === undefined) ? 1 : (resp.pd_lead_days | 0); // MARKER-PATCH-520
      calPdAllowDayOf = !!resp.pd_allow_day_of; // MARKER-PATCH-524
      calSlotResources = resp.slot_resources || {};
      renderCalendar();
      renderEarliestPill();
      bkApplyPending(); // MARKER-PATCH-526
    })
    .catch(function () {
      if (loading) loading.style.display = 'none';
      renderCalendar();
    });
  }

  // ======================================================================
  // MARKER-PATCH-518 — Day / Week / Month customer views
  // ======================================================================
  function capLabel(ds) {
    var c = calCapacity[ds];
    if (!c) return null;
    if (c.left === null || c.left === undefined) return 'open';
    return bookingMode === 'time_slots'
      ? c.left + (c.left === 1 ? ' time' : ' times')
      : c.left + ' left';
  }

  function ensureViewBar() {
    if (document.getElementById('bk-viewbar')) return;
    var grid = document.getElementById('cal-grid');
    if (!grid || !grid.parentElement) return;
    var bar = document.createElement('div');
    bar.id = 'bk-viewbar';
    bar.style.cssText = 'display:flex;gap:4px;margin:0 0 12px;background:rgba(0,0,0,.06);border:1px solid rgba(0,0,0,.08);border-radius:10px;padding:3px;width:fit-content';
    ['day', 'week', 'month'].forEach(function (v) {
      var b = document.createElement('button');
      b.type = 'button';
      b.dataset.view = v;
      b.textContent = v.charAt(0).toUpperCase() + v.slice(1);
      b.style.cssText = 'font-size:12px;font-weight:600;padding:6px 14px;border-radius:7px;border:0;cursor:pointer;background:transparent;color:var(--p-text);font-family:inherit;opacity:.65';
      b.addEventListener('click', function () { calView = v; paintViewBar(); renderCalendar(); });
      bar.appendChild(b);
    });
    grid.parentElement.insertBefore(bar, grid);
    paintViewBar();
  }

  function paintViewBar() {
    var bar = document.getElementById('bk-viewbar');
    if (!bar) return;
    bar.querySelectorAll('button').forEach(function (b) {
      var on = b.dataset.view === calView;
      b.style.background = on ? 'var(--p-accent)' : 'transparent';
      b.style.color      = on ? 'var(--p-accent-text)' : 'var(--p-text)';
      b.style.opacity    = on ? '1' : '.65';
    });
  }

  function altContainer() {
    var el = document.getElementById('bk-altview');
    if (!el) {
      el = document.createElement('div');
      el.id = 'bk-altview';
      var grid = document.getElementById('cal-grid');
      grid.parentElement.insertBefore(el, grid.nextSibling);
    }
    return el;
  }

  function fmtDayLabel(d) {
    return ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'][d.getDay()];
  }

  function renderWeekView() {
    var alt = altContainer();
    alt.innerHTML = '';
    // MARKER-PATCH-520 — stable week anchor; only the arrows move it
    if (!calWeekStart) {
      var a0 = state.date ? new Date(state.date + 'T12:00:00') : new Date();
      if (a0 < today) a0 = new Date();
      calWeekStart = a0.getFullYear() + '-' + pad(a0.getMonth() + 1) + '-' + pad(a0.getDate());
    }
    var anchor = new Date(calWeekStart + 'T12:00:00');
    var row = document.createElement('div');
    row.style.cssText = 'display:grid;grid-template-columns:repeat(7,1fr);gap:7px';
    for (var i = 0; i < 7; i++) {
      var d = new Date(anchor.getFullYear(), anchor.getMonth(), anchor.getDate() + i);
      var ds = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
      var open = !!calAvailable[ds];
      var c = calCapacity[ds];
      var col = document.createElement('div');
      var sel = ds === state.date;
      col.style.cssText = 'text-align:center;padding:10px 4px;border:1.5px solid ' + (sel ? 'var(--p-accent)' : 'rgba(0,0,0,.1)') + ';border-radius:10px;cursor:' + (open ? 'pointer' : 'default') + ';opacity:' + (open ? '1' : '.38') + (sel ? ';background:color-mix(in srgb, var(--p-accent) 12%, transparent)' : '');
      var pct = (c && c.max) ? Math.max(0, Math.min(1, ((c.max - c.left) / c.max))) : null;
      col.innerHTML =
        '<div style="font-size:10px;opacity:.6">' + fmtDayLabel(d) + '</div>' +
        '<div style="font-size:15px;font-weight:600;margin:1px 0 6px">' + d.getDate() + '</div>' +
        (open
          ? (pct !== null
              ? '<div style="height:5px;border-radius:99px;background:rgba(0,0,0,.1);overflow:hidden"><div style="height:100%;width:' + Math.round(pct * 100) + '%;background:var(--p-accent)"></div></div><div style="font-size:9.5px;margin-top:4px;opacity:.7">' + capLabel(ds) + '</div>'
              : '<div style="font-size:9.5px;opacity:.7">' + (capLabel(ds) || 'open') + '</div>')
          : '<div style="font-size:9.5px;opacity:.7">—</div>');
      if (open) (function (dstr) { col.addEventListener('click', function () { selectDate(dstr); renderCalendar(); }); })(ds);
      row.appendChild(col);
    }
    alt.appendChild(row);
  }

  function renderDayView() {
    var alt = altContainer();
    alt.innerHTML = '';
    var ds = state.date || (calEarliest && calEarliest.date);
    if (!ds) { alt.innerHTML = '<div style="font-size:13px;opacity:.6;padding:10px 0">Pick a date from the month view first.</div>'; return; }
    var d = new Date(ds + 'T12:00:00');
    var c = calCapacity[ds];
    var head = document.createElement('div');
    head.style.cssText = 'font-size:14px;font-weight:600;margin-bottom:10px';
    head.textContent = fmtDayLabel(d) + ', ' + d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    alt.appendChild(head);

    if (bookingMode === 'drop_off') {
      var card = document.createElement('div');
      card.style.cssText = 'border:1.5px solid var(--p-accent);border-radius:12px;padding:16px;background:color-mix(in srgb, var(--p-accent) 10%, transparent)';
      var leftTxt = c ? (c.left === null ? 'Open' : c.left + (c.max ? ' <span style="font-size:13px;opacity:.6;font-weight:500">of ' + c.max + '</span>' : '')) : '—';
      card.innerHTML =
        '<div style="font-size:11.5px;opacity:.7;margin-bottom:2px">' + ((calPdWindows[ds] || []).length ? 'Pickup spots left' : 'Drop-off spots left') + '</div>' +
        '<div style="font-size:26px;font-weight:700;letter-spacing:-.02em">' + leftTxt + '</div>';
      alt.appendChild(card);
      // window picker / receiving flow continues below via selectDate's DOM
    } else {
      renderTimeSlots(ds); // reuses the existing picker under the grid
    }
  }

  function renderCalendar() {
    var grid = document.getElementById('cal-grid');
    if (!grid) return;

    // Remove existing day cells
    Array.from(grid.querySelectorAll('.bk-cal-day')).forEach(function (d) { d.remove(); });

    var firstDay  = new Date(calYear, calMonth - 1, 1).getDay(); // 0=Sun
    var daysInMonth = new Date(calYear, calMonth, 0).getDate();
    var todayStr  = today.getFullYear() + '-' + pad(today.getMonth() + 1) + '-' + pad(today.getDate());

    // Empty cells for offset
    for (var i = 0; i < firstDay; i++) {
      var empty = document.createElement('div');
      empty.className = 'bk-cal-day';
      grid.appendChild(empty);
    }

    for (var day = 1; day <= daysInMonth; day++) {
      var dateStr = calYear + '-' + pad(calMonth) + '-' + pad(day);
      var cell    = document.createElement('div');
      cell.textContent = day;
      cell.className   = 'bk-cal-day';

      if (dateStr === todayStr) cell.classList.add('today');

      if (calAvailable[dateStr]) {
        cell.classList.add('available');
        if (dateStr === state.date) cell.classList.add('selected');
        // MARKER-PATCH-518 — capacity chip
        var capInfo = capLabel(dateStr);
        if (capInfo) {
          var chip = document.createElement('span');
          chip.textContent = capInfo;
          chip.style.cssText = 'display:block;font-size:8.5px;font-weight:600;line-height:1;margin-top:2px;opacity:.75';
          cell.appendChild(chip);
        }
        (function (ds) {
          cell.addEventListener('click', function () { selectDate(ds); });
        })(dateStr);
      } else if (calUnavailable[dateStr]) {
        cell.classList.add('unavailable');
      }

      grid.appendChild(cell);
    }

    // MARKER-PATCH-518 — view routing: month shows the grid, week/day swap it out
    ensureViewBar();
    var altEl = document.getElementById('bk-altview');
    if (calView === 'month') {
      grid.style.display = '';
      if (altEl) altEl.innerHTML = '';
    } else {
      grid.style.display = 'none';
      if (calView === 'week') renderWeekView(); else renderDayView();
    }
  }

  function selectDate(dateStr) {
    state.date = dateStr;
    state.appointmentTime = null;
    state.resourceId = null;
    var existingPicker = document.getElementById('bk-resource-picker');
    if (existingPicker) existingPicker.remove();
    document.querySelectorAll('.bk-cal-day').forEach(function (c) {
      c.classList.toggle('selected', c.textContent == parseInt(dateStr.split('-')[2], 10) && calAvailable[dateStr]);
    });
    renderCalendar();
    renderRailDay(dateStr); // MARKER-PATCH-525

    // Time slot mode — show time picker
    if (bookingMode === 'time_slots') {
      renderTimeSlots(dateStr);
    }

    // MARKER-PATCH-512 — pickup & delivery: window picker on drop_off dates
    state.pdWindowId = null;
    state.pdPickupDate = null; // MARKER-PATCH-520
    state.pdOutreach = false; // MARKER-WINDOW-MINISTEP
    var pdExisting = document.getElementById('bk-pd-windows');
    if (pdExisting) pdExisting.remove();
    if (bookingMode === 'drop_off' && (calPdWindows[dateStr] || []).length) {
      renderPdWindows(dateStr);
    }

    renderEarliestPill();
    updateNext2();
  }

  // MARKER-PATCH-512 — pickup window picker (mirrors renderTimeSlots)
  function renderPdWindows(dateStr) {
    // MARKER-PATCH-520 — windows from (dateStr - lead) through dateStr
    var windows = [];
    (function () {
      var end = new Date(dateStr + 'T12:00:00');
      var todayMid = new Date(); todayMid.setHours(0,0,0,0);
      for (var off = calPdLead; off >= (calPdAllowDayOf ? 0 : 1); off--) { // MARKER-PATCH-524
        var d = new Date(end.getFullYear(), end.getMonth(), end.getDate() - off);
        if (d < todayMid) continue;
        var ds = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
        (calPdWindows[ds] || []).forEach(function (w) {
          windows.push({
            id: w.id, label: w.label, remaining: w.remaining, full: w.full, date: ds,
            dayLabel: (off === 0 ? 'Day of' : ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'][d.getDay()] + ' ' + (d.getMonth() + 1) + '/' + d.getDate()),
          });
        });
      }
    })();
    // MARKER-WINDOW-MINISTEP-EMPTYFIX — a date can be gated on pickup (its
    // own windows exist) while having NO usable lead-day windows (e.g. the
    // earliest bookable day). Rendering nothing used to deadlock Continue;
    // now the mini-step renders with just the reach-out option.
    var gatedNoWindows = !windows.length
      && bookingMode === 'drop_off'
      && ((calPdWindows[dateStr] || []).length > 0);
    if (!windows.length && !gatedNoWindows) return;
    // MARKER-WINDOW-MINISTEP — the window choice is its own focused mini-step:
    // stepper (date done -> window pending), radio cards, and a "reach out to
    // me" skip that satisfies the requirement and flags outreach for staff.
    var wrap = document.createElement('div');
    wrap.id = 'bk-pd-windows';
    wrap.className = 'bk-pdw';

    var stepper = document.createElement('div');
    stepper.className = 'bk-pdw-stepper';
    stepper.innerHTML = '<span class="bk-pdw-s done"></span><span class="bk-pdw-s" id="bk-pdw-s2"></span>';
    wrap.appendChild(stepper);

    var label = document.createElement('div');
    label.className = 'bk-pdw-title';
    label.textContent = 'When should we come get it?';
    wrap.appendChild(label);
    var sub = document.createElement('div');
    sub.className = 'bk-pdw-sub';
    sub.textContent = gatedNoWindows
      ? 'No pickup windows fit before this date — we\'ll arrange pickup with you directly.'
      : 'Pick one window — this is how your ' + (window.BkAssetSingular || 'item') + ' reaches us.';
    wrap.appendChild(sub);

    function markDone() {
      var s2 = document.getElementById('bk-pdw-s2');
      if (s2) s2.classList.toggle('done', !!(state.pdWindowId || state.pdOutreach));
    }
    function clearCards() {
      wrap.querySelectorAll('.bk-pdw-card').forEach(function (c) { c.classList.remove('sel'); });
    }

    windows.forEach(function (w) {
      var card = document.createElement('div');
      card.className = 'bk-pdw-card' + (w.full ? ' full' : '');
      card.dataset.winId = w.id; card.dataset.winDate = w.date; // MARKER-PATCH-526
      card.setAttribute('role', 'radio');
      card.innerHTML = '<span class="bk-pdw-radio"></span>'
        + '<span class="bk-pdw-d">' + w.dayLabel + ' · ' + w.label + '</span>'
        + '<span class="bk-pdw-spots">' + (w.full ? 'full' : w.remaining + (w.remaining === 1 ? ' stop left' : ' stops left')) + '</span>';
      if (!w.full) card.addEventListener('click', function () {
        state.pdWindowId = w.id;
        state.pdPickupDate = w.date; // MARKER-PATCH-520
        state.pdOutreach = false;
        clearCards(); card.classList.add('sel');
        markDone(); updateNext2();
      });
      wrap.appendChild(card);
    });

    var skip = document.createElement('div');
    skip.className = 'bk-pdw-card bk-pdw-skip';
    skip.setAttribute('role', 'radio');
    skip.innerHTML = '<span class="bk-pdw-radio"></span>'
      + '<span class="bk-pdw-d">None of these work — reach out to me</span>'
      + '<span class="bk-pdw-sub2">Skip for now. We\'ll contact you to arrange pickup after you book.</span>';
    skip.addEventListener('click', function () {
      state.pdWindowId = null;
      state.pdPickupDate = null;
      state.pdOutreach = true;
      clearCards(); skip.classList.add('sel');
      markDone(); updateNext2();
    });
    wrap.appendChild(skip);
    markDone();

    // MARKER-PATCH-519 — optional "need it back by" under the window picker
    if (calPdNeedBy) {
      // MARKER-NEEDBY-POLISH — styled to match the mini-step cards
      var nb = document.createElement('div');
      nb.className = 'bk-pdw-needby';
      var nbl = document.createElement('label');
      nbl.className = 'bk-pdw-needby-l';
      nbl.innerHTML = 'Need it back by a certain date? <span>optional</span>';
      var nbi = document.createElement('input');
      nbi.type = 'date';
      nbi.min = dateStr;
      nbi.className = 'bk-pdw-needby-i';
      nbi.addEventListener('change', function () {
        state.needBy = nbi.value || null;
        nbi.classList.toggle('has-value', !!nbi.value);
      });
      nb.appendChild(nbl); nb.appendChild(nbi);
      wrap.appendChild(nb);
    }

    // MARKER-PATCH-525 — mount in the schedule rail when present, else legacy anchor
    var mnt = s2Mount();
    if (mnt) {
      mnt.appendChild(wrap);
    } else {
      var anchorEl = document.getElementById('bk-altview') || document.getElementById('cal-grid');
      if (anchorEl && anchorEl.parentNode) {
        anchorEl.parentNode.insertBefore(wrap, anchorEl.nextSibling);
      } else {
        var cal = document.getElementById('bk-calendar');
        if (cal && cal.parentElement) cal.parentElement.appendChild(wrap);
      }
    }
    updateNext2();
  }

  function renderTimeSlots(dateStr) {
    var existing = document.getElementById('bk-time-slots');
    if (existing) existing.remove();

    var slots = calTimeSlots[dateStr] || [];
    if (slots.length === 0) return;

    var wrap = document.createElement('div');
    wrap.id = 'bk-time-slots';
    wrap.style.cssText = 'margin-top:16px';

    var label = document.createElement('div');
    label.style.cssText = 'font-size:13px;font-weight:500;margin-bottom:10px';
    label.textContent = 'Available times';
    wrap.appendChild(label);

    var grid = document.createElement('div');
    grid.style.cssText = 'display:flex;flex-wrap:wrap;gap:8px';

    slots.forEach(function(slot) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.dataset.slot = slot; // MARKER-PATCH-526
      btn.textContent = formatTime(slot);
      btn.style.cssText = 'padding:8px 14px;border:1.5px solid rgba(0,0,0,.12);border-radius:var(--p-r);font-size:13px;font-weight:500;cursor:pointer;transition:all .12s;background:transparent;color:var(--p-text)';
      btn.addEventListener('click', function() {
        state.appointmentTime = slot;
        grid.querySelectorAll('button').forEach(function(b) {
          b.style.background = 'transparent';
          b.style.borderColor = 'rgba(0,0,0,.12)';
          b.style.color = 'var(--p-text)';
        });
        btn.style.background   = 'var(--p-accent)';
        btn.style.borderColor  = 'var(--p-accent)';
        btn.style.color        = 'var(--p-accent-text)';
        renderResourcePicker(dateStr, slot);
        updateNext2();
      });
      grid.appendChild(btn);
    });

    wrap.appendChild(grid);
    var mntTs = s2Mount(); // MARKER-PATCH-525
    if (mntTs) mntTs.appendChild(wrap); else document.getElementById('bk-calendar').after(wrap);
  }

  function renderResourcePicker(dateStr, time) {
    var existing = document.getElementById('bk-resource-picker');
    if (existing) existing.remove();
    state.resourceId = null;

    var resources = (d.resources || []);
    if (resources.length < 2) return; // single-resource: auto-assign server-side

    var freeIds = ((calSlotResources[dateStr] || {})[time]) || [];
    var freeResources = resources.filter(function (r) { return freeIds.indexOf(r.id) !== -1; });
    if (freeResources.length === 0) return;

    var wrap = document.createElement('div');
    wrap.id = 'bk-resource-picker';
    wrap.style.cssText = 'margin-top:16px';

    var label = document.createElement('div');
    label.style.cssText = 'font-size:13px;font-weight:500;margin-bottom:10px';
    label.textContent = 'Choose who';
    wrap.appendChild(label);

    var grid = document.createElement('div');
    grid.style.cssText = 'display:flex;flex-wrap:wrap;gap:8px';

    freeResources.forEach(function (res) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.dataset.resourceId = res.id;
      btn.textContent = res.name;
      btn.style.cssText = 'padding:8px 14px;border:1.5px solid rgba(0,0,0,.12);border-radius:var(--p-r);font-size:13px;font-weight:500;cursor:pointer;transition:all .12s;background:transparent;color:var(--p-text);display:inline-flex;align-items:center;gap:8px';

      // Color swatch
      if (res.color_hex) {
        var swatch = document.createElement('span');
        swatch.style.cssText = 'width:10px;height:10px;border-radius:50%;background:' + res.color_hex;
        btn.prepend(swatch);
      }

      btn.addEventListener('click', function () {
        state.resourceId = res.id;
        grid.querySelectorAll('button').forEach(function (b) {
          b.style.background = 'transparent';
          b.style.borderColor = 'rgba(0,0,0,.12)';
          b.style.color = 'var(--p-text)';
        });
        btn.style.background  = 'var(--p-accent)';
        btn.style.borderColor = 'var(--p-accent)';
        btn.style.color       = 'var(--p-accent-text)';
        updateNext2();
      });
      grid.appendChild(btn);
    });

    wrap.appendChild(grid);
    var timeSlotsEl = document.getElementById('bk-time-slots');
    if (timeSlotsEl) {
      timeSlotsEl.after(wrap);
    } else {
      var mntRp = s2Mount(); // MARKER-PATCH-525
      if (mntRp) mntRp.appendChild(wrap); else document.getElementById('bk-calendar').after(wrap);
    }
  }

  // MARKER-RETURNING-PREFILL — prefill + lock Details for a returning
  // customer. Called from the items-step continue AND from snapshot restore.
  window.bkApplyReturningCustomer = function (cust) {
    if (!cust || !cust.id) return;
    var lock = function (id, val) {
      var inp = document.getElementById(id);
      if (inp && val) { inp.value = val; inp.readOnly = true; inp.classList.add('bk-locked'); }
    };
    lock('bk-first-name', cust.firstName);
    lock('bk-last-name', cust.lastName);
    lock('bk-email', cust.email);
    lock('bk-phone', cust.phone);
    var fn = document.getElementById('bk-first-name');
    if (fn && !document.getElementById('bk-returning-note')) {
      var note = document.createElement('div');
      note.id = 'bk-returning-note';
      note.className = 'bk-returning-note';
      note.innerHTML = '<strong>Welcome back' + (cust.firstName ? ', ' + esc(cust.firstName) : '') + '!</strong> Your contact details are filled in from your account.';
      var grid = fn.closest('.bk-field-grid-2'); // MARKER-PATCH-214j — note above the grid, not inside it
      if (grid && grid.parentElement) grid.parentElement.insertBefore(note, grid);
      else if (fn.parentElement && fn.parentElement.parentElement) fn.parentElement.parentElement.insertBefore(note, fn.parentElement);
    }
  };

  function formatTime(timeStr) {
    try {
      var parts = timeStr.split(':');
      var h = parseInt(parts[0], 10);
      var m = parts[1];
      var ampm = h >= 12 ? 'PM' : 'AM';
      h = h % 12 || 12;
      return h + ':' + m + ' ' + ampm;
    } catch(e) { return timeStr; }
  }

  function bindReceiving() {
    var sel = document.getElementById('bk-receiving');
    if (!sel) return;
    sel.addEventListener('change', function () {
      state.receivingMethod = sel.value;
      updateNext2();
    });
  }

  function canProceedStep2() {
    if (!state.date) return false;
    if (bookingMode === 'time_slots' && !state.appointmentTime) return false;
    // MARKER-PATCH-512 — a date with route windows requires picking one
    if (bookingMode === 'drop_off' && (calPdWindows[state.date] || []).length && !state.pdWindowId && !state.pdOutreach) return false; // MARKER-WINDOW-MINISTEP
    if (bookingMode === 'time_slots' && (d.resources || []).length >= 2 && !state.resourceId) return false;
    if (d.hasReceiving) {
      var sel = document.getElementById('bk-receiving');
      if (sel && !sel.value) return false;
    }
    return true;
  }

  function updateNext2() {
    var btn = document.getElementById('bk-next-2');
    if (btn) btn.disabled = !canProceedStep2();
    bkSnap(); // MARKER-PATCH-526
  }

  // =========================================================================
  // Step 3 — Details
  // =========================================================================
  function canProceedStep3() {
    var fn = document.getElementById('bk-first-name');
    var ln = document.getElementById('bk-last-name');
    var em = document.getElementById('bk-email');
    if (!fn || !fn.value.trim()) { fn && fn.focus(); return false; }
    if (!ln || !ln.value.trim()) { ln && ln.focus(); return false; }
    if (!em || !em.value.trim() || !em.value.includes('@')) { em && em.focus(); return false; }

    // Required custom fields
    var missing = false;
    document.querySelectorAll('.bk-custom-field[required]').forEach(function (f) {
      if (!f.value.trim()) { missing = true; f.focus(); }
    });
    return !missing;
  }

  function collectDetails() {
    state.firstName = document.getElementById('bk-first-name')?.value.trim() || '';
    state.lastName  = document.getElementById('bk-last-name')?.value.trim()  || '';
    state.email     = document.getElementById('bk-email')?.value.trim()      || '';
    state.phone     = document.getElementById('bk-phone')?.value.trim()      || '';
    state.receivingMethod = document.getElementById('bk-receiving')?.value   || '';

    state.responses      = {};
    state.responseLabels = {};
    document.querySelectorAll('.bk-custom-field').forEach(function (f) {
      var key   = f.getAttribute('data-field-key');
      var label = f.getAttribute('data-field-label');
      var val   = f.type === 'checkbox' ? (f.checked ? 'Yes' : '') : f.value;
      if (key) {
        state.responses[key]      = val;
        state.responseLabels[key] = label;
      }
    });
  }

  // =========================================================================
  // Sidebar
  // =========================================================================
  // MARKER-PATCH-525 — schedule-rail helpers
  function s2Mount() { return document.getElementById('bk-rail-mounts'); }

  function initS2Rail() {
    var src = document.getElementById('bk-sidebar-items');
    var dst = document.getElementById('bk-rail-order-items');
    if (!src || !dst) return;
    var sync = function () { dst.innerHTML = src.innerHTML; };
    new MutationObserver(sync).observe(src, { childList: true, subtree: true });
    sync();
  }

  function renderRailDay(dateStr) {
    var el = document.getElementById('bk-rail-day');
    if (!el) return;
    if (!dateStr) { el.style.display = 'none'; return; }
    var dObj = new Date(dateStr + 'T12:00:00');
    var cap = capLabel(dateStr);
    el.style.display = '';
    el.querySelector('[data-rail-date]').textContent =
      dObj.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' });
    el.querySelector('[data-rail-cap]').textContent = cap || '';
  }

  function updateSidebar() {
    if (d.multiAsset && state.activeAsset) { state.assetSel[state.activeAsset] = cloneSel(state.selections); renderAssetTabs(); } // MARKER-PATCH-214b/d
    bkSnap(); // MARKER-PATCH-526
    var container = document.getElementById('bk-sidebar-items');
    if (!container) return;
    if (d.multiAsset) {
      // MARKER-PATCH-214g — numbered per-bike groups (treatment C), prominent grand total
      var mHtml = '', mTotal = 0, anySvc = false, bikeNum = 0;
      (window.BkAssets || []).forEach(function (a) {
        var sels = state.assetSel[a.clientKey] || {};
        var ks = Object.keys(sels);
        if (!ks.length) return;
        anySvc = true; bikeNum++;
        var bikeSub = 0, lines = '';
        ks.forEach(function (k) {
          var sel = sels[k];
          lines += '<div class="bk-cart-line"><span>' + esc(sel.serviceName) + '</span><span>' + fmtMoney(sel.priceCents) + '</span></div>';
          bikeSub += sel.priceCents;
          sel.addonIds.forEach(function (addonId) {
            var cb = document.querySelector('.bk-service-addon-check[data-service-id="' + sel.serviceId + '"][data-addon-id="' + addonId + '"]');
            if (!cb) return;
            var ap = parseInt(cb.getAttribute('data-addon-price-cents'), 10) || 0;
            lines += '<div class="bk-cart-line bk-cart-line--addon"><span>+ ' + esc(cb.getAttribute('data-addon-name') || '') + '</span><span>' + fmtMoney(ap) + '</span></div>';
            bikeSub += ap;
          });
        });
        mTotal += bikeSub;
        mHtml += '<div class="bk-cart-bike">'
              +    '<div class="bk-cart-head"><span class="bk-cart-idx">' + bikeNum + '</span>'
              +      '<span class="bk-cart-name">' + esc(a.name) + '</span>'
              +      '<span class="bk-cart-sub">' + fmtMoney(bikeSub) + '</span></div>'
              +    lines
              +  '</div>';
      });
      if (!anySvc) { container.innerHTML = '<p class="bk-sidebar-empty">No items selected yet.</p>'; return; }
      mHtml += '<div class="bk-cart-total"><span>Total</span><span>' + fmtMoney(mTotal) + '</span></div>';
      container.innerHTML = mHtml;
      return;
    }
    var services = Object.values(state.selections);
    if (services.length === 0) {
      container.innerHTML = '<p class="bk-sidebar-empty">No items selected yet.</p>';
      return;
    }
    var html = ''; var total = 0;
    services.forEach(function (sel) {
      html += '<div class="bk-sidebar-line"><span>' + esc(sel.serviceName) + '</span><span>' + fmtMoney(sel.priceCents) + '</span></div>';
      total += sel.priceCents;
      sel.addonIds.forEach(function (addonId) {
        var cb = document.querySelector('.bk-service-addon-check[data-service-id="' + sel.serviceId + '"][data-addon-id="' + addonId + '"]');
        if (!cb) return;
        var name  = cb.getAttribute('data-addon-name') || '';
        var price = parseInt(cb.getAttribute('data-addon-price-cents'), 10) || 0;
        html += '<div class="bk-sidebar-line" style="padding-left:16px;opacity:.85"><span>+ ' + esc(name) + '</span><span>' + fmtMoney(price) + '</span></div>';
        total += price;
      });
    });
    html += '<div class="bk-sidebar-total"><span>Total</span><span>' + fmtMoney(total) + '</span></div>';
    container.innerHTML = html;
  }

  // =========================================================================
  // Review
  // =========================================================================
  function renderReview() {
    updateSidebar();

    // Services
    var svc = document.getElementById('bk-review-services');
    if (svc) {
      var html = '';
      if (d.multiAsset) {
        (window.BkAssets || []).forEach(function (a) {
          var sels = state.assetSel[a.clientKey] || {};
          var ks = Object.keys(sels);
          html += '<div class="bk-review-asset"><div class="bk-review-asset-name">' + esc(a.name) + '</div>';
          if (!ks.length) html += '<div class="bk-review-row" style="opacity:.45"><span>No services</span><span></span></div>';
          ks.forEach(function (k) {
            var sel = sels[k];
            html += '<div class="bk-review-row"><span>' + esc(sel.serviceName) + '</span><span>' + fmtMoney(sel.priceCents) + '</span></div>';
            sel.addonIds.forEach(function (addonId) {
              var cb = document.querySelector('.bk-service-addon-check[data-service-id="' + sel.serviceId + '"][data-addon-id="' + addonId + '"]');
              if (!cb) return;
              html += '<div class="bk-review-row"><span class="bk-review-row-label">+ ' + esc(cb.getAttribute('data-addon-name') || '') + '</span><span>' + fmtMoney(parseInt(cb.getAttribute('data-addon-price-cents'), 10) || 0) + '</span></div>';
            });
          });
          html += '</div>';
        });
      } else {
        Object.values(state.selections).forEach(function (sel) {
          html += '<div class="bk-review-row"><span>' + esc(sel.serviceName) + '</span><span>' + fmtMoney(sel.priceCents) + '</span></div>';
          sel.addonIds.forEach(function (addonId) {
            var cb = document.querySelector('.bk-service-addon-check[data-service-id="' + sel.serviceId + '"][data-addon-id="' + addonId + '"]');
            if (!cb) return;
            var name  = cb.getAttribute('data-addon-name') || '';
            var price = parseInt(cb.getAttribute('data-addon-price-cents'), 10) || 0;
            html += '<div class="bk-review-row"><span class="bk-review-row-label">+ ' + esc(name) + '</span><span>' + fmtMoney(price) + '</span></div>';
          });
        });
      }
      var total = calcTotal();
      html += '<div class="bk-review-row" style="font-weight:700;border-top:1px solid rgba(0,0,0,.08);margin-top:8px;padding-top:8px"><span>Total</span><span>' + fmtMoney(total) + '</span></div>';
      svc.innerHTML = html || '<p style="opacity:.4;font-size:13px">None selected.</p>';
    }

    // Details
    var det = document.getElementById('bk-review-details');
    if (det) {
      var rows = [
        ['Date',    formatDate(state.date)],
        ['Name',    state.firstName + ' ' + state.lastName],
        ['Email',   state.email],
      ];
      if (state.phone)           rows.push(['Phone', state.phone]);
      if (state.receivingMethod) rows.push(['Drop-off', state.receivingMethod]);
      Object.keys(state.responses).forEach(function (k) {
        if (state.responses[k]) rows.push([state.responseLabels[k] || k, state.responses[k]]);
      });
      det.innerHTML = rows.map(function (r) {
        return '<div class="bk-review-row"><span class="bk-review-row-label">' + esc(r[0]) + '</span><span>' + esc(r[1]) + '</span></div>';
      }).join('');
    }
  }

  // =========================================================================
  // Payment
  // =========================================================================
  window.selectPayment = function (method) {
    state.paymentMethod = method;
    document.querySelectorAll('.bk-payment-btn').forEach(function (b) {
      b.classList.toggle('selected', b.id === 'pay-' + method);
    });
    var sw = document.getElementById('bk-stripe-wrap');
    var pw = document.getElementById('bk-paypal-wrap');
    if (sw) sw.style.display = method === 'stripe' ? '' : 'none';
    if (pw) pw.style.display = method === 'paypal' ? '' : 'none';
  };

  function initStripe() {
    if (!window.Stripe || !d.stripePk) return;
    stripe = Stripe(d.stripePk);
    stripeElements = stripe.elements();
    stripeCard     = stripeElements.create('card', {
      style: {
        base: {
          fontFamily:  '-apple-system, sans-serif',
          fontSize:    '15px',
          color:       (getComputedStyle(document.body).color || '#111111'),
          '::placeholder': { color: '#888888' },
        },
      },
    });
    var mountEl = document.getElementById('bk-stripe-elements');
    if (mountEl) {
      // Mount after a tick so the element is visible
      setTimeout(function () { stripeCard.mount('#bk-stripe-elements'); }, 100);
    }
  }

  function initPayPal() {
    if (!window.paypal) return;
    window.paypal.Buttons({
      createOrder: function (data, actions) {
        return submitBooking('paypal', true).then(function (resp) {
          if (!resp || !resp.success) throw new Error(resp?.message || 'Booking failed');
          // PayPal expects an order ID — we get an approve_url back
          // We redirect instead of using the embedded flow to handle server-side capture
          window.location.href = resp.approve_url;
          return resp.order_id;
        });
      },
      onError: function (err) {
        showError('PayPal error: ' + err);
      },
    }).render('#bk-paypal-button-container');
  }

  window.handlePayment = function () {
    if (state.paymentMethod === 'paypal') {
      // Handled by PayPal button
      return;
    }
    if (state.paymentMethod === 'stripe') {
      handleStripe();
      return;
    }
    submitBooking('none');
  };

  function handleStripe() {
    var btn = document.getElementById('bk-submit-btn');
    if (btn) { btn.disabled = true; btn.textContent = 'Processing…'; }

    submitBooking('stripe', false).then(function (resp) {
      if (!resp || !resp.success) {
        showError(resp?.message || 'Booking failed. Please try again.');
        resetSubmitBtn();
        return;
      }
      if (!resp.client_secret) {
        // Free booking
        window.location.href = resp.redirect;
        return;
      }
      stripe.confirmCardPayment(resp.client_secret, {
        payment_method: { card: stripeCard }
      }).then(function (result) {
        if (result.error) {
          showError(result.error.message);
          resetSubmitBtn();
        } else {
          // MARKER-PATCH-385 — card cleared; materialize the appointment server-side.
          fetch(d.finalizeUrl, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            body:    JSON.stringify({ pending_token: resp.pending_token }),
          }).then(function (r) { return r.json(); }).then(function (fin) {
            if (fin && fin.success && fin.redirect) {
              window.location.href = fin.redirect;
            } else {
              showError((fin && fin.message) || 'Your payment went through, but we could not finish the booking. Please contact us.');
              resetSubmitBtn();
            }
          }).catch(function () {
            showError('Your payment went through, but we could not finish the booking. Please contact us.');
            resetSubmitBtn();
          });
        }
      });
    });
  }

  // =========================================================================
  // Submit
  // =========================================================================
  window.submitBooking = function (paymentMethod, returnPromise) {
    var body = buildPayload(paymentMethod || state.paymentMethod);
    var promise = fetch(d.submitUrl, {
      method:  'POST',
      headers: {
        'Content-Type':     'application/json',
        'X-CSRF-TOKEN':     csrf,
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify(body),
    }).then(function (r) { return r.json(); });

    if (returnPromise) return promise;

    promise.then(function (resp) {
      if (!resp.success) { showError(resp.message || 'Booking failed.'); resetSubmitBtn(); return; }
      if (typeof window.__bkClearSnap === 'function') window.__bkClearSnap(); // MARKER-PATCH-526
      if (resp.redirect) { window.location.href = resp.redirect; return; }
      if (resp.payment === 'paypal' && resp.approve_url) { window.location.href = resp.approve_url; return; }
    }).catch(function () {
      showError('Network error. Please try again.');
      resetSubmitBtn();
    });

    return promise;
  };

  // ===== MARKER-PATCH-214b — per-asset service machinery =====
  function cloneSel(map) {
    var o = {};
    Object.keys(map || {}).forEach(function (k) {
      var s = map[k];
      o[k] = { serviceId: s.serviceId, serviceName: s.serviceName, priceCents: s.priceCents, durationMinutes: s.durationMinutes, addonIds: (s.addonIds || []).slice() };
    });
    return o;
  }
  function syncRowsToSelections() {
    document.querySelectorAll('.bk-service-row').forEach(function (row) {
      var sid = row.getAttribute('data-service-id');
      var sel = state.selections[sid];
      var btn = row.querySelector('.bk-service-add-btn');
      if (sel) { row.classList.add('is-selected'); if (btn) btn.textContent = '\u2713 Added'; }
      else { row.classList.remove('is-selected'); if (btn) btn.textContent = 'Add to booking'; }
      row.querySelectorAll('.bk-service-addon-check').forEach(function (cb) {
        cb.checked = !!(sel && sel.addonIds.indexOf(cb.getAttribute('data-addon-id')) !== -1);
      });
    });
  }
  function assetSubtotal(key) {
    var m = state.assetSel[key] || {}, t = 0;
    Object.keys(m).forEach(function (k) {
      var sel = m[k]; t += sel.priceCents;
      sel.addonIds.forEach(function (id) {
        var cb = document.querySelector('.bk-service-addon-check[data-service-id="' + sel.serviceId + '"][data-addon-id="' + id + '"]');
        if (cb) t += parseInt(cb.getAttribute('data-addon-price-cents'), 10) || 0;
      });
    });
    return t;
  }
  function renderAssetTabs() {
    var strip = document.getElementById('bk-asset-tabs');
    if (!strip) return;
    var html = '';
    (window.BkAssets || []).forEach(function (a) {
      var n = Object.keys(state.assetSel[a.clientKey] || {}).length;
      var on = a.clientKey === state.activeAsset;
      html += '<button type="button" class="bk-asset-tab' + (on ? ' on' : '') + '" data-k="' + a.clientKey + '">'
            + '<span class="bk-asset-tab-n">' + esc(a.name) + '</span>'
            + '<span class="bk-asset-tab-m">' + (n ? (n + ' service' + (n > 1 ? 's' : '') + ' \u00b7 ' + fmtMoney(assetSubtotal(a.clientKey))) : 'No services yet') + '</span>'
            + '</button>';
    });
    strip.innerHTML = html;
    strip.querySelectorAll('.bk-asset-tab').forEach(function (b) {
      b.addEventListener('click', function () { switchAsset(b.getAttribute('data-k')); });
    });
    var active = (window.BkAssets || []).filter(function (a) { return a.clientKey === state.activeAsset; })[0];
    var lbl = document.getElementById('bk-asset-choosing');
    if (lbl && active) lbl.innerHTML = 'Choosing services for <strong>' + esc(active.name) + '</strong>';
  }
  function switchAsset(key) {
    if (key === state.activeAsset) return;
    if (state.activeAsset) state.assetSel[state.activeAsset] = cloneSel(state.selections);
    state.activeAsset = key;
    state.selections = cloneSel(state.assetSel[key] || {});
    syncRowsToSelections();
    renderAssetTabs();
    updateSidebar();
  }
  function initAssetServices() {
    var assets = window.BkAssets || [];
    if (!assets.length) return;
    assets.forEach(function (a) { if (!state.assetSel[a.clientKey]) state.assetSel[a.clientKey] = {}; });
    var live = {}; assets.forEach(function (a) { live[a.clientKey] = true; });
    Object.keys(state.assetSel).forEach(function (k) { if (!live[k]) delete state.assetSel[k]; }); // MARKER-PATCH-214c prune removed bikes
    if (!live[state.activeAsset]) state.activeAsset = assets[0].clientKey;
    state.activeAsset = state.activeAsset || assets[0].clientKey;
    state.selections = cloneSel(state.assetSel[state.activeAsset]);
    var step1 = document.getElementById('bk-step-1');
    if (step1 && !document.getElementById('bk-asset-tabs')) {
      var wrap = document.createElement('div');
      wrap.innerHTML = '<div class="bk-asset-tabs" id="bk-asset-tabs"></div><div class="bk-asset-choosing" id="bk-asset-choosing"></div>';
      var toolbar = step1.querySelector('.bk-toolbar');
      if (toolbar) step1.insertBefore(wrap, toolbar);
      else step1.insertBefore(wrap, step1.children[2] || null);
    }
    renderAssetTabs();
    syncRowsToSelections();
  }

  function buildPayload(paymentMethod) {
    collectDetails();
    var items, assetsPayload = null, bkCustomerId = null;
    if (d.multiAsset) {
      items = [];
      assetsPayload = [];
      (window.BkAssets || []).forEach(function (a) {
        assetsPayload.push({ client_key: a.clientKey, name_snapshot: a.name, customer_asset_id: a.customerAssetId || null });
        var sels = state.assetSel[a.clientKey] || {};
        Object.keys(sels).forEach(function (k) {
          var s = sels[k];
          items.push({ service_item_id: s.serviceId, addon_ids: s.addonIds.slice(), asset_client_key: a.clientKey });
        });
      });
      bkCustomerId = (window.BkCustomer && window.BkCustomer.id) || null;
    } else {
      items = Object.values(state.selections).map(function (s) {
        return { service_item_id: s.serviceId, addon_ids: s.addonIds.slice() };
      });
    }
    var payload = {
      first_name: state.firstName, last_name: state.lastName,
      email: state.email, phone: state.phone,
      date: state.date, appointment_time: state.appointmentTime || null,
      route_window_id: state.pdWindowId || null, // MARKER-PATCH-512
      pickup_outreach: state.pdOutreach ? 1 : 0, // MARKER-WINDOW-MINISTEP
      need_by: state.needBy || null, // MARKER-PATCH-519
      pickup_date: state.pdPickupDate || null, // MARKER-PATCH-520
      resource_id: state.resourceId || null,
      receiving_method: state.receivingMethod,
      items: items,
      responses: state.responses, response_labels: state.responseLabels,
      payment_method: paymentMethod,
    };
    if (assetsPayload) payload.assets = assetsPayload;
    if (bkCustomerId) payload.customer_id = bkCustomerId;
    return payload;
  }

  // =========================================================================
  // Helpers
  // =========================================================================
  function calcTotal() {
    var t = 0;
    if (d.multiAsset) {
      Object.keys(state.assetSel).forEach(function (ak) {
        Object.keys(state.assetSel[ak]).forEach(function (k) {
          var sel = state.assetSel[ak][k]; t += sel.priceCents;
          sel.addonIds.forEach(function (id) {
            var cb = document.querySelector('.bk-service-addon-check[data-service-id="' + sel.serviceId + '"][data-addon-id="' + id + '"]');
            if (cb) t += parseInt(cb.getAttribute('data-addon-price-cents'), 10) || 0;
          });
        });
      });
      return t;
    }
    Object.values(state.selections).forEach(function (sel) {
      t += sel.priceCents;
      sel.addonIds.forEach(function (addonId) {
        var cb = document.querySelector('.bk-service-addon-check[data-service-id="' + sel.serviceId + '"][data-addon-id="' + addonId + '"]');
        if (cb) t += parseInt(cb.getAttribute('data-addon-price-cents'), 10) || 0;
      });
    });
    return t;
  }

  function fmtMoney(cents) {
    return d.currency + (cents / 100).toFixed(2);
  }

  function pad(n) { return String(n).padStart(2, '0'); }

  function fmtDate(ds) {
    if (!ds) return '';
    var dt;
    if (ds instanceof Date) {
      dt = ds;
    } else {
      var parts = String(ds).split('-');
      dt = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
    }
    return dt.toLocaleDateString(undefined, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
  }

  function formatDate(ds) { return fmtDate(ds); }

  function showError(msg) {
    var el = document.getElementById('bk-form-error');
    if (el) { el.textContent = msg; el.style.display = ''; el.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
  }

  function resetSubmitBtn() {
    var btn = document.getElementById('bk-submit-btn');
    if (btn) { btn.disabled = false; btn.textContent = state.paymentMethod === 'none' ? 'Confirm booking' : 'Pay & confirm'; }
  }

  function esc(s) {
    return String(s || '')
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

}());


/* ===== MARKER-PATCH-214 — multi-asset pre-flow (You + Bikes) ===== */
(function () {
  var d = window.BkData || {};
  if (!d.multiAsset) return;
  var pre = document.getElementById('bk-preflow');
  if (!pre) return;

  var path = 'new', customerId = null, firstName = '', lastName = '', custEmail = '', custPhone = '';
  var assets = [];
  var kn = 0;
  function nk() { return 'a' + (++kn); }
  function el(id) { return document.getElementById(id); }
  function escAttr(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;'); }

  var panelIntro = el('bk-pre-intro');
  var panelBikes = el('bk-pre-bikes');

  function showPanel(which) {
    panelIntro.classList.toggle('active', which === 'intro');
    panelBikes.classList.toggle('active', which === 'bikes');
    var youDot = document.querySelector('.bk-step--pre[data-pre="intro"]');
    var bikeDot = document.querySelector('.bk-step--pre[data-pre="bikes"]');
    if (youDot && bikeDot) {
      youDot.classList.toggle('active', which === 'intro');
      youDot.classList.toggle('done', which === 'bikes');
      bikeDot.classList.toggle('active', which === 'bikes');
      bikeDot.classList.remove('done');
    }
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  // intro toggle
  var toggle = el('bk-pre-toggle');
  toggle.querySelectorAll('button').forEach(function (b) {
    b.addEventListener('click', function () {
      path = b.getAttribute('data-path');
      toggle.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); });
      toggle.setAttribute('data-pos', path === 'returning' ? 'right' : 'left');
      el('bk-pre-new').style.display = path === 'new' ? '' : 'none';
      el('bk-pre-returning').style.display = path === 'returning' ? '' : 'none';
    });
  });

  // new customer -> bikes (one empty card)
  el('bk-pre-new-continue').addEventListener('click', function () {
    if (!assets.length) assets = [{ clientKey: nk(), name: '', customerAssetId: null, fromAccount: false, selected: true }]; // MARKER-ITEMS-PICK
    renderBikes(); showPanel('bikes');
  });

  // returning customer -> lookup
  el('bk-pre-lookup').addEventListener('click', function () {
    var email = (el('bk-pre-email').value || '').trim();
    var st = el('bk-pre-status');
    if (!email) { st.className = 'bk-pre-status show err'; st.textContent = 'Enter your email first.'; return; }
    st.className = 'bk-pre-status show'; st.textContent = 'Looking you up…';
    fetch(d.lookupUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': d.csrf },
      body: JSON.stringify({ email: email })
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res && res.found) {
          customerId = res.customer_id; firstName = res.first_name || ''; custEmail = email;
          lastName = res.last_name || ''; custPhone = res.phone || '';
          st.className = 'bk-pre-status show found';
          st.textContent = 'Welcome back' + (firstName ? (', ' + firstName) : '') + '! We pulled your ' + (d.assetPlural || 'items') + ' below.';
          // MARKER-ITEMS-PICK — account items start unselected; the customer
          // explicitly picks what's coming in. Deselect is the new "remove",
          // so nothing is ever unrecoverable.
          assets = (res.assets || []).map(function (a) {
            return { clientKey: nk(), name: a.name, customerAssetId: a.id, fromAccount: true, selected: false };
          });
          if (!assets.length) assets = [{ clientKey: nk(), name: '', customerAssetId: null, fromAccount: false, selected: true }];
          el('bk-pre-bikes-sub').textContent = "Tap the items you're bringing in — we pulled these from your account.";
          setTimeout(function () { renderBikes(); showPanel('bikes'); }, 600);
        } else {
          custEmail = email;
          st.className = 'bk-pre-status show err';
          st.innerHTML = "We didn't find that email. <button type='button' id='bk-pre-asnew' style='text-decoration:underline;background:none;border:none;color:inherit;cursor:pointer;font:inherit;padding:0'>Continue as new →</button>";
          var asNew = el('bk-pre-asnew');
          if (asNew) asNew.addEventListener('click', function () {
            assets = [{ clientKey: nk(), name: '', customerAssetId: null, fromAccount: false, selected: true }]; // MARKER-ITEMS-PICK
            renderBikes(); showPanel('bikes');
          });
        }
      })
      .catch(function () { st.className = 'bk-pre-status show err'; st.textContent = 'Something went wrong — please try again.'; });
  });

  // bikes
  function findAsset(k) { for (var i = 0; i < assets.length; i++) if (assets[i].clientKey === k) return assets[i]; return null; }
  function namedCount() { return assets.filter(function (b) { return b.selected !== false && (b.name || '').trim() !== ''; }).length; } // MARKER-ITEMS-PICK
  function updateContinue() {
    var n = namedCount();
    el('bk-pre-bikes-continue').disabled = n === 0;
    var hint = el('bk-pre-pick-hint');
    if (hint) hint.style.display = n === 0 ? 'block' : 'none';
  }

  function renderBikes() {
    var wrap = el('bk-pre-bike-list');
    var html = '';
    var shownIdx = 0;
    assets.forEach(function (b) {
      // MARKER-ITEMS-PICK — account items are toggleable pick-cards (214k
      // read-only names retained); manual items are editable and removable.
      if (b.fromAccount) {
        var on = b.selected !== false;
        html += '<div class="bk-pre-bike bk-pre-bike--pick' + (on ? ' bk-pre-bike--sel' : '') + '" data-pick="' + b.clientKey + '" role="button" tabindex="0">'
              +   '<div class="bk-pre-bike-h">'
              +     '<span class="bk-pre-pickcheck"></span>'
              +     '<span class="bk-pre-bike-tag">From your account</span>'
              +   '</div>'
              +   '<div class="bk-pre-bike-fixed">' + escAttr(b.name) + '</div>'
              + '</div>';
      } else {
        shownIdx++;
        html += '<div class="bk-pre-bike"><div class="bk-pre-bike-h"><span class="bk-pre-bike-idx">+</span>';
        html += '<button type="button" class="bk-pre-bike-rm" data-k="' + b.clientKey + '">Remove</button>';
        html += '</div>';
        html += '<input type="text" class="bk-input bk-pre-bike-name" data-k="' + b.clientKey + '" placeholder="Name this ' + escAttr(d.assetSingular || 'item') + '" value="' + escAttr(b.name) + '">';
        html += '</div>';
      }
    });
    wrap.innerHTML = html;
    wrap.querySelectorAll('.bk-pre-bike--pick').forEach(function (card) {
      card.addEventListener('click', function () {
        var b = findAsset(card.getAttribute('data-pick'));
        if (b) { b.selected = (b.selected === false); renderBikes(); }
      });
    });
    wrap.querySelectorAll('.bk-pre-bike-name').forEach(function (inp) {
      inp.addEventListener('input', function () { var b = findAsset(inp.getAttribute('data-k')); if (b) b.name = inp.value; updateContinue(); });
    });
    wrap.querySelectorAll('.bk-pre-bike-rm').forEach(function (btn) {
      btn.addEventListener('click', function () { assets = assets.filter(function (x) { return x.clientKey !== btn.getAttribute('data-k'); }); renderBikes(); });
    });
    updateContinue();
  }

  el('bk-pre-add').addEventListener('click', function () {
    assets.push({ clientKey: nk(), name: '', customerAssetId: null, fromAccount: false, selected: true }); renderBikes(); // MARKER-ITEMS-PICK
  });
  el('bk-pre-bikes-back').addEventListener('click', function () { showPanel('intro'); });

  el('bk-pre-bikes-continue').addEventListener('click', function () {
    var picked = assets
      .filter(function (b) { return b.selected !== false && (b.name || '').trim() !== ''; }) // MARKER-ITEMS-PICK
      .map(function (b) { return { clientKey: b.clientKey, name: b.name.trim(), customerAssetId: b.customerAssetId || null }; });
    if (!picked.length) return;

    // Hand off to the rest of booking.js (214b consumes these).
    window.BkAssets = picked;
    window.BkCustomer = { id: customerId, firstName: firstName, lastName: lastName, email: custEmail, phone: custPhone };
    // MARKER-RETURNING-PREFILL — shared with the refresh-restore path (214i logic lives there now)
    if (typeof window.bkApplyReturningCustomer === 'function') window.bkApplyReturningCustomer(window.BkCustomer);

    pre.classList.remove('active');
    document.querySelectorAll('.bk-step--pre').forEach(function (dot) { dot.classList.remove('active'); dot.classList.add('done'); });
    if (typeof window.goTo === 'function') window.goTo(1);
    if (typeof window.__bkInitAssetServices === 'function') window.__bkInitAssetServices(); // MARKER-PATCH-214c
  });
})();
SESSEXP_3_EOF

echo "sessions explorer applied — server needs view:clear"
