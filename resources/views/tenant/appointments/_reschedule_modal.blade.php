{{-- MARKER-PATCH-158-G2 — Shared reschedule modal partial.

     Extracted from show.blade.php so both the legacy view and the new
     multi-asset view (show-multi-asset.blade.php) can render the same
     battle-tested modal markup + JS without duplication.

     Expected variables in scope:
       $appointment           — the appointment model
       $availableResources    — collection of TenantResource for the tenant
       $isTerminal            — bool (true if status is cancelled or refunded)
       $updateUrl             — route('tenant.appointments.update', $appointment->id)

     Computes locally:
       $reschedFirstService, $reschedFirstServiceId
       $reschedCurrentResource, $reschedFromTimeC, $reschedFromEndC
--}}
@php
  $reschedFirstService    = $appointment->items->first();
  $reschedFirstServiceId  = $reschedFirstService?->service_item_id;
  $reschedCurrentResource = $availableResources->firstWhere('id', $appointment->resource_id);

  try {
    $reschedFromTimeC = $appointment->appointment_time
      ? \Carbon\Carbon::parse($appointment->appointment_date->toDateString() . ' ' . $appointment->appointment_time)
      : null;
    $reschedFromDur = (int) ($appointment->total_duration_minutes ?? 0);
    $reschedFromEndC = ($reschedFromTimeC && $reschedFromDur > 0)
      ? $reschedFromTimeC->copy()->addMinutes($reschedFromDur)
      : null;
  } catch (\Throwable $e) {
    $reschedFromTimeC = null; $reschedFromEndC = null;
  }
@endphp

