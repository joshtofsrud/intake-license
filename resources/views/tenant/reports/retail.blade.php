@extends('layouts.tenant.app')
@section('title', 'Reports · Retail')

@push('styles')
@include('tenant.reports._tab_styles')
@endpush

@section('content')
<div style="padding: 32px 40px;">
  <h1 class="rep-h1">Reports</h1>
  <div class="rep-sub">{{ $today_label }}</div>

  @include('tenant.reports._tab_subnav', ['active' => 'retail'])

  <div class="rep-rangebar">
    <div><span class="rep-rangebar-label">Range</span><span class="rep-rangebar-current">{{ $range_label }}</span></div>
    <div class="rep-rangebar-controls">
      <nav class="rep-toggle">
        <a href="{{ route('tenant.reports.retail', ['range' => 'today']) }}"  class="{{ $range === 'today' ? 'active' : '' }}">Today</a>
        <a href="{{ route('tenant.reports.retail', ['range' => 'week']) }}"   class="{{ $range === 'week'  ? 'active' : '' }}">Week</a>
        <a href="{{ route('tenant.reports.retail', ['range' => 'month']) }}"  class="{{ $range === 'month' ? 'active' : '' }}">Month</a>
      </nav>
    </div>
  </div>

  {{-- Sales summary --}}
  <div class="rep-zone">
    <div class="rep-zone-head"><div><div class="rep-zone-title">💰 Sales</div><div class="rep-zone-sub">Paid retail sales, refunds excluded.</div></div></div>
    <div class="rep-stat-strip">
      <div class="rep-stat-cell feat"><div class="lbl">Revenue</div><div class="val">${{ number_format($salesSummary['revenue_cents'] / 100, 2) }}</div><div class="meta">paid sales</div></div>
      <div class="rep-stat-cell"><div class="lbl">Sales</div><div class="val">{{ number_format($salesSummary['count']) }}</div><div class="meta">transactions</div></div>
      <div class="rep-stat-cell"><div class="lbl">Avg ticket</div><div class="val">${{ number_format($salesSummary['avg_ticket_cents'] / 100, 2) }}</div><div class="meta">per sale</div></div>
    </div>
  </div>

  {{-- Sales by user --}}
  <div class="rep-zone">
    <div class="rep-zone-head"><div><div class="rep-zone-title">👤 Sales by user</div><div class="rep-zone-sub">Who rang up what. Ranked by revenue.</div></div></div>
    @if($is_locked)
      @include('tenant.reports._locked_list', ['kind' => 'sales_by_user'])
    @elseif(empty($salesByUser['list']))
      <div class="rep-empty">No retail sales in range.</div>
    @else
      <table class="rep-tbl"><thead><tr><th>User</th><th class="right">Sales</th><th class="right">Revenue</th></tr></thead><tbody>
        @foreach($salesByUser['list'] as $u)
          <tr><td><span class="rep-cell-name">{{ $u['name'] }}</span></td><td class="right">{{ number_format($u['sale_count']) }}</td><td class="right" style="color:#BEF264;">${{ number_format($u['revenue_cents']/100, 2) }}</td></tr>
        @endforeach
      </tbody></table>
    @endif
  </div>

  {{-- Top SKUs --}}
  <div class="rep-zone">
    <div class="rep-zone-head"><div><div class="rep-zone-title">📦 Top SKUs</div><div class="rep-zone-sub">Best-selling inventory items by quantity sold.</div></div></div>
    @if($is_locked)
      @include('tenant.reports._locked_list', ['kind' => 'top_skus'])
    @elseif(empty($topSkus['list']))
      <div class="rep-empty">No product sales in range.</div>
    @else
      <table class="rep-tbl"><thead><tr><th>Item</th><th class="right">Qty</th><th class="right">Revenue</th></tr></thead><tbody>
        @foreach($topSkus['list'] as $s)
          <tr><td><span class="rep-cell-name">{{ $s['name'] }}</span></td><td class="right">{{ number_format($s['qty']) }}</td><td class="right" style="color:#BEF264;">${{ number_format($s['revenue_cents']/100, 2) }}</td></tr>
        @endforeach
      </tbody></table>
    @endif
  </div>

  {{-- Margin --}}
  <div class="rep-zone">
    <div class="rep-zone-head"><div><div class="rep-zone-title">📊 Margin</div><div class="rep-zone-sub">Retail margin — revenue vs cost-at-time on product sales where cost is known.</div></div></div>
    <div class="rep-stat-strip">
      <div class="rep-stat-cell"><div class="lbl">Revenue</div><div class="val">${{ number_format($margin['revenue_cents']/100, 2) }}</div></div>
      <div class="rep-stat-cell"><div class="lbl">Cost</div><div class="val">${{ number_format($margin['cost_cents']/100, 2) }}</div></div>
      <div class="rep-stat-cell feat"><div class="lbl">Margin</div><div class="val">${{ number_format($margin['margin_cents']/100, 2) }}</div><div class="meta">{{ $margin['margin_pct'] }}%</div></div>
    </div>
  </div>

  {{-- Inventory health --}}
  <div class="rep-zone">
    <div class="rep-zone-head"><div><div class="rep-zone-title">🩺 Inventory health</div><div class="rep-zone-sub">Low stock (≤ {{ $inventoryHealth['low_threshold'] }}) and dead stock (no sale in {{ $inventoryHealth['dead_days'] }}d).</div></div></div>
    <div class="rep-stat-strip">
      <div class="rep-stat-cell {{ $inventoryHealth['low_count'] > 0 ? 'warn' : '' }}"><div class="lbl">Low stock</div><div class="val">{{ number_format($inventoryHealth['low_count']) }}</div><div class="meta">items at or below {{ $inventoryHealth['low_threshold'] }}</div></div>
      <div class="rep-stat-cell {{ $inventoryHealth['dead_count'] > 0 ? 'warn' : '' }}"><div class="lbl">Dead stock</div><div class="val">{{ number_format($inventoryHealth['dead_count']) }}</div><div class="meta">no sale in {{ $inventoryHealth['dead_days'] }}d</div></div>
    </div>
    @if($is_locked)
      @include('tenant.reports._locked_list', ['kind' => 'inventory'])
    @elseif(!empty($inventoryHealth['low_list']))
      <h4 style="margin-top:18px;font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#888;font-weight:700;">Low stock</h4>
      <table class="rep-tbl"><thead><tr><th>Item</th><th>SKU</th><th class="right">Stock</th></tr></thead><tbody>
        @foreach($inventoryHealth['low_list'] as $i)
          <tr><td>{{ $i['name'] }}</td><td>{{ $i['sku'] }}</td><td class="right" style="color:#F59E0B;">{{ $i['stock'] }}</td></tr>
        @endforeach
      </tbody></table>
    @endif
  </div>

  {{-- Receiving --}}
  <div class="rep-zone">
    <div class="rep-zone-head"><div><div class="rep-zone-title">📥 Receiving</div><div class="rep-zone-sub">Inventory received in range.</div></div></div>
    <div class="rep-stat-strip">
      <div class="rep-stat-cell"><div class="lbl">Shipments</div><div class="val">{{ number_format($receiving['shipment_count']) }}</div></div>
      <div class="rep-stat-cell"><div class="lbl">Total cost</div><div class="val">${{ number_format($receiving['total_cost_cents']/100, 2) }}</div></div>
    </div>
  </div>

</div>

@if($is_locked)
@include('tenant.reports._upsell_modal', ['title' => 'Retail Reports', 'pitch' => 'See sales by user, top SKUs, margin on every product, and dead-stock alerts. Know what\'s working and what\'s not.'])
@endif
@endsection
