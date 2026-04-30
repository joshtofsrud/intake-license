@extends('layouts.tenant.app')
@section('title', 'Reports')

@push('styles')
<style>
  .rep-h1 { font-size: 28px; font-weight: 800; letter-spacing: -0.02em; margin-bottom: 4px; }
  .rep-sub { color: var(--ia-text-3, #888); font-size: 13.5px; margin-bottom: 24px; }

  /* Global range bar */
  .rep-rangebar {
    display: flex; align-items: center; justify-content: space-between;
    gap: 14px; flex-wrap: wrap;
    padding: 14px 16px; margin-bottom: 24px;
    background: var(--ia-surface, #131313);
    border: 1px solid var(--ia-border, #1f1f1f);
    border-radius: 12px;
  }
  .rep-rangebar-label {
    font-size: 11.5px; color: var(--ia-text-3, #888);
    text-transform: uppercase; letter-spacing: 0.08em; font-weight: 700;
  }
  .rep-rangebar-current {
    font-size: 14px; font-weight: 700; color: var(--ia-text, #f0f0f0);
    margin-left: 8px;
  }
  .rep-rangebar-controls { display: inline-flex; gap: 6px; align-items: center; }
  .rep-toggle { display: inline-flex; gap: 4px; background: rgba(255,255,255,0.02); border: 1px solid var(--ia-border, #1f1f1f); border-radius: 8px; padding: 3px; }
  .rep-toggle a { padding: 7px 14px; font-size: 12.5px; font-weight: 600; color: var(--ia-text-3, #888); text-decoration: none; border-radius: 5px; transition: all 0.12s; }
  .rep-toggle a:hover { color: var(--ia-text, #f0f0f0); }
  .rep-toggle a.active { background: #BEF264; color: #0a0a0a; }
  .rep-customrange-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 14px; font-size: 12.5px; font-weight: 600;
    color: var(--ia-text-3, #888);
    background: transparent;
    border: 1px solid var(--ia-border, #1f1f1f);
    border-radius: 8px; cursor: pointer;
    font-family: inherit;
    transition: all 0.12s;
  }
  .rep-customrange-btn:hover { color: var(--ia-text, #f0f0f0); border-color: var(--ia-border-2, #2a2a2a); }
  .rep-customrange-btn.active { color: #0a0a0a; background: #BEF264; border-color: #BEF264; }

  /* KPI cards */
  .rep-kpi-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 28px; }
  @media (max-width: 1000px) { .rep-kpi-row { grid-template-columns: repeat(2, 1fr); } }
  .rep-kpi-card { background: var(--ia-surface, #131313); border: 1px solid var(--ia-border, #1f1f1f); border-radius: 14px; padding: 18px 20px; transition: border-color 0.15s; }
  .rep-kpi-card:hover { border-color: var(--ia-border-2, #2a2a2a); }
  .rep-kpi-label { font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.08em; color: var(--ia-text-3, #888); font-weight: 700; margin-bottom: 8px; }
  .rep-kpi-value { font-size: 28px; font-weight: 800; letter-spacing: -0.02em; line-height: 1; font-feature-settings: 'tnum'; }
  .rep-kpi-meta { display: flex; align-items: center; gap: 8px; margin-top: 8px; font-size: 12px; }
  .rep-delta { display: inline-flex; align-items: center; gap: 3px; padding: 2px 7px; border-radius: 99px; font-size: 11px; font-weight: 700; }
  .rep-delta.up   { background: rgba(52,211,153,0.12); color: #34D399; }
  .rep-delta.down { background: rgba(239,68,68,0.12); color: #f87171; }
  .rep-delta.flat { background: rgba(255,255,255,0.04); color: var(--ia-text-3, #888); }
  .rep-kpi-period { color: var(--ia-text-3, #888); font-size: 11.5px; }

  /* Zones */
  .rep-zone { background: var(--ia-surface, #131313); border: 1px solid var(--ia-border, #1f1f1f); border-radius: 14px; padding: 22px; margin-bottom: 18px; }
  .rep-zone-head { margin-bottom: 18px; }
  .rep-zone-title { font-size: 15px; font-weight: 800; letter-spacing: -0.01em; }
  .rep-zone-sub { font-size: 12px; color: var(--ia-text-3, #888); font-weight: 500; margin-top: 2px; }
  .rep-zone-warn { font-size: 11.5px; color: #F59E0B; font-weight: 600; margin-top: 4px; }

  /* Lists */
  .rep-list { display: flex; flex-direction: column; }
  .rep-list-row { display: grid; grid-template-columns: 1fr 90px 90px; gap: 14px; align-items: center; padding: 11px 0; border-bottom: 1px solid var(--ia-border, #1f1f1f); font-size: 13.5px; }
  .rep-list-row:last-child { border-bottom: none; }
  .rep-list-row.head { color: var(--ia-text-3, #888); font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.08em; font-weight: 700; padding-bottom: 8px; }
  .rep-list-row .num-cell { text-align: right; font-weight: 600; font-feature-settings: 'tnum'; }
  .rep-list-row .meta { font-size: 11px; font-weight: 500; color: var(--ia-text-3, #888); margin-top: 2px; }
  .rep-bar { position: relative; height: 6px; border-radius: 3px; background: rgba(255,255,255,0.04); overflow: hidden; margin-top: 6px; }
  .rep-bar .fill { position: absolute; top: 0; left: 0; bottom: 0; background: #BEF264; border-radius: 3px; }

  .rep-grid-2 { display: grid; grid-template-columns: 1.6fr 1fr; gap: 14px; }
  @media (max-width: 1000px) { .rep-grid-2 { grid-template-columns: 1fr; } }

  /* Staff cards */
  .rep-staff-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
  @media (max-width: 700px) { .rep-staff-grid { grid-template-columns: 1fr; } }
  .rep-staff-card { background: rgba(255,255,255,0.02); border: 1px solid var(--ia-border, #1f1f1f); border-radius: 10px; padding: 14px 16px; }
  .rep-staff-head { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
  .rep-swatch { width: 28px; height: 28px; border-radius: 50%; border: 1px solid rgba(255,255,255,0.1); flex-shrink: 0; }
  .rep-util-bar { position: relative; height: 6px; border-radius: 3px; background: rgba(255,255,255,0.04); margin: 8px 0 4px; overflow: hidden; }
  .rep-util-bar .fill { position: absolute; top: 0; left: 0; bottom: 0; border-radius: 3px; }
  .rep-util-bar .fill.healthy    { background: #BEF264; }
  .rep-util-bar .fill.underused  { background: #F59E0B; }
  .rep-util-bar .fill.overloaded { background: #EF4444; }
  .rep-staff-stats { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; padding-top: 12px; border-top: 1px solid var(--ia-border, #1f1f1f); }
  .rep-staff-stat-label { font-size: 9.5px; color: var(--ia-text-3, #888); font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 4px; }
  .rep-staff-stat-val { font-size: 16px; font-weight: 800; letter-spacing: -0.01em; font-feature-settings: 'tnum'; }

  /* Heatmap */
  .rep-heatmap { display: grid; grid-template-columns: 60px repeat(14, 1fr); gap: 2px; margin-top: 8px; }
  .rep-heatmap-row-label { font-size: 11px; color: var(--ia-text-3, #888); display: flex; align-items: center; padding-right: 6px; font-weight: 600; }
  .rep-heatmap-cell { aspect-ratio: 1.6 / 1; border-radius: 2px; background: rgba(255,255,255,0.02); }
  .rep-heatmap-cell[data-fill="0"]  { background: rgba(255,255,255,0.02); }
  .rep-heatmap-cell[data-fill="1"]  { background: rgba(190,242,100,0.08); }
  .rep-heatmap-cell[data-fill="2"]  { background: rgba(190,242,100,0.18); }
  .rep-heatmap-cell[data-fill="3"]  { background: rgba(190,242,100,0.32); }
  .rep-heatmap-cell[data-fill="4"]  { background: rgba(190,242,100,0.55); }
  .rep-heatmap-cell[data-fill="5"]  { background: #BEF264; }
  .rep-heatmap-axis { display: grid; grid-template-columns: 60px repeat(14, 1fr); gap: 2px; margin-top: 6px; }
  .rep-heatmap-axis-label { font-size: 9.5px; color: var(--ia-text-4, #5a5a5a); text-align: center; }

  .rep-empty { color: var(--ia-text-3, #888); font-size: 13px; padding: 12px 0; }

  /* Custom range modal */
  .rep-modal-backdrop {
    position: fixed; inset: 0; z-index: 9998;
    background: rgba(0,0,0,0.6); backdrop-filter: blur(4px);
    display: none; align-items: center; justify-content: center;
  }
  .rep-modal-backdrop.show { display: flex; }
  .rep-modal {
    background: #131313;
    border: 1px solid #2a2a2a;
    border-radius: 14px;
    padding: 20px 22px;
    max-width: 360px; width: 92vw;
    color: #f0f0f0;
    box-shadow: 0 12px 48px rgba(0,0,0,0.6);
  }
  .rep-modal-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
  .rep-modal-title { font-size: 14px; font-weight: 700; }
  .rep-modal-close { background: transparent; border: none; color: #888; cursor: pointer; font-size: 20px; padding: 0 4px; line-height: 1; font-family: inherit; }
  .rep-modal-close:hover { color: #f0f0f0; }
  .rep-modal-monthbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
  .rep-modal-monthlabel { font-size: 13px; font-weight: 600; }
  .rep-modal-arrow { background: transparent; border: 1px solid #2a2a2a; border-radius: 50%; width: 26px; height: 26px; color: #888; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; font-family: inherit; }
  .rep-modal-arrow:hover { color: #f0f0f0; border-color: #888; }
  .rep-modal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; }
  .rep-modal-dow { font-size: 10px; color: #5a5a5a; text-align: center; padding: 6px 0; font-weight: 600; text-transform: uppercase; }
  .rep-modal-day { aspect-ratio: 1; display: flex; align-items: center; justify-content: center; font-size: 12px; cursor: pointer; border-radius: 50%; color: #c8c8c8; transition: all 0.1s; user-select: none; }
  .rep-modal-day:hover { background: rgba(255,255,255,0.06); color: #f0f0f0; }
  .rep-modal-day.empty { cursor: default; pointer-events: none; }
  .rep-modal-day.future { color: #3a3a3a; cursor: not-allowed; pointer-events: none; }
  .rep-modal-day.start  { background: #BEF264; color: #0a0a0a; font-weight: 700; }
  .rep-modal-day.end    { background: #BEF264; color: #0a0a0a; font-weight: 700; }
  .rep-modal-day.in-range { background: rgba(190,242,100,0.18); color: #BEF264; border-radius: 0; }
  .rep-modal-day.start.in-range { border-radius: 50% 0 0 50%; }
  .rep-modal-day.end.in-range   { border-radius: 0 50% 50% 0; }
  .rep-modal-foot { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-top: 14px; padding-top: 14px; border-top: 1px solid #1f1f1f; }
  .rep-modal-summary { font-size: 12px; color: #888; }
  .rep-modal-summary strong { color: #f0f0f0; font-weight: 600; }
  .rep-modal-actions { display: flex; gap: 8px; }
  .rep-modal-btn { padding: 7px 14px; font-size: 12.5px; font-weight: 600; border-radius: 7px; cursor: pointer; font-family: inherit; border: 1px solid transparent; }
  .rep-modal-btn--ghost { background: transparent; color: #888; border-color: #2a2a2a; }
  .rep-modal-btn--ghost:hover { color: #f0f0f0; }
  .rep-modal-btn--primary { background: #BEF264; color: #0a0a0a; }
  .rep-modal-btn--primary:disabled { opacity: 0.4; cursor: not-allowed; }
</style>
@endpush

@section('content')
<div style="padding: 32px 40px;">

  <h1 class="rep-h1">Reports</h1>
  <div class="rep-sub">{{ $today_label }}</div>

  {{-- GLOBAL RANGE BAR --}}
  <div class="rep-rangebar">
    <div>
      <span class="rep-rangebar-label">Range</span>
      <span class="rep-rangebar-current">{{ $range_label }}</span>
    </div>
    <div class="rep-rangebar-controls">
      <nav class="rep-toggle">
        <a href="{{ route('tenant.reports.index', ['subdomain' => tenant()->subdomain, 'range' => 'today']) }}"  class="{{ $range === 'today' ? 'active' : '' }}">Today</a>
        <a href="{{ route('tenant.reports.index', ['subdomain' => tenant()->subdomain, 'range' => 'week']) }}"   class="{{ $range === 'week'  ? 'active' : '' }}">Week</a>
        <a href="{{ route('tenant.reports.index', ['subdomain' => tenant()->subdomain, 'range' => 'month']) }}"  class="{{ $range === 'month' ? 'active' : '' }}">Month</a>
      </nav>
      <button type="button" id="rep-customrange-btn" class="rep-customrange-btn {{ $range === 'custom' ? 'active' : '' }}">📅 Custom range</button>
    </div>
  </div>

  {{-- KPI ROW --}}
  <div class="rep-kpi-row">
    @foreach($kpis as $kpi)
      <div class="rep-kpi-card">
        <div class="rep-kpi-label">{{ $kpi['label'] }}</div>
        <div class="rep-kpi-value">
          @if($kpi['format'] === 'money')
            ${{ number_format($kpi['value_dollars']) }}
          @elseif($kpi['format'] === 'percent')
            {{ $kpi['value_int'] }}%
          @else
            {{ $kpi['value_int'] }}@if(!empty($kpi['capacity']))<span style="font-size:18px;color:var(--ia-text-3,#888)"> / {{ $kpi['capacity'] }} cap</span>@endif
          @endif
        </div>
        <div class="rep-kpi-meta">
          @if(!empty($kpi['delta']))
            <span class="rep-delta {{ $kpi['delta']['direction'] }}">
              @if($kpi['delta']['direction'] === 'up') ↑
              @elseif($kpi['delta']['direction'] === 'down') ↓
              @else —
              @endif
              {{ $kpi['delta']['value'] }}
            </span>
          @endif
          @if(!empty($kpi['detail']))
            <span class="rep-kpi-period">{{ $kpi['detail'] }}</span>
          @endif
          <span class="rep-kpi-period">{{ $kpi['period_label'] }}</span>
        </div>
      </div>
    @endforeach
  </div>

  {{-- ZONE: REVENUE --}}
  <section class="rep-zone">
    <div class="rep-zone-head">
      <div class="rep-zone-title">💰 Revenue</div>
      <div class="rep-zone-sub">
        ${{ number_format($revenue['total_cents'] / 100) }} total
        @if($revenue['best_bucket'])
          · best {{ $revenue['series_kind'] === 'hourly' ? 'hour' : 'day' }} {{ $revenue['best_bucket']['label'] }} (${{ number_format($revenue['best_bucket']['cents'] / 100) }})
        @endif
      </div>
    </div>

    <div class="rep-grid-2">
      <div>
        @php $maxBucketCents = max(array_column($revenue['series'], 'cents')) ?: 1; @endphp
        @if($revenue['total_cents'] > 0)
          <div style="display:flex;align-items:flex-end;gap:4px;height:200px;border-bottom:1px solid var(--ia-border, #1f1f1f);padding-bottom:8px;">
            @foreach($revenue['series'] as $bucket)
              <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%;">
                <div style="width:100%;background:#BEF264;border-radius:2px 2px 0 0;height:{{ max(2, round(($bucket['cents'] / $maxBucketCents) * 180)) }}px;opacity:{{ $bucket['cents'] > 0 ? 1 : 0.15 }};"></div>
              </div>
            @endforeach
          </div>
          <div style="display:flex;gap:4px;margin-top:6px;">
            @foreach($revenue['series'] as $bucket)
              <div style="flex:1;text-align:center;font-size:9.5px;color:var(--ia-text-4,#5a5a5a);font-family:ui-monospace,monospace;">{{ $bucket['label'] }}</div>
            @endforeach
          </div>
        @else
          <div class="rep-empty">No paid revenue in this range.</div>
        @endif
      </div>

      <div>
        <div style="font-size:11px;color:var(--ia-text-3,#888);text-transform:uppercase;letter-spacing:0.08em;font-weight:700;margin-bottom:8px;">By service</div>
        @if(count($revenue['by_service']))
          <div class="rep-list">
            @foreach($revenue['by_service'] as $svc)
              <div class="rep-list-row">
                <div>
                  <div style="font-weight:600;">{{ $svc['name'] }}</div>
                  <div class="meta">{{ $svc['bookings'] }} {{ $svc['bookings'] === 1 ? 'booking' : 'bookings' }}</div>
                </div>
                <div class="num-cell">${{ number_format($svc['cents'] / 100) }}</div>
                <div class="num-cell" style="color:var(--ia-text-3,#888);font-size:11.5px;">{{ $svc['pct'] }}%</div>
              </div>
            @endforeach
          </div>
        @else
          <div class="rep-empty">No service revenue in this range.</div>
        @endif
      </div>
    </div>
  </section>

  {{-- ZONE: BOOKINGS --}}
  <section class="rep-zone">
    <div class="rep-zone-head">
      <div class="rep-zone-title">📅 Bookings &amp; cancellations</div>
      <div class="rep-zone-sub">{{ $bookings['confirmed'] }} confirmed · {{ $bookings['cancelled'] }} cancelled · {{ $bookings['no_shows'] }} no-show · {{ $bookings['walkins'] }} walk-in</div>
    </div>

    @php $maxBkCount = max(array_column($bookings['timeline'], 'count')) ?: 1; @endphp
    <div style="display:flex;align-items:flex-end;gap:6px;height:120px;border-bottom:1px solid var(--ia-border, #1f1f1f);padding-bottom:8px;">
      @foreach($bookings['timeline'] as $bk)
        <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%;">
          <div style="font-size:10px;color:var(--ia-text-3,#888);margin-bottom:4px;font-feature-settings:'tnum';">{{ $bk['count'] }}</div>
          <div style="width:100%;background:#BEF264;border-radius:2px 2px 0 0;height:{{ max(2, round(($bk['count'] / $maxBkCount) * 90)) }}px;opacity:{{ $bk['count'] > 0 ? 0.85 : 0.15 }};"></div>
        </div>
      @endforeach
    </div>
    <div style="display:flex;gap:6px;margin-top:6px;">
      @foreach($bookings['timeline'] as $bk)
        <div style="flex:1;text-align:center;font-size:10px;color:var(--ia-text-4,#5a5a5a);font-family:ui-monospace,monospace;">{{ $bk['label'] }}</div>
      @endforeach
    </div>
  </section>

  {{-- ZONE: CUSTOMERS --}}
  <section class="rep-zone">
    <div class="rep-zone-head">
      <div class="rep-zone-title">👤 Customers &amp; retention</div>
      <div class="rep-zone-sub">Top spenders + new vs. returning</div>
    </div>

    <div class="rep-grid-2">
      <div>
        <div style="font-size:11px;color:var(--ia-text-3,#888);text-transform:uppercase;letter-spacing:0.08em;font-weight:700;margin-bottom:8px;">New vs returning</div>
        @php $maxDailyTotal = max(array_map(fn($d) => $d['new'] + $d['returning'], $customers['daily'])) ?: 1; @endphp
        <div style="display:flex;align-items:flex-end;gap:2px;height:160px;border-bottom:1px solid var(--ia-border, #1f1f1f);padding-bottom:8px;">
          @foreach($customers['daily'] as $day)
            @php
              $total = $day['new'] + $day['returning'];
              $hPx = max(2, round(($total / $maxDailyTotal) * 140));
              $newH = $total > 0 ? round($hPx * ($day['new'] / $total)) : 0;
              $retH = $hPx - $newH;
            @endphp
            <div style="flex:1;display:flex;flex-direction:column;justify-content:flex-end;height:100%;">
              @if($newH > 0)<div style="background:rgba(190,242,100,0.4);height:{{ $newH }}px;"></div>@endif
              @if($retH > 0)<div style="background:#BEF264;height:{{ $retH }}px;border-radius:0 0 1px 1px;"></div>@endif
            </div>
          @endforeach
        </div>
        <div style="display:flex;gap:14px;margin-top:8px;font-size:11px;color:var(--ia-text-3,#888);">
          <span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:#BEF264;margin-right:5px;vertical-align:-1px;"></span>Returning</span>
          <span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:rgba(190,242,100,0.4);margin-right:5px;vertical-align:-1px;"></span>New</span>
        </div>
      </div>

      <div>
        <div style="font-size:11px;color:var(--ia-text-3,#888);text-transform:uppercase;letter-spacing:0.08em;font-weight:700;margin-bottom:8px;">Top customers</div>
        @if(count($customers['top_customers']))
          <div class="rep-list">
            @foreach($customers['top_customers'] as $c)
              <div class="rep-list-row">
                <div>
                  <div style="font-weight:600;">{{ $c['name'] }}</div>
                  <div class="meta">{{ $c['visits'] }} {{ $c['visits'] === 1 ? 'visit' : 'visits' }} · {{ $c['is_new_in_period'] ? 'new this period' : 'returning' }}</div>
                </div>
                <div class="num-cell">${{ number_format($c['cents'] / 100) }}</div>
                <div class="num-cell" style="color:var(--ia-text-3,#888);font-size:11.5px;">{{ $c['visits'] }}</div>
              </div>
            @endforeach
          </div>
        @else
          <div class="rep-empty">No customer revenue in this range.</div>
        @endif
      </div>
    </div>
  </section>

  {{-- ZONE: SERVICES --}}
  <section class="rep-zone">
    <div class="rep-zone-head">
      <div class="rep-zone-title">🔧 Service popularity</div>
      <div class="rep-zone-sub">{{ count($services['services']) }} services with paid bookings</div>
    </div>

    @if(count($services['services']))
      <div class="rep-list">
        <div class="rep-list-row head">
          <div>Service</div>
          <div class="num-cell">Bookings</div>
          <div class="num-cell">Revenue</div>
        </div>
        @foreach($services['services'] as $svc)
          <div class="rep-list-row">
            <div>
              <div style="font-weight:600;">{{ $svc['name'] }}</div>
              <div class="rep-bar"><div class="fill" style="width:{{ $svc['bar_pct'] }}%"></div></div>
            </div>
            <div class="num-cell">{{ $svc['bookings'] }}</div>
            <div class="num-cell">${{ number_format($svc['cents'] / 100) }}</div>
          </div>
        @endforeach
      </div>
    @else
      <div class="rep-empty">No paid services in this range.</div>
    @endif
  </section>

  {{-- ZONE: STAFF --}}
  <section class="rep-zone">
    <div class="rep-zone-head">
      <div class="rep-zone-title">👥 Staff utilization</div>
      <div class="rep-zone-sub">8h/day available baseline</div>
    </div>

    @if(count($staff['cards']))
      <div class="rep-staff-grid">
        @foreach($staff['cards'] as $card)
          <div class="rep-staff-card">
            <div class="rep-staff-head">
              <div class="rep-swatch" style="background: {{ $card['color_hex'] ?: '#888' }};"></div>
              <div>
                <div style="font-weight:600;">{{ $card['name'] }}</div>
                <div style="font-size:11px;color:var(--ia-text-3,#888);">{{ $card['subtitle'] }}</div>
              </div>
            </div>
            <div class="rep-util-bar">
              <div class="fill {{ $card['health'] }}" style="width: {{ $card['utilization'] }}%;"></div>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--ia-text-3,#888);margin-bottom:6px;">
              <span>{{ $card['utilization'] }}% booked</span>
              <span style="font-feature-settings:'tnum';">{{ $card['booked_hrs'] }} / {{ $card['available_hrs'] }} hrs</span>
            </div>
            <div class="rep-staff-stats">
              <div><div class="rep-staff-stat-label">Appts</div><div class="rep-staff-stat-val">{{ $card['appts'] }}</div></div>
              <div><div class="rep-staff-stat-label">Revenue</div><div class="rep-staff-stat-val">${{ number_format($card['revenue_cents'] / 100) }}</div></div>
              <div><div class="rep-staff-stat-label">No-show</div><div class="rep-staff-stat-val">{{ $card['no_show_rate'] }}%</div></div>
            </div>
          </div>
        @endforeach
      </div>
    @else
      <div class="rep-empty">No staff resources to report on yet.</div>
    @endif
  </section>

  {{-- ZONE: CAPACITY --}}
  <section class="rep-zone">
    <div class="rep-zone-head">
      <div class="rep-zone-title">🔥 Capacity utilization</div>
      <div class="rep-zone-sub">8a–9p · darker = busier</div>
      @if(!empty($capacity['used_fallback']))
        <div class="rep-zone-warn">⚠ Range too short for heatmap — showing last 14 days ({{ $capacity['fallback_label'] }}) instead.</div>
      @endif
    </div>

    <div class="rep-heatmap">
      @foreach($capacity['grid'] as $row)
        <div class="rep-heatmap-row-label">{{ $row['label'] }}</div>
        @foreach($row['cells'] as $cell)
          <div class="rep-heatmap-cell" data-fill="{{ $cell['fill'] }}" title="{{ $row['label'] }} {{ Carbon\Carbon::createFromTime($cell['hour'])->format('ga') }}: {{ $cell['count'] }} bookings"></div>
        @endforeach
      @endforeach
    </div>
    <div class="rep-heatmap-axis">
      <div></div>
      @foreach($capacity['hour_labels'] as $label)
        <div class="rep-heatmap-axis-label">{{ $label }}</div>
      @endforeach
    </div>
  </section>

</div>

{{-- CUSTOM RANGE MODAL --}}
<div class="rep-modal-backdrop" id="rep-modal-backdrop" role="dialog" aria-modal="true">
  <div class="rep-modal" id="rep-modal">
    <div class="rep-modal-head">
      <div class="rep-modal-title">Pick a date range</div>
      <button type="button" class="rep-modal-close" id="rep-modal-close" aria-label="Close">×</button>
    </div>
    <div class="rep-modal-monthbar">
      <button type="button" class="rep-modal-arrow" id="rep-modal-prev" aria-label="Previous month">‹</button>
      <div class="rep-modal-monthlabel" id="rep-modal-monthlabel"></div>
      <button type="button" class="rep-modal-arrow" id="rep-modal-next" aria-label="Next month">›</button>
    </div>
    <div class="rep-modal-grid" id="rep-modal-grid"></div>
    <div class="rep-modal-foot">
      <div class="rep-modal-summary" id="rep-modal-summary">Click a day to start.</div>
      <div class="rep-modal-actions">
        <button type="button" class="rep-modal-btn rep-modal-btn--ghost" id="rep-modal-clear">Clear</button>
        <button type="button" class="rep-modal-btn rep-modal-btn--primary" id="rep-modal-apply" disabled>Apply</button>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  const SUBDOMAIN = @json(tenant()->subdomain);
  const TODAY     = @json(Carbon\Carbon::today()->toDateString());

  // Pre-fill from URL if range=custom
  const urlParams = new URLSearchParams(window.location.search);
  let viewYear, viewMonth;  // currently displayed month in modal
  let selStart = urlParams.get('range') === 'custom' ? urlParams.get('from') : null;
  let selEnd   = urlParams.get('range') === 'custom' ? urlParams.get('to')   : null;

  const todayDate = new Date(TODAY + 'T00:00:00');
  if (selStart) {
    const d = new Date(selStart + 'T00:00:00');
    viewYear  = d.getFullYear();
    viewMonth = d.getMonth();
  } else {
    viewYear  = todayDate.getFullYear();
    viewMonth = todayDate.getMonth();
  }

  const backdrop  = document.getElementById('rep-modal-backdrop');
  const closeBtn  = document.getElementById('rep-modal-close');
  const openBtn   = document.getElementById('rep-customrange-btn');
  const prevBtn   = document.getElementById('rep-modal-prev');
  const nextBtn   = document.getElementById('rep-modal-next');
  const monthLbl  = document.getElementById('rep-modal-monthlabel');
  const grid      = document.getElementById('rep-modal-grid');
  const summary   = document.getElementById('rep-modal-summary');
  const clearBtn  = document.getElementById('rep-modal-clear');
  const applyBtn  = document.getElementById('rep-modal-apply');

  const MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  const DOWS   = ['S','M','T','W','T','F','S'];

  function fmt(d) {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + day;
  }

  function fmtPretty(s) {
    const d = new Date(s + 'T00:00:00');
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
  }

  function render() {
    monthLbl.textContent = MONTHS[viewMonth] + ' ' + viewYear;
    grid.innerHTML = '';

    // DOW header row
    DOWS.forEach(d => {
      const el = document.createElement('div');
      el.className = 'rep-modal-dow';
      el.textContent = d;
      grid.appendChild(el);
    });

    const firstOfMonth = new Date(viewYear, viewMonth, 1);
    const startDow = firstOfMonth.getDay();
    const daysInMonth = new Date(viewYear, viewMonth + 1, 0).getDate();

    // Empty cells before day 1
    for (let i = 0; i < startDow; i++) {
      const empty = document.createElement('div');
      empty.className = 'rep-modal-day empty';
      grid.appendChild(empty);
    }

    for (let day = 1; day <= daysInMonth; day++) {
      const cell = document.createElement('div');
      cell.className = 'rep-modal-day';
      cell.textContent = day;

      const cellDate = new Date(viewYear, viewMonth, day);
      const cellStr = fmt(cellDate);

      if (cellDate > todayDate) {
        cell.classList.add('future');
      } else {
        cell.addEventListener('click', () => onDayClick(cellStr));
      }

      // Decorate selection state
      if (selStart && cellStr === selStart) cell.classList.add('start');
      if (selEnd   && cellStr === selEnd)   cell.classList.add('end');
      if (selStart && selEnd && cellStr > selStart && cellStr < selEnd) {
        cell.classList.add('in-range');
      }
      if (selStart && selEnd && cellStr === selStart) cell.classList.add('in-range');
      if (selStart && selEnd && cellStr === selEnd)   cell.classList.add('in-range');

      grid.appendChild(cell);
    }

    // Update summary + apply button
    if (selStart && selEnd) {
      summary.innerHTML = '<strong>' + fmtPretty(selStart) + '</strong> to <strong>' + fmtPretty(selEnd) + '</strong>';
      applyBtn.disabled = false;
    } else if (selStart) {
      summary.innerHTML = 'Start: <strong>' + fmtPretty(selStart) + '</strong> — click an end date.';
      applyBtn.disabled = false;  // single day is valid
    } else {
      summary.textContent = 'Click a day to start.';
      applyBtn.disabled = true;
    }
  }

  function onDayClick(dateStr) {
    if (!selStart || (selStart && selEnd)) {
      // Start fresh
      selStart = dateStr;
      selEnd   = null;
    } else {
      // Already have a start — set end (or swap if user picked earlier)
      if (dateStr < selStart) {
        selEnd = selStart;
        selStart = dateStr;
      } else if (dateStr === selStart) {
        // Clicking the same day: treat as a single-day selection
        selEnd = dateStr;
      } else {
        selEnd = dateStr;
      }
    }
    render();
  }

  function open() {
    backdrop.classList.add('show');
    document.body.style.overflow = 'hidden';
    render();
  }
  function close() {
    backdrop.classList.remove('show');
    document.body.style.overflow = '';
  }

  openBtn.addEventListener('click', open);
  closeBtn.addEventListener('click', close);
  backdrop.addEventListener('click', (e) => { if (e.target === backdrop) close(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });

  prevBtn.addEventListener('click', () => {
    viewMonth--;
    if (viewMonth < 0) { viewMonth = 11; viewYear--; }
    render();
  });
  nextBtn.addEventListener('click', () => {
    viewMonth++;
    if (viewMonth > 11) { viewMonth = 0; viewYear++; }
    render();
  });

  clearBtn.addEventListener('click', () => {
    selStart = null;
    selEnd   = null;
    render();
  });

  applyBtn.addEventListener('click', () => {
    if (!selStart) return;
    const from = selStart;
    const to   = selEnd || selStart;  // single-day = from === to
    const url  = new URL(window.location.href);
    url.searchParams.set('range', 'custom');
    url.searchParams.set('from',  from);
    url.searchParams.set('to',    to);
    window.location.href = url.toString();
  });

  // If we land here with custom range, render once on load so the calendar
  // is pre-populated when the modal opens.
  render();
})();
</script>
@endsection
