@extends('layouts.tenant.app')
@section('title', 'Reports · Services')

@push('styles')
<style>
  .rep-h1 { font-size: 28px; font-weight: 800; letter-spacing: -0.02em; margin-bottom: 4px; }
  .rep-sub { color: var(--ia-text-3, #888); font-size: 13.5px; margin-bottom: 24px; }

  .rep-toggle { display: inline-flex; gap: 4px; background: rgba(255,255,255,0.02); border: 1px solid var(--ia-border, #1f1f1f); border-radius: 8px; padding: 3px; }
  .rep-toggle a { padding: 7px 14px; font-size: 12.5px; font-weight: 600; color: var(--ia-text-3, #888); text-decoration: none; border-radius: 5px; transition: all 0.12s; }
  .rep-toggle a:hover { color: var(--ia-text, #f0f0f0); }
  .rep-toggle a.active { background: #BEF264; color: #0a0a0a; }

  .rep-rangebar { display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap; padding: 14px 16px; margin-bottom: 24px; background: var(--ia-surface, #131313); border: 1px solid var(--ia-border, #1f1f1f); border-radius: 12px; }
  .rep-rangebar-label { font-size: 11.5px; color: var(--ia-text-3, #888); text-transform: uppercase; letter-spacing: 0.08em; font-weight: 700; }
  .rep-rangebar-current { font-size: 14px; font-weight: 700; color: var(--ia-text, #f0f0f0); margin-left: 8px; }
  .rep-rangebar-controls { display: inline-flex; gap: 6px; align-items: center; }

  .rep-zone { background: var(--ia-surface, #131313); border: 1px solid var(--ia-border, #1f1f1f); border-radius: 14px; padding: 22px; margin-bottom: 18px; position: relative; }
  .rep-zone-head { margin-bottom: 18px; display: flex; align-items: flex-start; justify-content: space-between; gap: 14px; flex-wrap: wrap; }
  .rep-zone-title { font-size: 15px; font-weight: 800; letter-spacing: -0.01em; }
  .rep-zone-sub { font-size: 12px; color: var(--ia-text-3, #888); font-weight: 500; margin-top: 2px; }

  .rep-stat-strip { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 0; border-top: 0.5px solid var(--ia-border, #1f1f1f); border-bottom: 0.5px solid var(--ia-border, #1f1f1f); margin: 14px 0; }
  .rep-stat-cell { padding: 16px 18px; border-right: 0.5px solid var(--ia-border, #1f1f1f); }
  .rep-stat-cell:last-child { border-right: none; }
  .rep-stat-cell .lbl { font-size: 10.5px; letter-spacing: 0.08em; text-transform: uppercase; color: var(--ia-text-3, #888); font-weight: 700; margin-bottom: 8px; }
  .rep-stat-cell .val { font-size: 24px; font-weight: 800; letter-spacing: -0.02em; line-height: 1; font-feature-settings: 'tnum'; }
  .rep-stat-cell.feat .val { color: #BEF264; }
  .rep-stat-cell.warn .val { color: #F59E0B; }
  .rep-stat-cell .meta { font-size: 11px; color: var(--ia-text-3, #888); margin-top: 6px; }

  table.rep-tbl { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 14px; }
  table.rep-tbl th { text-align: left; padding: 10px 12px; font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.08em; color: var(--ia-text-3, #888); font-weight: 700; border-bottom: 1px solid var(--ia-border, #1f1f1f); }
  table.rep-tbl th.right { text-align: right; }
  table.rep-tbl td { padding: 11px 12px; border-bottom: 1px solid var(--ia-border, #1f1f1f); vertical-align: top; }
  table.rep-tbl td.right { text-align: right; font-feature-settings: 'tnum'; font-weight: 600; }
  table.rep-tbl tr:last-child td { border-bottom: none; }
  .rep-cell-name { color: var(--ia-text, #f0f0f0); font-weight: 600; }
  .rep-cell-meta { color: var(--ia-text-3, #888); font-size: 11px; margin-top: 2px; }

  .rep-empty { padding: 28px 18px; text-align: center; color: var(--ia-text-3, #888); font-size: 13px; }
  .rep-stub { padding: 32px 22px; text-align: center; color: var(--ia-text-3, #888); font-size: 13px; background: rgba(245,158,11,0.04); border: 1px dashed rgba(245,158,11,0.2); border-radius: 10px; margin-top: 14px; }
  .rep-stub strong { color: #F59E0B; font-weight: 700; }

  /* Sparkline-ish bar for throughput */
  .rep-bars { display: flex; gap: 2px; align-items: flex-end; height: 80px; padding: 14px 0; }
  .rep-bars .bar { flex: 1; background: #BEF264; border-radius: 2px 2px 0 0; min-height: 2px; opacity: 0.9; }
  .rep-bars .bar:hover { opacity: 1; }

  /* Locked-state — copied from customers.blade.php */
  .rep-locked-list { position: relative; }
  .rep-locked-list table.rep-tbl,
  .rep-locked-list .rep-bars { filter: blur(5px); user-select: none; pointer-events: none; }
  .rep-locked-overlay { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; pointer-events: none; }
  .rep-locked-badge { display: inline-flex; align-items: center; gap: 8px; background: var(--ia-surface-2, #1a1a1a); border: 1px solid var(--ia-border-2, #2a2a2a); border-radius: 99px; padding: 8px 16px; font-size: 12.5px; font-weight: 700; color: var(--ia-text, #f0f0f0); box-shadow: 0 6px 20px rgba(0,0,0,0.5); pointer-events: auto; }
  .rep-locked-badge .lime { color: #BEF264; }
  .rep-locked-badge button { background: #BEF264; color: #0a0a0a; border: none; border-radius: 99px; padding: 5px 12px; font-size: 11.5px; font-weight: 700; cursor: pointer; margin-left: 4px; font-family: inherit; }

  .rep-upsell-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 1000; display: none; align-items: center; justify-content: center; padding: 20px; backdrop-filter: blur(2px); }
  .rep-upsell-backdrop.open { display: flex; }
  .rep-upsell-modal { background: var(--ia-surface, #131313); border: 1px solid var(--ia-border-2, #2a2a2a); border-radius: 16px; max-width: 460px; width: 100%; padding: 28px; position: relative; box-shadow: 0 20px 60px rgba(0,0,0,0.6); }
  .rep-upsell-modal .close { position: absolute; top: 14px; right: 14px; background: transparent; border: none; color: var(--ia-text-3, #888); font-size: 20px; cursor: pointer; padding: 4px 8px; line-height: 1; }
  .rep-upsell-modal .badge { display: inline-block; background: rgba(190,242,100,0.12); color: #BEF264; font-size: 10.5px; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; padding: 4px 10px; border-radius: 99px; margin-bottom: 14px; }
  .rep-upsell-modal h2 { font-size: 22px; font-weight: 800; margin-bottom: 10px; }
  .rep-upsell-modal p { font-size: 13.5px; line-height: 1.55; color: var(--ia-text-2, #c8c8c8); margin-bottom: 20px; }
  .rep-upsell-modal .cta-row { display: flex; gap: 10px; }
  .rep-upsell-modal .cta-primary { background: #BEF264; color: #0a0a0a; border: none; border-radius: 8px; padding: 11px 20px; font-size: 13px; font-weight: 700; text-decoration: none; cursor: pointer; font-family: inherit; }
  .rep-upsell-modal .cta-secondary { background: transparent; color: var(--ia-text-3, #888); border: 1px solid var(--ia-border, #1f1f1f); border-radius: 8px; padding: 11px 18px; font-size: 13px; font-weight: 600; cursor: pointer; font-family: inherit; }
</style>
@endpush

@section('content')
<div style="padding: 32px 40px;">

  <h1 class="rep-h1">Reports</h1>
  <div class="rep-sub">{{ $today_label }}</div>

  @include('tenant.reports._tab_subnav', ['active' => 'services'])

  <div class="rep-rangebar">
    <div>
      <span class="rep-rangebar-label">Range</span>
      <span class="rep-rangebar-current">{{ $range_label }}</span>
    </div>
    <div class="rep-rangebar-controls">
      <nav class="rep-toggle">
        <a href="{{ route('tenant.reports.services', ['subdomain' => tenant()->subdomain, 'range' => 'today']) }}"  class="{{ $range === 'today' ? 'active' : '' }}">Today</a>
        <a href="{{ route('tenant.reports.services', ['subdomain' => tenant()->subdomain, 'range' => 'week']) }}"   class="{{ $range === 'week'  ? 'active' : '' }}">Week</a>
        <a href="{{ route('tenant.reports.services', ['subdomain' => tenant()->subdomain, 'range' => 'month']) }}"  class="{{ $range === 'month' ? 'active' : '' }}">Month</a>
        <a href="{{ route('tenant.reports.services', ['subdomain' => tenant()->subdomain, 'range' => 'last_30']) }}" class="{{ $range === 'last_30' ? 'active' : '' }}">Last 30</a>
      </nav>
    </div>
  </div>

  {{-- ============ Throughput ============ --}}
  <div class="rep-zone">
    <div class="rep-zone-head">
      <div>
        <div class="rep-zone-title">📈 Throughput</div>
        <div class="rep-zone-sub">Delivered appointments per day across the range.</div>
      </div>
    </div>

    <div class="rep-stat-strip">
      <div class="rep-stat-cell feat">
        <div class="lbl">Delivered</div>
        <div class="val">{{ number_format($throughput['total_delivered']) }}</div>
        <div class="meta">across {{ $throughput['range_days'] }} {{ \Illuminate\Support\Str::plural('day', $throughput['range_days']) }}</div>
      </div>
      <div class="rep-stat-cell">
        <div class="lbl">Avg per day</div>
        <div class="val">{{ $throughput['avg_per_day'] }}</div>
        <div class="meta">appointments delivered</div>
      </div>
    </div>

    @if($is_locked)
      <div class="rep-locked-list">
        <div class="rep-bars" aria-hidden="true">
          @for($i = 0; $i < 14; $i++)<div class="bar" style="height: {{ rand(20, 90) }}%"></div>@endfor
        </div>
        <div class="rep-locked-overlay">
          <div class="rep-locked-badge">
            <span>🔒</span>
            <span><span class="lime">Branded feature</span> — see your daily throughput</span>
            <button type="button" onclick="document.getElementById('rep-upsell-backdrop').classList.add('open')">Upgrade</button>
          </div>
        </div>
      </div>
    @elseif(empty($throughput['daily']) || $throughput['total_delivered'] === 0)
      <div class="rep-empty">No delivered appointments in this range yet.</div>
    @else
      @php $maxCount = max(array_map(fn($d) => $d['count'], $throughput['daily'])) ?: 1; @endphp
      <div class="rep-bars" title="Throughput by day">
        @foreach($throughput['daily'] as $d)
          <div class="bar" style="height: {{ max(2, ($d['count'] / $maxCount) * 100) }}%" title="{{ $d['date'] }}: {{ $d['count'] }}"></div>
        @endforeach
      </div>
    @endif
  </div>

  {{-- ============ Service mix ============ --}}
  <div class="rep-zone">
    <div class="rep-zone-head">
      <div>
        <div class="rep-zone-title">🧰 Service mix</div>
        <div class="rep-zone-sub">Top services by revenue across the range. Counted from line items on delivered appointments.</div>
      </div>
    </div>

    <div class="rep-stat-strip">
      <div class="rep-stat-cell">
        <div class="lbl">Line items</div>
        <div class="val">{{ number_format($serviceMix['total_line_items']) }}</div>
        <div class="meta">across delivered appts</div>
      </div>
      <div class="rep-stat-cell feat">
        <div class="lbl">Service revenue</div>
        <div class="val">${{ number_format($serviceMix['total_revenue'] / 100, 2) }}</div>
        <div class="meta">snapshot prices</div>
      </div>
    </div>

    @if($is_locked)
      @include('tenant.reports._locked_list', ['kind' => 'service_mix'])
    @elseif(empty($serviceMix['top']))
      <div class="rep-empty">No service line items in this range.</div>
    @else
      <table class="rep-tbl">
        <thead><tr><th>Service</th><th class="right">Count</th><th class="right">Revenue</th></tr></thead>
        <tbody>
          @foreach($serviceMix['top'] as $s)
            <tr>
              <td><span class="rep-cell-name">{{ $s['name'] }}</span></td>
              <td class="right">{{ number_format($s['count']) }}</td>
              <td class="right" style="color:#BEF264;">${{ number_format($s['cents'] / 100, 2) }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>

  {{-- ============ Parts attach ============ --}}
  <div class="rep-zone">
    <div class="rep-zone-head">
      <div>
        <div class="rep-zone-title">🔩 Parts attach</div>
        <div class="rep-zone-sub">How often parts get added to service work, and how much they earn.</div>
      </div>
    </div>

    <div class="rep-stat-strip">
      <div class="rep-stat-cell feat">
        <div class="lbl">Attach rate</div>
        <div class="val">{{ $partsAttach['attach_pct'] }}%</div>
        <div class="meta">{{ $partsAttach['appts_with_parts'] }} of {{ $partsAttach['total_appts'] }} appts</div>
      </div>
      <div class="rep-stat-cell">
        <div class="lbl">Parts revenue</div>
        <div class="val">${{ number_format($partsAttach['parts_revenue_cents'] / 100, 2) }}</div>
        <div class="meta">{{ $partsAttach['parts_to_service_pct'] }}% of service revenue</div>
      </div>
      <div class="rep-stat-cell">
        <div class="lbl">Parts margin</div>
        <div class="val">${{ number_format($partsAttach['parts_margin_cents'] / 100, 2) }}</div>
        <div class="meta">revenue − cost-at-time</div>
      </div>
    </div>
  </div>

  {{-- ============ Comebacks ============ --}}
  <div class="rep-zone">
    <div class="rep-zone-head">
      <div>
        <div class="rep-zone-title">🔄 Comebacks</div>
        <div class="rep-zone-sub">Customers who returned within {{ $comebacks['window_days'] }} days after a delivered appointment in the range.</div>
      </div>
    </div>

    <div class="rep-stat-strip">
      <div class="rep-stat-cell">
        <div class="lbl">Initial appts</div>
        <div class="val">{{ number_format($comebacks['firsts_in_range']) }}</div>
        <div class="meta">in range</div>
      </div>
      <div class="rep-stat-cell {{ $comebacks['comeback_rate'] > 10 ? 'warn' : '' }}">
        <div class="lbl">Comebacks</div>
        <div class="val">{{ number_format($comebacks['comebacks']) }}</div>
        <div class="meta">within {{ $comebacks['window_days'] }} days</div>
      </div>
      <div class="rep-stat-cell {{ $comebacks['comeback_rate'] > 10 ? 'warn' : 'feat' }}">
        <div class="lbl">Rate</div>
        <div class="val">{{ $comebacks['comeback_rate'] }}%</div>
        <div class="meta">return within window</div>
      </div>
    </div>
  </div>

  {{-- ============ Production by resource ============ --}}
  <div class="rep-zone">
    <div class="rep-zone-head">
      <div>
        <div class="rep-zone-title">🏗️ Production by resource</div>
        <div class="rep-zone-sub">Appointments + revenue per station / chair / bay. Resource colors set in Resources settings.</div>
      </div>
    </div>

    <div class="rep-stat-strip">
      <div class="rep-stat-cell">
        <div class="lbl">Assigned appts</div>
        <div class="val">{{ number_format($productionByResource['total_appts']) }}</div>
        <div class="meta">with a resource set</div>
      </div>
    </div>

    @if($is_locked)
      @include('tenant.reports._locked_list', ['kind' => 'production'])
    @elseif(empty($productionByResource['list']))
      <div class="rep-empty">No appointments with assigned resources in this range.</div>
    @else
      <table class="rep-tbl">
        <thead><tr><th>Resource</th><th class="right">Appts</th><th class="right">Revenue</th></tr></thead>
        <tbody>
          @foreach($productionByResource['list'] as $r)
            <tr>
              <td><span class="rep-cell-name">{{ $r['resource_name'] }}</span></td>
              <td class="right">{{ number_format($r['appt_count']) }}</td>
              <td class="right" style="color:#BEF264;">${{ number_format($r['revenue_cents'] / 100, 2) }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>

  {{-- ============ Mechanic productivity — stub ============ --}}
  <div class="rep-zone">
    <div class="rep-zone-head">
      <div>
        <div class="rep-zone-title">👷 Mechanic productivity</div>
        <div class="rep-zone-sub">Per-staff appointment volume, revenue, and average ticket. Coming with staff assignment.</div>
      </div>
    </div>
    <div class="rep-stub">
      <strong>Coming soon.</strong> {{ $mechanicProductivity['reason'] }}
    </div>
  </div>

  {{-- ============ Estimate accuracy — stub ============ --}}
  <div class="rep-zone">
    <div class="rep-zone-head">
      <div>
        <div class="rep-zone-title">🎯 Estimate accuracy</div>
        <div class="rep-zone-sub">Quoted vs actual — how close your estimates land to the final ticket. Coming with the estimates feature.</div>
      </div>
    </div>
    <div class="rep-stub">
      <strong>Coming soon.</strong> {{ $estimateAccuracy['reason'] }}
    </div>
  </div>

</div>

@if($is_locked)
<div id="rep-upsell-backdrop" class="rep-upsell-backdrop open" onclick="if (event.target === this) closeRepUpsell()">
  <div class="rep-upsell-modal" role="dialog">
    <button type="button" class="close" onclick="closeRepUpsell()" aria-label="Close">×</button>
    <div class="badge">Branded feature</div>
    <h2>Services Reports</h2>
    <p>See throughput, service mix, parts attach rate, comebacks, and production by resource. Spot trends and tune your shop's day.</p>
    <div class="cta-row">
      <a class="cta-primary" href="{{ route('tenant.team.index', ['subdomain' => tenant()->subdomain]) }}">Upgrade to Branded →</a>
      <button type="button" class="cta-secondary" onclick="closeRepUpsell()">Maybe later</button>
    </div>
  </div>
</div>
<script>
  function closeRepUpsell() { document.getElementById('rep-upsell-backdrop').classList.remove('open'); }
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeRepUpsell(); });
</script>
@endif

@endsection
