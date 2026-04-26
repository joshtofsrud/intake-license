@extends('layouts.tenant.app')
@php
  $pageTitle = 'Calendar';

  // Build the date label + nav targets per current view mode.
  if ($viewMode === 'week') {
    $dateLabel = $weekStart->format('M j') . ' – ' . $weekEnd->format('M j, Y');
  } elseif ($viewMode === 'month') {
    $dateLabel = $monthLabel;
  } else {
    $dateLabel = $date->format('l, F j');
    $dateYear  = $date->format('Y');
  }
@endphp

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Calendar</h1>
    <p class="ia-page-subtitle">Your schedule, your shop, one view.</p>
  </div>
</div>

<x-tenant.schedule-tabs active="calendar" />

<div class="ia-cal-shell"
     data-view-mode="{{ $viewMode }}"
     @if($viewMode === 'day')
       data-cal-open-min="{{ $openMin }}"
       data-cal-close-min="{{ $closeMin }}"
       data-cal-px-per-min="1.4"
       data-cal-is-today="{{ $isToday ? '1' : '0' }}"
     @endif>

  {{-- ===== Toolbar ===== --}}
  <div class="ia-cal-toolbar">
    <div class="ia-cal-toolbar-left">
      <a href="{{ route('tenant.calendar.index', ['view' => $viewMode, 'date' => $todayStr]) }}"
         class="ia-cal-today-btn {{ ($viewMode === 'day' && $isToday) ? 'is-active' : '' }}">
        Today
      </a>
      <div class="ia-cal-nav-group">
        <a href="{{ route('tenant.calendar.index', ['view' => $viewMode, 'date' => $prevDate]) }}"
           class="ia-cal-nav-btn"
           aria-label="Previous {{ $viewMode }}">‹</a>
        <a href="{{ route('tenant.calendar.index', ['view' => $viewMode, 'date' => $nextDate]) }}"
           class="ia-cal-nav-btn"
           aria-label="Next {{ $viewMode }}">›</a>
      </div>
      <div class="ia-cal-date-label">
        {{ $dateLabel }}
        @if($viewMode === 'day')
          <span class="ia-cal-date-year">{{ $dateYear }}</span>
        @endif
      </div>
    </div>
    <div class="ia-cal-toolbar-right">
      <div class="ia-cal-view-switch">
        <a href="{{ route('tenant.calendar.index', ['view' => 'day', 'date' => ($viewMode === 'day' ? $dateStr : ($viewMode === 'week' ? $weekStartStr : $monthAnchor->toDateString()))]) }}"
           class="ia-cal-view-btn {{ $viewMode === 'day' ? 'is-active' : '' }}"
           data-view="day">Day</a>
        <a href="{{ route('tenant.calendar.index', ['view' => 'week', 'date' => ($viewMode === 'day' ? $dateStr : ($viewMode === 'week' ? $weekStartStr : $monthAnchor->toDateString()))]) }}"
           class="ia-cal-view-btn {{ $viewMode === 'week' ? 'is-active' : '' }}"
           data-view="week">Week</a>
        <a href="{{ route('tenant.calendar.index', ['view' => 'month', 'date' => ($viewMode === 'day' ? $dateStr : ($viewMode === 'week' ? $weekStartStr : $monthAnchor->toDateString()))]) }}"
           class="ia-cal-view-btn {{ $viewMode === 'month' ? 'is-active' : '' }}"
           data-view="month">Month</a>
      </div>
    </div>
  </div>

  {{-- ===== Resource filter (day + week only; month merges resources) ===== --}}
  @if($viewMode !== 'month' && $allResources->count() > 1)
    @php
      $visibleCount = $resources->count();
      if ($filterMode === 'all') {
        $filterButtonLabel = 'All';
      } elseif ($visibleCount === 1) {
        $filterButtonLabel = $resources->first()->name;
      } else {
        $filterButtonLabel = $visibleCount . ' selected';
      }
    @endphp

    <button type="button" class="ia-cal-filter-trigger" id="ia-cal-filter-trigger"
            aria-label="Filter resources" aria-haspopup="dialog">
      <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
        <path d="M2 3h10M3.5 7h7M5 11h4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
      </svg>
      <span class="ia-cal-filter-trigger-label">{{ $filterButtonLabel }}</span>
      @if($filterMode !== 'all')
        <span class="ia-cal-filter-trigger-dot" aria-hidden="true"></span>
      @endif
    </button>

    <div class="ia-cal-filter-bar" id="ia-cal-filter-bar"
         data-current-mode="{{ $filterMode }}">
      <span class="ia-cal-filter-label">Show</span>
      <button type="button"
              class="ia-cal-fchip ia-cal-fchip-all {{ $filterMode === 'all' ? 'is-on' : '' }}"
              data-action="all">All</button>
      @foreach($allResources as $r)
        @php $isVisible = in_array($r->id, $resources->pluck('id')->all()); @endphp
        <span class="ia-cal-fchip-wrap">
          <button type="button"
                  class="ia-cal-fchip {{ $isVisible ? 'is-on' : '' }}"
                  data-resource-id="{{ $r->id }}"
                  title="Click to toggle · Double-click to solo">
            <span class="ia-cal-fchip-dot" style="background: {{ $r->color_hex ?: '#888' }};"></span>
            {{ $r->name }}
          </button>
          <button type="button"
                  class="ia-cal-fchip-solo"
                  data-resource-id="{{ $r->id }}"
                  title="Show only {{ $r->name }}"
                  aria-label="Show only {{ $r->name }}">
            <svg width="11" height="11" viewBox="0 0 11 11" fill="none">
              <circle cx="5.5" cy="5.5" r="4.5" stroke="currentColor" stroke-width="1"/>
              <circle cx="5.5" cy="5.5" r="1.5" fill="currentColor"/>
            </svg>
          </button>
        </span>
      @endforeach
    </div>

    <div class="ia-cal-filter-sheet" id="ia-cal-filter-sheet" aria-hidden="true" role="dialog" aria-modal="true">
      <div class="ia-cal-filter-sheet-backdrop" onclick="CalendarFilterSheet.close()"></div>
      <div class="ia-cal-filter-sheet-panel">
        <div class="ia-cal-filter-sheet-handle"></div>
        <div class="ia-cal-filter-sheet-head">
          <span class="ia-cal-filter-sheet-title">Filter resources</span>
          <button type="button" class="ia-cal-filter-sheet-close" onclick="CalendarFilterSheet.close()" aria-label="Close">×</button>
        </div>
        <div class="ia-cal-filter-sheet-body">
          <button type="button"
                  class="ia-cal-sheet-row {{ $filterMode === 'all' ? 'is-on' : '' }}"
                  data-action="all">
            <span class="ia-cal-sheet-row-label">All resources</span>
            @if($filterMode === 'all')
              <span class="ia-cal-sheet-check" aria-hidden="true">✓</span>
            @endif
          </button>
          @foreach($allResources as $r)
            @php $isVisible = in_array($r->id, $resources->pluck('id')->all()); @endphp
            <button type="button"
                    class="ia-cal-sheet-row {{ $isVisible ? 'is-on' : '' }}"
                    data-resource-id="{{ $r->id }}">
              <span class="ia-cal-sheet-row-dot" style="background: {{ $r->color_hex ?: '#888' }};"></span>
              <span class="ia-cal-sheet-row-label">
                {{ $r->name }}
                @if($r->subtitle)
                  <span class="ia-cal-sheet-row-sub">· {{ $r->subtitle }}</span>
                @endif
              </span>
              <button type="button" class="ia-cal-sheet-row-solo"
                      data-resource-id="{{ $r->id }}"
                      onclick="event.stopPropagation(); CalendarFilterSheet.solo('{{ $r->id }}');"
                      aria-label="Show only {{ $r->name }}">Only</button>
              @if($isVisible)
                <span class="ia-cal-sheet-check" aria-hidden="true">✓</span>
              @endif
            </button>
          @endforeach
        </div>
      </div>
    </div>
  @endif

  {{-- ===== View dispatcher ===== --}}
  @if($viewMode === 'week')
    @include('tenant.calendar._week-grid')
  @elseif($viewMode === 'month')
    @include('tenant.calendar._month-grid')
  @else
    @include('tenant.calendar._day-grid')
  @endif

