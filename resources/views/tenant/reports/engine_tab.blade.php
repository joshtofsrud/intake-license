@extends('layouts.tenant.app')
@section('title', 'Reports · ' . $title)

@push('styles')
@include('tenant.reports._tab_styles')
<link rel="stylesheet" href="{{ asset('css/tenant/report-builder.css') }}?v={{ filemtime(public_path('css/tenant/report-builder.css')) }}">
@endpush

@section('content')
{{-- MARKER-REPORT-TABS-ENGINE — a Reports tab drawn from the report engine. Read-only: nothing here changes shop data. --}}
@php
  $tabRoute = 'tenant.reports.' . $tab;
  $base = ['range' => $q['slug'], 'compare' => $q['compare']] + ($q['range'] === 'Custom' ? ['from' => $q['from'], 'to' => $q['to']] : []);
  $fmtDate = fn ($a, $b) => $a->isSameDay($b) ? $a->format('M j, Y') : ($a->year === $b->year ? $a->format('M j') . ' – ' . $b->format('M j, Y') : $a->format('M j, Y') . ' – ' . $b->format('M j, Y'));
  $openMap = [];
  foreach ($panels as $p) { if (!empty($p['open'])) $openMap[$p['id']] = ['title' => $p['title'], 'rows' => $p['open']['rows'], 'url' => $p['open']['url']]; }
@endphp
<div class="ia-section ia-section--wide" id="rx-app" data-explain="{{ route('tenant.reports.custom.explain') }}" data-locked="{{ $locked ? 1 : 0 }}" data-upgrade="{{ route('tenant.team.index', []) }}">

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Reports</h1>
    <p class="ia-page-subtitle">{{ $subtitle }}</p>
  </div>
</div>

@include('tenant.reports._tab_subnav', ['active' => $tab])

@if($locked)
<div class="rb-note" style="margin-bottom:18px">
  <div><b>Extended reports aren't on for this shop.</b> The totals below are your real figures. The lists and per-person detail are blurred until you upgrade, and Explain and Open in builder open then too.</div>
  <button class="ia-btn ia-btn--sm ia-btn--primary" type="button" data-up="extended reports" style="margin-left:auto;flex:none">Upgrade</button>
</div>
@endif

<div class="rb-bar" id="rx-ctl">
  <span class="rb-hint" style="font-weight:600;text-transform:uppercase;letter-spacing:.07em">Range</span>
  <div class="rb-seg">
    @foreach(\App\Services\Tenant\ReportBuilder\TabQuery::SEGMENTS as [$slug, $label])
      @if($slug === 'custom')
        <button type="button" class="{{ $q['slug'] === 'custom' ? 'on' : '' }}" id="rx-custom-btn" aria-expanded="{{ $q['slug'] === 'custom' ? 'true' : 'false' }}">{{ $label }}</button>
      @else
        <a href="{{ route($tabRoute, ['range' => $slug, 'compare' => $q['compare']]) }}" class="rb-segbtn {{ $q['slug'] === $slug ? 'on' : '' }}">{{ $label }}</a>
      @endif
    @endforeach
  </div>
  <span class="sp"></span>
  <label class="rb-hint" for="rx-cmp">Compare</label>
  <select class="rb-sel" id="rx-cmp" aria-label="Compare with" data-base="{{ route($tabRoute) }}" data-range="{{ $q['slug'] }}" data-from="{{ $q['from'] }}" data-to="{{ $q['to'] }}">
    @foreach(\App\Services\Tenant\ReportBuilder\TabQuery::COMPARE as $k => $label)
      <option value="{{ $k }}" @selected($q['compare'] === $k)>{{ $label }}</option>
    @endforeach
  </select>
</div>

<form class="rb-bar rx-custom" id="rx-custom" method="get" action="{{ route($tabRoute) }}" style="{{ $q['slug'] === 'custom' ? '' : 'display:none' }}">
  <input type="hidden" name="range" value="custom"><input type="hidden" name="compare" value="{{ $q['compare'] }}">
  <label class="rb-hint" for="rx-from">From</label>
  <input class="rb-inp" style="width:auto" type="date" id="rx-from" name="from" value="{{ $q['from'] ?: $from->toDateString() }}" max="{{ now()->addYear()->toDateString() }}" required>
  <label class="rb-hint" for="rx-to">To</label>
  <input class="rb-inp" style="width:auto" type="date" id="rx-to" name="to" value="{{ $q['to'] ?: $to->toDateString() }}" required>
  <button class="ia-btn ia-btn--sm ia-btn--primary" type="submit">Show these dates</button>
</form>

@if(!empty($q['note']))<div class="rb-note rb-stale" style="margin-bottom:14px"><div>{{ $q['note'] }}</div></div>@endif
<div class="rx-dates">Showing {{ $fmtDate($from, $to) }}@if($prev) · compared with {{ $fmtDate($prev[0], $prev[1]) }}@endif</div>

@foreach($panels as $p)
  @include('tenant.reports._rx_panel', ['p' => $p])
@endforeach

<div class="rb-legend">
  <b>How this behaves.</b>
  @foreach($legend as [$k, $v])
    <div class="row"><span>{{ $k }}</span><span>{{ $v }}</span></div>
  @endforeach
  @if($locked)
    <div class="row"><span>Branded:</span><span>totals stay visible. The lists are blurred until the shop has extended reports, and none of the rows behind them are sent to this page.</span></div>
  @endif
</div>

</div>

<div class="rx-scr" id="rx-scr"></div>
<div class="rx-mod" id="rx-open" role="dialog" aria-modal="true" aria-labelledby="rx-open-t">
  <div class="h"><h3 id="rx-open-t">Open in builder</h3><button type="button" class="rb-x" data-rx-close aria-label="Close">×</button></div>
  <div class="b"><div id="rx-open-b"></div></div>
  <div class="f"><button type="button" class="ia-btn" data-rx-close>Close</button><a class="ia-btn ia-btn--primary" id="rx-open-go" href="#">Open in builder →</a></div>
</div>
<div class="rx-mod" id="rx-up" role="dialog" aria-modal="true" aria-labelledby="rx-up-t">
  <div class="h"><div><div class="badge">Branded feature</div><h3 id="rx-up-t">Extended reports</h3></div><button type="button" class="rb-x" data-rx-close aria-label="Close">×</button></div>
  <div class="b" id="rx-up-b">Upgrade to use extended reports.</div>
  <div class="f"><button type="button" class="ia-btn" data-rx-close>Maybe later</button><a class="ia-btn ia-btn--primary" href="{{ route('tenant.team.index', []) }}">Upgrade to Branded →</a></div>
</div>
<div class="rb-toast" id="rx-toast"></div>

<script type="application/json" id="rx-open-data">{!! json_encode($openMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
<script src="{{ asset('js/tenant/report-tabs.js') }}?v={{ filemtime(public_path('js/tenant/report-tabs.js')) }}" defer></script>
@endsection
