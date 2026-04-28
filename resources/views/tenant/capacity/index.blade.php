@extends('layouts.tenant.app')
@php
  $pageTitle = 'Capacity';
  $dayLabels = [0 => 'Sun', 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat'];
@endphp

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Capacity</h1>
    <p class="ia-page-subtitle">When you're open, how many bookings you'll take, and per-day exceptions.</p>
  </div>
  <div class="ia-page-head-right">
    <div class="cap-mode-pill" data-mode="{{ $mode }}">
      Booking mode: <strong>{{ $mode === 'time_slots' ? 'Time slots' : 'Drop-off' }}</strong>
    </div>
  </div>
</div>

@if(session('flash'))
  <div class="ia-flash ia-flash--success" style="margin-bottom:16px">{{ session('flash') }}</div>
@endif

{{-- ============================================================
     Resource caps summary
     ============================================================ --}}
<div class="ia-card cap-resource-summary" style="padding:14px 18px;margin-bottom:18px">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap">
    <div>
      <div class="ia-label" style="font-size:11px;letter-spacing:.06em">Resource daily caps</div>
      <div style="font-size:13px;color:var(--ia-text-2);margin-top:3px">
        @if(count($jsResources) === 0)
          No active resources yet. <a href="{{ route('tenant.resources.index') }}" style="color:var(--ia-accent)">Add resources →</a>
        @elseif($resourceCapSum === 0)
          Resources have no per-day caps set. Bookings will be unbounded by resource quota.
        @else
          Sum across {{ count($jsResources) }} resource{{ count($jsResources) === 1 ? '' : 's' }}: <strong>{{ $resourceCapSum }}</strong> bookings/day.
        @endif
      </div>
    </div>
    <a href="{{ route('tenant.resources.index') }}" class="ia-btn ia-btn--secondary ia-btn--sm">Manage resources →</a>
  </div>
  @if(count($jsResources) > 0)
    <div class="cap-resource-chips">
      @foreach($jsResources as $r)
        <span class="cap-resource-chip">
          <span class="cap-resource-dot" style="background:{{ $r['color_hex'] }}"></span>
          <span>{{ $r['name'] }}</span>
          <span class="cap-resource-cap">
            @if($r['max_appointments_per_day'] === null)
              no cap
            @else
              {{ $r['max_appointments_per_day'] }}/day
            @endif
          </span>
        </span>
      @endforeach
    </div>
  @endif
</div>

{{-- ============================================================
     Weekly defaults (Mon-Sun rows)
     ============================================================ --}}
<div class="ia-card" style="padding:0;margin-bottom:18px;overflow:hidden">
  <div class="cap-card-head">
    <div>
      <div class="ia-card-title">Weekly defaults</div>
      <div class="ia-card-sub">Open/close hours, closed days, and any shop-wide bookings cap that overrides the resource sum.</div>
    </div>
    <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" id="cap-toggle-advanced">
      <span data-when-hidden>Show advanced</span>
      <span data-when-shown style="display:none">Hide advanced</span>
    </button>
  </div>

  <div class="cap-day-header">
    <div class="cap-col-day">Day</div>
    <div class="cap-col-closed">Status</div>
    <div class="cap-col-hours">Open hours</div>
    <div class="cap-col-spacer"></div>
    <div class="cap-col-max">{{ $mode === 'drop_off' ? 'Daily cap' : 'Slot interval' }}</div>
    <div class="cap-col-advanced">{{ $mode === 'drop_off' ? 'Slot interval' : 'Daily cap' }}</div>
  </div>

  <div id="cap-defaults-list">
    {{-- Day rows rendered by JS from window.CAP_BOOT --}}
  </div>

  <div class="cap-legend">
    @if($mode === 'drop_off')
      <div class="cap-legend-row">
        <strong>Daily cap</strong> — the maximum bookings you'll accept on this day.
        Leave blank to use the sum of your resources' per-day caps from
        <a href="{{ route('tenant.resources.index') }}">Resources</a>.
      </div>
      <div class="cap-legend-row">
        <strong>Closed</strong> — toggle to mark the day as closed. No bookings will be accepted regardless of cap.
      </div>
      <div class="cap-legend-row cap-legend-advanced">
        <strong>Slot interval</strong> (advanced) — drop-off mode doesn't use slot intervals; visible for completeness only.
      </div>
    @else
      <div class="cap-legend-row">
        <strong>Slot interval</strong> — how long each bookable time slot is, in minutes. Combined with open hours and resource count, this determines how many bookings fit in the day.
      </div>
      <div class="cap-legend-row">
        <strong>Closed</strong> — toggle to mark the day as closed. No bookings will be accepted.
      </div>
      <div class="cap-legend-row cap-legend-advanced">
        <strong>Daily cap</strong> (advanced) — optional override that caps bookings below what the grid math would normally allow. Rarely needed.
      </div>
    @endif
  </div>
</div>

{{-- ============================================================
     Date overrides
     ============================================================ --}}
<div class="ia-card" style="padding:0;overflow:hidden">
  <div class="cap-card-head">
    <div>
      <div class="ia-card-title">Date overrides</div>
      <div class="ia-card-sub">One-off changes for specific dates. Useful for holidays, special events, or unplanned closures.</div>
    </div>
    <button type="button" class="ia-btn ia-btn--primary ia-btn--sm" id="cap-add-override-btn">+ Add override</button>
  </div>

  <div id="cap-overrides-list">
    {{-- Override rows rendered by JS --}}
  </div>

  <div id="cap-override-empty" style="padding:34px;text-align:center;color:var(--ia-text-3);font-size:13px;display:none">
    No date overrides yet. Click <strong>+ Add override</strong> to set capacity or close the shop on a specific date.
  </div>
</div>

<div class="cap-status" id="cap-status"></div>

{{-- ============================================================
     Add override modal
     ============================================================ --}}
<div class="cap-modal" id="cap-override-modal" style="display:none">
  <div class="cap-modal-back"></div>
  <div class="cap-modal-card">
    <div class="cap-modal-head">
      <span class="ia-card-title">Date override</span>
      <button type="button" class="cap-modal-x" id="cap-override-close">×</button>
    </div>
    <div class="cap-modal-body">
      <div class="ia-form-group">
        <label class="ia-form-label">Date</label>
        <input type="date" id="ov-date" class="ia-input">
      </div>
      <div class="ia-form-group">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" id="ov-is-closed">
          <span>Closed on this date</span>
        </label>
      </div>
      <div class="ia-form-group" id="ov-max-group">
        <label class="ia-form-label">Max bookings (leave blank to use resource sum)</label>
        <input type="number" id="ov-max" class="ia-input" min="0" placeholder="Leave blank for resource sum">
      </div>
      <div class="ia-form-group">
        <label class="ia-form-label">Note (optional)</label>
        <input type="text" id="ov-note" class="ia-input" placeholder="e.g. Holiday, vacation, half-day">
      </div>
    </div>
    <div class="cap-modal-actions">
      <button type="button" class="ia-btn ia-btn--ghost" id="cap-override-cancel">Cancel</button>
      <button type="button" class="ia-btn ia-btn--primary" id="cap-override-save">Save</button>
    </div>
  </div>
</div>

@endsection

@push('styles')
<link rel="stylesheet" href="{{ asset('css/tenant/capacity.css') }}?v={{ filemtime(public_path('css/tenant/capacity.css')) }}">
<style>
.cap-mode-pill {
  background: var(--ia-surface-2, rgba(255,255,255,0.04));
  border: 0.5px solid var(--ia-border);
  border-radius: 999px;
  padding: 6px 14px;
  font-size: 12px;
  color: var(--ia-text-2);
}
.cap-mode-pill strong { color: var(--ia-text); font-weight: 600; }

.cap-card-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
  padding: 16px 20px;
  border-bottom: 0.5px solid var(--ia-border);
}
.ia-card-title { font-size: 14px; font-weight: 600; }
.ia-card-sub { font-size: 12px; color: var(--ia-text-3); margin-top: 3px; }

