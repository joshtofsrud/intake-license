{{-- MARKER-PATCH-311 — shared inline promised-date editor.
     Only one work-order view renders per request, so the fixed ids are safe. --}}
@php $pmVal = $appointment->promised_at ? tlocal_carbon($appointment->promised_at)->format('Y-m-d') : ''; @endphp
<div class="appt-promised-row" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:2px;">
  <span class="lbl" style="opacity:.55;font-size:12px;min-width:60px;">Promised</span>
  <input type="date" id="apptPromisedInput" value="{{ $pmVal }}"
    style="background:var(--ia-input-bg);border:0.5px solid var(--ia-border);border-radius:6px;color:var(--ia-text);font-size:12px;padding:5px 8px;font-family:var(--ia-mono,ui-monospace,monospace);">
  <button type="button" id="apptPromisedSave" class="ia-btn ia-btn--secondary ia-btn--sm" style="padding:5px 11px;">Save</button>
</div>
<script>
(function () {
  var input = document.getElementById('apptPromisedInput');
  var btn   = document.getElementById('apptPromisedSave');
  if (!input || !btn) return;
  btn.addEventListener('click', function () {
    var token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    btn.disabled = true;
    fetch("{{ route('tenant.appointments.update', $appointment->id) }}", {
      method: 'PATCH', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
      body: JSON.stringify({ op: 'promised', promised_date: input.value })
    })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      btn.disabled = false;
      if (d && d.ok) {
        if (window.IntakeToast) window.IntakeToast.success(d.promised_local ? ('Promised ' + d.promised_local) : 'Promised date cleared.');
      } else if (window.IntakeToast) {
        window.IntakeToast.error('Could not save promised date.');
      }
    })
    .catch(function () { btn.disabled = false; if (window.IntakeToast) window.IntakeToast.error('Could not save promised date.'); });
  });
})();
</script>
