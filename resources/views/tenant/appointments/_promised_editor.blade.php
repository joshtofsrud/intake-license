{{-- MARKER-PATCH-312 — shared promised-date row: display value + inline edit.
     Self-styled to match .ma-schedule-row so it looks native in both
     work-order views. Only one renders per request, so fixed ids are safe. --}}
@php
  $pmLocal = $appointment->promised_at ? tlocal_date($appointment->promised_at) : null;
  $pmVal   = $appointment->promised_at ? tlocal_carbon($appointment->promised_at)->format('Y-m-d') : '';
@endphp
<div class="appt-promised-row">
  <span class="appt-promised-lbl">Promised</span>
  <span class="appt-promised-val">
    <span id="apptPromisedDisplay">{{ $pmLocal ?? '—' }}</span>
    <button type="button" id="apptPromisedEdit" class="appt-promised-link">{{ $pmLocal ? 'Edit' : 'Set' }}</button>
    <span id="apptPromisedForm" class="appt-promised-form">
      <input type="date" id="apptPromisedInput" value="{{ $pmVal }}" class="appt-promised-input">
      <button type="button" id="apptPromisedSave" class="ia-btn ia-btn--secondary ia-btn--sm" style="padding:4px 11px;">Save</button>
      <button type="button" id="apptPromisedCancel" class="appt-promised-link">Cancel</button>
    </span>
  </span>
</div>
<style>
.appt-promised-row{display:grid;grid-template-columns:80px 1fr;padding:6px 0;font-size:13px;border-top:0.5px solid var(--ia-border);align-items:center;}
.appt-promised-lbl{color:var(--ia-text-faint,#52525b);font-size:11px;text-transform:uppercase;letter-spacing:.06em;align-self:center;}
.appt-promised-val{display:flex;align-items:center;gap:10px;flex-wrap:wrap;min-height:24px;}
.appt-promised-link{background:none;border:none;color:var(--ia-accent,#BEF264);font-size:12px;cursor:pointer;padding:0;font-family:inherit;}
.appt-promised-link:hover{opacity:.8;}
.appt-promised-form{display:none;align-items:center;gap:8px;}
.appt-promised-input{background:var(--ia-input-bg);border:0.5px solid var(--ia-border);border-radius:6px;color:var(--ia-text);font-size:12px;padding:4px 8px;font-family:var(--ia-mono,ui-monospace,monospace);}
.appt-promised-input::-webkit-calendar-picker-indicator{filter:invert(.6);cursor:pointer;}
</style>
<script>
(function () {
  var disp   = document.getElementById('apptPromisedDisplay'),
      editBtn= document.getElementById('apptPromisedEdit'),
      form   = document.getElementById('apptPromisedForm'),
      input  = document.getElementById('apptPromisedInput'),
      saveBtn= document.getElementById('apptPromisedSave'),
      cancel = document.getElementById('apptPromisedCancel');
  if (!editBtn || !form) return;
  function show(editing) {
    form.style.display   = editing ? 'inline-flex' : 'none';
    disp.style.display   = editing ? 'none' : '';
    editBtn.style.display= editing ? 'none' : '';
  }
  editBtn.addEventListener('click', function () { show(true); try { input.focus(); } catch (e) {} });
  cancel.addEventListener('click', function () { show(false); });
  saveBtn.addEventListener('click', function () {
    var token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    saveBtn.disabled = true;
    fetch("{{ route('tenant.appointments.update', $appointment->id) }}", {
      method: 'PATCH', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
      body: JSON.stringify({ op: 'promised', promised_date: input.value })
    })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      saveBtn.disabled = false;
      if (d && d.ok) {
        disp.textContent    = d.promised_local || '—';
        editBtn.textContent = d.promised_local ? 'Edit' : 'Set';
        show(false);
        if (window.IntakeToast) window.IntakeToast.success(d.promised_local ? ('Promised ' + d.promised_local) : 'Promised date cleared.');
      } else if (window.IntakeToast) { window.IntakeToast.error('Could not save promised date.'); }
    })
    .catch(function () { saveBtn.disabled = false; if (window.IntakeToast) window.IntakeToast.error('Could not save promised date.'); });
  });
})();
</script>
