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
