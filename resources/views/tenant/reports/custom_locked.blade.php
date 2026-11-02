@extends('layouts.tenant.app')
@section('title', 'Reports · Custom')

@push('styles')
@include('tenant.reports._tab_styles')
<link rel="stylesheet" href="{{ asset('css/tenant/report-builder.css') }}?v={{ filemtime(public_path('css/tenant/report-builder.css')) }}">
@endpush

@section('content')
{{-- MARKER-REPORT-GATE — the builder for a shop without extended reports. Nothing is run and no shop data is sent to this page. --}}
@php
  $fake = function (int $rows, int $cols) {
      $out = [];
      for ($i = 0; $i < $rows; $i++) {
          $r = [];
          for ($j = 0; $j < $cols; $j++) $r[] = $j ? 34 + (($i * 13 + $j * 7) % 26) : 90 + (($i * 17) % 50);
          $out[] = $r;
      }
      return $out;
  };
@endphp
<div class="ia-section ia-section--wide" id="rb-app">

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Reports</h1>
    <p class="ia-page-subtitle">Build your own, ask for one in plain words, and have it emailed or pinned to your dashboard.</p>
  </div>
</div>

@include('tenant.reports._tab_subnav', ['active' => 'custom'])

<div class="rb-note" style="margin-bottom:22px">
  <div><b>The report builder is part of extended reports.</b> Your plan doesn't include it, so nothing here runs. You can look at what each ready-made report is; building, saving, emailing and Explain open when you upgrade.</div>
  <button class="ia-btn ia-btn--sm ia-btn--primary" type="button" data-rb-up="the report builder" style="margin-left:auto;flex:none">Upgrade</button>
</div>

<div class="rb-askwrap">
  <div class="rb-lbl" style="margin-bottom:8px">Ask for a report</div>
  <div class="rb-ask"><input class="rb-inp rb-askin" disabled placeholder='Try: "margin by category compared to last year"'><button class="ia-btn ia-btn--primary" type="button" data-rb-up="custom reports">Build it</button></div>
</div>

<div class="rb-sec">
  <div class="rb-h"><h2>Templates</h2><span class="rb-hint">ready-made reports you can open once you upgrade</span></div>
  <div class="rb-tiles">
    @foreach($templates as $t)
      <button class="rb-tile" type="button" data-rb-up="{{ $t['name'] }}">
        <span class="nm">{{ $t['name'] }}</span>
        <span class="ds">{{ $t['desc'] }}</span>
        <span class="rb-lockgo">Branded feature</span>
      </button>
    @endforeach
  </div>
</div>

<div class="rb-sec" style="margin-top:36px">
  <div class="rb-h"><h2>Suggested builds</h2><span class="rb-hint">found by comparing recent weeks with the ones before</span></div>
  <div class="rb-lock">
    <div class="rb-tiles rb-blurme">
      @foreach(['+109%' => 'Revenue from one category is up on the 30 days before', '+65%' => 'Overtime is up on the 30 days before', '+3.4 pts' => 'Cancellations are up on the 30 days before', '−0.2' => 'Items per ticket fell for part of the team'] as $amt => $txt)
        <div class="rb-tile big"><span class="amt">{{ $amt }}</span><span class="nm">{{ $txt }}</span></div>
      @endforeach
    </div>
    <div class="rb-lockbadge"><span><b>Branded feature</b> — see what stood out in your shop<button type="button" data-rb-up="suggested builds">Upgrade</button></span></div>
  </div>
</div>

<div class="rb-sec" style="margin-top:40px">
  <div class="rb-h"><h2>Your reports</h2>@if($savedCount)<span class="rb-hint">{{ $savedCount }} saved {{ \Illuminate\Support\Str::plural('report', $savedCount) }} waiting</span>@endif</div>
  <div class="rb-lock">
    <div class="rb-tbl"><table class="ia-table">
      <thead><tr><th>Name</th><th>Data</th><th>Created</th><th>Emailed</th><th>Dashboard</th></tr></thead>
      <tbody>
        @foreach($fake(3, 5) as $r)
          <tr>@foreach($r as $w)<td><span class="rb-ph" style="width:{{ $w }}px"></span></td>@endforeach</tr>
        @endforeach
      </tbody>
    </table></div>
    <div class="rb-lockbadge"><span><b>Branded feature</b> — {{ $savedCount ? 'your saved reports open again when you upgrade' : 'save and re-open your own reports' }}<button type="button" data-rb-up="saved reports">Upgrade</button></span></div>
  </div>
</div>

<div class="rb-legend" style="margin-top:34px">
  <b>How this behaves.</b>
  <div class="row"><span>Locked:</span><span>you can see what each template is. Nothing runs, so there are no numbers, exports, emails, alerts or Explain, and no figures from your shop are sent to this page.</span></div>
  <div class="row"><span>Saved reports:</span><span>reports saved before a downgrade keep their settings. Emailed reports and alerts pause, and start again when extended reports come back.</span></div>
  <div class="row"><span>Dashboard pins:</span><span>pinned reports show a lock in place of their numbers until then.</span></div>
</div>

</div>

<div id="rb-up-backdrop" class="rep-upsell-backdrop" onclick="if (event.target === this) rbUpClose()">
  <div class="rep-upsell-modal" role="dialog" aria-labelledby="rb-up-title">
    <button type="button" class="close" onclick="rbUpClose()" aria-label="Close">×</button>
    <div class="badge">Branded feature</div>
    <h2 id="rb-up-title">Extended reports</h2>
    <p id="rb-up-text">Upgrade to use the report builder.</p>
    <div class="cta-row">
      <a class="cta-primary" href="{{ route('tenant.team.index', []) }}">Upgrade to Branded →</a>
      <button type="button" class="cta-secondary" onclick="rbUpClose()">Maybe later</button>
    </div>
  </div>
</div>
<script>
  function rbUpClose() { document.getElementById('rb-up-backdrop').classList.remove('open'); }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-rb-up]');
    if (!b) return;
    document.getElementById('rb-up-text').textContent = 'Upgrade to use ' + b.getAttribute('data-rb-up') + '. Extended reports unlock the lists and per-person detail on every Reports tab, and the Custom report builder with emailed reports, alerts, dashboard pins and Explain.';
    document.getElementById('rb-up-backdrop').classList.add('open');
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') rbUpClose(); });
</script>
@endsection
