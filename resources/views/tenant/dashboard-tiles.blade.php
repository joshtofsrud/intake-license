@extends('layouts.tenant.app')
{{-- the simplified dashboard. A second view alongside the
     Overview dashboard, which is unchanged and stays the default. --}}
@section('title', 'Dashboard')
{{-- the round + button shows here too, as on Overview --}}
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

@push('styles')
  <link rel="stylesheet" href="{{ asset('css/tenant/dashboard-simple.css') }}?v={{ filemtime(public_path('css/tenant/dashboard-simple.css')) }}">
@endpush

@section('content')
{{-- MARKER-DASH-TEMPLATES: Tiles is now a template; this page is its frame. --}}

{{-- the same three figures as Overview, on phones,
     a size smaller so Tiles stays tight. --}}
<div class="ia-tiles-stats" aria-label="This week">
  <div><b>{{ $today['today_count'] ?? 0 }}</b><span>Today</span></div>
  <div><b>{{ format_money((int) ($today['week_revenue_cents'] ?? 0)) }}</b><span>Wk revenue</span></div>
  <div><b>{{ $today['week_new_customers'] ?? 0 }}</b><span>New cust.</span></div>
</div>

{{-- identical head to the Overview view: same
     greeting construction, same bold long date, same amber attention count
     (attention total, not the visible-card count), same action buttons. --}}
<div class="ia-page-head ia-tiles-head ia-dash-head {{ tenant()->retail_enabled ? 'has-fab' : '' }}">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">{{ $greeting['name'] ? 'Good ' . $greeting['time_of_day'] . ', ' . $greeting['name'] . '.' : 'Good ' . $greeting['time_of_day'] . '.' }}</h1>
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
  <div class="ia-page-actions ia-tiles-actions">
    <a href="{{ route('tenant.register.index') }}" class="ia-btn ia-btn--primary">
      + New sale
    </a>
    <a href="{{ route('tenant.appointments.index', ['new' => 1]) }}" class="ia-btn ia-btn--primary">
      + New appointment
    </a>
    @include('tenant.dashboard._switch')
    @include('tenant.dashboard._customize_button')
  </div>
</div>

{{-- MARKER-DASH-TEMPLATES: the blocks, in the order the template's live version lists them. --}}
@include('tenant.dashboard._rows', ['dashRows' => $dashRows])
@include('tenant.dashboard._customize_panel')
@endsection
