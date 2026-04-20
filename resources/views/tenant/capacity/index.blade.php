@extends('layouts.tenant.app')
@php $pageTitle = 'Capacity'; @endphp

@push('styles')
<style>
.cap-mode-banner{border-radius:var(--ia-r-lg);padding:16px 20px;margin-bottom:24px;display:flex;align-items:center;justify-content:space-between;gap:16px}
.cap-mode-banner.drop-off{background:rgba(56,138,221,.08);border:0.5px solid rgba(56,138,221,.2)}
.cap-mode-banner.time-slots{background:rgba(190,242,100,.08);border:0.5px solid rgba(190,242,100,.2)}
.cap-mode-label{font-size:14px;font-weight:600}
.cap-mode-desc{font-size:12px;opacity:.6;margin-top:2px}
.cap-layout{display:grid;grid-template-columns:1fr 320px;gap:20px;align-items:start}
.cap-day-card{background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);padding:14px 16px;margin-bottom:8px}
.cap-day-header{display:flex;align-items:center;gap:16px;margin-bottom:0}
.cap-day-name{width:96px;font-size:13px;font-weight:500;flex-shrink:0}
.cap-bar-wrap{flex:1;height:8px;background:var(--ia-border);border-radius:4px;overflow:hidden}
.cap-bar{height:100%;background:var(--ia-accent);border-radius:4px;transition:width .2s}
.cap-spinner{display:flex;align-items:center;gap:6px;flex-shrink:0}
.cap-spinner-btn{width:28px;height:28px;border-radius:var(--ia-r-md);border:0.5px solid var(--ia-border);background:var(--ia-input-bg);color:var(--ia-text);font-size:16px;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:background var(--ia-t);line-height:1}
.cap-spinner-btn:hover{background:var(--ia-hover)}
.cap-spinner-val{font-size:14px;font-weight:500;min-width:28px;text-align:center}
.cap-slot-info{font-size:11px;opacity:.45;min-width:140px;text-align:right;line-height:1.4}
.cap-time-fields{display:none;margin-top:12px;padding-top:12px;border-top:0.5px solid var(--ia-border);display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}
.cap-time-fields.hidden{display:none}
.cap-override-row{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:0.5px solid var(--ia-border);font-size:13px}
.cap-override-row:last-child{border-bottom:none}
.cap-override-date{font-weight:500;flex:1}
.cap-override-note{font-size:12px;opacity:.5}
.cap-override-max{font-weight:500;min-width:60px;text-align:right}

/* Mode switch modal */
.cap-switch-modal{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:200;display:flex;align-items:center;justify-content:center;padding:20px}
.cap-switch-card{background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-lg);padding:28px;width:100%;max-width:560px;max-height:80vh;overflow-y:auto}
.cap-switch-title{font-size:18px;font-weight:600;margin-bottom:8px}
.cap-switch-desc{font-size:13px;opacity:.6;margin-bottom:20px;line-height:1.6}
.cap-preview-table{width:100%;border-collapse:collapse;font-size:13px;margin-bottom:20px}
.cap-preview-table th{font-size:11px;text-transform:uppercase;letter-spacing:.07em;opacity:.45;padding:6px 0;text-align:left;border-bottom:0.5px solid var(--ia-border)}
.cap-preview-table td{padding:10px 0;border-bottom:0.5px solid var(--ia-border)}
.cap-preview-table tr:last-child td{border-bottom:none}
.cap-preview-input{width:80px;padding:5px 8px;border-radius:6px;border:0.5px solid var(--ia-border);background:var(--ia-input-bg);color:var(--ia-text);font-size:13px;text-align:right}
@media(max-width:800px){.cap-layout{grid-template-columns:1fr}}
</style>
@endpush

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Capacity</h1>
    <p class="ia-page-subtitle">Control how many appointments you accept.</p>
  </div>
  <div class="ia-page-actions">
    <button type="button" class="ia-btn ia-btn--primary" id="cap-save-btn">Save defaults</button>
  </div>
</div>

{{-- Mode banner — VERY obvious --}}
<div class="cap-mode-banner {{ $mode === 'drop_off' ? 'drop-off' : 'time-slots' }}" id="cap-mode-banner">
  <div>
    <div class="cap-mode-label">
      {{ $mode === 'drop_off' ? '📅 Drop-off mode' : '🕐 Time slot mode' }}
    </div>
    <div class="cap-mode-desc">
      @if($mode === 'drop_off')
        Customers pick a date. You control how many jobs you accept per day.
      @else
        Customers pick a date and time. Each service has a set duration.
      @endif
    </div>
  </div>
  <button type="button" class="ia-btn ia-btn--secondary"
    onclick="openSwitchModal('{{ $mode === 'drop_off' ? 'time_slots' : 'drop_off' }}')">
    Switch to {{ $mode === 'drop_off' ? 'time slot' : 'drop-off' }} mode
  </button>
