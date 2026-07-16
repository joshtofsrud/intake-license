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

