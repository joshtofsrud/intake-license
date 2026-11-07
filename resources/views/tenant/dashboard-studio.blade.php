@extends($dashLayout ?? 'layouts.tenant.app'){{-- MARKER-DASH-PREVIEW: master admin's preview draws the same page in layouts.tenant.preview --}}
{{-- MARKER-DASH-STUDIO: the Studio dashboard frame — greeting, search and
     actions in a header that frosts as the page scrolls, then the template's
     rows (coloured tiles and the queue beside the calendar and today). Motion
     runs once on load and never on the quiet refresh; it is off for anyone
     whose device asks for less motion. --}}
@php
  $see ??= \App\Support\Dashboard\BlockAccess::for(auth('tenant')->user());
  $sd = $studio ?? null;
  $sdLoc = null;
  if (tenant()?->multi_location_enabled && session('current_location_id')) {
      $sdLoc = \App\Models\Tenant\TenantLocation::where('tenant_id', tenant()->id)->whereKey(session('current_location_id'))->value('name');
  }
@endphp
@section('title', 'Dashboard')
@section('mobile-fab', $see->can('register') ? 'walk-in' : 'new-booking')
@push('styles')
  <link rel="stylesheet" href="{{ asset('css/tenant/dashboard-simple.css') }}?v={{ filemtime(public_path('css/tenant/dashboard-simple.css')) }}">
  <link rel="stylesheet" href="{{ asset('css/tenant/dashboard-studio.css') }}?v={{ filemtime(public_path('css/tenant/dashboard-studio.css')) }}">
@endpush

@section('content')
<div class="sd" id="sd" data-sd-root>
  <header class="sd-head" id="sdHead">
    <div class="sd-head-l">
      <h1 class="sd-hi">{{ rtrim(\App\Support\Dashboard\BriefView::hello($greeting), '.') }}</h1>
      <p class="sd-sub" data-sd-live="summary">{{ $sd['summary'] ?? $greeting['date_long'] }}</p>
    </div>
    <div class="sd-head-r">
      <button type="button" class="sd-search" data-ar-search aria-label="Search">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <span>Search customers, jobs, items</span><kbd>⌘K</kbd>
      </button>
      @if ($see->can('register'))
        <a href="{{ route('tenant.register.index') }}" class="ia-btn ia-btn--sm sd-hide-m">New sale</a>
      @endif
      @if ($see->can('schedule'))
        <a href="{{ route('tenant.appointments.index', ['new' => 1]) }}" class="ia-btn ia-btn--sm ia-btn--primary sd-hide-m">New booking</a>
      @endif
    </div>
    <div class="sd-head-bar">
      @include('tenant.dashboard._switch')
      @include('tenant.dashboard._customize_button')
      @if ($sdLoc)
        <span class="sd-loc" title="Jobs with no location show at every location">Showing {{ $sdLoc }} · jobs with no location show everywhere</span>
      @endif
    </div>
  </header>

  @include('tenant.dashboard._rows')

  <p class="sd-legend">Figures are for today in your shop's time zone and refresh every few minutes while this page is open. Sales are payments taken, with refunds taken off.</p>
</div>
<div class="sd-pop" id="sdPop" role="tooltip" hidden></div>
@include('tenant.dashboard._customize_panel')
@include('tenant.dashboard._pinned_reports')
@endsection

@push('scripts')
  <script src="{{ asset('js/tenant/dashboard-studio.js') }}?v={{ filemtime(public_path('js/tenant/dashboard-studio.js')) }}" defer></script>
@endpush