</div>

<div class="cap-layout">

  {{-- Left: 7-day defaults --}}
  <div>
    <div style="font-size:11px;text-transform:uppercase;letter-spacing:.07em;font-weight:500;opacity:.4;margin-bottom:12px">
      Weekly defaults
    </div>
    <div id="cap-days"></div>
    <p id="cap-status" style="font-size:12px;opacity:.5;margin-top:8px;min-height:20px"></p>
  </div>

  {{-- Right: overrides --}}
  <div>
    <div class="ia-card ia-card--tight">
      <div style="font-size:11px;text-transform:uppercase;letter-spacing:.07em;font-weight:500;opacity:.4;margin-bottom:14px">
        Date overrides
      </div>
      <div style="margin-bottom:16px;padding-bottom:16px;border-bottom:0.5px solid var(--ia-border)">
        <div class="ia-form-group">
          <label class="ia-form-label">Date <span class="ia-required">*</span></label>
          <input type="date" id="ov-date" class="ia-input" min="{{ now()->toDateString() }}">
        </div>
        <div class="ia-input-grid-2">
          <div class="ia-form-group">
            <label class="ia-form-label">Max bookings</label>
            <input type="number" id="ov-max" class="ia-input" min="0" value="0">
          </div>
          <div class="ia-form-group">
            <label class="ia-form-label">Note</label>
            <input type="text" id="ov-note" class="ia-input" placeholder="e.g. Holiday">
          </div>
        </div>
        <button type="button" class="ia-btn ia-btn--secondary ia-btn--sm" id="ov-add-btn">Add override</button>
        <p id="ov-error" style="font-size:12px;color:#E24B4A;margin-top:6px;display:none"></p>
      </div>
      <div id="ov-list"></div>
    </div>
  </div>

</div>

{{-- Mode switch modal --}}
<div class="cap-switch-modal" id="switch-modal" style="display:none">
  <div class="cap-switch-card">
    <div class="cap-switch-title" id="switch-modal-title">Switching to time slot mode</div>
    <p class="cap-switch-desc" id="switch-modal-desc">
      We've estimated durations for your services based on their slot weights.
      Review them below and adjust any that need changing before switching.
    </p>

    <table class="cap-preview-table" id="switch-preview-table">
      <thead>
        <tr>
          <th>Service</th>
          <th id="switch-col-current">Current weight</th>
          <th id="switch-col-new">Duration (min)</th>
        </tr>
      </thead>
      <tbody id="switch-preview-body">
        <tr><td colspan="3" style="opacity:.4;padding:16px 0">Loading…</td></tr>
      </tbody>
    </table>

    <div style="background:rgba(226,75,74,.08);border:0.5px solid rgba(226,75,74,.2);border-radius:8px;padding:12px 14px;font-size:13px;margin-bottom:20px">
      <strong>Note:</strong> Existing appointments are not affected. Only future bookings will use the new mode.
    </div>

    <div style="display:flex;gap:10px">
      <button type="button" class="ia-btn ia-btn--ghost" onclick="closeSwitchModal()">Cancel</button>
      <button type="button" class="ia-btn ia-btn--primary" id="switch-confirm-btn" onclick="confirmSwitch()">
        Confirm switch
      </button>
    </div>
  </div>
</div>

@endsection

@push('scripts')
<script>
var d         = { defaults: @json($jsDefaults), overrides: @json($jsOverrides), usage: @json($jsUsage) };
var mode      = '{{ $mode }}';
var ajaxUrl   = '{{ route("tenant.capacity.store") }}';
var csrf      = window.IntakeAdmin.csrfToken;
var DAY_NAMES = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
var INTERVALS = [15,30,45,60,90,120];
var switchTargetMode = null;
var switchPreviewData = [];

document.addEventListener('DOMContentLoaded', function() {
  renderDays();
  renderOverrides();
  bindSave();
  bindAddOverride();
});

