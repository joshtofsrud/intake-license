/*
 * MARKER-LIVE-VIEW: Intake support asking to see this tab.
 *
 * Every signed-in tab checks for a request every few seconds. The answer is
 * an in-app Allow / Decline. Once allowed, this tab records its own page
 * with rrweb and posts what changed about every quarter second. A bar at the
 * top says it's being viewed and has Stop. Moving to another page in the same
 * tab carries on (sessionStorage); closing the tab ends it.
 *
 * Masked before anything leaves the browser: passwords and PINs, card fields,
 * and anything inside [data-lv-mask]. Nothing inside [data-lv-self] (the bar)
 * is recorded.
 */
(function () {
  'use strict';
  var me = document.currentScript;
  if (!me || window.__intakeLiveView) return;
  window.__intakeLiveView = true;

  var cfg = {
    pending: me.dataset.pending,
    base: me.dataset.base,
    recorder: me.dataset.recorder,
    poll: (parseInt(me.dataset.poll, 10) || 15) * 1000,
    minutes: parseInt(me.dataset.minutes, 10) || 30
  };
  var KEY = 'intake.liveView';
  var csrf = (document.querySelector('meta[name=csrf-token]') || {}).content || '';

  var sid = null, stopRec = null, buf = [], sending = false, lastSent = 0, loop = null, bar = null, pollTimer = null, asking = false;

  function store(v) { try { v ? sessionStorage.setItem(KEY, v) : sessionStorage.removeItem(KEY); } catch (e) {} }
  function stored() { try { return sessionStorage.getItem(KEY); } catch (e) { return null; } }

  function post(path, body, extra) {
    var h = { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-Intake-Background': '1' };
    for (var k in (extra || {})) h[k] = extra[k];
    return fetch(cfg.base + '/' + encodeURIComponent(sid) + '/' + path, { method: 'POST', headers: h, body: body, credentials: 'same-origin' });
  }

  // ── waiting for a request ───────────────────────────────────────
  function poll() {
    clearTimeout(pollTimer);
    pollTimer = setTimeout(poll, cfg.poll);
    if (sid || asking || document.visibilityState === 'hidden') return;
    fetch(cfg.pending, { headers: { 'Accept': 'application/json', 'X-Intake-Background': '1' }, credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) { if (j && j.ask && !sid && !asking) ask(j.ask); })
      .catch(function () {});
  }

  function ask(req) {
    asking = true;
    var msg = (req.by || 'Intake support') + ' from Intake support would like to see this tab, to help with what you’re looking at. ' +
      'They can only watch: they can’t click or type. Passwords, PINs and card numbers stay hidden. ' +
      'You’ll see a bar at the top while it’s on; press Stop there any time. It ends when you close this tab, or after ' + cfg.minutes + ' minutes.';
    var show = window.IntakeConfirm && window.IntakeConfirm.show
      ? window.IntakeConfirm.show({ title: 'Intake support would like to see your screen', message: msg, confirmText: 'Allow', cancelText: 'Decline' })
      : Promise.resolve(false);
    show.then(function (allow) {
      sid = req.id;
      return post('answer', JSON.stringify({ allow: !!allow }), { 'Content-Type': 'application/json' })
        .then(function (r) {
          if (allow && r.ok) { store(req.id); start(); }
          else sid = null;
        });
    }).catch(function () { sid = null; }).then(function () { asking = false; });
  }

  // ── being viewed ────────────────────────────────────────────────
  function sensitive(el) {
    if (!el || !el.matches) return false;
    var t = (el.getAttribute('type') || '').toLowerCase();
    if (t === 'password') return true;
    return el.matches('[autocomplete^="cc-"], [name*="card" i], [name*="cvc" i], [name*="cvv" i], [name="pin"], [name^="pin_"], [name$="_pin"], [data-lv-mask], [data-lv-mask] *');
  }

  function loadRecorder() {
    if (window.rrwebRecord) return Promise.resolve();
    return new Promise(function (ok, no) {
      var s = document.createElement('script');
      s.src = cfg.recorder; s.onload = ok; s.onerror = no;
      document.head.appendChild(s);
    });
  }

  function start() {
    sid = stored();
    if (!sid) return;
    showBar();
    loadRecorder().then(function () {
      if (!sid) return;
      stopRec = window.rrwebRecord.record({
        emit: function (e) { buf.push(e); },
        blockSelector: '[data-lv-self]',
        maskTextSelector: '[data-lv-mask]',
        maskAllInputs: true,
        maskInputFn: function (text, el) { return sensitive(el) ? '*'.repeat(text.length) : text; },
        sampling: { mousemove: 50, scroll: 150, media: 800 }, // every keystroke, so the field being typed in shows as it's typed
        checkoutEveryNms: 5 * 60 * 1000,
        recordCanvas: false,
        collectFonts: false,
        inlineImages: false
      });
      loop = setInterval(flush, 250);
      flush();
    }).catch(function () { finish(); });
  }

  function flush() {
    if (!sid || sending) return;
    var now = Date.now();
    if (!buf.length && now - lastSent < 2000) return;
    var events = buf; buf = [];
    sending = true; lastSent = now;
    var json = JSON.stringify(events);
    pack(json).then(function (p) {
      return post('events', p.body, { 'Content-Type': p.gz ? 'application/octet-stream' : 'application/json', 'X-LV-Gzip': p.gz ? '1' : '0' });
    }).then(function (r) {
      if (r.status === 404 || r.status === 403 || r.status === 401) return { end: true };
      if (r.status === 423) { buf = events.concat(buf); return {}; }
      return r.json();
    }).then(function (j) {
      if (j && j.end) return finish();
      if (j && j.want && window.rrwebRecord) window.rrwebRecord.record.takeFullSnapshot(true);
    }).catch(function () {
      buf = events.concat(buf); // try again on the next tick
    }).then(function () { sending = false; });
  }

  // gzip the bigger posts where the browser can
  function pack(json) {
    if (json.length < 4096 || typeof CompressionStream === 'undefined') return Promise.resolve({ body: json, gz: false });
    try {
      var s = new Blob([json]).stream().pipeThrough(new CompressionStream('gzip'));
      return new Response(s).blob().then(function (b) { return { body: b, gz: true }; });
    } catch (e) { return Promise.resolve({ body: json, gz: false }); }
  }

  function showBar() {
    if (bar) return;
    bar = document.createElement('div');
    bar.className = 'lv-bar';
    bar.setAttribute('data-lv-self', '');
    bar.setAttribute('role', 'status');
    bar.innerHTML = '<span class="lv-bar-dot"></span><span>Intake support is viewing<span class="lv-bar-more"> this tab</span></span><button type="button">Stop</button>';
    bar.querySelector('button').addEventListener('click', function () {
      if (sid) post('end', '', {}).catch(function () {});
      finish();
    });
    document.body.appendChild(bar);
  }

  function finish() {
    if (loop) clearInterval(loop);
    loop = null;
    if (stopRec) { try { stopRec(); } catch (e) {} }
    stopRec = null; buf = []; sid = null;
    store(null);
    if (bar && bar.parentNode) bar.parentNode.removeChild(bar);
    bar = null;
  }

  function boot() {
    if (stored()) start();
    pollTimer = setTimeout(poll, 3000);
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible' && !sid) poll(); });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
