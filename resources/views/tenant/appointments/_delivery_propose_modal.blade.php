{{-- MARKER-PATCH-527 — "text delivery windows?" modal, shown after an appointment hits Completed --}}
<div id="dp-modal" style="display:none;position:fixed;inset:0;z-index:220;align-items:center;justify-content:center;background:rgba(0,0,0,.55);backdrop-filter:blur(2px)">
  <div style="background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:14px;padding:22px 24px;width:min(440px,calc(100vw - 32px));box-shadow:0 24px 60px rgba(0,0,0,.5)">
    <div style="font-size:15px;font-weight:600;margin-bottom:4px" id="dp-modal-title">Text delivery windows?</div>
    <div style="font-size:12.5px;color:var(--ia-text-muted);margin-bottom:14px" id="dp-modal-sub"></div>
    <div id="dp-modal-windows" style="display:flex;flex-direction:column;gap:7px;margin-bottom:16px"></div>
    <div style="font-size:11.5px;color:var(--ia-text-muted);margin-bottom:16px" id="dp-modal-note"></div>
    {{-- MARKER-PATCH-533 — nothing goes to the customer without this box --}}
    <label style="display:flex;align-items:center;gap:9px;font-size:12.5px;cursor:pointer;margin-bottom:14px;color:var(--ia-text)">
      <input type="checkbox" id="dp-modal-notify">
      <span>Send the customer a message <span style="color:var(--ia-text-muted)">(confirmation on Schedule, options link on Let customer choose)</span></span>
    </label>
    {{-- MARKER-PATCH-531 — pick a window here, or hand the choice to the customer --}}
    <div style="display:flex;gap:10px;justify-content:flex-end;align-items:center">
      <button type="button" class="ia-btn ia-btn--ghost" id="dp-modal-skip" style="margin-right:auto">Skip</button>
      <button type="button" class="ia-btn ia-btn--secondary" id="dp-modal-send">Let customer choose</button>
      <button type="button" class="ia-btn ia-btn--primary" id="dp-modal-schedule" disabled>Schedule it</button>
    </div>
  </div>
</div>
<script>
window.IntakeDeliveryPropose = (function () {
  var modal, updateUrl, csrf, selected; // MARKER-PATCH-531

  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

  function show(payload, opts) {
    modal     = document.getElementById('dp-modal');
    updateUrl = (opts && opts.updateUrl) || window.__dpUpdateUrl;
    csrf      = (opts && opts.csrf) || (document.querySelector('meta[name="csrf-token"]') || {}).content;
    if (!modal || !updateUrl || !payload) return false;

    document.getElementById('dp-modal-sub').innerHTML =
      'Text <b>' + esc(payload.customer_name) + '</b> (…' + esc(payload.phone_tail) + ') the next open delivery windows — they pick one from the link.';
    var list = document.getElementById('dp-modal-windows');
    list.innerHTML = '';
    selected = null; // MARKER-PATCH-531
    (payload.windows || []).forEach(function (w, i) {
      var row = document.createElement('button');
      row.type = 'button';
      row.style.cssText = 'display:flex;align-items:center;gap:10px;border:0.5px solid var(--ia-border);border-radius:10px;padding:9px 12px;font-size:13px;background:none;color:var(--ia-text);font-family:inherit;cursor:pointer;width:100%;text-align:left';
      row.innerHTML = '<span style="font-weight:600">' + esc(w.day_label) + '</span><span>' + esc(w.label) + '</span>'
        + '<span style="margin-left:auto;font-size:11px;color:var(--ia-text-muted)">' + esc(w.remaining) + ' stop' + (w.remaining === 1 ? '' : 's') + ' left</span>'
        + (i === 0 ? '<span style="font-size:10px;color:var(--ia-accent);border:0.5px solid var(--ia-accent);border-radius:99px;padding:1px 7px">first offered</span>' : '');
      row.onclick = function () {
        selected = w;
        Array.prototype.forEach.call(list.children, function (c) { c.style.borderColor = 'var(--ia-border)'; c.style.background = 'none'; });
        row.style.borderColor = 'var(--ia-accent)';
        row.style.background = 'var(--ia-accent-soft, rgba(212,255,63,.08))';
        document.getElementById('dp-modal-schedule').disabled = false;
      };
      list.appendChild(row);
    });
    document.getElementById('dp-modal-note').textContent =
      'Pick a window and Schedule it — or text the options and no reply by ' + payload.deadline_label + ' locks in the first one.';

    modal.style.display = 'flex';

    // MARKER-PATCH-533 — default unchecked: no accidental customer messages
    var notifyCb = document.getElementById('dp-modal-notify');
    notifyCb.checked = false;
    var sendBtn = document.getElementById('dp-modal-send');
    var toggleSend = function () { sendBtn.disabled = !notifyCb.checked; };
    notifyCb.onchange = toggleSend;
    toggleSend();

    document.getElementById('dp-modal-skip').onclick = function () { close(true); };
    sendBtn.onclick = function () { if (notifyCb.checked) send(); };
    document.getElementById('dp-modal-schedule').onclick = function () { scheduleDirect(); }; // MARKER-PATCH-531
    return true;
  }

  function close(reload) {
    if (modal) modal.style.display = 'none';
    if (reload) window.location.reload();
  }

  function send() {
    var btn = document.getElementById('dp-modal-send');
    btn.disabled = true; btn.textContent = 'Sending…';
    var fd = new FormData();
    fd.append('_token', csrf);
    fd.append('_method', 'PATCH');
    fd.append('op', 'delivery_proposal_send');
    fetch(updateUrl, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j && j.ok) {
          if (window.IntakeToast) IntakeToast.success('Windows texted');
          close(true);
        } else {
          btn.disabled = false; btn.textContent = 'Send text';
          if (window.IntakeToast) IntakeToast.error((j && j.message) || 'Could not send.');
        }
      })
      .catch(function () {
        btn.disabled = false; btn.textContent = 'Send text';
        if (window.IntakeToast) IntakeToast.error('Network error. Try again.');
      });
  }

  // MARKER-PATCH-531 — staff picked a window: schedule immediately
  function scheduleDirect() {
    if (!selected) return;
    var btn = document.getElementById('dp-modal-schedule');
    btn.disabled = true; btn.textContent = 'Scheduling…';
    var fd = new FormData();
    fd.append('_token', csrf);
    fd.append('_method', 'PATCH');
    fd.append('op', 'delivery_schedule_direct');
    fd.append('window_id', selected.window_id);
    fd.append('date', selected.date);
    fd.append('notify', document.getElementById('dp-modal-notify').checked ? '1' : '0'); // MARKER-PATCH-533
    fetch(updateUrl, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j && j.ok) {
          if (window.IntakeToast) IntakeToast.success(document.getElementById('dp-modal-notify').checked ? 'Delivery scheduled — customer notified' : 'Delivery scheduled — customer NOT notified');
          close(true);
        } else {
          btn.disabled = false; btn.textContent = 'Schedule it';
          if (window.IntakeToast) IntakeToast.error((j && j.message) || 'Could not schedule.');
        }
      })
      .catch(function () {
        btn.disabled = false; btn.textContent = 'Schedule it';
        if (window.IntakeToast) IntakeToast.error('Network error. Try again.');
      });
  }

  return { show: show };
})();
</script>