// ================================================================
// Day grid
// ================================================================
function renderDays() {
  var container = document.getElementById('cap-days');
  container.innerHTML = '';
  var maxVal = Math.max.apply(null, d.defaults.map(function(x) { return x.max; }).concat([1]));

  d.defaults.forEach(function(rule, i) {
    var usage    = d.usage[getTodayPlusDays(rule.day)] || { slots_used: 0, job_count: 0 };
    var slotsUsed = usage.slots_used || 0;
    var remaining = Math.max(0, rule.max - slotsUsed);

    var card = document.createElement('div');
    card.className = 'cap-day-card';

    // Header row: name + bar + spinner + slot info
    var header = document.createElement('div');
    header.className = 'cap-day-header';

    var name = document.createElement('div');
    name.className = 'cap-day-name';
    name.textContent = DAY_NAMES[rule.day];

    var barWrap = document.createElement('div');
    barWrap.className = 'cap-bar-wrap';
    var bar = document.createElement('div');
    bar.className = 'cap-bar';
    bar.id = 'bar-' + rule.day;
    bar.style.width = rule.max > 0 ? (rule.max / maxVal * 100).toFixed(1) + '%' : '0%';
    barWrap.appendChild(bar);

    var spinner = document.createElement('div');
    spinner.className = 'cap-spinner';

    var minus = document.createElement('button');
    minus.className = 'cap-spinner-btn'; minus.textContent = '−'; minus.type = 'button';
    var valEl = document.createElement('span');
    valEl.className = 'cap-spinner-val'; valEl.textContent = rule.max;
    valEl.id = 'val-' + rule.day;
    var plus = document.createElement('button');
    plus.className = 'cap-spinner-btn'; plus.textContent = '+'; plus.type = 'button';

    function update(newVal) {
      rule.max = Math.max(0, newVal);
      valEl.textContent = rule.max;
      var newMax = Math.max.apply(null, d.defaults.map(function(x) { return x.max; }).concat([1]));
      d.defaults.forEach(function(r) {
        var b = document.getElementById('bar-' + r.day);
        if (b) b.style.width = r.max > 0 ? (r.max / newMax * 100).toFixed(1) + '%' : '0%';
      });
      updateSlotInfo(rule.day, rule.max, slotsUsed);
    }

    minus.addEventListener('click', function() { update(rule.max - 1); });
    plus.addEventListener('click',  function() { update(rule.max + 1); });
    spinner.appendChild(minus); spinner.appendChild(valEl); spinner.appendChild(plus);

    // Slot info display
    var slotInfo = document.createElement('div');
    slotInfo.className = 'cap-slot-info';
    slotInfo.id = 'slot-info-' + rule.day;
    updateSlotInfoEl(slotInfo, rule.max, slotsUsed);

    header.appendChild(name);
    header.appendChild(barWrap);
    header.appendChild(spinner);
    header.appendChild(slotInfo);
    card.appendChild(header);

    // Time slot mode fields
    if (mode === 'time_slots') {
      var timeFields = document.createElement('div');
      timeFields.className = 'cap-time-fields';
      timeFields.innerHTML =
        '<div class="ia-form-group" style="margin-bottom:0"><label class="ia-form-label">Opens</label>' +
        '<input type="time" class="ia-input" id="open-' + rule.day + '" value="' + (rule.open_time || '09:00') + '" oninput="rule_' + i + '_open=this.value"></div>' +
        '<div class="ia-form-group" style="margin-bottom:0"><label class="ia-form-label">Closes</label>' +
        '<input type="time" class="ia-input" id="close-' + rule.day + '" value="' + (rule.close_time || '17:00') + '"></div>' +
        '<div class="ia-form-group" style="margin-bottom:0"><label class="ia-form-label">Slot every</label>' +
        '<select class="ia-input" id="interval-' + rule.day + '">' +
        INTERVALS.map(function(v) {
          return '<option value="' + v + '"' + (rule.slot_interval_minutes == v ? ' selected' : '') + '>' + v + ' min</option>';
        }).join('') + '</select></div>';
      card.appendChild(timeFields);
    }

    container.appendChild(card);
  });
}

function updateSlotInfo(day, max, used) {
  var el = document.getElementById('slot-info-' + day);
  if (el) updateSlotInfoEl(el, max, used);
}

function updateSlotInfoEl(el, max, used) {
  var remaining = Math.max(0, max - used);
  if (max === 0) {
    el.innerHTML = '<span style="opacity:.4">Closed</span>';
  } else if (used > 0) {
    el.innerHTML = used + ' of ' + max + ' slots booked<br><span style="color:var(--ia-accent)">' + remaining + ' remaining</span>';
  } else {
    el.textContent = max + ' slots available';
  }
}

function getTodayPlusDays(dow) {
  var today = new Date();
  var diff  = (dow - today.getDay() + 7) % 7;
  var target = new Date(today);
  target.setDate(today.getDate() + diff);
  return target.toISOString().split('T')[0];
}

