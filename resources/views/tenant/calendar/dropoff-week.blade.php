@extends('layouts.tenant.app')
@php
  $pageTitle = 'Calendar';
@endphp

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Calendar</h1>
    <p class="ia-page-subtitle">Drop-off mode · Week of {{ $weekStart->format('M j') }} – {{ $weekEnd->format('M j, Y') }}</p>
  </div>
    <div class="ia-page-actions" style="margin-left:auto;display:flex;gap:10px;align-items:center">
      {{-- MARKER-PATCH-182B — legend trigger, matches time-slot mode --}}
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
      {{-- MARKER-PATCH-163 — canonical new-appointment entry point on calendar header --}}
      <button type="button" class="ia-btn ia-btn--primary" onclick="openApptModal()">
        + New appointment
      </button>
    </div>
  <div class="ia-page-head-right" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;justify-content:flex-end">
    <div class="cal-view-toggle">
      <a href="?view=day&date={{ $weekStart->format('Y-m-d') }}" class="cal-view-tab">Day</a>
      <a href="?view=week&date={{ $weekStart->format('Y-m-d') }}" class="cal-view-tab is-active">Week</a>
    </div>
    <div class="cal-date-nav">
      <a href="?view=week&date={{ $weekStart->copy()->subWeek()->format('Y-m-d') }}" class="cal-date-btn" title="Previous week">‹</a>
      <a href="?view=week&date={{ now($weekStart->timezone)->format('Y-m-d') }}" class="cal-date-btn cal-date-today">This week</a>
      <a href="?view=week&date={{ $weekStart->copy()->addWeek()->format('Y-m-d') }}" class="cal-date-btn" title="Next week">›</a>
    </div>
  </div>
</div>

{{-- MARKER-PATCH-152A — capacity-mode was missing the schedule sub-toggle --}}
<x-tenant.schedule-tabs active="calendar" />

{{-- MARKER-PATCH-182C — legend sits ABOVE the calendar, not nested inside it --}}
<div class="ia-cal-legend" id="ia-cal-legend" hidden style="margin-bottom:16px">
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
</div>

@if(session('flash'))
  <div class="ia-flash ia-flash--success" style="margin-bottom:16px">{{ session('flash') }}</div>
@endif

@if($resources->isEmpty())
  <div class="ia-card" style="padding:40px;text-align:center">
    <div class="ia-empty-title">No active resources</div>
    <div class="ia-empty-body" style="margin-top:6px">
      Add at least one resource on the
      <a href="{{ route('tenant.resources.index') }}" style="color:var(--ia-accent)">resources page</a>
      to see your calendar.
    </div>
  </div>
