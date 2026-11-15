@extends('layouts.tenant.app')
{{-- what you're holding, what's moving, what's stuck.
     Read-only: this page computes and displays, it never writes. --}}
@section('title', 'Inventory reports')

@section('content')
<div class="ia-section ia-section--wide">{{-- fluid, capped; see base.css --}}

{{-- the header goes above the tabs, as on every
     other page in this section; this page had them the other way round. --}}
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

{{-- the shared inventory tab bar. The first
     version of this page rendered no tabs at all, so it read as a
     different app and Reports was unreachable from the other screens.
     and it belongs AFTER the header, not inside
     it: nested in that flex row the tabs sat beside the title and clipped. --}}
@include('layouts.tenant._inventory-tabs')

{{-- .ia-stats-grid / .ia-stat / .ia-stat-label
     / .ia-stat-value / .ia-stat-delta are the app's real components. The
     first version invented its own and drifted on size, weight and
     tracking. --}}
<div class="ia-stats-grid">
  <div class="ia-stat">
    <div class="ia-stat-label">Inventory at cost</div>
    <div class="ia-stat-value">{{ format_money($valuation['cost_cents']) }}</div>
    <div class="ia-stat-delta">{{ number_format($valuation['units']) }} units · {{ number_format($valuation['skus']) }} SKUs</div>
  </div>
  <div class="ia-stat">
    <div class="ia-stat-label">Retail value</div>
    <div class="ia-stat-value">{{ format_money($valuation['retail_cents']) }}</div>
    <div class="ia-stat-delta">
      @if($valuation['margin_pct'] !== null){{ $valuation['margin_pct'] }}% margin on hand @else no prices set @endif
    </div>
  </div>
  <div class="ia-stat">
    <div class="ia-stat-label">Turns · trailing 12mo</div>
    <div class="ia-stat-value">
      @if($turns['turns'] !== null){{ $turns['turns'] }}&times;@else &mdash; @endif
    </div>
    {{-- Said plainly: there's no inventory history to average, so this is
         measured against stock as it stands today. --}}
    <div class="ia-stat-delta">{{ format_money($turns['cogs_12mo_cents']) }} sold at cost, vs stock now</div>
  </div>
  <div class="ia-stat">
    <div class="ia-stat-label">Dead stock</div>
    <div class="ia-stat-value">{{ format_money($dead['cost_cents']) }}</div>
    <div class="ia-stat-delta">{{ number_format($dead['skus']) }} SKUs · nothing sold in {{ $dead['days'] }}d</div>
  </div>
</div>

