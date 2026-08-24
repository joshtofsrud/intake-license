@extends('layouts.tenant.app')
{{-- MARKER-INV-REPORTS — what you're holding, what's moving, what's stuck.
     Read-only: this page computes and displays, it never writes. --}}
@section('title', 'Inventory reports')

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Inventory reports</h1>
    <p class="ia-page-subtitle">What you're holding, what's moving, what's stuck.</p>
  </div>
  <div style="display:flex;gap:8px;align-items:center">
    <div class="ivr-window">
      @foreach([30 => '30d', 90 => '90d', 180 => '6mo', 365 => '12mo'] as $d => $lbl)
        <a href="{{ route('tenant.inventory.reports', ['days' => $d]) }}"
           class="{{ $days === $d ? 'on' : '' }}">{{ $lbl }}</a>
      @endforeach
    </div>
    <a href="{{ route('tenant.inventory.reports', ['days' => $days, 'export' => 'csv']) }}" class="ia-btn ia-btn--sm">Export CSV</a>
  </div>
</div>

<div class="ivr-stats">
  <div class="ivr-stat">
    <div class="ivr-stat-label">Inventory at cost</div>
    <div class="ivr-stat-value">{{ format_money($valuation['cost_cents']) }}</div>
    <div class="ivr-stat-note">{{ number_format($valuation['units']) }} units · {{ number_format($valuation['skus']) }} SKUs</div>
  </div>
  <div class="ivr-stat">
    <div class="ivr-stat-label">Retail value</div>
    <div class="ivr-stat-value">{{ format_money($valuation['retail_cents']) }}</div>
    <div class="ivr-stat-note">
      @if($valuation['margin_pct'] !== null){{ $valuation['margin_pct'] }}% margin on hand @else no prices set @endif
    </div>
  </div>
  <div class="ivr-stat">
    <div class="ivr-stat-label">Turns · trailing 12mo</div>
    <div class="ivr-stat-value">
      @if($turns['turns'] !== null){{ $turns['turns'] }}&times;@else &mdash; @endif
    </div>
    {{-- Said plainly: there's no inventory history to average, so this is
         against stock as it stands today. --}}
    <div class="ivr-stat-note">{{ format_money($turns['cogs_12mo_cents']) }} cost of goods sold, vs stock on hand now</div>
  </div>
  <div class="ivr-stat {{ $dead['cost_cents'] > 0 ? 'is-warn' : '' }}">
    <div class="ivr-stat-label">Dead stock</div>
    <div class="ivr-stat-value">{{ format_money($dead['cost_cents']) }}</div>
    <div class="ivr-stat-note">{{ number_format($dead['skus']) }} SKUs · nothing sold in {{ $dead['days'] }}d</div>
  </div>
</div>

<div class="ia-card" style="margin-bottom:16px">
  <div class="ia-card-head">
    <div class="ia-card-title">By category</div>
    <span class="ivr-hint">movement over the last {{ $days }} days</span>
  </div>
  @if(count($categories))
    <table class="ia-table ivr-table">
      <thead>
        <tr><th>Category</th><th class="num">SKUs</th><th class="num">On hand</th><th class="num">At cost</th><th class="num">Sold</th><th class="num">Sell-through</th></tr>
      </thead>
      <tbody>
        @foreach($categories as $c)
          <tr>
            <td>{{ $c['category'] }}</td>
            <td class="num">{{ number_format($c['skus']) }}</td>
            <td class="num">{{ number_format($c['units']) }}</td>
            <td class="num">{{ format_money($c['cost_cents']) }}</td>
            <td class="num">{{ rtrim(rtrim(number_format($c['sold_units'], 2), '0'), '.') }}</td>
            <td class="num">
              @if($c['sell_through_pct'] !== null)
                <span class="ivr-bar"><span style="width:{{ min(100, $c['sell_through_pct']) }}%"></span></span>
                {{ $c['sell_through_pct'] }}%
              @else
                &mdash;
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @else
    <div class="ivr-empty">No active inventory yet.</div>
  @endif
</div>

