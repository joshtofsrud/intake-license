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
  </div>
  @endif
</div>
@endsection
