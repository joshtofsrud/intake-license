#!/usr/bin/env python3
"""
Patch 151-a — Traffic reports tab, skeleton + top stats + visitors chart.

Phase 1.3 of the traffic-reports build (after patches 149+150).

Adds /admin/reports/traffic powered by data already flowing into
tenant_funnel_events. Free for all tenants (no extended_reports gate).
This patch lands the foundation; patches 151-b and 151-c layer on the
funnel, sources, devices, top pages, etc.

What ships here:
  - Service: TrafficReportService — windowed queries + comparison math
  - Controller: ReportsController::traffic(Request)
  - View: tenant/reports/traffic.blade.php
  - Subnav entry: "Traffic" added to the reports tab partial
  - Route: GET /admin/reports/traffic

Window:
  ?window=7d  |  ?window=30d (default)  |  ?window=90d

Top stat tiles + an SVG line chart comparing current vs prior window.

Idempotent.
"""

import argparse
import pathlib
import sys


# ============================================================
# NEW FILES
# ============================================================

SERVICE = r'''<?php
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
'''


VIEW = r'''@extends('layouts.tenant.app')
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
</style>
@endpush

@section('content')
<div class="ia-page">
  <h1 class="rep-h1">Reports</h1>
  <div class="rep-sub">How your business is performing.</div>

  @include('tenant.reports._tab_subnav', ['active' => 'traffic'])

  {{-- Window switcher --}}
  <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; flex-wrap: wrap; gap: 10px;">
    <div style="font-size: 13px; color: var(--ia-text-dim, rgba(255,255,255,.42));">
      Showing <strong style="color: var(--ia-text);">{{ $window }}</strong> · compared to prior {{ $window }}
    </div>
    <div class="rep-window">
      <a href="?window=7d"  class="{{ $window === '7d'  ? 'active' : '' }}">7 days</a>
      <a href="?window=30d" class="{{ $window === '30d' ? 'active' : '' }}">30 days</a>
      <a href="?window=90d" class="{{ $window === '90d' ? 'active' : '' }}">90 days</a>
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
      <div style="font-size: 11px; text-transform: uppercase; letter-spacing: .08em; color: var(--ia-text-dim, rgba(255,255,255,.42)); font-weight: 700; margin-bottom: 6px;">
        Daily visitors
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
      @endphp
      <svg class="rep-chart" viewBox="0 0 {{ $vbW }} {{ $vbH }}" preserveAspectRatio="none" aria-label="Daily visitors line chart">
        {{-- Grid --}}
        @foreach($gridYs as $gy)
          <line x1="0" y1="{{ $gy }}" x2="{{ $vbW }}" y2="{{ $gy }}" stroke="rgba(255,255,255,.04)" stroke-width="1" />
        @endforeach
        {{-- Prior period (muted, dashed) --}}
        <path d="{{ $pathPrior }}" stroke="rgba(255,255,255,.32)" stroke-width="1.2" fill="none" stroke-dasharray="3 3" stroke-linecap="round" stroke-linejoin="round" />
        {{-- Current period (lime, solid) --}}
        <path d="{{ $pathCur }}" stroke="#BEF264" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" />
      </svg>
      <div class="rep-chart-legend">
        <span><i style="background: #BEF264;"></i> Last {{ $window }} · peak {{ number_format(max($cur ?: [0])) }}/day</span>
        <span><i style="background: rgba(255,255,255,.32);"></i> Prior {{ $window }} · peak {{ number_format(max($prior ?: [0])) }}/day</span>
      </div>
    </div>
  </div>

  {{-- 151-b will add: funnel, top sources, devices, top pages --}}
  <div class="rep-zone">
    <div class="rep-zone-head">
      <div>
        <div class="rep-zone-title">More panels coming</div>
        <div class="rep-zone-sub">Funnel · Sources · Devices · Top pages · New vs returning</div>
      </div>
    </div>
    <div class="rep-empty" style="padding: 16px 20px;">
      The next patch adds the booking funnel breakdown, traffic sources, device split, and top pages.
    </div>
  </div>
  @endif
</div>
@endsection
'''


# ============================================================
# EDITS
# ============================================================

# 1. Add the Traffic tab to the subnav partial
OLD_SUBNAV = """  <a href=\"{{ route('tenant.reports.staff',     []) }}\" class=\"{{ ($active ?? '') === 'staff'      ? 'active' : '' }}\">Staff</a>"""

