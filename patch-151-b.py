#!/usr/bin/env python3
"""
Patch 151-b — Traffic reports, panel build-out (Phase 1.3 part b).

Builds on 151-a. Adds five new panels plus moves Traffic to first
position in the reports subnav.

Panels added:
  - Booking funnel       — 3 steps with drop-off annotations
  - Top sources          — referrer domain + UTM source attribution
  - Devices              — mobile/desktop/tablet split
  - Top pages            — most-visited paths
  - New vs returning     — first-time vs repeat visitors

Service methods added on TrafficReportService:
  - funnel()             — array of 3 step rows
  - topSources($limit)   — array of source rows (visits + conversion)
  - deviceSplit()        — counts + % per device class
  - topPages($limit)     — array of path rows
  - newVsReturning()     — new/returning split + conversion comparison

Idempotent.
"""

import argparse
import pathlib
import sys


# ============================================================
# 1. Reorder subnav — Traffic first
# ============================================================
#
# Current order in _tab_subnav.blade.php (after 151-a):
#   Operations, Customers, Services, Retail, Money, Staff, Traffic
# New order:
#   Traffic, Operations, Customers, Services, Retail, Money, Staff
#
# Simplest reliable edit: replace the whole nav block.

OLD_SUBNAV = """<nav class="rep-toggle" style="margin-bottom: 18px;">
  <a href="{{ route('tenant.reports.index',     []) }}" class="{{ ($active ?? '') === 'operations' ? 'active' : '' }}">Operations</a>
  <a href="{{ route('tenant.reports.customers', []) }}" class="{{ ($active ?? '') === 'customers'  ? 'active' : '' }}">Customers</a>
  <a href="{{ route('tenant.reports.services',  []) }}" class="{{ ($active ?? '') === 'services'   ? 'active' : '' }}">Services</a>
  <a href="{{ route('tenant.reports.retail',    []) }}" class="{{ ($active ?? '') === 'retail'     ? 'active' : '' }}">Retail</a>
  <a href="{{ route('tenant.reports.money',     []) }}" class="{{ ($active ?? '') === 'money'      ? 'active' : '' }}">Money</a>
  <a href="{{ route('tenant.reports.staff',     []) }}" class="{{ ($active ?? '') === 'staff'      ? 'active' : '' }}">Staff</a>
  {{-- MARKER-PATCH-151A — Traffic reports tab --}}
  <a href="{{ route('tenant.reports.traffic',   []) }}" class="{{ ($active ?? '') === 'traffic'    ? 'active' : '' }}">Traffic</a>
</nav>"""

NEW_SUBNAV = """<nav class="rep-toggle" style="margin-bottom: 18px;">
  {{-- MARKER-PATCH-151B — Traffic moved to first position --}}
  <a href="{{ route('tenant.reports.traffic',   []) }}" class="{{ ($active ?? '') === 'traffic'    ? 'active' : '' }}">Traffic</a>
  <a href="{{ route('tenant.reports.index',     []) }}" class="{{ ($active ?? '') === 'operations' ? 'active' : '' }}">Operations</a>
  <a href="{{ route('tenant.reports.customers', []) }}" class="{{ ($active ?? '') === 'customers'  ? 'active' : '' }}">Customers</a>
  <a href="{{ route('tenant.reports.services',  []) }}" class="{{ ($active ?? '') === 'services'   ? 'active' : '' }}">Services</a>
  <a href="{{ route('tenant.reports.retail',    []) }}" class="{{ ($active ?? '') === 'retail'     ? 'active' : '' }}">Retail</a>
  <a href="{{ route('tenant.reports.money',     []) }}" class="{{ ($active ?? '') === 'money'      ? 'active' : '' }}">Money</a>
  <a href="{{ route('tenant.reports.staff',     []) }}" class="{{ ($active ?? '') === 'staff'      ? 'active' : '' }}">Staff</a>
</nav>"""


# ============================================================
# 2. Service methods — append to TrafficReportService
# ============================================================

OLD_SERVICE_END = """    protected function tile(string $label, int $cur, int $prev, bool $accent = false): array
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
}"""

