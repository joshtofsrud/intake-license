{{-- MARKER-PATCH-534 — completion modal, reworked: pick a window and the
     primary button says exactly what goes out (pills toggle text/email);
     no window selected = text the options. No hidden state, no checkbox,
     and no assume-first — no reply just surfaces on the dashboard. --}}
<div id="dp-modal" style="display:none;position:fixed;inset:0;z-index:220;align-items:center;justify-content:center;background:rgba(0,0,0,.55);backdrop-filter:blur(2px)">
  <div style="background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:16px;padding:24px 26px;width:min(480px,calc(100vw - 32px));box-shadow:0 24px 60px rgba(0,0,0,.5)">
    <div style="font-size:17px;font-weight:700;margin-bottom:4px;text-transform:capitalize" id="dp-modal-title">Done &mdash; delivery?</div>{{-- MARKER-PATCH-535 — title uses tenant asset noun via payload --}}
    <div style="font-size:13px;color:var(--ia-text-muted);margin-bottom:16px" id="dp-modal-sub"></div>
    <div id="dp-modal-windows" style="display:flex;flex-direction:column;gap:8px;margin-bottom:4px"></div>
    {{-- MARKER-PATCH-535 — always visible, full modal width --}}
    <div id="dp-modal-notify" style="display:flex;align-items:center;gap:10px;margin:14px 0 2px;width:100%">
      <span style="font-size:12.5px;color:var(--ia-text-muted);flex:none" id="dp-notify-lbl">Notify by:</span>
      <button type="button" class="dp-pill" id="dp-pill-text" data-on="1" style="flex:1;justify-content:center"><span class="dp-tick">&#10003;</span>Text</button>
      <button type="button" class="dp-pill" id="dp-pill-email" data-on="1" style="flex:1;justify-content:center"><span class="dp-tick">&#10003;</span>Email</button>
    </div>
    <div style="font-size:12px;color:var(--ia-text-muted);margin:11px 0 16px;min-height:17px" id="dp-modal-hint"></div>
    <div style="display:flex;align-items:center;gap:12px">
      <button type="button" class="ia-btn ia-btn--ghost" id="dp-modal-skip">Skip</button>
      <button type="button" id="dp-modal-clear" style="margin-left:auto;display:none;background:none;border:0;color:var(--ia-text-muted);font-size:12px;font-family:inherit;cursor:pointer;text-decoration:underline;text-underline-offset:3px">clear selection</button>
      <button type="button" class="ia-btn ia-btn--primary" id="dp-modal-go">Text the options</button>
    </div>
  </div>
</div>
<style>
  /* MARKER-PATCH-534 */
  .dp-pill{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--ia-accent);background:var(--ia-accent-soft,rgba(212,255,63,.08));color:var(--ia-accent);border-radius:99px;padding:4px 12px;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;user-select:none}
  .dp-pill[data-on="0"]{border-color:var(--ia-border);background:none;color:var(--ia-text-muted)}
  .dp-pill[data-on="0"] .dp-tick{visibility:hidden}
  .dp-win{display:flex;align-items:center;gap:10px;border:0.5px solid var(--ia-border);border-radius:11px;padding:11px 13px;font-size:13px;background:none;color:var(--ia-text);font-family:inherit;cursor:pointer;width:100%;text-align:left}
  .dp-win:hover{border-color:var(--ia-text-muted)}
  .dp-win.sel{border-color:var(--ia-accent);background:var(--ia-accent-soft,rgba(212,255,63,.08))}