<div class="ivr-two">
  <div class="ia-card">
    <div class="ia-card-head"><div class="ia-card-title">Movers</div><span class="ivr-hint">last {{ $days }} days</span></div>
    @if(count($movers['top']))
      <table class="ia-table ivr-table">
        <thead><tr><th>Item</th><th class="num">Sold</th><th class="num">On hand</th></tr></thead>
        <tbody>
          @foreach($movers['top'] as $m)
            <tr>
              <td><a href="{{ route('tenant.inventory.show', $m['id']) }}">{{ $m['name'] }}</a><div class="ivr-sku">{{ $m['sku'] }}</div></td>
              <td class="num">{{ rtrim(rtrim(number_format($m['units'], 2), '0'), '.') }}</td>
              <td class="num">{{ number_format($m['on_hand']) }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @else
      <div class="ivr-empty">Nothing sold in this window.</div>
    @endif
  </div>

  <div class="ia-card">
    <div class="ia-card-head">
      <div class="ia-card-title">Stuck</div>
      <span class="ivr-hint">most money tied up</span>
    </div>
    @if($dead['items']->count())
      <table class="ia-table ivr-table">
        <thead><tr><th>Item</th><th class="num">On hand</th><th class="num">Tied up</th></tr></thead>
        <tbody>
          @foreach($dead['items']->take(10) as $d)
            <tr>
              <td><a href="{{ route('tenant.inventory.show', $d->id) }}">{{ $d->name }}</a><div class="ivr-sku">{{ $d->sku }}</div></td>
              <td class="num">{{ number_format($d->computed_stock_count) }}</td>
              <td class="num">{{ format_money($d->tied_cents) }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @else
      <div class="ivr-empty">Nothing has been sitting longer than {{ $dead['days'] }} days.</div>
    @endif
  </div>
</div>
@endsection

@push('styles')
<style>
  .ivr-window{display:inline-flex;background:var(--ia-surface);border:1px solid var(--ia-border);border-radius:9px;padding:3px}
  .ivr-window a{padding:5px 11px;font-size:12px;font-weight:600;border-radius:6px;color:var(--ia-text-dim);text-decoration:none}
  .ivr-window a.on{background:var(--ia-surface-2);color:var(--ia-text)}
  .ivr-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px}
  .ivr-stat{background:var(--ia-surface);border:.5px solid var(--ia-border);border-radius:var(--ia-r-lg,12px);padding:16px 18px}
  .ivr-stat.is-warn{box-shadow:inset 3px 0 0 #FBBF24}
  .ivr-stat-label{font-size:10.5px;text-transform:uppercase;letter-spacing:.08em;color:var(--ia-text-muted);font-weight:700}
  .ivr-stat-value{font-size:26px;font-weight:300;letter-spacing:-.02em;margin-top:8px;line-height:1}
  .ivr-stat-note{font-size:11.5px;color:var(--ia-text-dim);margin-top:8px;line-height:1.5}
  .ivr-hint{font-size:11.5px;color:var(--ia-text-muted)}
  .ivr-table{width:100%;font-size:12.5px}
  .ivr-table th{font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-muted);font-weight:700;text-align:left;padding:8px 10px}
  .ivr-table td{padding:9px 10px;border-top:.5px solid rgba(127,127,127,.12)}
  .ivr-table .num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
  .ivr-table a{color:var(--ia-text);text-decoration:none}
  .ivr-table a:hover{text-decoration:underline}
  .ivr-sku{font-size:11px;color:var(--ia-text-muted);font-family:ui-monospace,Menlo,monospace;margin-top:1px}
  .ivr-bar{display:inline-block;width:52px;height:5px;border-radius:99px;background:rgba(127,127,127,.2);
    overflow:hidden;vertical-align:middle;margin-right:7px}
  .ivr-bar>span{display:block;height:100%;background:var(--ia-accent)}
  .ivr-empty{padding:28px 16px;text-align:center;color:var(--ia-text-muted);font-size:12.5px}
  .ivr-two{display:grid;grid-template-columns:1fr 1fr;gap:16px}
  @media (max-width:1000px){ .ivr-stats{grid-template-columns:1fr 1fr} .ivr-two{grid-template-columns:1fr} }
  @media (max-width:560px){ .ivr-stats{grid-template-columns:1fr} }
</style>
@endpush