.cap-resource-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 12px;
  padding-top: 12px;
  border-top: 0.5px dashed var(--ia-border);
}
.cap-resource-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: var(--ia-surface-2, rgba(255,255,255,0.04));
  border: 0.5px solid var(--ia-border);
  border-radius: 999px;
  padding: 4px 10px 4px 8px;
  font-size: 11.5px;
}
.cap-resource-dot {
  display: inline-block;
  width: 8px; height: 8px;
  border-radius: 50%;
}
.cap-resource-cap {
  color: var(--ia-text-3);
  font-size: 10.5px;
  margin-left: 2px;
  padding-left: 8px;
  border-left: 0.5px solid var(--ia-border);
}

/* Day-row column header — mirrors the .cap-day-row grid template. */
.cap-day-header {
  display: grid;
  grid-template-columns: 80px 70px 1fr 1fr 100px 100px;
  gap: 14px;
  align-items: center;
  padding: 10px 20px;
  border-bottom: 0.5px solid var(--ia-border);
  background: var(--ia-surface-2, rgba(255,255,255,0.025));
  font-size: 10.5px;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--ia-text-3);
  font-weight: 600;
}
.cap-day-header .cap-col-max,
.cap-day-header .cap-col-advanced {
  text-align: right;
}
/* Hide the advanced-only column header until Show advanced is toggled. */
.cap-day-header .cap-col-advanced { visibility: hidden; }
.ia-card:has(.cap-day-row[data-show-advanced="1"]) .cap-day-header .cap-col-advanced { visibility: visible; }