</style>
<script>
window.IntakeDeliveryPropose = (function () {
  var modal, updateUrl, csrf, selected, firstName;

  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function pillOn(id) { return document.getElementById(id).dataset.on === '1'; }

  function show(payload, opts) {
    modal     = document.getElementById('dp-modal');
    updateUrl = (opts && opts.updateUrl) || window.__dpUpdateUrl;
    csrf      = (opts && opts.csrf) || (document.querySelector('meta[name="csrf-token"]') || {}).content;
    if (!modal || !updateUrl || !payload) return false;

    selected  = null;
    firstName = (payload.customer_name || 'the customer').split(' ')[0];
    // MARKER-PATCH-535 — tenant asset noun, never hardcoded
    var noun = payload.asset_noun || 'work';
    document.getElementById('dp-modal-title').textContent = noun + "'s done \u2014 delivery?";
    document.getElementById('dp-modal-sub').innerHTML =
      'Pick a window for <b style="color:var(--ia-text)">' + esc(payload.customer_name) + '</b> (&hellip;' + esc(payload.phone_tail) + '), or text the options and let them choose.';
    document.getElementById('dp-notify-lbl').textContent = 'Notify ' + firstName + ' by:';

    var list = document.getElementById('dp-modal-windows');
    list.innerHTML = '';
    (payload.windows || []).forEach(function (w, i) {
      var row = document.createElement('button');
      row.type = 'button';
      row.className = 'dp-win';
      row.innerHTML = '<span style="font-weight:700">' + esc(w.day_label) + '</span><span>' + esc(w.label) + '</span>'
        + '<span style="margin-left:auto;font-size:11px;color:var(--ia-text-muted)">' + esc(w.remaining) + ' stop' + (w.remaining === 1 ? '' : 's') + ' left</span>';
      row.onclick = function () {
        if (selected === w) { selected = null; row.classList.remove('sel'); }
        else {
          selected = w;
          Array.prototype.forEach.call(list.children, function (c) { c.classList.remove('sel'); });
          row.classList.add('sel');
        }
        render();
      };
      list.appendChild(row);
    });

    document.getElementById('dp-pill-text').onclick  = function () { this.dataset.on = this.dataset.on === '1' ? '0' : '1'; render(); };
    document.getElementById('dp-pill-email').onclick = function () { this.dataset.on = this.dataset.on === '1' ? '0' : '1'; render(); };
    document.getElementById('dp-pill-text').dataset.on = '1';
    document.getElementById('dp-pill-email').dataset.on = '1';
    document.getElementById('dp-modal-go').disabled = false; // MARKER-PATCH-535
    document.getElementById('dp-modal-skip').onclick  = function () { close(true); };
    document.getElementById('dp-modal-clear').onclick = function () {
      selected = null;
      Array.prototype.forEach.call(list.children, function (c) { c.classList.remove('sel'); });
      render();
    };
    document.getElementById('dp-modal-go').onclick = go;

    render();
    modal.style.display = 'flex';
    return true;
  }

  function render() {
    // MARKER-PATCH-535 — pills always visible; button label carries the consequence
    var goBtn = document.getElementById('dp-modal-go');
    var hint  = document.getElementById('dp-modal-hint');
    var clear = document.getElementById('dp-modal-clear');
    var t = pillOn('dp-pill-text'), e = pillOn('dp-pill-email');
    if (!selected) {
      clear.style.display = 'none';
      goBtn.textContent = 'Text ' + firstName + ' the options';
      goBtn.disabled = !t; // the options link travels by text
      hint.textContent = t
        ? 'They pick from a link; if they don\u2019t reply, the appointment shows on your dashboard as awaiting delivery.'
        : 'The options link goes by text \u2014 turn Text back on, or pick a window to schedule it yourself.';
      return;
    }
    clear.style.display = 'block';
    goBtn.disabled = false;
    goBtn.textContent =
      t && e ? 'Schedule + text & email' :
      t      ? 'Schedule + text' :
      e      ? 'Schedule + email' :
               'Schedule silently';
    hint.textContent = (t || e)
      ? 'Books ' + selected.day_label + ' ' + selected.label + ' and sends the confirmation.'
      : 'Books ' + selected.day_label + ' ' + selected.label + '. ' + firstName + ' gets nothing \u2014 you tell them yourself.';
  }

  function close(reload) {
    if (modal) modal.style.display = 'none';
    if (reload) window.location.reload();
  }

  function go() {
    var btn = document.getElementById('dp-modal-go');
    var original = btn.textContent;
    btn.disabled = true; btn.textContent = 'Working\u2026';
    var fd = new FormData();
    fd.append('_token', csrf);
    fd.append('_method', 'PATCH');
    if (selected) {
      fd.append('op', 'delivery_schedule_direct');
      fd.append('window_id', selected.window_id);
      fd.append('date', selected.date);
      fd.append('notify_sms',   pillOn('dp-pill-text')  ? '1' : '0');
      fd.append('notify_email', pillOn('dp-pill-email') ? '1' : '0');
    } else {
      fd.append('op', 'delivery_proposal_send');
    }
    fetch(updateUrl, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j && j.ok) {
          if (window.IntakeToast) IntakeToast.success(selected ? original.replace('Schedule', 'Scheduled') : 'Options texted');
          close(true);
        } else {
          btn.disabled = false; btn.textContent = original;
          if (window.IntakeToast) IntakeToast.error((j && j.message) || 'Could not complete.');
        }
      })
      .catch(function () {
        btn.disabled = false; btn.textContent = original;
        if (window.IntakeToast) IntakeToast.error('Network error. Try again.');
      });
  }

  return { show: show };
})();
</script>