@else
  <div class="cal-week-wrap">
    <div class="cal-week-grid" style="grid-template-columns: 140px repeat(7, minmax(150px, 1fr));">
      <div class="cal-week-corner"></div>
      @foreach($days as $day)
        <div class="cal-week-day-head">
          <div class="cal-week-day-name">{{ $day->format('D') }}</div>
          <div class="cal-week-day-num {{ $day->isToday() ? 'is-today' : '' }}">{{ $day->format('j') }}</div>
        </div>
      @endforeach

      @foreach($resources as $r)
        <div class="cal-week-resource-cell" style="border-left: 3px solid {{ $r->color_hex }}">
          <div class="cal-week-resource-name">{{ $r->name }}</div>
          @if($r->subtitle)
            <div class="cal-week-resource-sub">{{ $r->subtitle }}</div>
          @endif
          @if($r->max_appointments_per_day !== null)
            <div class="cal-week-resource-cap">{{ $r->max_appointments_per_day }}/day max</div>
          @endif
        </div>
        @foreach($days as $day)
          @php
            $key   = $day->format('Y-m-d') . '|' . $r->id;
            $items = $byCell[$key] ?? [];
            $count = count($items);
            $cap   = $r->max_appointments_per_day;
            $atCap = ($cap !== null && $count >= $cap);
          @endphp
          {{-- MARKER-WEEK-DROPSTATE — data-cap so the drag handler can rebuild the
               count badge after a drop. The cap used to live only inside the
               badge's own text, which is gone the moment a cell empties. --}}
          <div class="cal-week-cell {{ $atCap ? 'is-full' : '' }} {{ $day->isToday() ? 'is-today' : '' }}"
               data-resource-id="{{ $r->id }}"
               data-date="{{ $day->format('Y-m-d') }}"
               data-cap="{{ $cap !== null ? $cap : '' }}">
            @if($count > 0)
              <div class="cal-week-cell-count">{{ $count }}@if($cap !== null)<span class="cap-of">/{{ $cap }}</span>@endif</div>
              @foreach($items as $appt)
                <div class="cal-week-card" data-appointment-id="{{ $appt->id }}" data-status="{{ $appt->status }}">
                  <div class="cal-week-card-ra">{{ $appt->ra_number }}</div>
                  <div class="cal-week-card-name">{{ $appt->customer_first_name }} {{ $appt->customer_last_name }}</div>
                </div>
              @endforeach
            @else
              <div class="cal-week-cell-empty"></div>
            @endif
          </div>
        @endforeach
      @endforeach
    </div>
  </div>
@endif

@include('tenant._appt-drawer')

{{-- MARKER-PATCH-163 — defines window.openApptModal() --}}
@include('tenant.appointments._create_modal')

@endsection

@push('styles')
{{-- MARKER-PATCH-182B — shared calendar CSS for the legend component --}}
<link rel="stylesheet" href="{{ asset('css/tenant/calendar.css') }}?v={{ filemtime(public_path('css/tenant/calendar.css')) }}">
<style>
.cal-view-toggle{display:inline-flex;background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:6px;padding:2px;gap:2px}
.cal-view-tab{padding:6px 12px;font-size:12px;color:var(--ia-text-muted);border-radius:4px;text-decoration:none;font-weight:500;white-space:nowrap}
.cal-view-tab:hover{color:var(--ia-text)}
.cal-view-tab.is-active{background:var(--ia-accent-soft);color:var(--ia-accent)}
.cal-date-nav{display:inline-flex;align-items:center;gap:4px}
.cal-date-btn{padding:5px 12px;border-radius:6px;font-size:13px;color:var(--ia-text-muted);text-decoration:none;border:0.5px solid var(--ia-border);background:var(--ia-surface);white-space:nowrap;display:inline-flex;align-items:center;justify-content:center;min-height:34px;box-sizing:border-box}/* MARKER-PATCH-346 */
.cal-date-btn:hover{color:var(--ia-text);border-color:var(--ia-border-strong)}
.cal-date-today{font-weight:600}

.cal-week-wrap{background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:8px;overflow-x:auto}/* MARKER-PATCH-345 */
.cal-week-grid{display:grid;gap:0}
.cal-week-corner{background:var(--ia-surface-2,rgba(255,255,255,0.02));border-bottom:0.5px solid var(--ia-border);border-right:0.5px solid var(--ia-border)}
.cal-week-day-head{text-align:center;padding:10px 8px;border-bottom:0.5px solid var(--ia-border);border-right:0.5px solid var(--ia-border);background:var(--ia-surface-2,rgba(255,255,255,0.02))}
.cal-week-day-head:last-child{border-right:none}
.cal-week-day-name{font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:0.06em;color:var(--ia-text-3)}
.cal-week-day-num{font-size:18px;font-weight:600;color:var(--ia-text);margin-top:2px;font-feature-settings:"tnum"}
.cal-week-day-num.is-today{display:inline-block;background:var(--ia-accent);color:var(--ia-accent-text,#0a0a0a);width:30px;height:30px;line-height:30px;border-radius:50%}

.cal-week-resource-cell{padding:12px 14px;border-bottom:0.5px solid var(--ia-border);border-right:0.5px solid var(--ia-border);background:var(--ia-surface-2,rgba(255,255,255,0.02))}
.cal-week-resource-name{font-weight:600;font-size:13px}
.cal-week-resource-sub{font-size:10.5px;color:var(--ia-text-3);margin-top:2px}
.cal-week-resource-cap{font-size:10px;color:var(--ia-text-3);margin-top:4px;font-feature-settings:"tnum"}

.cal-week-cell{border-bottom:0.5px solid var(--ia-border);border-right:0.5px solid var(--ia-border);padding:6px;min-height:80px;display:flex;flex-direction:column;gap:4px;position:relative}
.cal-week-cell:last-child{border-right:none}
.cal-week-cell.is-today{background:rgba(212,255,63,0.025)}
.cal-week-cell.is-full{background:rgba(217,122,122,0.04)}
.cal-week-cell-empty{flex:1}
.cal-week-cell-count{position:absolute;top:4px;right:6px;font-size:10px;color:var(--ia-text-3);font-feature-settings:"tnum"}
.cap-of{color:var(--ia-text-3);opacity:0.6}

.cal-week-card{background:var(--ia-surface-2,rgba(255,255,255,0.04));border:0.5px solid var(--ia-border);border-radius:4px;padding:4px 6px;cursor:grab;font-size:11px;margin-top:14px}
.cal-week-card:first-of-type{margin-top:0}
.cal-week-card.sortable-ghost{opacity:0.5}
.cal-week-card.sortable-chosen{cursor:grabbing}
.cal-week-card-ra{font-size:9.5px;font-weight:600;color:var(--ia-text-3);font-feature-settings:"tnum"}
.cal-week-card-name{font-size:11px;font-weight:500;color:var(--ia-text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
/* MARKER-PATCH-182 — status visuals on capacity/drop-off week cards, matching
   the time-slot status system. Left border carries the status color; pending
   uses a dashed left border (booked-not-confirmed). */
.cal-week-card{border-left-width:3px;border-left-style:solid;border-left-color:var(--ia-border)}
.cal-week-card[data-status="pending"]{border-left-style:dashed;border-left-color:#F59E0B}
.cal-week-card[data-status="confirmed"]{border-left-color:var(--ia-accent)}
.cal-week-card[data-status="in_progress"]{border-left-color:#3B82F6}
.cal-week-card[data-status="completed"]{border-left-color:#6C6C6C;opacity:0.7}
.cal-week-card[data-status="completed"] .cal-week-card-name::after{content:" ✓";color:#6C6C6C}

/* MARKER-PATCH-428 — mobile: full-width controls. Day/Week toggle and the
   date selector share one full-width row; schedule pill spans full width.
   Scoped here so the shared pill (Appointments/Deliveries pages) is unaffected. */
@media (max-width: 640px) {
  .ia-page-head .ia-page-title { display:block; font-size:22px; margin:0; color:var(--ia-text); }
  .ia-page-head .ia-page-subtitle { font-size:12.5px; margin-top:4px; }
  .ia-page-actions { width:100%; }
  .ia-page-actions .ia-btn { flex:1; }
  .ia-page-head-right { width:100%; flex-wrap:wrap; justify-content:flex-start !important; }
  .cal-date-btn { padding:8px 14px; font-size:13.5px; min-height:36px; }
  /* MARKER-PATCH-430 — compact Day/Week toggle; date nav fills to the right edge. */
  .cal-view-toggle { flex:0 0 auto; }
  .cal-date-nav { display:flex; flex:1; }
  .cal-date-today { flex:1; }
  .ia-schedule-toggle { display:flex; width:100%; }
  .ia-schedule-pill { flex:1; justify-content:center; }
}
</style>
@endpush

@push('scripts')
{{-- MARKER-PATCH-182B — legend toggle (same behavior + storage key as time-slot mode) --}}
<script>
(function () {
  var KEY = 'intake.calendar.legend.open';
  var trigger = document.getElementById('ia-cal-legend-trigger');
  var panel   = document.getElementById('ia-cal-legend');
  if (!trigger || !panel) return;
  function set(open) {
    panel.hidden = !open;
    trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
    try { localStorage.setItem(KEY, open ? '1' : '0'); } catch (e) {}
  }
  var stored = '0';
  try { stored = localStorage.getItem(KEY) || '0'; } catch (e) {}
  set(stored === '1');
  trigger.addEventListener('click', function () { set(panel.hidden); });
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script>
window.CAL_DROPOFF_BOOT = {
  csrf:           '{{ csrf_token() }}',
  rescheduleUrl:  '{{ route("tenant.calendar.dropoff.reschedule") }}',
  view:           'week',
  weekStart:      '{{ $weekStart->format("Y-m-d") }}',
};
</script>
<script src="{{ asset('js/tenant/calendar-dropoff.js') }}?v={{ filemtime(public_path('js/tenant/calendar-dropoff.js')) }}" defer></script>
@endpush
