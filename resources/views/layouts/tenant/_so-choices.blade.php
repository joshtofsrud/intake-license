{{--
  MARKER-SO-HANDOVER: shared special-order dialogs, on every tenant page.

  1. Choice dialog. When an action would leave a special order without a
     next step, the server answers 409 { needs_choice, choice }. The fetch
     wrapper below shows the question and sends the same request again with
     so_choice[<order id>] = <option> (and so_reason). Closing the dialog
     changes nothing; the caller gets { ok:false, dismissed:true }.
  2. "Tell customer" dialog. IntakeSO.notify([ids]) shows who would be told
     and how, with the wording, and sends or skips. It opens by itself after
     a receipt or a hand arrival that filled orders people are waiting for.
--}}
<style>
.so-dlg .ia-confirm-card{max-width:560px;max-height:calc(100vh - 32px);overflow:auto}
.so-dlg-field{margin-bottom:14px}
.so-dlg-field-h{font-size:13px;font-weight:600;color:var(--ia-text);margin-bottom:6px}
.so-dlg-field-h span{font-weight:400;color:var(--ia-text-dim);font-size:12px;margin-left:6px}
.so-choice{display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);margin-bottom:8px;cursor:pointer}
.so-choice:has(input:checked){border-color:var(--ia-accent)}
.so-choice input{margin-top:3px;accent-color:var(--ia-accent)}
.so-choice b{display:block;font-size:13.5px;font-weight:600;color:var(--ia-text)}
.so-choice span{font-size:12.5px;color:var(--ia-text-muted);line-height:1.45}
.so-nt{width:100%;border-collapse:collapse;font-size:13px;margin-bottom:12px}
.so-nt td{padding:9px 6px;border-bottom:0.5px solid var(--ia-border);vertical-align:top;color:var(--ia-text)}
.so-nt td:first-child{padding-left:0}
.so-nt .so-sub{display:block;font-size:11.5px;color:var(--ia-text-muted)}
.so-nt label{display:inline-flex;gap:5px;align-items:center;margin-right:10px;font-size:12.5px;color:var(--ia-text)}
.so-nt label.dis{opacity:.4}
.so-nt input{accent-color:var(--ia-accent)}
.so-msg{width:100%;min-height:96px;background:var(--ia-input-bg);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);color:var(--ia-text);font:inherit;font-size:13px;padding:10px;box-sizing:border-box;resize:vertical}
.so-fine{font-size:11.5px;color:var(--ia-text-dim);margin:6px 0 14px;line-height:1.5}
</style>
<script>
(function () {
  'use strict';
  if (window.IntakeSO) { return; }

  var CSRF = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var ROUTES = {
    rows:   @json(route('tenant.special-orders.notify-rows')),
    notify: @json(route('tenant.special-orders.notify')),
  };

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text != null) { n.textContent = text; }
    return n;
  }

  // One modal shell, the same look as IntakeConfirm.
  function shell(title, message) {
    var back = el('div', 'ia-confirm-backdrop so-dlg');
    var card = el('div', 'ia-confirm-card');
    card.setAttribute('role', 'dialog');
    card.setAttribute('aria-modal', 'true');
    card.appendChild(el('div', 'ia-confirm-title', title));
    if (message) { card.appendChild(el('div', 'ia-confirm-message', message)); }
    back.appendChild(card);
    document.body.appendChild(back);
    void back.offsetWidth;
    back.classList.add('is-shown');
    return { back: back, card: card };
  }
  function close(s) {
    s.back.classList.remove('is-shown');
    setTimeout(function () { if (s.back.parentNode) { s.back.parentNode.removeChild(s.back); } }, 160);
  }
  function actions(card, cancelText, okText, danger) {
    var row = el('div', 'ia-confirm-actions');
    var no = el('button', 'ia-confirm-btn', cancelText); no.type = 'button';
    var ok = el('button', 'ia-confirm-btn ' + (danger ? 'ia-confirm-btn--danger' : 'ia-confirm-btn--primary'), okText); ok.type = 'button';
    row.appendChild(no); row.appendChild(ok);
    card.appendChild(row);
    return { no: no, ok: ok };
  }

  /** Ask a choice. Resolves { picks: {id: value}, reason } or null when closed. */
  function choose(choice) {
    return new Promise(function (resolve) {
      var s = shell(choice.title || 'Choose what happens', choice.message || '');
      var groups = [];
      (choice.fields || []).forEach(function (f, i) {
        var box = el('div', 'so-dlg-field');
        if (f.label || f.note) {
          var h = el('div', 'so-dlg-field-h', f.label || '');
          if (f.note) { h.appendChild(el('span', null, f.note)); }
          box.appendChild(h);
        }
        (f.options || []).forEach(function (o) {
          var lab = el('label', 'so-choice');
          var r = el('input'); r.type = 'radio'; r.name = 'so_c_' + i; r.value = o.value;
          if (o.value === (f['default'] || (f.options[0] || {}).value)) { r.checked = true; }
          var txt = el('div');
          txt.appendChild(el('b', null, o.label));
          if (o.help) { txt.appendChild(el('span', null, o.help)); }
          lab.appendChild(r); lab.appendChild(txt);
          box.appendChild(lab);
        });
        groups.push({ id: f.id, box: box });
        s.card.appendChild(box);
      });
      var reason = null;
      if (choice.reason) {
        reason = el('input', 'ia-confirm-input');
        reason.type = 'text';
        reason.maxLength = 255;
        reason.placeholder = choice.reason.placeholder || 'Reason';
        reason.style.margin = '0 0 16px';
        s.card.appendChild(reason);
      }
      var b = actions(s.card, 'Go back', choice.confirm || 'Continue', !!choice.danger);
      function done(v) { close(s); document.removeEventListener('keydown', onKey); resolve(v); }
      function onKey(e) { if (e.key === 'Escape') { done(null); } }
      b.no.addEventListener('click', function () { done(null); });
      s.back.addEventListener('click', function (e) { if (e.target === s.back) { done(null); } });
      document.addEventListener('keydown', onKey);
      b.ok.addEventListener('click', function () {
        var picks = {};
        groups.forEach(function (g) {
          var c = g.box.querySelector('input:checked');
          if (c) { picks[g.id] = c.value; }
        });
        // required always, or only when a given option is picked
        var need = reason && (choice.reason.required
          || (choice.reason.when && Object.keys(picks).some(function (k) { return picks[k] === choice.reason.when; })));
        if (need && !reason.value.trim()) {
          reason.style.borderColor = '#F87171';
          reason.focus();
          return;
        }
        done({ picks: picks, reason: reason ? reason.value.trim() : '' });
      });
      setTimeout(function () { b.ok.focus(); }, 50);
    });
  }

  // Same request again, with the answers added. JSON, FormData and
  // urlencoded bodies are handled; anything else can't be repeated.
  function withAnswers(init, ans) {
    var body = init.body;
    var next = Object.assign({}, init);
    if (typeof body === 'string') {
      var t = body.trim();
      if (t.charAt(0) === '{') {
        var o = JSON.parse(t);
        o.so_choice = Object.assign({}, o.so_choice || {}, ans.picks);
        if (ans.reason) { o.so_reason = ans.reason; }
        next.body = JSON.stringify(o);
        return next;
      }
      var p = new URLSearchParams(t);
      Object.keys(ans.picks).forEach(function (k) { p.set('so_choice[' + k + ']', ans.picks[k]); });
      if (ans.reason) { p.set('so_reason', ans.reason); }
      next.body = p.toString();
      return next;
    }
    if (body instanceof FormData) {
      var fd = new FormData();
      body.forEach(function (v, k) { fd.append(k, v); });
      Object.keys(ans.picks).forEach(function (k) { fd.set('so_choice[' + k + ']', ans.picks[k]); });
      if (ans.reason) { fd.set('so_reason', ans.reason); }
      next.body = fd;
      return next;
    }
    if (body instanceof URLSearchParams) {
      var q = new URLSearchParams(body.toString());
      Object.keys(ans.picks).forEach(function (k) { q.set('so_choice[' + k + ']', ans.picks[k]); });
      if (ans.reason) { q.set('so_reason', ans.reason); }
      next.body = q;
      return next;
    }
    if (body == null) {
      next.method = next.method || 'POST';
      next.headers = Object.assign({ 'Content-Type': 'application/json' }, next.headers || {});
      next.body = JSON.stringify({ so_choice: ans.picks, so_reason: ans.reason || undefined });
      return next;
    }
    return null;
  }

  function jsonResponse(obj, status) {
    return new Response(JSON.stringify(obj), { status: status, headers: { 'Content-Type': 'application/json' } });
  }

  var raw = window.fetch.bind(window);
  window.fetch = function (input, init) {
    return raw(input, init).then(function (res) {
      if (res.status !== 409) { return res; }
      var ct = res.headers.get('content-type') || '';
      if (ct.indexOf('json') === -1) { return res; }
      return res.clone().json().then(function (d) {
        if (!d || !d.needs_choice || !d.choice) { return res; }
        if (typeof input !== 'string' && !(input instanceof URL)) { return res; } // a Request object can't be rebuilt
        return choose(d.choice).then(function (ans) {
          if (!ans) { return jsonResponse({ ok: false, dismissed: true, error: 'Nothing was changed.', message: 'Nothing was changed.' }, 409); }
          var again = withAnswers(init || {}, ans);
          if (!again) { return res; }
          return window.fetch(input, again); // may ask again (several orders)
        });
      }, function () { return res; });
    });
  };

  // ── "Tell customer" ──────────────────────────────────────
  function notify(ids, opts) {
    opts = opts || {};
    ids = (ids || []).filter(Boolean);
    if (!ids.length) { return Promise.resolve(null); }
    return raw(ROUTES.rows, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify({ ids: ids, kind: opts.kind || 'arrived' }),
    }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d || !d.ok || !d.rows || !d.rows.length) { return null; }
      return show(d.rows, d.message, opts);
    }).catch(function () {
      if (window.IntakeToast) { IntakeToast.error('Couldn\'t load who to tell.'); }
      return null;
    });
  }

  function show(rows, message, opts) {
    return new Promise(function (resolve) {
      var n = rows.length;
      var s = shell(opts.title || (n === 1 ? 'Tell ' + (rows[0].who || 'the customer') + ' it\'s here?' : 'Tell ' + n + ' people their part is here?'),
        opts.message || (n === 1 ? (rows[0].so_number + ' · ' + rows[0].item) : 'These orders just arrived. Untick anyone you\'d rather call yourself.'));
      var table = el('table', 'so-nt');
      var tb = el('tbody');
      rows.forEach(function (row) {
        var tr = el('tr');
        var who = el('td');
        who.appendChild(document.createTextNode(row.who || ''));
        who.appendChild(el('span', 'so-sub', row.so_number + ' · ' + row.item + (row.note ? ' · ' + row.note : '')));
        if (row.told) { who.appendChild(el('span', 'so-sub', 'Already told: ' + row.told)); }
        var ch = el('td');
        (row.channels || []).forEach(function (c) {
          var lab = el('label', c.available ? null : 'dis');
          var cb = el('input'); cb.type = 'checkbox';
          cb.dataset.so = row.id; cb.dataset.ch = c.key;
          cb.checked = !!c.on && !row.told; cb.disabled = !c.available;
          lab.appendChild(cb);
          lab.appendChild(document.createTextNode(c.label));
          if (c.why) { lab.title = c.why; }
          ch.appendChild(lab);
          if (!c.available && c.why) { ch.appendChild(el('span', 'so-sub', c.label + ': ' + c.why)); }
        });
        tr.appendChild(who); tr.appendChild(ch);
        tb.appendChild(tr);
      });
      table.appendChild(tb);
      s.card.appendChild(table);
      var ta = el('textarea', 'so-msg');
      ta.value = message || '';
      ta.maxLength = 1000;
      s.card.appendChild(ta);
      s.card.appendChild(el('div', 'so-fine',
        (opts.kind === 'new_date'
          ? '{{ '{' }}{first_name}}, {{ '{' }}{item}} and {{ '{' }}{expected}} fill in per person. Texts end with "Reply STOP to opt out." Email and text can be switched off for everyone in Communication Center → Special order date changed.'
          : '{{ '{' }}{first_name}}, {{ '{' }}{item}} and {{ '{' }}{location}} fill in per person. Texts end with "Reply STOP to opt out." Channels that are greyed out aren\'t available for that person; email and text can be switched off for everyone in Communication Center → Special order arrived.')));
      var b = actions(s.card, 'Skip', 'Send', false);
      function done(v) { close(s); resolve(v); }
      b.no.addEventListener('click', function () { done(null); });
      b.ok.addEventListener('click', function () {
        var picks = {};
        s.card.querySelectorAll('input[type=checkbox][data-so]').forEach(function (cb) {
          picks[cb.dataset.so] = picks[cb.dataset.so] || {};
          picks[cb.dataset.so][cb.dataset.ch] = cb.checked && !cb.disabled;
        });
        b.ok.disabled = true;
        raw(ROUTES.notify, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': CSRF },
          body: JSON.stringify({ ids: rows.map(function (r) { return r.id; }), picks: picks, message: ta.value, kind: opts.kind || 'arrived' }),
        }).then(function (r) { return r.json(); }).then(function (d) {
          if (window.IntakeToast && d && d.message) { IntakeToast[(d.counts && (d.counts.email + d.counts.sms + d.counts.staff) > 0) ? 'success' : 'error'](d.message); }
          done(d);
          if (opts.reload) { location.reload(); }
        }).catch(function () {
          b.ok.disabled = false;
          if (window.IntakeToast) { IntakeToast.error('Couldn\'t send. Nothing was sent.'); }
        });
      });
    });
  }

  /**
   * Post to a special-order action and report the outcome. The choice
   * dialog, if needed, happens inside fetch. Resolves the JSON.
   */
  function act(url, body) {
    return window.fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify(body || {}),
    }).then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Something went wrong.' }; }); })
      .then(function (d) {
        if (d && d.ok) {
          if (window.IntakeToast && d.message) { IntakeToast.success(d.message); }
        } else if (d && !d.dismissed) {
          IntakeConfirm.alert({ title: 'Couldn\'t do that', message: (d && (d.error || d.message)) || 'Something went wrong.' });
        }
        return d || { ok: false };
      });
  }

  window.IntakeSO = { choose: choose, notify: notify, act: act };

  @php $soNotify = array_values(array_filter((array) session('so_notify', []))); @endphp
  @if (! empty($soNotify))
  document.addEventListener('DOMContentLoaded', function () { notify(@json($soNotify), { reload: true }); });
  @endif
})();
</script>