@push('styles')
<style>
/* RESCHEDULE-MODAL-CSS (shared partial) */
.resch-modal { position: fixed; inset: 0; z-index: 1000; display: flex; align-items: center; justify-content: center; padding: 20px; }
.resch-modal[hidden] { display: none; }
.resch-modal-backdrop { position: absolute; inset: 0; background: rgba(0,0,0,.55); backdrop-filter: blur(4px); }
.resch-modal-card {
  position: relative;
  background: var(--ia-surface);
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-lg, 12px);
  width: 100%; max-width: 520px;
  max-height: 86vh;
  display: flex; flex-direction: column;
  box-shadow: 0 20px 60px rgba(0,0,0,.4);
}
.resch-modal-head {
  display: flex; align-items: center; justify-content: space-between;
  padding: 16px 20px;
  border-bottom: 0.5px solid var(--ia-border);
}
.resch-modal-title { margin: 0; font-size: 15px; font-weight: 500; letter-spacing: -.01em; }
.resch-modal-close {
  background: none; border: none; color: inherit;
  font-size: 22px; line-height: 1; cursor: pointer;
  padding: 4px 8px; border-radius: 4px; opacity: .6;
}
.resch-modal-close:hover { opacity: 1; background: rgba(255,255,255,.06); }
.resch-modal-body { padding: 18px 20px; overflow-y: auto; }
.resch-modal-foot {
  display: flex; justify-content: flex-end; gap: 8px;
  padding: 14px 20px;
  border-top: 0.5px solid var(--ia-border);
}
.resch-from {
  background: var(--ia-surface-2, rgba(255,255,255,.03));
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-md, 8px);
  padding: 12px 14px;
  margin-bottom: 16px;
}
.resch-from-label, .resch-to-label {
  font-size: 11px; text-transform: uppercase; letter-spacing: .07em;
  font-weight: 500; opacity: .5; margin-bottom: 6px;
}
.resch-from-when, .resch-to-when { font-size: 14px; font-weight: 500; }
.resch-from-resource, .resch-to-resource {
  font-size: 12px; opacity: .7; margin-top: 4px;
  display: flex; align-items: center; gap: 6px;
}
.resch-swatch { display: inline-block; width: 8px; height: 8px; border-radius: 50%; }
.resch-to {
  background: rgba(190,242,100,.07);
  border: 1px solid rgba(190,242,100,.25);
  border-radius: var(--ia-r-md, 8px);
  padding: 12px 14px;
  margin-top: 12px;
}
.resch-to-label { color: var(--ia-accent); opacity: 1; }
.resch-field { margin-bottom: 14px; }
.resch-label {
  display: block; font-size: 12px; opacity: .7;
  margin-bottom: 6px;
}
.resch-input {
  width: 100%; padding: 9px 12px;
  background: var(--ia-surface-2, rgba(255,255,255,.03));
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-md, 6px);
  color: inherit; font-family: inherit; font-size: 13px;
}
.resch-times-head {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 8px;
}
.resch-week-nav { display: flex; align-items: center; gap: 4px; }
.resch-week-btn {
  background: transparent; border: 0.5px solid var(--ia-border);
  color: inherit; cursor: pointer;
  width: 24px; height: 24px; border-radius: 4px;
  font-size: 14px; line-height: 1; padding: 0;
}
.resch-week-btn:hover:not(:disabled) { background: rgba(255,255,255,.04); }
.resch-week-btn:disabled { opacity: .3; cursor: not-allowed; }
.resch-week-label { font-size: 12px; opacity: .7; min-width: 110px; text-align: center; }
.resch-times-list {
  max-height: 240px; overflow-y: auto;
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-md, 6px);
}
.resch-times-empty { padding: 24px 16px; text-align: center; font-size: 12px; opacity: .5; }
.resch-times-empty.error { color: #f59999; }
.resch-time-row {
  display: flex; justify-content: space-between; align-items: center;
  padding: 10px 14px;
  border-bottom: 0.5px solid var(--ia-border);
  cursor: pointer;
  font-size: 13px;
  transition: background .1s;
}
.resch-time-row:last-child { border-bottom: none; }
.resch-time-row:hover { background: rgba(255,255,255,.04); }
.resch-time-row.selected {
  background: rgba(190,242,100,.1);
  color: var(--ia-accent);
  font-weight: 500;
}
.resch-time-date { opacity: .7; }
.resch-time-time { font-variant-numeric: tabular-nums; }
</style>
@endpush

@unless($isTerminal)
<div class="resch-modal" id="resch-modal" hidden role="dialog" aria-modal="true" aria-labelledby="resch-modal-title">
  <div class="resch-modal-backdrop" data-resch-close></div>
  <div class="resch-modal-card">
    <div class="resch-modal-head">
      <h2 class="resch-modal-title" id="resch-modal-title">Reschedule appointment</h2>
      <button type="button" class="resch-modal-close" data-resch-close aria-label="Close">×</button>
    </div>

    <div class="resch-modal-body">

      {{-- "From" current state --}}
      <div class="resch-from">
        <div class="resch-from-label">Current</div>
        <div class="resch-from-when">
          @if($reschedFromTimeC)
            {{ $reschedFromTimeC->format('D, M j · g:i A') }}@if($reschedFromEndC) – {{ $reschedFromEndC->format('g:i A') }}@endif
          @else
            No time set
          @endif
        </div>
        <div class="resch-from-resource">
          @if($reschedCurrentResource)
            <span class="resch-swatch" style="background: {{ $reschedCurrentResource->color_hex ?: '#888' }}"></span>
            {{ $reschedCurrentResource->name }}
          @else
            Unassigned
          @endif
        </div>
      </div>

      {{-- Resource picker --}}
      <div class="resch-field">
        <label class="resch-label" for="resch-resource">Resource</label>
        <select class="resch-input" id="resch-resource" data-resch-resource>
          @foreach($availableResources as $r)
            <option value="{{ $r->id }}" data-color="{{ $r->color_hex ?: '#888' }}" @selected($r->id === $appointment->resource_id)>
              {{ $r->name }}@if($r->subtitle) · {{ $r->subtitle }}@endif
            </option>
          @endforeach
        </select>
      </div>

      {{-- Times picker --}}
      <div class="resch-field">
        <div class="resch-times-head">
          <label class="resch-label" style="margin:0">Available times</label>
          <div class="resch-week-nav">
            <button type="button" class="resch-week-btn" id="resch-prev-week" aria-label="Previous week">‹</button>
            <span class="resch-week-label" id="resch-week-label">—</span>
            <button type="button" class="resch-week-btn" id="resch-next-week" aria-label="Next week">›</button>
          </div>
        </div>
        <div class="resch-times-list" id="resch-times-list">
          <div class="resch-times-empty">Pick a resource and click Show times.</div>
        </div>
        <button type="button" class="ia-btn ia-btn--secondary ia-btn--sm" id="resch-show-times"
                style="width:100%;margin-top:8px">
          Show available times
        </button>
      </div>

      {{-- "To" preview --}}
      <div class="resch-to" id="resch-to" hidden>
        <div class="resch-to-label">New</div>
        <div class="resch-to-when" id="resch-to-when">—</div>
        <div class="resch-to-resource" id="resch-to-resource">—</div>
      </div>

    </div>

    <div class="resch-modal-foot">
      <button type="button" class="ia-btn ia-btn--ghost" data-resch-close>Cancel</button>
      <button type="button" class="ia-btn ia-btn--primary" id="resch-submit" disabled
              data-appt-id="{{ $appointment->id }}"
              data-update-url="{{ $updateUrl }}"
              data-first-service-id="{{ $reschedFirstServiceId ?? '' }}">
        Reschedule
      </button>
    </div>
  </div>
</div>
@endunless

@unless($isTerminal)
<script>
// RESCHEDULE-MODAL-JS v1
(function () {
  var modal = document.getElementById('resch-modal');
  if (!modal) return;  // terminal-state appointments don't render the modal

  var openBtn  = document.querySelector('.appt-b-reschedule-btn');
  if (!openBtn) return;

  // Strip the old "ships tomorrow" toast handler by cloning the button.
  // (clone+replace removes all event listeners.)
  var newOpenBtn = openBtn.cloneNode(true);
  openBtn.parentNode.replaceChild(newOpenBtn, openBtn);
  openBtn = newOpenBtn;

  var resourceSel = document.getElementById('resch-resource');
  var showBtn     = document.getElementById('resch-show-times');
  var prevWeekBtn = document.getElementById('resch-prev-week');
  var nextWeekBtn = document.getElementById('resch-next-week');
  var weekLabel   = document.getElementById('resch-week-label');
  var listEl      = document.getElementById('resch-times-list');
  var toBlock     = document.getElementById('resch-to');
  var toWhenEl    = document.getElementById('resch-to-when');
  var toResEl     = document.getElementById('resch-to-resource');
  var submitBtn   = document.getElementById('resch-submit');

  var weekTimesUrl = "{{ route('tenant.appointments.week-times') }}";

  var state = {
    weekStartDate: null,
    selectedSlot:  null,   // {date, time, time_label, date_label}
    slots:         [],
  };

  function todayStr() {
    var d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
  }

  function open() {
    modal.hidden = false;
    document.body.style.overflow = 'hidden';
    state.selectedSlot = null;
    state.weekStartDate = todayStr();
    weekLabel.textContent = formatWeekLabel(state.weekStartDate);
    listEl.innerHTML = '<div class="resch-times-empty">Click Show available times to see open slots.</div>';
    toBlock.hidden = true;
    submitBtn.disabled = true;
  }

  function close() {
    modal.hidden = true;
    document.body.style.overflow = '';
  }

  function fetchTimes() {
    var firstSvc = submitBtn.getAttribute('data-first-service-id');
    if (!firstSvc) {
      listEl.innerHTML = '<div class="resch-times-empty error">This appointment has no service item; cannot look up available times.</div>';
      return;
    }
    var resourceId = resourceSel.value;
    if (!resourceId) {
      listEl.innerHTML = '<div class="resch-times-empty error">Pick a resource first.</div>';
      return;
    }
    listEl.innerHTML = '<div class="resch-times-empty">Loading…</div>';
    weekLabel.textContent = formatWeekLabel(state.weekStartDate);
    prevWeekBtn.disabled = (state.weekStartDate <= todayStr());

    var url = weekTimesUrl
      + '?service_id='  + encodeURIComponent(firstSvc)
      + '&resource_id=' + encodeURIComponent(resourceId)
      + '&start_date='  + encodeURIComponent(state.weekStartDate);

    fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        state.slots = data.slots || [];
        renderTimes();
      })
      .catch(function () {
        listEl.innerHTML = '<div class="resch-times-empty error">Could not load available times.</div>';
      });
  }

  function renderTimes() {
    if (!state.slots.length) {
      listEl.innerHTML = '<div class="resch-times-empty">No available times this week. Try Next week →</div>';
      return;
    }
    var html = '';
    state.slots.forEach(function (slot, idx) {
      var isSel = state.selectedSlot
        && state.selectedSlot.date === slot.date
        && state.selectedSlot.time === slot.time;
      html += '<div class="resch-time-row' + (isSel ? ' selected' : '') + '" data-idx="' + idx + '">'
        +   '<span class="resch-time-date">' + escapeHtml(slot.date_label) + '</span>'
        +   '<span class="resch-time-time">' + escapeHtml(slot.time_label) + '</span>'
        + '</div>';
    });
    listEl.innerHTML = html;
    listEl.querySelectorAll('.resch-time-row').forEach(function (row) {
      row.addEventListener('click', function () {
        var slot = state.slots[parseInt(row.getAttribute('data-idx'), 10)];
        state.selectedSlot = slot;
        renderTimes();
        updateToPreview();
      });
    });
  }

  function updateToPreview() {
    if (!state.selectedSlot) {
      toBlock.hidden = true;
      submitBtn.disabled = true;
      return;
    }
    var resOpt = resourceSel.options[resourceSel.selectedIndex];
    var resColor = resOpt ? resOpt.getAttribute('data-color') : '#888';
    var resName  = resOpt ? resOpt.textContent.trim() : '';
    toWhenEl.textContent = state.selectedSlot.date_label + ' · ' + state.selectedSlot.time_label;
    toResEl.innerHTML = '<span class="resch-swatch" style="background:' + escapeHtml(resColor) + '"></span>' + escapeHtml(resName);
    toBlock.hidden = false;
    submitBtn.disabled = false;
  }

  function formatWeekLabel(startDate) {
    if (!startDate) return '—';
    var s = new Date(startDate + 'T00:00:00');
    var e = new Date(s); e.setDate(e.getDate() + 6);
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return months[s.getMonth()] + ' ' + s.getDate() + ' – ' + months[e.getMonth()] + ' ' + e.getDate();
  }

  function shiftWeek(days) {
    if (!state.weekStartDate) state.weekStartDate = todayStr();
    var d = new Date(state.weekStartDate + 'T00:00:00');
    d.setDate(d.getDate() + days);
    var ymd = d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
    if (ymd < todayStr()) ymd = todayStr();
    state.weekStartDate = ymd;
    fetchTimes();
  }

  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
    });
  }

  function submit() {
    if (!state.selectedSlot) return;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Rescheduling…';

    var url = submitBtn.getAttribute('data-update-url');
    var token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    fetch(url, {
      method: 'PATCH',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': token,
      },
      credentials: 'same-origin',
      body: JSON.stringify({
        op: 'reschedule',
        appointment_date: state.selectedSlot.date,
        appointment_time: state.selectedSlot.time,
        resource_id:      resourceSel.value,
      }),
    }).then(function (r) {
      return r.json().then(function (data) { return { status: r.status, data: data }; });
    }).then(function (resp) {
      if (resp.status === 200 && resp.data.ok) {
        if (window.IntakeToast) window.IntakeToast.success('Appointment rescheduled.');
        // Reload to reflect the new state across rail + main + system note.
        setTimeout(function () { window.location.reload(); }, 600);
      } else if (resp.status === 409) {
        // Slot taken — refresh times.
        if (window.IntakeToast) window.IntakeToast.error(resp.data.message || 'That time was just taken.');
        state.selectedSlot = null;
        toBlock.hidden = true;
        submitBtn.disabled = true;
        submitBtn.textContent = 'Reschedule';
        fetchTimes();
      } else {
        if (window.IntakeToast) window.IntakeToast.error(resp.data.message || 'Reschedule failed.');
        submitBtn.disabled = false;
        submitBtn.textContent = 'Reschedule';
      }
    }).catch(function () {
      if (window.IntakeToast) window.IntakeToast.error('Network error. Try again.');
      submitBtn.disabled = false;
      submitBtn.textContent = 'Reschedule';
    });
  }

  // Wire up.
  openBtn.addEventListener('click', open);
  modal.querySelectorAll('[data-resch-close]').forEach(function (el) {
    el.addEventListener('click', close);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !modal.hidden) close();
  });
  resourceSel.addEventListener('change', function () {
    state.selectedSlot = null;
    toBlock.hidden = true;
    submitBtn.disabled = true;
    if (state.slots.length) fetchTimes();
  });
  showBtn.addEventListener('click', fetchTimes);
  prevWeekBtn.addEventListener('click', function () { shiftWeek(-7); });
  nextWeekBtn.addEventListener('click', function () { shiftWeek(7); });
  submitBtn.addEventListener('click', submit);
})();
</script>
@endunless
