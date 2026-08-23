@extends('layouts.tenant.app')
@php
  $pageTitle = 'Calendar';
@endphp

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Calendar</h1>
    <p class="ia-page-subtitle">Drop-off mode · {{ $date->format('l, F j, Y') }}</p>
  </div>
    <div class="ia-page-actions" style="margin-left:auto;display:flex;gap:10px;align-items:center">
      {{-- MARKER-PATCH-430 — legend trigger (ported from week view) --}}
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
  <div class="ia-page-head-right" style="display:flex;gap:10px;align-items:center">
    <div class="cal-view-toggle">
      <a href="?view=day&date={{ $date->format('Y-m-d') }}" class="cal-view-tab is-active">Day</a>
      <a href="?view=week&date={{ $date->format('Y-m-d') }}" class="cal-view-tab">Week</a>
    </div>
    <div class="cal-date-nav">
      <a href="?view=day&date={{ $date->copy()->subDay()->format('Y-m-d') }}" class="cal-date-btn" title="Previous day">‹</a>
      <a href="?view=day&date={{ now($date->timezone)->format('Y-m-d') }}" class="cal-date-btn cal-date-today">Today</a>
      <a href="?view=day&date={{ $date->copy()->addDay()->format('Y-m-d') }}" class="cal-date-btn" title="Next day">›</a>
    </div>
  </div>
</div>

{{-- MARKER-PATCH-152A — capacity-mode was missing the schedule sub-toggle --}}
<x-tenant.schedule-tabs active="calendar" />