function bindSave() {
  var btn = document.getElementById('cap-save-btn');
  if (!btn) return;
  btn.addEventListener('click', function() {
    btn.disabled = true; btn.textContent = 'Saving…';
    var payload = { op: 'save_defaults' };
    d.defaults.forEach(function(rule) {
      payload['days[' + rule.day + '][max]'] = rule.max;
      if (mode === 'time_slots') {
        var openEl     = document.getElementById('open-'     + rule.day);
        var closeEl    = document.getElementById('close-'    + rule.day);
        var intervalEl = document.getElementById('interval-' + rule.day);
        if (openEl)     payload['days[' + rule.day + '][open_time]']             = openEl.value;
        if (closeEl)    payload['days[' + rule.day + '][close_time]']            = closeEl.value;
        if (intervalEl) payload['days[' + rule.day + '][slot_interval_minutes]'] = intervalEl.value;
      }
    });
    post(payload, function(resp) {
      btn.disabled = false; btn.textContent = 'Save defaults';
      setStatus(resp.success ? 'Saved ✓' : 'Error saving.');
    });
  });
}

// ================================================================
// Override management
// ================================================================
function renderOverrides() {
  var list = document.getElementById('ov-list');
  if (!list) return;
  list.innerHTML = '';
  if (d.overrides.length === 0) {
    list.innerHTML = '<p style="font-size:13px;opacity:.4">No date overrides yet.</p>';
    return;
  }
  d.overrides.forEach(function(ov) { list.appendChild(buildOverrideRow(ov)); });
}

function buildOverrideRow(ov) {
  var row = document.createElement('div');
  row.className = 'cap-override-row';
  row.setAttribute('data-id', ov.id);

  var dateEl = document.createElement('div');
  dateEl.className = 'cap-override-date';
  dateEl.textContent = formatDate(ov.date);

  var noteEl = document.createElement('div');
  noteEl.className = 'cap-override-note';
  noteEl.style.flex = '1';
  noteEl.textContent = ov.note || '';

  var maxEl = document.createElement('div');
  maxEl.className = 'cap-override-max';
  maxEl.textContent = ov.max + ' slots';

  var delBtn = document.createElement('button');
  delBtn.className = 'ia-btn ia-btn--ghost ia-btn--sm ia-btn--icon';
  delBtn.type = 'button'; delBtn.title = 'Delete'; delBtn.innerHTML = '&#x2715;';
  delBtn.addEventListener('click', function() {
    post({ op: 'delete_override', id: ov.id }, function(resp) {
      if (resp.success) {
        row.remove();
        d.overrides = d.overrides.filter(function(o) { return o.id !== ov.id; });
        if (d.overrides.length === 0) {
          document.getElementById('ov-list').innerHTML = '<p style="font-size:13px;opacity:.4">No date overrides yet.</p>';
        }
      }
    });
  });

  row.appendChild(dateEl); row.appendChild(noteEl);
  row.appendChild(maxEl); row.appendChild(delBtn);
  return row;
}

function bindAddOverride() {
  var addBtn  = document.getElementById('ov-add-btn');
  var dateInp = document.getElementById('ov-date');
  var maxInp  = document.getElementById('ov-max');
  var noteInp = document.getElementById('ov-note');
  var errEl   = document.getElementById('ov-error');
  var list    = document.getElementById('ov-list');
  if (!addBtn) return;

  addBtn.addEventListener('click', function() {
    var date = dateInp.value, max = parseInt(maxInp.value, 10);
    if (!date) { showErr(errEl, 'Please select a date.'); return; }
    if (isNaN(max) || max < 0) { showErr(errEl, 'Max must be 0 or more.'); return; }
    hideErr(errEl);
    addBtn.disabled = true; addBtn.textContent = 'Saving…';

    post({ op: 'save_override', date: date, max: max, note: noteInp.value.trim() }, function(resp) {
      addBtn.disabled = false; addBtn.textContent = 'Add override';
      if (!resp.success) { showErr(errEl, resp.message || 'Error.'); return; }
      var empty = list.querySelector('p'); if (empty) empty.remove();
      d.overrides.push({ id: resp.id, date: resp.date, max: resp.max, note: resp.note });
      d.overrides.sort(function(a, b) { return a.date.localeCompare(b.date); });
      list.innerHTML = '';
      d.overrides.forEach(function(ov) { list.appendChild(buildOverrideRow(ov)); });
      dateInp.value = ''; maxInp.value = '0'; noteInp.value = '';
    });
  });
}

