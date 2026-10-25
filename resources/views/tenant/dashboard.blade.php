@extends('layouts.tenant.app')
@push('styles')
  <link rel="stylesheet" href="{{ asset('css/tenant/dashboard.css') }}?v={{ filemtime(public_path('css/tenant/dashboard.css')) }}">
  {{-- a --}}
  <link rel="stylesheet" href="{{ asset('css/tenant/dashboard-tiles.css') }}?v={{ filemtime(public_path('css/tenant/dashboard-tiles.css')) }}">
@endpush
@section('mobile-fab', 'walk-in')
@push('styles')
{{-- on phones the view switch shrinks to two icons
     at the end of the greeting line, and the New sale / New appointment
     buttons give way to the round + button (which offers both). A shop
     without the + (retail off) keeps its buttons. --}}
<style>
  .ia-dash-head .vs-ic { display: none; }
  @media (max-width: 1023px) {
    .ia-dash-head { display: flex !important; flex-direction: row !important; flex-wrap: nowrap !important;
      align-items: flex-start; justify-content: space-between; gap: 10px; }
    .ia-dash-head .ia-page-head-left { flex: 1; min-width: 0; }
    .ia-dash-head .ia-page-actions { flex: none; width: auto !important; margin: 0 !important; }
    .ia-dash-head.has-fab .ia-page-actions > a.ia-btn { display: none !important; }
    .ia-dash-head .ia-viewseg { padding: 2px !important; }
    .ia-dash-head .ia-viewseg button { padding: 5px 8px !important; line-height: 0; }
    .ia-dash-head .ia-viewseg .vs-lb { display: none; }
    .ia-dash-head .ia-viewseg .vs-ic { display: inline-block; }
  }
</style>
@endpush

@section('content')

@php
  $greetingWord = "Good {$greeting['time_of_day']}";
  $greetingLine = $greeting['name'] ? "{$greetingWord}, {$greeting['name']}." : "{$greetingWord}.";
@endphp