@if(($valuation['negative_skus'] ?? 0) > 0)
  {{-- Sold but never received. Not a display quirk — the stock figures
       can't be right until these are reconciled. --}}
  <div class="ia-flash ia-flash--info" style="margin-bottom:16px">
    {{ number_format($valuation['negative_skus']) }}
    {{ Str::plural('item', $valuation['negative_skus']) }} show negative stock — sold without ever being received.
    Value totals below count them as zero, not as negative stock.
  </div>
@endif

<div class="ia-card" style="margin-bottom:16px">
  <div class="ia-card-head" style="gap:10px;flex-wrap:wrap">
    <div class="ia-card-title">By category</div>
    <span class="ivr-hint">movement over the last {{ $days }} days</span>
    {{-- MARKER-INV-REPORT-LEVELS: top-level totals, or every category on its own --}}
    <div class="ivr-window" style="margin-left:auto">
      <a href="{{ route('tenant.inventory.reports', ['days' => $days, 'level' => 'top', 'sort' => $sort, 'dir' => $dir]) }}" class="{{ $level === 'top' ? 'on' : '' }}">Top level</a>
      <a href="{{ route('tenant.inventory.reports', ['days' => $days, 'level' => 'all', 'sort' => $sort, 'dir' => $dir]) }}" class="{{ $level === 'all' ? 'on' : '' }}">Every level</a>
    </div>
  </div>
  @if(count($categories))
    @php
      // MARKER-INV-REPORT-SORT: every heading sorts; the same one again flips it
      $th = function (string $key, string $label, bool $num = true) use ($days, $level, $sort, $dir) {
          $nextDir = $sort === $key ? ($dir === 'asc' ? 'desc' : 'asc') : ($key === 'name' ? 'asc' : 'desc');
          $arrow = $sort === $key ? ($dir === 'asc' ? '▲' : '▼') : '↕';
          return '<th class="' . ($num ? 'ia-num ' : '') . 'ivr-sort' . ($sort === $key ? ' on' : '') . '"><a href="'
              . e(route('tenant.inventory.reports', ['days' => $days, 'level' => $level, 'sort' => $key, 'dir' => $nextDir]))
              . '">' . e($label) . '<span class="ar">' . $arrow . '</span></a></th>';
      };
      $cell = function (array $c) {
          $st = $c['sell_through_pct'] !== null
              ? '<span class="ivr-bar"><span style="width:' . min(100, $c['sell_through_pct']) . '%"></span></span>' . $c['sell_through_pct'] . '%'
              : '&mdash;';
          return '<td class="ia-num">' . number_format($c['skus']) . '</td><td class="ia-num">' . number_format($c['units']) . '</td><td class="ia-num">'
              . e(format_money($c['cost_cents'])) . '</td><td class="ia-num">' . rtrim(rtrim(number_format($c['sold_units'], 2), '0'), '.') . '</td><td class="ia-num">' . $st . '</td>';
      };
    @endphp
    <table class="ia-table">
      <thead>
        <tr>{!! $th('name', 'Category', false) !!}{!! $th('skus', 'SKUs') !!}{!! $th('units', 'On hand') !!}{!! $th('cost', 'At cost') !!}{!! $th('sold', 'Sold') !!}{!! $th('st', 'Sell-through') !!}</tr>
      </thead>
      <tbody>
        @foreach($categories as $c)
          @php $kids = $c['children'] ?? []; @endphp
          <tr class="{{ $kids ? 'ivr-par' : '' }}" @if($kids) data-ivr-open="{{ $loop->index }}" title="Show what makes this up" @endif>
            <td>
              @if($level === 'top')<span class="ivr-tw">{{ $kids ? '▸' : '' }}</span>@endif
              {{ $c['category'] }}
              @if($level === 'all' && $c['parent'])<span class="ivr-chip">{{ $c['parent'] }}</span>@endif
            </td>
            {!! $cell($c) !!}
          </tr>
          @foreach($kids as $k)
            <tr class="ivr-kid" data-ivr-kid="{{ $loop->parent->index }}" hidden><td>{{ $k['category'] }}</td>{!! $cell($k) !!}</tr>
          @endforeach
        @endforeach
      </tbody>
    </table>
    <script>
      document.querySelectorAll('[data-ivr-open]').forEach(function (row) {
        row.addEventListener('click', function () {
          var kids = document.querySelectorAll('[data-ivr-kid="' + row.dataset.ivrOpen + '"]');
          var opening = kids.length && kids[0].hidden;
          kids.forEach(function (k) { k.hidden = !opening; });
          row.querySelector('.ivr-tw').textContent = opening ? '▾' : '▸';
        });
      });
    </script>
  @else
    <div class="ivr-empty">No active inventory yet.</div>
  @endif
</div>

<div class="ivr-two">
  <div class="ia-card">
    <div class="ia-card-head"><div class="ia-card-title">Movers</div><span class="ivr-hint">last {{ $days }} days</span></div>
    @if(count($movers['top']))
      <table class="ia-table">
        <thead><tr><th>Item</th><th class="ia-num">Sold</th><th class="ia-num">On hand</th></tr></thead>
        <tbody>
          @foreach($movers['top'] as $m)
            <tr>
              <td><a href="{{ route('tenant.inventory.show', $m['id']) }}">{{ $m['name'] }}</a><div class="ivr-sku">{{ $m['sku'] }}</div></td>
              <td class="ia-num">{{ rtrim(rtrim(number_format($m['units'], 2), '0'), '.') }}</td>
              <td class="ia-num">{{ number_format($m['on_hand']) }}</td>
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
      <table class="ia-table">
        <thead><tr><th>Item</th><th class="ia-num">On hand</th><th class="ia-num">Tied up</th></tr></thead>
        <tbody>
          @foreach($dead['items']->take(10) as $d)
            <tr>
              <td><a href="{{ route('tenant.inventory.show', $d->id) }}">{{ $d->name }}</a><div class="ivr-sku">{{ $d->sku }}</div></td>
              <td class="ia-num">{{ number_format($d->computed_stock_count) }}</td>
              <td class="ia-num">{{ format_money($d->tied_cents) }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @else
      <div class="ivr-empty">Nothing has been sitting longer than {{ $dead['days'] }} days.</div>
    @endif
  </div>
</div>
</div>
@endsection

@push('styles')
<style>
  /* only what base.css doesn't already have.
     Stat cards and tables now use .ia-stat / .ia-table, so their type,
     weights and spacing come from the app rather than from here. */
  .ivr-window{display:inline-flex;background:var(--ia-surface);border:1px solid var(--ia-border);border-radius:9px;padding:3px}
  .ivr-window a{padding:5px 11px;font-size:12px;font-weight:600;border-radius:6px;color:var(--ia-text-dim);text-decoration:none}
  .ivr-window a.on{background:var(--ia-surface-2);color:var(--ia-text)}
  .ia-table tbody tr{cursor:default}
  .ivr-hint{font-size:11.5px;color:var(--ia-text-muted)}
  .ivr-sku{font-size:11px;color:var(--ia-text-muted);font-family:ui-monospace,Menlo,monospace;margin-top:1px}
  .ivr-bar{display:inline-block;width:52px;height:5px;border-radius:99px;background:rgba(127,127,127,.2);
    overflow:hidden;vertical-align:middle;margin-right:7px}
  .ivr-bar>span{display:block;height:100%;background:var(--ia-accent)}
  /* MARKER-INV-REPORT-LEVELS / MARKER-INV-REPORT-SORT */
  .ivr-sort a{color:inherit;text-decoration:none;white-space:nowrap}
  .ivr-sort .ar{opacity:.35;margin-left:4px;font-size:10px}
  .ivr-sort.on .ar{opacity:1;color:var(--ia-accent)}
  .ivr-par{cursor:pointer !important}.ivr-par td:first-child{font-weight:500}
  .ivr-tw{display:inline-block;width:16px;color:var(--ia-text-muted)}
  .ivr-kid td:first-child{padding-left:38px;color:var(--ia-text-muted)}
  .ivr-chip{display:inline-block;margin-left:8px;font-size:11px;padding:1px 8px;border-radius:99px;background:var(--ia-surface-2);color:var(--ia-text-muted)}
  .ivr-empty{padding:28px 16px;text-align:center;color:var(--ia-text-muted);font-size:13px}
  .ivr-two{display:grid;grid-template-columns:1fr 1fr;gap:16px}
  @media (max-width:1000px){ .ivr-two{grid-template-columns:1fr} }
</style>
@endpush
