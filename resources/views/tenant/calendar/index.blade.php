@extends('layouts.tenant.app')
@php
  $pageTitle = 'Calendar';
@endphp

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Calendar</h1>
    <p class="ia-page-subtitle">Your schedule, your shop, one view.</p>
  </div>
</div>

<div class="ia-cal-shell">

  {{-- Toolbar: date nav + view switcher --}}
  <div class="ia-cal-toolbar">
    <div class="ia-cal-toolbar-left">
      <a href="{{ route('calendar.index', ['date' => $todayStr]) }}"
         class="ia-cal-today-btn {{ $isToday ? 'is-active' : '' }}">
        Today
      </a>

      <div class="ia-cal-nav-group">
        <a href="{{ route('calendar.index', ['date' => $prevDate]) }}"
           class="ia-cal-nav-btn"
           title="Previous day"
           aria-label="Previous day">‹</a>
        <a href="{{ route('calendar.index', ['date' => $nextDate]) }}"
           class="ia-cal-nav-btn"
           title="Next day"
           aria-label="Next day">›</a>
      </div>

      <div class="ia-cal-date-label">
        {{ $date->format('l, F j') }}<span class="ia-cal-date-year">{{ $date->format('Y') }}</span>
      </div>
    </div>

    <div class="ia-cal-toolbar-right">
      <div class="ia-cal-view-switch">
        <button type="button" class="ia-cal-view-btn is-active" data-view="day">Day</button>
        <button type="button" class="ia-cal-view-btn is-disabled" data-view="week" disabled title="Coming soon">Week</button>
        <button type="button" class="ia-cal-view-btn is-disabled" data-view="month" disabled title="Coming soon">Month</button>
      </div>
    </div>
  </div>

  {{-- Grid area — piece 3 onward fills this --}}
  <div class="ia-cal-body">
    <div class="ia-cal-placeholder">
      Grid + resources land next.
    </div>
  </div>

</div>

@endsection

@push('styles')
  <link rel="stylesheet" href="{{ asset('css/tenant/calendar.css') }}">
@endpush

@push('scripts')
  <script src="{{ asset('js/tenant/calendar.js') }}" defer></script>
@endpush
