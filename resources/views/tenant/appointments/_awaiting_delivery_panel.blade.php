{{-- MARKER-DELIVERY-RESOLUTION — triage panel for the Awaiting delivery queue.
     Every row states WHY it is still here and carries the actions that
     resolve it, so jobs leave this list because someone decided something
     rather than because the 14-day window forgot them. --}}
<style>
  .adp{background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-lg);overflow:hidden;margin-bottom:18px}
  .adp-hd{display:flex;align-items:baseline;gap:10px;padding:14px 16px;border-bottom:0.5px solid var(--ia-border);flex-wrap:wrap}
  .adp-hd .t{font-size:14px;font-weight:800;letter-spacing:-.01em}
  .adp-hd .s{font-size:12px;color:var(--ia-text-muted)}
  .adp-row{display:flex;align-items:center;gap:12px;padding:13px 16px;border-bottom:0.5px solid var(--ia-border);flex-wrap:wrap}
  .adp-row:last-child{border-bottom:none}
  .adp-row.gone{opacity:.4}
  .adp-id{font-size:11.5px;color:var(--ia-text-muted);width:78px;flex:none}
  .adp-ident{flex:1;min-width:160px}
  .adp-nm{font-weight:600;font-size:13.5px}
  .adp-meta{font-size:11.5px;color:var(--ia-text-muted);margin-top:2px}
  .adp-why{font-size:10px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;border-radius:100px;padding:3px 9px;white-space:nowrap;flex:none}
  .adp-why.none{background:rgba(240,149,149,.1);color:#F09595;border:0.5px solid rgba(240,149,149,.3)}
  .adp-why.sent{background:rgba(232,163,61,.1);color:#E8A33D;border:0.5px solid rgba(232,163,61,.32)}
  .adp-why.replied{background:rgba(143,184,240,.1);color:#8FB8F0;border:0.5px solid rgba(143,184,240,.32)}
  .adp-age{font-size:11.5px;color:var(--ia-text-muted);white-space:nowrap;flex:none}
  .adp-age.old{color:#F09595;font-weight:700}
  .adp-acts{display:flex;gap:6px;flex-wrap:wrap;flex:none}
  .adp-btn{font-family:inherit;font-size:11.5px;font-weight:700;border-radius:8px;padding:7px 11px;cursor:pointer;border:0.5px solid var(--ia-border);background:transparent;color:var(--ia-text);white-space:nowrap;text-decoration:none;display:inline-block}
  .adp-btn:hover{border-color:var(--ia-accent)}
  .adp-btn.done{color:#7FD98F;border-color:rgba(127,217,143,.4)}
  .adp-btn.muted{color:var(--ia-text-muted)}
  .adp-ok{font-size:11.5px;color:#7FD98F;font-weight:600;white-space:nowrap}
</style>

<div class="adp" id="adp">
  <div class="adp-hd">
    <span class="t">Getting these back to customers</span>
    <span class="s">Resolve each one — scheduling a drop-off, or recording that the customer already has it.</span>
  </div>

  @foreach($appointments as $appt)
    @php
      $why = $deliveryWhy[$appt->id] ?? null;
      $whyKey = $why === null ? 'none' : ($why === 'no_reply' ? 'sent' : ($why === 'sent' ? 'sent' : 'replied'));
      $whyLabel = [
        'none'    => 'no contact yet',
        'sent'    => $why === 'no_reply' ? 'no reply yet' : 'options sent',
        'replied' => 'replied — needs scheduling',
      ][$whyKey];
      $days = $appt->completed_at ? (int) $appt->completed_at->diffInDays(now()) : 0;
    @endphp
    <div class="adp-row" data-ad-row data-id="{{ $appt->id }}">
      <span class="adp-id">{{ $appt->ra_number }}</span>
      <div class="adp-ident">
        <div class="adp-nm">{{ trim(($appt->customer->first_name ?? '') . ' ' . ($appt->customer->last_name ?? '')) ?: 'Customer' }}</div>
        <div class="adp-meta">
          Completed {{ $appt->completed_at ? $appt->completed_at->setTimezone(tenant()->timezone())->format('M j · g:ia') : '—' }}
        </div>
      </div>
      <span class="adp-why {{ $whyKey }}">{{ $whyLabel }}</span>
      <span class="adp-age {{ $days >= 10 ? 'old' : '' }}">{{ $days }}d waiting</span>
      <div class="adp-acts">
        <a class="adp-btn" href="{{ route('tenant.appointments.show', $appt->id) }}">Open</a>
        <button type="button" class="adp-btn done" data-ad-resolve="customer_pickup">Picked up</button>
        <button type="button" class="adp-btn done" data-ad-resolve="not_needed">No delivery needed</button>
        <button type="button" class="adp-btn muted" data-ad-snooze="3">Snooze 3d</button>
      </div>
    </div>
  @endforeach
</div>

<script>
(function () {
  var root = document.getElementById('adp');
  if (!root) return;
  var url  = @json(route('tenant.appointments.update', ['id' => '__ID__']));
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content;

  function patch(row, fields, okText) {
    var id = row.dataset.id;
    var fd = new FormData();
    fd.append('_token', csrf);
    fd.append('_method', 'PATCH');
    Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });

    row.querySelectorAll('button').forEach(function (b) { b.disabled = true; });

    fetch(url.replace('__ID__', id), {
      method: 'POST', body: fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j && j.ok) {
          row.classList.add('gone');
          var acts = row.querySelector('.adp-acts');
          acts.innerHTML = '<span class="adp-ok">\u2713 ' + okText + '</span>' +
                           ' <button type="button" class="adp-btn muted" data-ad-undo>Undo</button>';
          acts.querySelector('[data-ad-undo]').addEventListener('click', function () {
            patchUndo(row);
          });
          if (window.IntakeToast) IntakeToast.success(okText);
        } else {
          row.querySelectorAll('button').forEach(function (b) { b.disabled = false; });
          if (window.IntakeToast) IntakeToast.error((j && j.message) || 'Could not save.');
        }
      })
      .catch(function () {
        row.querySelectorAll('button').forEach(function (b) { b.disabled = false; });
        if (window.IntakeToast) IntakeToast.error('Network error.');
      });
  }

  function patchUndo(row) {
    var fd = new FormData();
    fd.append('_token', csrf);
    fd.append('_method', 'PATCH');
    fd.append('op', 'delivery_resolution_clear');
    fetch(url.replace('__ID__', row.dataset.id), {
      method: 'POST', body: fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    }).then(function () { window.location.reload(); });
  }

  root.addEventListener('click', function (e) {
    var row = e.target.closest('[data-ad-row]');
    if (!row) return;

    var res = e.target.getAttribute('data-ad-resolve');
    if (res) {
      patch(row, { op: 'delivery_resolution', resolution: res },
        res === 'customer_pickup' ? 'Picked up in person' : 'No delivery needed');
      return;
    }

    var snooze = e.target.getAttribute('data-ad-snooze');
    if (snooze) {
      patch(row, { op: 'delivery_snooze', days: snooze }, 'Snoozed ' + snooze + ' days');
    }
  });
})();
</script>
