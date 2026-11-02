/* Reports tabs that run on the report engine: Explain, Open in builder, the upgrade note and the compare/date controls.
   MARKER-REPORT-TABS-ENGINE. Nothing here changes shop data. */
(function () {
  var app = document.getElementById('rx-app');
  if (!app) return;
  var LOCK = app.getAttribute('data-locked') === '1';
  var EXPLAIN_URL = app.getAttribute('data-explain');
  var UPGRADE_URL = app.getAttribute('data-upgrade');
  var CSRF = (document.querySelector('meta[name=csrf-token]') || {}).content || '';
  var OPEN = {};
  try { OPEN = JSON.parse(document.getElementById('rx-open-data').textContent || '{}'); } catch (e) { OPEN = {}; }
  var EX = {};

  function $(s, r) { return (r || document).querySelector(s); }
  function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function toast(t) { var e = $('#rx-toast'); e.textContent = t; e.classList.add('on'); clearTimeout(toast._t); toast._t = setTimeout(function () { e.classList.remove('on'); }, 2800); }

  /* ---- modals ---- */
  function showModal(id) { $('#rx-scr').classList.add('on'); $(id).classList.add('on'); }
  function closeModals() {
    $('#rx-scr').classList.remove('on');
    ['#rx-open', '#rx-up'].forEach(function (m) { $(m).classList.remove('on'); });
  }
  function upsell(what) {
    $('#rx-up-b').textContent = 'Upgrade to use ' + what + '. Extended reports unlock the lists and per-person detail on every Reports tab, Explain, and the Custom report builder with emailed reports and alerts.';
    showModal('#rx-up');
  }
  function openBuilder(id) {
    var o = OPEN[id]; if (!o) return;
    $('#rx-open-t').textContent = o.title + ' in the builder';
    $('#rx-open-b').innerHTML = '<p style="margin:0 0 10px">These settings rebuild this panel in Custom, where you can change anything, split it another way, save it, email it or pin it.</p>' +
      o.rows.map(function (r) { return '<div class="rb-kv"><span>' + esc(r[0]) + '</span><span style="text-align:right">' + esc(r[1]) + '</span></div>'; }).join('');
    var go = $('#rx-open-go');
    if (LOCK) { go.textContent = 'Upgrade to open it →'; go.setAttribute('href', UPGRADE_URL); }
    else { go.textContent = 'Open in builder →'; go.setAttribute('href', o.url); }
    showModal('#rx-open');
  }

  /* ---- Explain ---- */
  function renderExplain(id) {
    var box = $('#rx-ex-' + id); if (!box) return;
    var x = EX[id];
    if (!x) { box.innerHTML = ''; return; }
    if (x.busy) { box.innerHTML = '<div class="rb-note"><div><b>In plain words.</b> <span class="rb-dim">Reading the numbers…</span></div></div>'; return; }
    if (x.err) { box.innerHTML = '<div class="rb-note"><div>That couldn\'t be explained: ' + esc(x.err) + '</div><button class="ia-btn ia-btn--sm" type="button" data-rx-again="' + esc(id) + '" style="margin-left:auto">Try again</button></div>'; return; }
    box.innerHTML = '<div class="rb-note"><div><b>In plain words.</b> ' + esc(x.text) +
      '<div class="rb-hint" style="margin-top:6px">' + (x.by === 'claude' ? 'Written by Claude from the numbers in this panel only.' : esc(x.note || 'Built-in summary from the numbers in this panel.')) + '</div></div>' +
      '<span style="margin-left:auto;display:flex;gap:6px;flex-shrink:0"><button class="ia-btn ia-btn--sm" type="button" data-rx-copy="' + esc(id) + '">Copy</button><button class="rb-x" type="button" data-rx-hide="' + esc(id) + '" aria-label="Hide explanation">×</button></span></div>';
  }
  function runExplain(btn) {
    var id = btn.getAttribute('data-rx-explain'), cfg;
    try { cfg = JSON.parse(btn.getAttribute('data-cfg')); } catch (e) { toast('That panel can\'t be explained.'); return; }
    EX[id] = { busy: true }; renderExplain(id);
    fetch(EXPLAIN_URL, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ cfg: cfg, title: btn.getAttribute('data-title') || '' }) })
      .then(function (r) { return r.json().then(function (j) { if (!r.ok) throw (j && j.message) || 'Something went wrong.'; return j; }); })
      .then(function (j) { EX[id] = { text: j.text, by: j.by, note: j.note }; renderExplain(id); })
      .catch(function (e) { EX[id] = { err: String(e) }; renderExplain(id); });
  }

  document.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target.closest('button, a, [data-up]') : null;
    if (!t) { if (e.target.id === 'rx-scr') closeModals(); return; }
    if (t.id === 'rx-scr') { closeModals(); return; }
    if (t.hasAttribute('data-rx-close')) { closeModals(); return; }
    if (t.hasAttribute('data-up')) { upsell(t.getAttribute('data-up')); return; }
    if (t.hasAttribute('data-rx-open')) { openBuilder(t.getAttribute('data-rx-open')); return; }
    if (t.hasAttribute('data-rx-explain')) {
      var id = t.getAttribute('data-rx-explain');
      if (LOCK) { upsell('Explain'); return; }
      if (EX[id] && !EX[id].busy) { EX[id] = null; renderExplain(id); return; }
      if (!EX[id]) runExplain(t);
      return;
    }
    if (t.hasAttribute('data-rx-again')) { var b = $('[data-rx-explain="' + t.getAttribute('data-rx-again') + '"]'); if (b) runExplain(b); return; }
    if (t.hasAttribute('data-rx-hide')) { var h = t.getAttribute('data-rx-hide'); EX[h] = null; renderExplain(h); return; }
    if (t.hasAttribute('data-rx-copy')) {
      var x = EX[t.getAttribute('data-rx-copy')];
      if (x && x.text && navigator.clipboard) navigator.clipboard.writeText(x.text).then(function () { toast('Copied'); }, function () { toast('Couldn\'t copy — select the text instead.'); });
      else toast('Couldn\'t copy — select the text instead.');
      return;
    }
    if (t.id === 'rx-custom-btn') {
      var f = $('#rx-custom'), on = f.style.display === 'none';
      f.style.display = on ? '' : 'none'; t.setAttribute('aria-expanded', on ? 'true' : 'false');
      if (on) { var fi = $('#rx-from'); if (fi) fi.focus(); }
    }
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeModals(); });

  /* ---- compare ---- */
  var cmp = $('#rx-cmp');
  if (cmp) cmp.addEventListener('change', function () {
    var q = ['range=' + encodeURIComponent(cmp.getAttribute('data-range')), 'compare=' + encodeURIComponent(cmp.value)];
    if (cmp.getAttribute('data-range') === 'custom') { q.push('from=' + encodeURIComponent(cmp.getAttribute('data-from'))); q.push('to=' + encodeURIComponent(cmp.getAttribute('data-to'))); }
    location.href = cmp.getAttribute('data-base') + '?' + q.join('&');
  });
})();