{{-- DASH-MOBILE v1 — mobile-only hero + at-a-glance stats. Hidden on desktop. --}}
<div class="ia-dash-mobile-only">
  {{-- 3-stat row --}}
  <div class="ia-dash-m-stats ia-dash-m-stats--strip">
    <div class="ia-dash-m-stat">
      <div class="ia-dash-m-stat-num">{{ $today['today_count'] }}</div>
      <div class="ia-dash-m-stat-lbl">Today</div>
    </div>
    <div class="ia-dash-m-stat">
      <div class="ia-dash-m-stat-num">{{ format_money($today['week_revenue_cents']) }}</div>
      <div class="ia-dash-m-stat-lbl">Wk revenue</div>
    </div>
    <div class="ia-dash-m-stat">
      <div class="ia-dash-m-stat-num">{{ $today['week_new_customers'] }}</div>
      <div class="ia-dash-m-stat-lbl">New cust.</div>
    </div>
  </div>

  {{-- Next-up hero card. Renders only when there's a next_up appointment with a time. --}}
  @php
    $nu = $today['next_up'] ?? null;
    $nuTime = ($nu && $nu->appointment_time)
      ? \Carbon\Carbon::parse($nu->appointment_time)
      : null;
    $nuMinutesAway = null;
    if ($nuTime) {
      try {
        // appointment_time is naive tenant-local wall-clock;
        // parse it in the tenant timezone so the countdown isn't offset by the
        // UTC delta (which made the banner appear/hide at the wrong times).
        $tz = tenant()?->timezone() ?? config('app.timezone', 'UTC');
        $nuStart = \Carbon\Carbon::parse($nu->appointment_date->toDateString() . ' ' . $nu->appointment_time, $tz);
        // CARBON3-DIFF-FIX v1: timestamp math instead of diffInMinutes(false)
        // because Carbon 3 returns negative for "$nuStart is later than now",
        // which broke the "In 24 minutes" branch (always fell through to "Next up").
        $nuMinutesAway = (int) round(($nuStart->getTimestamp() - now()->getTimestamp()) / 60);
      } catch (\Throwable $e) { $nuMinutesAway = null; }
    }
    $nuService = $nu && $nu->items->isNotEmpty() ? $nu->items->first()->item_name_snapshot : null;
  @endphp
  @if($nu && $nuTime)
    <a href="{{ route('tenant.appointments.show', $nu->id) }}" class="ia-dash-m-hero">
      <div class="ia-dash-m-hero-when">
        @if($nuMinutesAway !== null && $nuMinutesAway >= 0 && $nuMinutesAway < 120)
          @if($nuMinutesAway === 0)
            Right now
          @elseif($nuMinutesAway < 60)
            In {{ $nuMinutesAway }} {{ \Illuminate\Support\Str::plural('minute', $nuMinutesAway) }}
          @else
            In {{ floor($nuMinutesAway / 60) }}h {{ $nuMinutesAway % 60 }}m
          @endif
          · {{ $nuTime->format('g:i A') }}
        @else
          Next up · {{ $nuTime->format('g:i A') }}
        @endif
      </div>
      <div class="ia-dash-m-hero-cust">{{ $nu->customerName() }}</div>
      @if($nuService)
        <div class="ia-dash-m-hero-svc">{{ $nuService }}@if($nu->total_duration_minutes) · {{ $nu->total_duration_minutes }} min @endif</div>
      @endif
      <div class="ia-dash-m-hero-cta">View →</div>
    </a>
  @elseif($today['today_count'] === 0)
    <div class="ia-dash-m-empty">
      No appointments today. Open the calendar to book one.
    </div>
  @endif
</div>

<div class="ia-page-head ia-dash-head {{ tenant()->retail_enabled ? 'has-fab' : '' }}">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">{{ $greetingLine }}</h1>
    {{-- c --}}
    <p class="ia-page-subtitle">
      <strong>{{ $greeting['date_long'] }}</strong>
      @php $attentionCount = $attention['total_items'] ?? 0; @endphp
      @if($attentionCount > 0)
        · <span style="color:#F59E0B;font-weight:600">{{ $attentionCount }} {{ Str::plural('thing', $attentionCount) }} {{ $attentionCount === 1 ? 'needs' : 'need' }} you today</span>
      @else
        · <span style="color:var(--ia-accent);font-weight:600">all caught up · enjoy the calm</span>
      @endif
    </p>
  </div>
  <div class="ia-page-actions">
    <a href="{{ route('tenant.register.index') }}" class="ia-btn ia-btn--primary">
      + New sale
    </a>
    {{-- was a bare link to the list, which meant
         hunting for the real button once you got there. --}}
    <a href="{{ route('tenant.appointments.index', ['new' => 1]) }}" class="ia-btn ia-btn--primary">
      + New appointment
    </a>
    {{-- toggle lives in the page head on both views --}}
    @include('tenant.dashboard._switch', ['inline' => true])
    @include('tenant.dashboard._customize_button')
  </div>
</div>

@if(!empty($workOrderBanner))
<div class="wof-dashboard-banner" id="wof-banner" style="background:var(--ia-accent-soft);border-left:2px solid var(--ia-accent);border-radius:var(--ia-r-md);padding:16px 20px;margin-bottom:20px;display:flex;justify-content:space-between;align-items:flex-start;gap:16px">
  <div style="flex:1">
    <div style="font-size:14px;font-weight:500;margin-bottom:4px">{{ $workOrderBanner['title'] }}</div>
    <div style="font-size:13px;opacity:.75;line-height:1.5">{{ $workOrderBanner['body'] }}</div>
  </div>
  <div style="display:flex;gap:8px;flex-shrink:0">
    <a href="{{ $workOrderBanner['cta_url'] }}" class="ia-btn ia-btn--primary ia-btn--sm">{{ $workOrderBanner['cta_label'] }}</a>
    <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" id="wof-banner-dismiss">Dismiss</button>
  </div>
</div>

@push('scripts')
<script>
(function(){
  var btn = document.getElementById('wof-banner-dismiss');
  var banner = document.getElementById('wof-banner');
  if (!btn || !banner) return;
  btn.addEventListener('click', function(){
    var fd = new FormData();
    fd.append('_token', window.IntakeAdmin.csrfToken);
    fetch('{{ route("tenant.dashboard.wof-banner.dismiss") }}', {
      method: 'POST',
      body: fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function(){ banner.style.display = 'none'; });
  });
})();
</script>
@endpush
@endif

{{-- b --}}
{{-- toggle moved into the page head above --}}

{{-- MARKER-DASH-TEMPLATES: Overview is now a template; this page is its frame
     and the zones are its blocks, drawn in the order its live version lists. --}}
@include('tenant.dashboard._rows', ['dashRows' => $dashRows])
@include('tenant.dashboard._customize_panel')



@endsection