NEW_SERVICE_END = """    protected function tile(string $label, int $cur, int $prev, bool $accent = false): array
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
        $sessionCount = function (string $eventType) {
            return (int) TenantFunnelEvent::query()
                ->where('tenant_id', $this->tenant->id)
                ->where('event_type', $eventType)
                ->where('created_at', '>=', $this->curStart)
                ->where('created_at', '<',  $this->curEnd)
                ->distinct('session_id')
                ->count('session_id');
        };

        $viewed    = $sessionCount('booking_page_viewed');
        $started   = $sessionCount('booking_started');
        $completed = $sessionCount('booking_completed');

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
}"""


# ============================================================
# 3. Controller — pass new data to the view
# ============================================================

OLD_CONTROLLER = """        return view('tenant.reports.traffic', [
            'tenant'         => $tenant,
            'window'         => $window,
            'topStats'       => $svc->topStats(),
            'dailyVisitors'  => $svc->dailyVisitors(),
        ]);"""

NEW_CONTROLLER = """        return view('tenant.reports.traffic', [
            'tenant'         => $tenant,
            'window'         => $window,
            'topStats'       => $svc->topStats(),
            'dailyVisitors'  => $svc->dailyVisitors(),
            // MARKER-PATCH-151B — additional panels
            'funnel'         => $svc->funnel(),
            'topSources'     => $svc->topSources(),
            'deviceSplit'    => $svc->deviceSplit(),
            'topPages'       => $svc->topPages(),
            'newVsReturning' => $svc->newVsReturning(),
        ]);"""


# ============================================================
# 4. View — replace the "more panels coming" placeholder with the real panels
# ============================================================

OLD_VIEW_PLACEHOLDER = """  {{-- 151-b will add: funnel, top sources, devices, top pages --}}
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
  </div>"""

NEW_VIEW_PANELS = """  {{-- MARKER-PATCH-151B — full panel set --}}

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
      $maxFunnel = max(array_map(fn ($s) => $s['count'], $funnel['steps']) ?: [1], [1]);
      $maxFunnel = max($maxFunnel, 1);
    @endphp

    <div class="rep-funnel">
      @foreach($funnel['steps'] as $i => $step)
        <div class="rep-funnel-step">
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
  </div>"""


# ============================================================
# 5. CSS — append to view's style block
# ============================================================

OLD_VIEW_CSS_END = """  .rep-empty {
    padding: 32px 20px;
    text-align: center;
    color: var(--ia-text-dim, rgba(255,255,255,.42));
    font-size: 13px;
    line-height: 1.6;
  }
</style>"""

NEW_VIEW_CSS_END = """  .rep-empty {
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
</style>"""


EDITS = [
    ('resources/views/tenant/reports/_tab_subnav.blade.php', OLD_SUBNAV,           NEW_SUBNAV,           'reorder subnav: Traffic first', 'MARKER-PATCH-151B — Traffic moved'),
    ('app/Services/Tenant/TrafficReportService.php',         OLD_SERVICE_END,      NEW_SERVICE_END,      'service: funnel + sources + devices + pages + new/returning', 'MARKER-PATCH-151B — funnel'),
    ('app/Http/Controllers/Tenant/ReportsController.php',    OLD_CONTROLLER,       NEW_CONTROLLER,       'controller: pass new panel data',  'MARKER-PATCH-151B — additional panels'),
    ('resources/views/tenant/reports/traffic.blade.php',     OLD_VIEW_PLACEHOLDER, NEW_VIEW_PANELS,      'view: replace placeholder with real panels', 'MARKER-PATCH-151B — full panel set'),
    ('resources/views/tenant/reports/traffic.blade.php',     OLD_VIEW_CSS_END,     NEW_VIEW_CSS_END,     'view: add panel CSS', 'MARKER-PATCH-151B — panel-specific styles'),
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
    print(f'=== patch-151-b [{mode}] target={root} ===\n')

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
        print('Then visit /admin/reports/traffic — Traffic should be first tab, with all panels populated.')


if __name__ == '__main__':
    main()
