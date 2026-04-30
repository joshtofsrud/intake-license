@extends('layouts.tenant.app')
@section('title', 'Reports')

@push('styles')
<style>
  .rep-h1 { font-size: 28px; font-weight: 800; letter-spacing: -0.02em; margin-bottom: 4px; }
  .rep-sub { color: var(--ia-text-3, #888); font-size: 13.5px; margin-bottom: 24px; }

  .rep-kpi-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 28px; }
  @media (max-width: 1000px) { .rep-kpi-row { grid-template-columns: repeat(2, 1fr); } }

  .rep-kpi-card {
    background: var(--ia-surface, #131313);
    border: 1px solid var(--ia-border, #1f1f1f);
    border-radius: 14px; padding: 18px 20px;
    transition: border-color 0.15s;
  }
  .rep-kpi-card:hover { border-color: var(--ia-border-2, #2a2a2a); }
  .rep-kpi-label {
    font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.08em;
    color: var(--ia-text-3, #888); font-weight: 700; margin-bottom: 8px;
  }
  .rep-kpi-value {
    font-size: 28px; font-weight: 800; letter-spacing: -0.02em;
    line-height: 1; font-feature-settings: 'tnum';
  }
  .rep-kpi-meta { display: flex; align-items: center; gap: 8px; margin-top: 8px; font-size: 12px; }
  .rep-delta { display: inline-flex; align-items: center; gap: 3px; padding: 2px 7px; border-radius: 99px; font-size: 11px; font-weight: 700; }
  .rep-delta.up   { background: rgba(52,211,153,0.12); color: #34D399; }
  .rep-delta.down { background: rgba(239,68,68,0.12); color: #f87171; }
  .rep-delta.flat { background: rgba(255,255,255,0.04); color: var(--ia-text-3, #888); }
  .rep-kpi-period { color: var(--ia-text-3, #888); font-size: 11.5px; }

  .rep-zone {
    background: var(--ia-surface, #131313);
    border: 1px solid var(--ia-border, #1f1f1f);
    border-radius: 14px; padding: 22px;
    margin-bottom: 18px;
  }
  .rep-zone-head {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 18px; gap: 14px;
  }
  .rep-zone-title { font-size: 15px; font-weight: 800; letter-spacing: -0.01em; }
  .rep-zone-sub { font-size: 12px; color: var(--ia-text-3, #888); font-weight: 500; margin-top: 2px; }

  .rep-list { display: flex; flex-direction: column; }
  .rep-list-row {
    display: grid; grid-template-columns: 1fr 90px 90px;
    gap: 14px; align-items: center;
    padding: 11px 0;
    border-bottom: 1px solid var(--ia-border, #1f1f1f);
    font-size: 13.5px;
  }
  .rep-list-row:last-child { border-bottom: none; }
  .rep-list-row.head {
    color: var(--ia-text-3, #888); font-size: 10.5px;
    text-transform: uppercase; letter-spacing: 0.08em;
    font-weight: 700; padding-bottom: 8px;
  }
  .rep-list-row .num-cell { text-align: right; font-weight: 600; font-feature-settings: 'tnum'; }
  .rep-list-row .meta { font-size: 11px; font-weight: 500; color: var(--ia-text-3, #888); margin-top: 2px; }
  .rep-bar { position: relative; height: 6px; border-radius: 3px; background: rgba(255,255,255,0.04); overflow: hidden; margin-top: 6px; }
  .rep-bar .fill { position: absolute; top: 0; left: 0; bottom: 0; background: #BEF264; border-radius: 3px; }

  .rep-grid-2 { display: grid; grid-template-columns: 1.6fr 1fr; gap: 14px; }
  @media (max-width: 1000px) { .rep-grid-2 { grid-template-columns: 1fr; } }

  .rep-staff-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
  @media (max-width: 700px) { .rep-staff-grid { grid-template-columns: 1fr; } }
  .rep-staff-card {
    background: rgba(255,255,255,0.02);
    border: 1px solid var(--ia-border, #1f1f1f);
    border-radius: 10px; padding: 14px 16px;
  }
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
</style>
@endpush

@section('content')
<div style="padding: 32px 40px;">

  <h1 class="rep-h1">Reports</h1>
  <div class="rep-sub">{{ $today_label }} · daily ops view</div>

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
      <div>
        <div class="rep-zone-title">💰 Revenue</div>
        <div class="rep-zone-sub">
          ${{ number_format($revenue['total_cents'] / 100) }} today
          @if($revenue['best_hour'])
            · best hour {{ $revenue['best_hour']['label'] }} (${{ number_format($revenue['best_hour']['cents'] / 100) }})
          @endif
        </div>
      </div>
    </div>

    <div class="rep-grid-2">
      <div>
        @php
          $maxHourCents = max(array_column($revenue['hourly'], 'cents')) ?: 1;
        @endphp
        @if($revenue['total_cents'] > 0)
          <div style="display:flex;align-items:flex-end;gap:4px;height:200px;border-bottom:1px solid var(--ia-border, #1f1f1f);padding-bottom:8px;">
            @foreach($revenue['hourly'] as $hr)
              <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%;">
                <div style="width:100%;background:#BEF264;border-radius:2px 2px 0 0;height:{{ max(2, round(($hr['cents'] / $maxHourCents) * 180)) }}px;opacity:{{ $hr['cents'] > 0 ? 1 : 0.15 }};"></div>
              </div>
            @endforeach
          </div>
          <div style="display:flex;gap:4px;margin-top:6px;">
            @foreach($revenue['hourly'] as $hr)
              <div style="flex:1;text-align:center;font-size:9.5px;color:var(--ia-text-4,#5a5a5a);font-family:ui-monospace,monospace;">{{ $hr['label'] }}</div>
            @endforeach
          </div>
        @else
          <div class="rep-empty">No paid revenue today yet.</div>
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
          <div class="rep-empty">No service revenue today.</div>
        @endif
      </div>
    </div>
  </section>

  {{-- ZONE: BOOKINGS --}}
  <section class="rep-zone">
    <div class="rep-zone-head">
      <div>
        <div class="rep-zone-title">📅 Bookings &amp; cancellations</div>
        <div class="rep-zone-sub">{{ $bookings['confirmed'] }} confirmed · {{ $bookings['cancelled'] }} cancelled · {{ $bookings['no_shows'] }} no-show · {{ $bookings['walkins'] }} walk-in</div>
      </div>
    </div>

    @php
      $maxBkCount = max(array_column($bookings['timeline'], 'count')) ?: 1;
    @endphp
    <div style="display:flex;align-items:flex-end;gap:6px;height:120px;border-bottom:1px solid var(--ia-border, #1f1f1f);padding-bottom:8px;">
      @foreach($bookings['timeline'] as $day)
        <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%;">
          <div style="font-size:10px;color:var(--ia-text-3,#888);margin-bottom:4px;font-feature-settings:'tnum';">{{ $day['count'] }}</div>
          <div style="width:100%;background:#BEF264;border-radius:2px 2px 0 0;height:{{ max(2, round(($day['count'] / $maxBkCount) * 90)) }}px;opacity:{{ $day['count'] > 0 ? 0.85 : 0.15 }};"></div>
        </div>
      @endforeach
    </div>
    <div style="display:flex;gap:6px;margin-top:6px;">
      @foreach($bookings['timeline'] as $day)
        <div style="flex:1;text-align:center;font-size:10px;color:var(--ia-text-4,#5a5a5a);font-family:ui-monospace,monospace;">{{ $day['label'] }}</div>
      @endforeach
    </div>
  </section>

  {{-- ZONE: CUSTOMERS --}}
  <section class="rep-zone">
    <div class="rep-zone-head">
      <div>
        <div class="rep-zone-title">👤 Customers &amp; retention</div>
        <div class="rep-zone-sub">{{ $customers['month_label'] }} · top spenders + daily new vs. returning</div>
      </div>
    </div>

    <div class="rep-grid-2">
      <div>
        <div style="font-size:11px;color:var(--ia-text-3,#888);text-transform:uppercase;letter-spacing:0.08em;font-weight:700;margin-bottom:8px;">New vs returning · {{ $customers['month_label'] }}</div>
        @php
          $maxDailyTotal = max(array_map(fn($d) => $d['new'] + $d['returning'], $customers['daily'])) ?: 1;
        @endphp
        <div style="display:flex;align-items:flex-end;gap:2px;height:160px;border-bottom:1px solid var(--ia-border, #1f1f1f);padding-bottom:8px;">
          @foreach($customers['daily'] as $day)
            @php
              $total = $day['new'] + $day['returning'];
              $hPx = max(2, round(($total / $maxDailyTotal) * 140));
              $newH = $total > 0 ? round($hPx * ($day['new'] / $total)) : 0;
              $retH = $hPx - $newH;
            @endphp
            <div style="flex:1;display:flex;flex-direction:column;justify-content:flex-end;height:100%;">
              @if($newH > 0)
                <div style="background:rgba(190,242,100,0.4);height:{{ $newH }}px;"></div>
              @endif
              @if($retH > 0)
                <div style="background:#BEF264;height:{{ $retH }}px;border-radius:0 0 1px 1px;"></div>
              @endif
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
                  <div class="meta">{{ $c['visits'] }} {{ $c['visits'] === 1 ? 'visit' : 'visits' }} · {{ $c['is_new_this_month'] ? 'new this month' : 'returning' }}</div>
                </div>
                <div class="num-cell">${{ number_format($c['cents'] / 100) }}</div>
                <div class="num-cell" style="color:var(--ia-text-3,#888);font-size:11.5px;">{{ $c['visits'] }}</div>
              </div>
            @endforeach
          </div>
        @else
          <div class="rep-empty">No customer revenue this month.</div>
        @endif
      </div>
    </div>
  </section>

  {{-- ZONE: SERVICES --}}
  <section class="rep-zone">
    <div class="rep-zone-head">
      <div>
        <div class="rep-zone-title">🔧 Service popularity</div>
        <div class="rep-zone-sub">Trailing 30 days · {{ count($services['services']) }} services with paid bookings</div>
      </div>
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
      <div class="rep-empty">No paid services in the last 30 days.</div>
    @endif
  </section>

  {{-- ZONE: STAFF --}}
  <section class="rep-zone">
    <div class="rep-zone-head">
      <div>
        <div class="rep-zone-title">👥 Staff utilization</div>
        <div class="rep-zone-sub">Trailing 7 days · 8h/day available baseline</div>
      </div>
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
              <div>
                <div class="rep-staff-stat-label">Appts</div>
                <div class="rep-staff-stat-val">{{ $card['appts'] }}</div>
              </div>
              <div>
                <div class="rep-staff-stat-label">Revenue</div>
                <div class="rep-staff-stat-val">${{ number_format($card['revenue_cents'] / 100) }}</div>
              </div>
              <div>
                <div class="rep-staff-stat-label">No-show</div>
                <div class="rep-staff-stat-val">{{ $card['no_show_rate'] }}%</div>
              </div>
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
      <div>
        <div class="rep-zone-title">🔥 Capacity utilization</div>
        <div class="rep-zone-sub">Last 14 days · 8a–9p · darker = busier</div>
      </div>
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
@endsection