</div>

{{-- Quick-book modal (day view only — week/month drill to day to book) --}}
@if($viewMode === 'day')
<div class="qb-modal" id="qb-modal" style="display:none">
  <div class="qb-modal-backdrop" onclick="QuickBook.close()"></div>
  <div class="qb-modal-card">
    <div class="qb-modal-head">
      <div>
        <div class="qb-modal-title">New appointment</div>
        <div class="qb-modal-context" id="qb-context">—</div>
      </div>
      <button type="button" class="qb-modal-close" onclick="QuickBook.close()" aria-label="Close">×</button>
    </div>

    <div class="qb-modal-body">
      <div class="qb-field">
        <label>Customer</label>
        <input type="text" id="qb-customer-search" placeholder="Search by name or email, or fill in the fields below" autocomplete="off">
        <div id="qb-customer-results" class="qb-results" style="display:none"></div>
      </div>

      <div id="qb-new-customer">
        <div class="qb-field-row">
          <div class="qb-field"><label>First name</label><input type="text" id="qb-first-name"></div>
          <div class="qb-field"><label>Last name</label><input type="text" id="qb-last-name"></div>
        </div>
        <div class="qb-field-row">
          <div class="qb-field"><label>Email</label><input type="email" id="qb-email"></div>
          <div class="qb-field"><label>Phone</label><input type="tel" id="qb-phone"></div>
        </div>
      </div>

      <div class="qb-field">
        <label>Service</label>
        <select id="qb-service"><option value="">Select a service…</option></select>
      </div>

      <div id="qb-error" class="qb-error" style="display:none"></div>
    </div>

    <div class="qb-modal-foot">
      <button type="button" class="ia-btn ia-btn--ghost" onclick="QuickBook.close()">Cancel</button>
      <button type="button" class="ia-btn ia-btn--primary" id="qb-submit" onclick="QuickBook.submit()">Book appointment</button>
    </div>
  </div>
</div>
@endif

@endsection

@push('styles')
  <link rel="stylesheet" href="{{ asset('css/tenant/calendar.css') }}?v={{ filemtime(public_path('css/tenant/calendar.css')) }}">
@endpush

@push('scripts')
  <script src="{{ asset('js/tenant/calendar.js') }}?v={{ filemtime(public_path('js/tenant/calendar.js')) }}" defer></script>
@endpush