// ================================================================
// Mode switching
// ================================================================
function openSwitchModal(toMode) {
  switchTargetMode = toMode;
  var modal      = document.getElementById('switch-modal');
  var title      = document.getElementById('switch-modal-title');
  var desc       = document.getElementById('switch-modal-desc');
  var colCurrent = document.getElementById('switch-col-current');
  var colNew     = document.getElementById('switch-col-new');
  var body       = document.getElementById('switch-preview-body');

  title.textContent = 'Switching to ' + (toMode === 'time_slots' ? 'time slot' : 'drop-off') + ' mode';
  desc.textContent  = toMode === 'time_slots'
    ? "We've estimated durations for your services based on their slot weights. Review and adjust before confirming."
    : "We've estimated slot weights for your services based on their durations. Review and adjust before confirming.";
  colCurrent.textContent = toMode === 'time_slots' ? 'Current weight' : 'Current duration';
  colNew.textContent     = toMode === 'time_slots' ? 'Duration (min)' : 'Slot weight (1–4)';
  body.innerHTML = '<tr><td colspan="3" style="opacity:.4;padding:16px 0">Loading…</td></tr>';
  modal.style.display = 'flex';

  post({ op: 'preview_switch', to_mode: toMode }, function(resp) {
    if (!resp.success) return;
    switchPreviewData = resp.preview;
    body.innerHTML = '';
    resp.preview.forEach(function(item) {
      var tr = document.createElement('tr');
      var currentVal = toMode === 'time_slots'
        ? item.current_weight + ' slot' + (item.current_weight > 1 ? 's' : '')
        : item.current_duration + ' min';
      var newVal = toMode === 'time_slots' ? item.estimated_duration : item.estimated_weight;
      var fieldName = toMode === 'time_slots' ? 'duration_minutes' : 'slot_weight';
      tr.innerHTML =
        '<td style="font-size:13px">' + item.name + '</td>' +
        '<td style="font-size:13px;opacity:.6">' + currentVal + '</td>' +
        '<td><input type="number" class="cap-preview-input" ' +
        'data-item-id="' + item.id + '" data-field="' + fieldName + '" ' +
        'value="' + newVal + '" min="' + (toMode === 'time_slots' ? '5' : '1') + '" ' +
        'max="' + (toMode === 'time_slots' ? '480' : '4') + '" step="' + (toMode === 'time_slots' ? '5' : '1') + '"></td>';
      body.appendChild(tr);
    });
  });
}

function closeSwitchModal() {
  document.getElementById('switch-modal').style.display = 'none';
  switchTargetMode = null; switchPreviewData = [];
}

function confirmSwitch() {
  var btn = document.getElementById('switch-confirm-btn');
  btn.disabled = true; btn.textContent = 'Switching…';

  // Collect overrides from the table inputs
  var overrides = {};
  document.querySelectorAll('.cap-preview-input').forEach(function(inp) {
    var itemId = inp.getAttribute('data-item-id');
    var field  = inp.getAttribute('data-field');
    if (!overrides[itemId]) overrides[itemId] = {};
    overrides[itemId][field] = inp.value;
  });

  post({ op: 'execute_switch', to_mode: switchTargetMode, overrides: JSON.stringify(overrides) }, function(resp) {
    if (resp && resp.success) {
      window.location.reload();
    } else {
      btn.disabled = false; btn.textContent = 'Confirm switch';
      var msg = (resp && resp.message) ? resp.message : 'Switch failed. Please try again.';
      alert(msg);
    }
  });
}

// ================================================================
// Helpers
// ================================================================
function setStatus(msg) {
  var el = document.getElementById('cap-status');
  if (el) el.textContent = msg;
}
function formatDate(dateStr) {
  try {
    var parts = dateStr.split('-');
    var dt = new Date(+parts[0], +parts[1]-1, +parts[2]);
    return dt.toLocaleDateString(undefined, { weekday:'short', month:'short', day:'numeric', year:'numeric' });
  } catch(e) { return dateStr; }
}
function showErr(el, msg) { if (el) { el.textContent = msg; el.style.display = ''; } }
function hideErr(el)       { if (el) el.style.display = 'none'; }
function post(data, callback) {
  var fd = new FormData();
  fd.append('_token', csrf);
  Object.keys(data).forEach(function(k) { fd.append(k, data[k]); });
  fetch(ajaxUrl, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(function(r) { return r.json(); }).then(callback)
    .catch(function(err) { console.error('Capacity error:', err); });
}
</script>
@endpush