{{-- MARKER-PATCH-430 — legend panel (ported from week view) --}}
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
  {{-- MARKER-DROPOFF-POLISH — auto-fill wraps to a new row instead of
       running off the right edge; the cap keeps 1–2 resources from
       stretching a column across the whole page. --}}
  <div class="cal-dropoff-grid"
       style="grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); max-width: {{ min($resources->count(), 5) * 260 }}px;">
    @foreach($resources as $r)
      @php
        $cellKey  = $r->id;
        $items    = $appointments->get($cellKey, collect());
        $count    = $items->count();
        $cap      = $r->max_appointments_per_day;
        $atCap    = ($cap !== null && $count >= $cap);
      @endphp
      {{-- MARKER-DROPOFF-POLISH — head is one flex row at a fixed height so
           every column starts its cards on the same line, with or without a
           subtitle. The count pill is the link to this person's list. --}}
      @php
        $nearCap = ($cap !== null && !$atCap && $count >= (int) ceil($cap * 0.8) && $cap > 0);
        $capPct  = ($cap !== null && $cap > 0) ? min(100, (int) round($count * 100 / $cap)) : 0;
      @endphp
      <div class="cal-dropoff-col" data-resource-id="{{ $r->id }}" style="--res: {{ $r->color_hex }}">
        <div class="cal-dropoff-col-head">
          <div class="cal-dropoff-col-id">
            <span class="cal-res-dot" aria-hidden="true"></span>
            <div class="cal-dropoff-col-idtext">
              <div class="cal-dropoff-col-name">{{ $r->name }}</div>
              @if($r->subtitle)
                <div class="cal-dropoff-col-sub">{{ $r->subtitle }}</div>
              @endif
            </div>
          </div>
          <a href="{{ route('tenant.appointments.index', ['resource_id' => $r->id, 'date_from' => $date->format('Y-m-d')]) }}"
             class="cal-dropoff-col-cap {{ $atCap ? 'is-full' : ($nearCap ? 'is-near' : '') }}"
             title="See {{ $r->name }}'s appointments">
            {{ $count }}@if($cap !== null)<span class="cap-of">/{{ $cap }}</span>@endif<span class="cap-go" aria-hidden="true">&rarr;</span>
          </a>
        </div>
        @if($cap !== null)
          <div class="cal-cap-meter"><i class="{{ $atCap ? 'is-full' : '' }}" style="width: {{ $capPct }}%"></i></div>
        @endif
        <div class="cal-dropoff-col-body" data-resource-id="{{ $r->id }}" data-date="{{ $date->format('Y-m-d') }}">
          @if($items->isEmpty())
            <div class="cal-dropoff-empty">
              <div>Nothing scheduled</div>
              <div class="cal-dropoff-empty-hint-desktop">Drag a card here to assign</div>
              <div class="cal-dropoff-empty-hint-mobile">Tap + below to add one</div>
            </div>
          @else
            @foreach($items as $appt)
              <div class="cal-dropoff-card" data-appointment-id="{{ $appt->id }}" data-status="{{ $appt->status }}">
                <div class="cal-dropoff-card-head">
                  <span class="cal-dropoff-card-ra">{{ $appt->ra_number }}</span>
                  <span class="cal-dropoff-card-status status-{{ $appt->status }}">{{ ucfirst(str_replace('_', ' ', $appt->status)) }}</span>
                </div>
                <div class="cal-dropoff-card-customer">{{ $appt->customer_first_name }} {{ $appt->customer_last_name }}</div>
                @if($appt->receiving_method_snapshot)
                  <div class="cal-dropoff-card-method">{{ $appt->receiving_method_snapshot }}</div>
                @endif
              </div>
            @endforeach
          @endif
        </div>
      </div>
    @endforeach
  </div>
@endif

@include('tenant._appt-drawer')

{{-- MARKER-PATCH-163 — defines window.openApptModal() --}}
@include('tenant.appointments._create_modal')

@endsection

@push('styles')
<link rel="stylesheet" href="{{ asset('css/tenant/calendar.css') }}?v={{ filemtime(public_path('css/tenant/calendar.css')) }}">
<style>
.cal-view-toggle {
  display: inline-flex;
  background: var(--ia-surface);
  border: 0.5px solid var(--ia-border);
  border-radius: 6px;
  padding: 2px;
  gap: 2px;
}
.cal-view-tab {
  padding: 6px 12px;
  font-size: 12px;
  color: var(--ia-text-muted);
  border-radius: 4px;
  text-decoration: none;
  font-weight: 500;
}
.cal-view-tab:hover { color: var(--ia-text); }
.cal-view-tab.is-active {
  background: var(--ia-accent-soft);
  color: var(--ia-accent);
}

.cal-date-nav {
  display: inline-flex;
  align-items: center;
  gap: 4px;
}
.cal-date-btn {
  padding: 5px 10px;
  border-radius: 6px;
  font-size: 13px;
  color: var(--ia-text-muted);
  text-decoration: none;
  border: 0.5px solid var(--ia-border);
  background: var(--ia-surface);
}
.cal-date-btn:hover { color: var(--ia-text); border-color: var(--ia-border-strong); }
.cal-date-today { font-weight: 600; }

/* MARKER-DROPOFF-POLISH */
.cal-dropoff-grid {
  display: grid;
  gap: 12px;
  align-items: stretch;   /* every column the same height across a row */
}
.cal-dropoff-col {
  background: var(--ia-surface);
  border: 0.5px solid var(--ia-border);
  border-radius: 8px;
  overflow: hidden;
  min-height: 200px;
  display: flex;
  flex-direction: column;
  transition: border-color 0.15s;
}
.cal-dropoff-col:hover { border-color: var(--ia-border-strong); }
.cal-dropoff-col-seeall {
  display: inline-block;
  margin-top: 4px;
  font-size: 11px;
  color: var(--ia-text-3, #888);
  text-decoration: none;
  letter-spacing: 0.02em;
}
.cal-dropoff-col-seeall:hover {
  color: var(--ia-accent, #BEF264);
}
.cal-dropoff-col-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  min-height: 58px;
  padding: 11px 13px;
  border-bottom: 0.5px solid var(--ia-border);
  background: linear-gradient(to bottom,
              color-mix(in srgb, var(--res, transparent) 13%, transparent), transparent);
}
.cal-dropoff-col-id { display: flex; align-items: center; gap: 8px; min-width: 0; }
.cal-dropoff-col-idtext { min-width: 0; }
.cal-res-dot {
  width: 8px; height: 8px; border-radius: 50%; flex: 0 0 auto;
  background: var(--res, var(--ia-text-3));
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--res, transparent) 22%, transparent);
}
.cal-cap-meter { height: 2px; background: rgba(127,127,127,0.14); }
.cal-cap-meter i { display: block; height: 100%; background: var(--res, var(--ia-accent)); transition: width 0.2s; }
.cal-cap-meter i.is-full { background: #d97a7a; }
.cal-dropoff-col-name {
  font-weight: 600;
  font-size: 14px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.cal-dropoff-col-sub {
  font-size: 11px;
  color: var(--ia-text-3);
  margin-top: 1px;
}
.cal-dropoff-col-cap {
  display: flex;
  align-items: baseline;
  gap: 1px;
  flex: 0 0 auto;
  text-decoration: none;
  font-size: 12.5px;
  font-weight: 700;
  color: var(--ia-text-2);
  font-feature-settings: "tnum";
  background: rgba(127,127,127,0.10);
  border: 0.5px solid var(--ia-border);
  border-radius: 99px;
  padding: 3px 9px;
  transition: border-color 0.15s, color 0.15s;
}
.cal-dropoff-col-cap:hover { border-color: var(--ia-border-strong); color: var(--ia-text); }
.cal-dropoff-col-cap:focus-visible { outline: 2px solid var(--ia-accent); outline-offset: 2px; }
.cal-dropoff-col-cap .cap-go { opacity: 0; margin-left: 3px; font-size: 11px; transition: opacity 0.15s; }
.cal-dropoff-col:hover .cap-go { opacity: 0.55; }
.cal-dropoff-col-cap.is-near {
  color: #F59E0B;
  border-color: rgba(245,158,11,0.4);
  background: rgba(245,158,11,0.10);
}
.cal-dropoff-col-cap.is-full {
  color: #d97a7a;
  border-color: rgba(217,122,122,0.45);
  background: rgba(217,122,122,0.12);
}
.cap-of {
  color: var(--ia-text-3);
  font-weight: 400;
}
.cal-dropoff-col-body {
  padding: 10px;
  min-height: 140px;
  display: flex;
  flex-direction: column;
  gap: 8px;
  flex: 1;
}
.cal-dropoff-empty {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 3px;
  padding: 20px 10px;
  text-align: center;
  font-size: 12px;
  color: var(--ia-text-3);
}
/* The drag hint belongs to the column you're pointing at, not all of them. */
.cal-dropoff-empty-hint-desktop { color: transparent; transition: color 0.15s; }
.cal-dropoff-col:hover .cal-dropoff-empty-hint-desktop { color: var(--ia-text-3); }

.cal-dropoff-card {
  background: var(--ia-surface-2, rgba(255,255,255,0.04));
  border: 0.5px solid var(--ia-border);
  border-radius: 6px;
  padding: 9px 11px;
  cursor: grab;
  transition: border-color 0.15s, transform 0.1s;
}
.cal-dropoff-card:hover {
  border-color: var(--ia-border-strong);
}
.cal-dropoff-card.sortable-ghost {
  opacity: 0.5;
}
.cal-dropoff-card.sortable-chosen {
  cursor: grabbing;
}
.cal-dropoff-card-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 4px;
}
.cal-dropoff-card-ra {
  font-size: 11px;
  font-weight: 600;
  color: var(--ia-text-3);
  font-feature-settings: "tnum";
}
.cal-dropoff-card-status {
  font-size: 9.5px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  padding: 1px 6px;
  border-radius: 3px;
}
.status-pending      { background: rgba(245,158,11,0.15);  color: #F59E0B; }
.status-confirmed    { background: rgba(212,255,63,0.15);  color: var(--ia-accent); }
.status-in_progress  { background: rgba(59,130,246,0.15);  color: #3B82F6; }
.status-completed    { background: rgba(108,108,108,0.15); color: var(--ia-text-3); }

.cal-dropoff-card-customer {
  font-size: 13px;
  font-weight: 500;
  color: var(--ia-text);
}
.cal-dropoff-card-method {
  font-size: 11px;
  color: var(--ia-text-3);
  margin-top: 2px;
}


/* Drop-off calendar mobile (patch #42) */
.cal-dropoff-empty-hint-mobile { display: none; }
.cal-dropoff-empty-hint-desktop { display: block; }

@media (max-width: 640px) {
  /* Ensure the page title renders visibly on mobile.
     Some interaction (likely the global page-head column-stack rule
     from mobile-forms.css combined with the inline flex:gap:10px on
     the .ia-page-head-right) was leaving the title row visually empty.
     This rule explicitly forces title visibility + reasonable size. */
  .ia-page-head .ia-page-title {
    display: block;
    font-size: 22px;
    margin: 0;
    color: var(--ia-text);
  }
  .ia-page-head .ia-page-subtitle {
    font-size: 12.5px;
    margin-top: 4px;
  }

  /* Page head right side: view toggle + date nav. They sit in a flex
     container with gap:10px (inline style). On mobile, let them wrap
     onto their own row below the title. */
  .ia-page-head-right {
    flex-wrap: wrap;
    width: 100%;
    justify-content: flex-start !important;
  }

  /* Date-nav buttons grow to fit available width but stay touch-friendly. */
  .cal-date-btn {
    padding: 8px 14px;
    font-size: 13.5px;
    min-height: 36px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
  }
  .cal-view-tab {
    padding: 8px 14px;
    font-size: 13px;
    min-height: 32px;
    display: inline-flex;
    align-items: center;
  }

  /* MARKER-PATCH-428 — full-width controls on mobile, aligned to the
     "+ New appointment" button. Day/Week + date nav each take their own
     full-width row; the schedule toggle pill spans the width too.
     Scoped here so the shared pill (Appointments/Deliveries pages) is
     unaffected. */
  /* MARKER-PATCH-430 — compact Day/Week toggle; date nav fills to the right
     edge (single row, not stacked). Actions row holds Legend + New appointment. */
  .ia-page-actions { width: 100%; }
  .ia-page-actions .ia-btn { flex: 1; }
  .cal-view-toggle { flex: 0 0 auto; }
  .cal-date-nav { display: flex; flex: 1; }
  .cal-date-today { flex: 1; }
  .ia-schedule-toggle { display: flex; width: 100%; }
  .ia-schedule-pill { flex: 1; justify-content: center; }

  /* Resource columns: the inline style sets
     `grid-template-columns: repeat(N, minmax(220px, 1fr))` which forces
     each col to at least 220px. With 2+ resources this overflows the
     390px viewport. Override to single-column on mobile so each resource
     becomes a stacked card. */
  .cal-dropoff-grid[style*="grid-template-columns"] {
    grid-template-columns: 1fr !important;
    gap: 10px !important;
  }

  /* Tighten the column header + body */
  .cal-dropoff-col {
    min-height: 0;  /* Don't reserve 200px when empty on mobile */
  }
  .cal-dropoff-col-head {
    padding: 10px 14px;
  }
  .cal-dropoff-col-body {
    min-height: 60px;
    padding: 8px 10px;
  }

  /* Empty-state hint: swap desktop wording for mobile wording */
  .cal-dropoff-empty {
    padding: 18px 10px;
    font-size: 12.5px;
  }
  .cal-dropoff-empty-hint-desktop { display: none; }
  .cal-dropoff-empty-hint-mobile  { display: block; margin-top: 4px; color: var(--ia-text-3); }
  /* MARKER-DROPOFF-POLISH — no hover on touch, and the width cap must go. */
  .cal-dropoff-grid[style*="grid-template-columns"] { max-width: none !important; }
  .cal-dropoff-col-head { min-height: 0; }

  /* Drag handles aren't useful on mobile — soften the grab cursor */
  .cal-dropoff-card { cursor: default; }
}
</style>
@endpush

@push('scripts')
{{-- MARKER-PATCH-430 — legend toggle (ported from week view) --}}
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
  view:           'day',
  date:           '{{ $date->format("Y-m-d") }}',
};
</script>
<script src="{{ asset('js/tenant/calendar-dropoff.js') }}?v={{ filemtime(public_path('js/tenant/calendar-dropoff.js')) }}" defer></script>
@endpush
