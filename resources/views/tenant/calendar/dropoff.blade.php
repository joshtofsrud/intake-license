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
    <div class="ia-page-actions" style="margin-left:auto">
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
  <div class="cal-dropoff-grid" style="grid-template-columns: repeat({{ $resources->count() }}, minmax(220px, 1fr));">
    @foreach($resources as $r)
      @php
        $cellKey  = $r->id;
        $items    = $appointments->get($cellKey, collect());
        $count    = $items->count();
        $cap      = $r->max_appointments_per_day;
        $atCap    = ($cap !== null && $count >= $cap);
      @endphp
      <div class="cal-dropoff-col" data-resource-id="{{ $r->id }}">
        {{-- MARKER-PATCH-113 - "See all" link added in column header --}}
        <div class="cal-dropoff-col-head" style="border-top: 3px solid {{ $r->color_hex }}">
          <div>
            <div class="cal-dropoff-col-name">{{ $r->name }}</div>
            @if($r->subtitle)
              <div class="cal-dropoff-col-sub">{{ $r->subtitle }}</div>
            @endif
            <a href="{{ route('tenant.appointments.index', ['resource_id' => $r->id, 'date_from' => $date->format('Y-m-d')]) }}"
               class="cal-dropoff-col-seeall">See all →</a>
          </div>
          <div class="cal-dropoff-col-cap {{ $atCap ? 'is-full' : '' }}">
            {{ $count }}@if($cap !== null)<span class="cap-of">/{{ $cap }}</span>@endif
          </div>
        </div>
        <div class="cal-dropoff-col-body" data-resource-id="{{ $r->id }}" data-date="{{ $date->format('Y-m-d') }}">
          @if($items->isEmpty())
            <div class="cal-dropoff-empty">
              <div>No appointments yet.</div>
              <div class="cal-dropoff-empty-hint-desktop">Drag a card here to assign.</div>
              <div class="cal-dropoff-empty-hint-mobile">Tap + below to add one.</div>
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

.cal-dropoff-grid {
  display: grid;
  gap: 12px;
  align-items: start;
}
.cal-dropoff-col {
  background: var(--ia-surface);
  border: 0.5px solid var(--ia-border);
  border-radius: 8px;
  overflow: hidden;
  min-height: 200px;
}
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
  padding: 12px 14px;
  border-bottom: 0.5px solid var(--ia-border);
  background: var(--ia-surface-2, rgba(255,255,255,0.02));
}
.cal-dropoff-col-name {
  font-weight: 600;
  font-size: 14px;
}
.cal-dropoff-col-sub {
  font-size: 11px;
  color: var(--ia-text-3);
  margin-top: 1px;
}
.cal-dropoff-col-cap {
  font-size: 13px;
  color: var(--ia-text-2);
  font-feature-settings: "tnum";
  font-weight: 600;
}
.cal-dropoff-col-cap.is-full {
  color: #d97a7a;
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
}
.cal-dropoff-empty {
  padding: 30px 10px;
  text-align: center;
  font-size: 12px;
  color: var(--ia-text-3);
  font-style: italic;
}

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
  .cal-dropoff-empty-hint-mobile  { display: block; margin-top: 4px; }

  /* Drag handles aren't useful on mobile — soften the grab cursor */
  .cal-dropoff-card { cursor: default; }
}
</style>
@endpush

@push('scripts')
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