.cap-day-row {
  display: grid;
  grid-template-columns: 80px 70px 1fr 1fr 100px 100px;
  gap: 14px;
  align-items: center;
  padding: 12px 20px;
  border-bottom: 0.5px solid var(--ia-border);
  background: var(--ia-surface);
}
.cap-day-row:last-child { border-bottom: none; }
.cap-day-row.is-closed { opacity: 0.55; }
.cap-day-row.is-closed .cap-day-fields-when-open { display: none; }
.cap-day-row .cap-day-fields-when-closed { display: none; }
.cap-day-row.is-closed .cap-day-fields-when-closed { display: block; color: var(--ia-text-3); font-style: italic; font-size: 12px; }

.cap-day-label { font-weight: 600; font-size: 13px; }
.cap-day-toggle {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 11px;
  color: var(--ia-text-3);
  cursor: pointer;
  user-select: none;
}
.cap-day-toggle input { cursor: pointer; }

.cap-day-time {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 12px;
  color: var(--ia-text-3);
}
.cap-day-time input {
  background: var(--ia-surface-2, rgba(255,255,255,0.05));
  border: 0.5px solid var(--ia-border);
  border-radius: 4px;
  padding: 5px 7px;
  font-size: 12px;
  color: var(--ia-text);
  font-family: inherit;
  width: 80px;
}

.cap-day-max input,
.cap-day-interval input {
  background: var(--ia-surface-2, rgba(255,255,255,0.05));
  border: 0.5px solid var(--ia-border);
  border-radius: 4px;
  padding: 5px 7px;
  font-size: 12px;
  color: var(--ia-text);
  font-family: inherit;
  width: 100%;
  text-align: right;
}

/* Clear-to-no-limit button overlaid on the cap input. Hidden when input is empty. */
.cap-input-wrap {
  position: relative;
  display: block;
}
.cap-input-wrap input {
  padding-right: 22px;
}
.cap-input-clear {
  position: absolute;
  top: 50%;
  right: 4px;
  transform: translateY(-50%);
  background: transparent;
  border: none;
  cursor: pointer;
  font-size: 14px;
  line-height: 1;
  width: 18px;
  height: 18px;
  padding: 0;
  color: var(--ia-text-3);
  border-radius: 3px;
  display: flex;
  align-items: center;
  justify-content: center;
}
.cap-input-clear:hover {
  background: var(--ia-hover, rgba(255,255,255,0.06));
  color: var(--ia-text);
}

.cap-day-interval { display: none; }
.cap-day-advanced-only { display: none; }
.cap-day-row[data-show-advanced="1"] .cap-day-advanced-only { display: block; }
.cap-day-row[data-show-advanced="1"] .cap-day-interval { display: block; }

