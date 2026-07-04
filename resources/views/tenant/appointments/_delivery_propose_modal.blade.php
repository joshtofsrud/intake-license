{{-- MARKER-PATCH-527 — "text delivery windows?" modal, shown after an appointment hits Completed --}}
<div id="dp-modal" style="display:none;position:fixed;inset:0;z-index:220;align-items:center;justify-content:center;background:rgba(0,0,0,.55);backdrop-filter:blur(2px)">
  <div style="background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:14px;padding:22px 24px;width:min(440px,calc(100vw - 32px));box-shadow:0 24px 60px rgba(0,0,0,.5)">
    <div style="font-size:15px;font-weight:600;margin-bottom:4px" id="dp-modal-title">Text delivery windows?</div>
    <div style="font-size:12.5px;color:var(--ia-text-muted);margin-bottom:14px" id="dp-modal-sub"></div>
    <div id="dp-modal-windows" style="display:flex;flex-direction:column;gap:7px;margin-bottom:16px"></div>
    <div style="font-size:11.5px;color:var(--ia-text-muted);margin-bottom:16px" id="dp-modal-note"></div>
    <div style="display:flex;gap:10px;justify-content:flex-end">
      <button type="button" class="ia-btn ia-btn--ghost" id="dp-modal-skip">Skip</button>
      <button type="button" class="ia-btn ia-btn--primary" id="dp-modal-send">Send text</button>
    </div>
  </div>
</div>
<script>
window.IntakeDeliveryPropose = (function () {
  var modal, updateUrl, csrf;

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
    (payload.windows || []).forEach(function (w, i) {
      var row = document.createElement('div');
      row.style.cssText = 'display:flex;align-items:center;gap:10px;border:0.5px solid var(--ia-border);border-radius:10px;padding:9px 12px;font-size:13px' + (i === 0 ? ';border-color:var(--ia-accent)' : '');
      row.innerHTML = '<span style="font-weight:600">' + esc(w.day_label) + '</span><span>' + esc(w.label) + '</span>'
        + '<span style="margin-left:auto;font-size:11px;color:var(--ia-text-muted)">' + esc(w.remaining) + ' stop' + (w.remaining === 1 ? '' : 's') + ' left</span>'
        + (i === 0 ? '<span style="font-size:10px;color:var(--ia-accent);border:0.5px solid var(--ia-accent);border-radius:99px;padding:1px 7px">default</span>' : '');
      list.appendChild(row);
    });
    document.getElementById('dp-modal-note').textContent =
      'No reply by ' + payload.deadline_label + ' → the first window locks in automatically.';

    modal.style.display = 'flex';

    document.getElementById('dp-modal-skip').onclick = function () { close(true); };
    document.getElementById('dp-modal-send').onclick = function () { send(); };
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

  return { show: show };
})();
</script>
