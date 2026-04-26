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
      <button type="button" class="ia-cal-legend-trigger" id="ia-cal-legend-trigger"
              aria-label="Show calendar legend" aria-expanded="false">
        <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
          <circle cx="7" cy="7" r="6" stroke="currentColor" stroke-width="1.2"/>
          <path d="M5.4 5.2c0-.9.7-1.6 1.6-1.6s1.6.7 1.6 1.6c0 .7-.4 1.1-1 1.4-.4.2-.6.4-.6.7v.5"
                stroke="currentColor" stroke-width="1.2" stroke-linecap="round" fill="none"/>
          <circle cx="7" cy="10" r=".7" fill="currentColor"/>
        </svg>
        <span class="ia-cal-legend-trigger-label">Legend</span>
      </button>
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

  {{-- ===== Legend panel (collapsible, persisted in localStorage) ===== --}}
  <div class="ia-cal-legend" id="ia-cal-legend" hidden>
    <div class="ia-cal-legend-section">
      <div class="ia-cal-legend-heading">Appointment status</div>
      <div class="ia-cal-legend-rows">
        <div class="ia-cal-legend-row">
          <span class="ia-cal-legend-swatch is-status-pending"></span>
          <span class="ia-cal-legend-text"><strong>Pending</strong> · dashed border. Booked but not yet confirmed.</span>
        </div>
        <div class="ia-cal-legend-row">
          <span class="ia-cal-legend-swatch is-status-confirmed"></span>
          <span class="ia-cal-legend-text"><strong>Confirmed</strong> · solid block. Customer is locked in.</span>
        </div>
        <div class="ia-cal-legend-row">
          <span class="ia-cal-legend-swatch is-status-in-progress"></span>
          <span class="ia-cal-legend-text"><strong>In progress</strong> · accent border. Work has started.</span>
        </div>
        <div class="ia-cal-legend-row">
          <span class="ia-cal-legend-swatch is-status-completed"></span>
          <span class="ia-cal-legend-text"><strong>Completed</strong> · muted with check. Done and closed.</span>
        </div>
        <div class="ia-cal-legend-row">
          <span class="ia-cal-legend-text ia-cal-legend-note">Cancelled appointments are hidden from the grid by default. Find them in the Appointments list with the status filter.</span>
        </div>
      </div>
    </div>

    @if($viewMode === 'day')
    <div class="ia-cal-legend-section">
      <div class="ia-cal-legend-heading">Time blocks</div>
      <div class="ia-cal-legend-rows">
        <div class="ia-cal-legend-row">
          <span class="ia-cal-legend-swatch is-bookend"></span>
          <span class="ia-cal-legend-text"><strong>Prep / cleanup</strong> · hatched bands above and below an appointment. Time the customer doesn't see, but the resource is occupied.</span>
        </div>
        <div class="ia-cal-legend-row">
          <span class="ia-cal-legend-swatch is-hold"></span>
          <span class="ia-cal-legend-text"><strong>Walk-in hold</strong> · lime dashed. Reserved capacity for walk-in customers — converts to an appointment when one arrives.</span>
        </div>
        <div class="ia-cal-legend-row">
          <span class="ia-cal-legend-swatch is-break"></span>
          <span class="ia-cal-legend-text"><strong>Break</strong> · hatched neutral. Lunch, vendor visits, anything that takes a resource off the schedule.</span>
        </div>
      </div>
    </div>

    <div class="ia-cal-legend-section">
      <div class="ia-cal-legend-heading">Resource color</div>
      <div class="ia-cal-legend-rows">
        <div class="ia-cal-legend-row">
          <span class="ia-cal-legend-text">The colored strip on the left of each block matches the resource's dot in the column header. Set or change resource colors on the <a href="{{ route('tenant.resources.index') }}">Resources page</a>.</span>
        </div>
      </div>
    </div>
    @endif
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

@if($viewMode === 'day' && !empty($prefillCustomer ?? null))
<script>
  window.IntakeCalendarPrefill = @json($prefillCustomer);
</script>
@endif

@endsection

@push('styles')
  <link rel="stylesheet" href="{{ asset('css/tenant/calendar.css') }}?v={{ filemtime(public_path('css/tenant/calendar.css')) }}">
@endpush

@push('scripts')
  <script src="{{ asset('js/tenant/calendar.js') }}?v={{ filemtime(public_path('js/tenant/calendar.js')) }}" defer></script>
@endpush