/* Legend below the day rows. */
.cap-legend {
  padding: 14px 20px;
  border-top: 0.5px solid var(--ia-border);
  background: var(--ia-surface-2, rgba(255,255,255,0.02));
  font-size: 12px;
  color: var(--ia-text-3);
}
.cap-legend-row {
  padding: 4px 0;
  line-height: 1.6;
}
.cap-legend-row strong { color: var(--ia-text-2); font-weight: 600; }
.cap-legend-row a { color: var(--ia-accent); text-decoration: none; }
.cap-legend-row a:hover { text-decoration: underline; }
.cap-legend-advanced { display: none; }
.ia-card:has(.cap-day-row[data-show-advanced="1"]) .cap-legend-advanced { display: block; }

.cap-override-row {
  display: grid;
  grid-template-columns: 1fr 80px 1fr 80px 32px;
  gap: 14px;
  align-items: center;
  padding: 12px 20px;
  border-bottom: 0.5px solid var(--ia-border);
  background: var(--ia-surface);
}
.cap-override-row:last-child { border-bottom: none; }
.cap-override-date { font-weight: 600; font-size: 13px; }
.cap-override-status {
  display: inline-block;
  padding: 2px 7px;
  border-radius: 3px;
  font-size: 10.5px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}
.cap-override-status.closed { background: rgba(217, 122, 122, 0.12); color: #d97a7a; }
.cap-override-status.cap    { background: rgba(212, 255, 63, 0.10); color: var(--ia-accent); }
.cap-override-note { font-size: 12px; color: var(--ia-text-3); }
.cap-override-cap-display { font-size: 13px; text-align: right; font-feature-settings: "tnum"; }

.cap-status {
  position: fixed;
  bottom: 18px;
  right: 18px;
  background: var(--ia-surface-2, #131313);
  border: 0.5px solid var(--ia-border);
  border-radius: 6px;
  padding: 8px 14px;
  font-size: 12px;
  color: var(--ia-text-2);
  opacity: 0;
  transition: opacity 0.18s;
  pointer-events: none;
}
.cap-status.show { opacity: 1; }

.cap-modal {
  position: fixed; inset: 0;
  display: flex; align-items: center; justify-content: center;
  z-index: 100;
}
.cap-modal-back {
  position: absolute; inset: 0;
  background: rgba(0,0,0,0.55);
}
.cap-modal-card {
  position: relative;
  background: var(--ia-surface);
  border: 0.5px solid var(--ia-border);
  border-radius: 10px;
  width: 90%; max-width: 460px;
  box-shadow: 0 20px 50px rgba(0,0,0,0.4);
}
.cap-modal-head {
  display: flex; align-items: center; justify-content: space-between;
  padding: 16px 20px;
  border-bottom: 0.5px solid var(--ia-border);
}
.cap-modal-x {
  background: transparent; border: none; cursor: pointer;
  font-size: 22px; color: var(--ia-text-3);
  padding: 4px 8px;
}
.cap-modal-x:hover { color: var(--ia-text); }
.cap-modal-body { padding: 20px; }
.cap-modal-actions {
  display: flex; align-items: center; justify-content: flex-end;
  gap: 10px; padding: 14px 20px;
  border-top: 0.5px solid var(--ia-border);
}
</style>
@endpush

@push('scripts')
<script>
window.CAP_BOOT = {
  csrf:           '{{ csrf_token() }}',
  saveUrl:        '{{ route("tenant.capacity.store") }}',
  mode:           {!! json_encode($mode) !!},
  defaults:       {!! json_encode($jsDefaults) !!},
  overrides:      {!! json_encode($jsOverrides) !!},
  usage:          {!! json_encode($jsUsage) !!},
  resources:      {!! json_encode($jsResources) !!},
  resourceCapSum: {!! (int) $resourceCapSum !!},
};
</script>
<script src="{{ asset('js/tenant/capacity.js') }}?v={{ filemtime(public_path('js/tenant/capacity.js')) }}" defer></script>
@endpush
