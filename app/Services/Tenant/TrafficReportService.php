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
}
