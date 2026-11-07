/* MARKER-DASH-STUDIO — the Studio dashboard's behaviour.
 *
 * Motion runs once on load: blocks fade up as they reach the view, tile
 * colours spread and charts draw, numbers count up, queue rows below the fold
 * come in soft and sharpen, and the header frosts once the page scrolls.
 * Nothing moves for people whose device asks for less motion.
 *
 * The queue filters, the picked job's details, the multi-asset list, and a
 * quiet refresh every few minutes while the tab is in view (no motion, keeps
 * the picked job and filter).
 */
(function () {
  'use strict';
  var root = document.getElementById('sd');
  if (!root) return;
  var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (!reduce) document.documentElement.classList.add('sd-motion');

  function $(s, el) { return (el || document).querySelector(s); }
  function $$(s, el) { return Array.prototype.slice.call((el || document).querySelectorAll(s)); }
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function money(c) { return '$' + (c / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

  // ---------------------------------------------------------------- motion
  var pending = [];
  function markBelow() {
    if (reduce) return;
    $$('.sd-row').forEach(function (r) {
      if (r._seen) return;
      if (r.getBoundingClientRect().top > innerHeight * 0.92) { r.classList.add('sd-blur'); pending.push(r); } else { r._seen = 1; }
    });
  }
  function check() {
    var line = innerHeight * 0.85;
    $$('.sd-in:not(.is-in)').forEach(function (el) {
      if (el.getBoundingClientRect().top < line) {
        el.classList.add('is-in');
        $$('.sd-tile', el).forEach(function (t) { t.classList.add('is-in'); });
        if (el.classList.contains('sd-tile')) count(el);
      }
    });
    var lst = $('#sdList'), lb = lst ? lst.getBoundingClientRect().bottom : Infinity;
    pending = pending.filter(function (r) {
      // in view of the window AND of the list (the list scrolls on its own on desktop)
      if (r.getBoundingClientRect().top < Math.min(innerHeight * 0.92, lb - 8)) { r.classList.remove('sd-blur'); r._seen = 1; return false; }
      return true;
    });
    endCap();
    var head = $('#sdHead');
    if (head) head.classList.toggle('frost', window.scrollY > 24 && innerWidth > 1023);
  }
  function count(tile) {
    $$('[data-count]', tile).forEach(function (el) {
      if (el._counted) return; el._counted = 1;
      var to = +el.dataset.count || 0, pre = el.dataset.pre || '', t0 = null, dur = reduce ? 0 : 900;
      function step(ts) {
        if (!t0) t0 = ts;
        var p = dur ? Math.min(1, (ts - t0) / dur) : 1;
        el.textContent = pre + Math.round(to * (1 - Math.pow(1 - p, 3))).toLocaleString('en-US');
        if (p < 1) requestAnimationFrame(step);
      }
      if (dur) { el.textContent = pre + '0'; requestAnimationFrame(step); }
    });
  }
  // tiles are drawn inside .sd-tiles; each tile appears on its own delay
  $$('.sd-tile').forEach(function (t) { t.classList.add('sd-in'); });
  var raf = 0;
  addEventListener('scroll', function () { if (!raf) raf = requestAnimationFrame(function () { raf = 0; check(); hidePop(); }); }, { passive: true });
  addEventListener('resize', check);

  // ------------------------------------------------------------ queue + detail
  var data = [], filter = 'all', sel = 0;
  function readData() {
    var el = $('#sdQueue');
    try { data = el ? JSON.parse(el.textContent) : []; } catch (e) { data = []; }
  }
  function detail(i) {
    var box = $('#sdDetail'), r = data[i];
    if (!box) return;
    if (!r) { box.hidden = true; return; }
    box.hidden = false;
    box.classList.add('swap');
    setTimeout(function () {
      if (r.actions) { box.innerHTML = generic(r); box.classList.remove('swap'); return; }
      var assetP = box.dataset.assetP || 'items';
      var h = '<h3>' + esc(r.who) + ' · ' + esc(r.title) + '<span>' + esc(r.number) + '</span></h3>';
      h += '<div class="sd-tags"><span>' + esc(r.late ? 'Late' : r.groupLabel) + '</span><span>In the shop ' + esc(r.age) + '</span>' +
        (r.due > 0 ? '<span>' + money(r.due) + ' due</span>' : (r.total > 0 ? '<span>Paid</span>' : '')) + '</div><dl class="sd-kv">';
      if (r.assets && r.assets.length > 1) {
        h += '<dt>' + esc(assetP.charAt(0).toUpperCase() + assetP.slice(1)) + '</dt><dd><ul class="sd-ul">' + r.assets.map(function (a) {
          return '<li>' + esc(a.name) + '<span>' + esc(a.services) + (a.when ? ' · ' + esc(a.when) : '') + '</span></li>';
        }).join('') + '</ul></dd>';
      }
      if (r.notes) h += '<dt>Notes</dt><dd>' + esc(r.notes) + '</dd>';
      if (r.total > 0) h += '<dt>Total</dt><dd>' + money(r.total) + (r.due > 0 && r.due < r.total ? ' · ' + money(r.due) + ' still due' : '') + '</dd>';
      if (r.phone) h += '<dt>Contact</dt><dd>' + esc(r.phone) + '</dd>';
      h += '</dl><div class="sd-act"><a class="ia-btn ia-btn--sm ia-btn--primary" href="' + esc(r.url) + '">Open work order</a>';
      if (r.phone) h += '<a class="ia-btn ia-btn--sm" href="sms:' + esc(String(r.phone).replace(/[^\d+]/g, '')) + '">Text ' + esc(r.who.split(' ')[0]) + '</a>';
      if (r.customerUrl) h += '<a class="ia-btn ia-btn--sm" href="' + esc(r.customerUrl) + '">Customer</a>';
      if (r.group === 'ready' && box.dataset.canRegister === '1') h += '<a class="ia-btn ia-btn--sm" href="' + esc(box.dataset.registerUrl) + '">Ring up</a>';
      h += '</div>';
      box.innerHTML = h;
      box.classList.remove('swap');
    }, 110);
  }
  // a page that sends its own detail (tags, label/value pairs, actions) — the rental desk
  function generic(r) {
    var h = '<h3>' + esc(r.who) + (r.title ? ' · ' + esc(r.title) : '') + '<span>' + esc(r.number) + '</span></h3>';
    if (r.tags && r.tags.length) h += '<div class="sd-tags">' + r.tags.map(function (t) { return '<span>' + esc(t) + '</span>'; }).join('') + '</div>';
    h += '<dl class="sd-kv">';
    if (r.units && r.units.length) h += '<dt>' + (r.units.length > 1 ? 'Units' : 'Unit') + '</dt><dd><ul class="sd-ul">' + r.units.map(function (u) { return '<li>' + esc(u.name) + '<span>' + esc(u.note || '') + '</span></li>'; }).join('') + '</ul></dd>';
    (r.kv || []).forEach(function (p) { h += '<dt>' + esc(p[0]) + '</dt><dd>' + esc(p[1]) + '</dd>'; });
    h += '</dl><div class="sd-act">' + (r.actions || []).map(function (a) { return '<a class="ia-btn ia-btn--sm' + (a.primary ? ' ia-btn--primary' : '') + '" href="' + esc(a.url) + '">' + esc(a.label) + '</a>'; }).join('') + '</div>';
    return h;
  }
  function applyFilter() {
    var list = $('#sdList');
    if (!list) return;
    var shown = 0, firstShown = null;
    $$('.sd-row', list).forEach(function (row) {
      var on = filter === 'all' || (' ' + row.dataset.group + ' ').indexOf(' ' + filter + ' ') > -1;
      row.hidden = !on;
      if (on) { shown++; if (firstShown === null) firstShown = +row.dataset.i; }
    });
    var none = $('.sd-none', list); if (none) none.hidden = shown > 0;
    var end = $('.sd-end', list);
    if (end) {
      var b = $('.sd-seg button.on');
      var name = b ? b.textContent.replace(/\s*\d+\s*$/, '') : 'All';
      end.hidden = shown === 0;
      end.classList.remove('in');
      var word = end.dataset.word || 'jobs';
      end.querySelector('span').textContent = (filter === 'all' ? 'End of the list' : 'End of ' + name) + ' · ' + shown + ' ' + (shown === 1 ? word.replace(/s$/, '') : word);
      requestAnimationFrame(endCap);
    }
    var selRow = $('.sd-row[data-i="' + sel + '"]', list);
    if (!selRow || selRow.hidden) { if (firstShown !== null) pick(firstShown); else detail(-1); }
  }
  // "End of …" draws itself in once it comes into view, putting a full stop on the list
  function endCap() {
    var end = $('.sd-end'), lst = $('#sdList');
    if (!end || end.hidden || end.classList.contains('in')) return;
    var r = end.getBoundingClientRect(), lb = lst ? lst.getBoundingClientRect().bottom : innerHeight;
    if (r.top < Math.min(innerHeight, lb) - 4 && r.bottom > 0) end.classList.add('in');
  }
  function pick(i) {
    sel = i;
    $$('.sd-row').forEach(function (r) { r.classList.toggle('sel', +r.dataset.i === i); });
    detail(i);
  }
  root.addEventListener('click', function (e) {
    var m = e.target.closest('.sd-multi');
    if (m) { e.preventDefault(); e.stopPropagation(); popFor === m ? hidePop() : showPop(m); return; }
    var b = e.target.closest('.sd-seg button');
    if (b) {
      filter = b.dataset.f;
      $$('.sd-seg button').forEach(function (x) { x.classList.toggle('on', x === b); });
      applyFilter();
      return;
    }
    var row = e.target.closest('.sd-row');
    if (row) pick(+row.dataset.i);
  });
  root.addEventListener('keydown', function (e) {
    var row = e.target.closest && e.target.closest('.sd-row');
    if (row && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); pick(+row.dataset.i); }
  });

  // ------------------------------------------------------- multi-asset list
  var pop = document.getElementById('sdPop'), popFor = null;
  function showPop(btn) {
    var list;
    try { list = JSON.parse(btn.dataset.assets || '[]'); } catch (e) { list = []; }
    if (!pop || !list.length) return;
    pop.innerHTML = '<h4>' + list.length + ' on this booking</h4>' + list.map(function (a) {
      return '<div><b>' + esc(a.name) + (a.when ? '<span>' + esc(a.when) + '</span>' : '') + '</b><small>' + esc(a.services) + '</small></div>';
    }).join('');
    pop.hidden = false;
    var r = btn.getBoundingClientRect(), w = pop.offsetWidth, hgt = pop.offsetHeight;
    pop.style.left = Math.max(8, Math.min(r.left, innerWidth - w - 8)) + 'px';
    pop.style.top = (r.bottom + 8 + hgt > innerHeight ? Math.max(8, r.top - hgt - 8) : r.bottom + 8) + 'px';
    popFor = btn;
  }
  function hidePop() { if (pop) pop.hidden = true; popFor = null; }
  root.addEventListener('mouseover', function (e) { var m = e.target.closest('.sd-multi'); if (m && matchMedia('(hover: hover)').matches) showPop(m); });
  root.addEventListener('mouseout', function (e) { if (e.target.closest('.sd-multi') && matchMedia('(hover: hover)').matches) hidePop(); });
  root.addEventListener('focusin', function (e) { var m = e.target.closest('.sd-multi'); if (m) showPop(m); });
  root.addEventListener('focusout', function (e) { if (e.target.closest('.sd-multi')) hidePop(); });
  document.addEventListener('click', function (e) { if (popFor && !e.target.closest('.sd-multi')) hidePop(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') hidePop(); });

  // ---------------------------------------------------------- quiet refresh
  // Every 3 minutes while the tab is in view, fetch the page again and swap
  // the live parts in place. No motion; the filter and picked job stay.
  var REFRESH = 180000, last = Date.now(), busy = false;
  function refresh() {
    if (busy || document.hidden || Date.now() - last < REFRESH) return;
    busy = true;
    fetch(location.href, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { if (!r.ok) throw r.status; return r.text(); })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        $$('[data-sd-live]').forEach(function (el) {
          var fresh = doc.querySelector('[data-sd-live="' + el.dataset.sdLive + '"]');
          if (!fresh) return;
          $$('.sd-in', fresh).concat(fresh.classList.contains('sd-in') ? [fresh] : []).forEach(function (x) { x.classList.add('is-in'); });
          $$('.sd-tile', fresh).forEach(function (x) { x.classList.add('sd-in', 'is-in'); });
          $$('[data-count]', fresh).forEach(function (x) { x._counted = 1; });
          el.replaceWith(fresh);
        });
        $$('.sd-row').forEach(function (r) { r._seen = 1; });
        readData();
        var keep = filter;
        $$('.sd-seg button').forEach(function (x) { x.classList.toggle('on', x.dataset.f === keep); });
        if (!data[sel]) sel = 0;
        applyFilter();
        $$('.sd-row').forEach(function (r) { r.classList.toggle('sel', +r.dataset.i === sel); });
        detail(sel);
      })
      .catch(function () { /* stay on what's shown; the next tick tries again */ })
      .then(function () { busy = false; last = Date.now(); });
  }
  setInterval(refresh, 30000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) refresh(); });

  // ------------------------------------------------------------------ start
  readData();
  if (data.length) { pick(0); applyFilter(); }
  var listEl = $('#sdList');
  if (listEl) listEl.addEventListener('scroll', function () { if (!raf) raf = requestAnimationFrame(function () { raf = 0; check(); hidePop(); }); }, { passive: true });
  markBelow();
  requestAnimationFrame(function () { setTimeout(check, 40); });
  if (reduce) $$('.sd-tile').forEach(count);
})();