NEW_SUBNAV = """  <a href=\"{{ route('tenant.reports.staff',     []) }}\" class=\"{{ ($active ?? '') === 'staff'      ? 'active' : '' }}\">Staff</a>
  {{-- MARKER-PATCH-151A — Traffic reports tab --}}
  <a href=\"{{ route('tenant.reports.traffic',   []) }}\" class=\"{{ ($active ?? '') === 'traffic'    ? 'active' : '' }}\">Traffic</a>"""


# 2. Add the controller method. Anchor on the staff() method's signature
#    (the last method in the controller).
OLD_CONTROLLER_BLOCK = """    public function staff(Request $request): View"""

NEW_CONTROLLER_BLOCK = """    /**
     * Traffic tab — site usage analytics over tenant_funnel_events.
     * Free for all tenants. Window: 7d / 30d (default) / 90d.
     * MARKER-PATCH-151A
     */
    public function traffic(Request $request): View
    {
        $tenant = tenant();
        $window = $request->query('window', '30d');
        if (!in_array($window, ['7d', '30d', '90d'], true)) {
            $window = '30d';
        }

        $svc = new \\App\\Services\\Tenant\\TrafficReportService($tenant, $window);

        return view('tenant.reports.traffic', [
            'tenant'         => $tenant,
            'window'         => $window,
            'topStats'       => $svc->topStats(),
            'dailyVisitors'  => $svc->dailyVisitors(),
        ]);
    }

    public function staff(Request $request): View"""


# 3. Add the route. Anchor on the existing staff route line.
OLD_ROUTE = """            Route::get('/reports/staff',        [TenantControllers\\ReportsController::class, 'staff'])->name('reports.staff');"""

NEW_ROUTE = """            Route::get('/reports/staff',        [TenantControllers\\ReportsController::class, 'staff'])->name('reports.staff');
            // MARKER-PATCH-151A — Traffic tab
            Route::get('/reports/traffic',      [TenantControllers\\ReportsController::class, 'traffic'])->name('reports.traffic');"""


NEW_FILES = {
    'app/Services/Tenant/TrafficReportService.php':         SERVICE,
    'resources/views/tenant/reports/traffic.blade.php':     VIEW,
}

EDITS = [
    ('resources/views/tenant/reports/_tab_subnav.blade.php', OLD_SUBNAV, NEW_SUBNAV, 'reports subnav: Traffic tab', 'MARKER-PATCH-151A — Traffic reports tab'),
    ('app/Http/Controllers/Tenant/ReportsController.php',    OLD_CONTROLLER_BLOCK, NEW_CONTROLLER_BLOCK, 'ReportsController::traffic',  'MARKER-PATCH-151A'),
    ('routes/web.php',                                       OLD_ROUTE,  NEW_ROUTE,  'routes: reports.traffic',     'MARKER-PATCH-151A — Traffic tab'),
]


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('root')
    ap.add_argument('--apply', action='store_true')
    a = ap.parse_args()
    root = pathlib.Path(a.root)
    if not (root / 'routes' / 'web.php').exists():
        print('ERROR: not an intake repo', file=sys.stderr); sys.exit(2)
    mode = 'APPLY' if a.apply else 'DRY-RUN'
    print(f'=== patch-151-a [{mode}] target={root} ===\n')

    for rel, content in NEW_FILES.items():
        p = root / rel
        if p.exists() and p.read_text() == content:
            print(f'  unchanged: {rel}'); continue
        if a.apply:
            p.parent.mkdir(parents=True, exist_ok=True)
            p.write_text(content)
        print(f'  {"written" if a.apply else "would_write"}: {rel}')

    for rel, old, new, label, marker in EDITS:
        p = root / rel
        if not p.exists():
            print(f'  ERROR: file missing for {label}: {rel}', file=sys.stderr); sys.exit(2)
        t = p.read_text()
        if marker in t:
            print(f'  already_applied: {label}'); continue
        if old not in t:
            print(f'  ERROR: anchor missing for {label}', file=sys.stderr); sys.exit(2)
        if t.count(old) > 1:
            print(f'  ERROR: anchor not unique for {label}', file=sys.stderr); sys.exit(2)
        if a.apply:
            p.write_text(t.replace(old, new, 1))
        print(f'  {"applied" if a.apply else "would_apply"}: {label}')

    if a.apply:
        print('\nDeploy: git pull && php artisan view:clear && systemctl restart php8.3-fpm')
        print('Then visit /admin/reports/traffic')
    else:
        print('\n(dry-run — no files written.)')


if __name__ == '__main__':
    main()
