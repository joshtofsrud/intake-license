/* MARKER-ECOM-AVAILABILITY — E-commerce settings center: tabs + the Availability tool.
   Switches save at once. Every bulk change is counted first, confirmed in the app's own
   dialog, and can be undone from the toast. */
(function () {
  'use strict';
  var cfgEl = document.getElementById('ecomCfg');
  if (!cfgEl) return;
  var URLS = { items: cfgEl.dataset.items, cats: cfgEl.dataset.cats, item: cfgEl.dataset.item, bulk: cfgEl.dataset.bulk, undo: cfgEl.dataset.undo };
  var CSRF = cfgEl.dataset.csrf;
  var $ = function (id) { return document.getElementById(id); };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var money = function (c) { return c == null ? '—' : '$' + (c / 100).toFixed(2); };
  var plural = function (n, one, many) { return n + ' ' + (n === 1 ? one : (many || one + 's')); };

  var state = { page: 1, rows: [], selected: {}, view: 'items', loaded: false, undoTimer: null };

  /* ---------- http ---------- */
  function api(method, url, body) {
    var opts = { method: method, headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF, 'X-Requested-With': 'XMLHttpRequest' } };
    if (body) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    return fetch(url, opts).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) { if (!r.ok || j.ok === false) throw new Error(j.message || 'Something went wrong — nothing was changed.'); return j; });
    });
  }
  function qs(extra) {
    var p = new URLSearchParams();
    if ($('q').value.trim()) p.set('q', $('q').value.trim());
    if ($('fCat').value) p.set('cat', $('fCat').value);
    if ($('fStock').value) p.set('stock', $('fStock').value);
    if ($('fState').value) p.set('state', $('fState').value);
    Object.keys(extra || {}).forEach(function (k) { p.set(k, extra[k]); });
    return p.toString();
  }
  function say(msg) { var f = $('flash'); f.textContent = msg || 'Saved'; f.classList.add('on'); clearTimeout(f._t); f._t = setTimeout(function () { f.classList.remove('on'); }, 1400); }
  function problem(e) {
    if (window.IntakeConfirm && IntakeConfirm.alert) IntakeConfirm.alert({ title: 'Not saved', message: e.message || String(e) });
    loadItems(true);
  }

  /* ---------- tabs ---------- */
  document.querySelectorAll('#tabs .ec-tab').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('#tabs .ec-tab').forEach(function (x) { x.classList.toggle('on', x === b); });
      document.querySelectorAll('.ec-panel').forEach(function (p) { p.classList.toggle('on', p.id === 'p-' + b.dataset.p); });
      try { history.replaceState(null, '', '?tab=' + b.dataset.p); } catch (e) { }
      if (b.dataset.p === 'avail') init();
    });
  });
  var ld = $('ldOn'), ldBox = $('ldBox');
  if (ld && ldBox) { var syncLd = function () { ldBox.classList.toggle('hide', !ld.checked); }; ld.addEventListener('change', syncLd); syncLd(); }

  /* ---------- summary ---------- */
  function setSummary(s) {
    if (!s) return;
    $('sOnline').textContent = s.online; $('sOver').textContent = s.oversell; $('sOut').textContent = s.out; $('sOff').textContent = s.offline;
    var pip = $('pipOut'); pip.textContent = s.out; pip.classList.toggle('warn', s.out > 0);
  }

  /* ---------- item rows ---------- */
  var BADGE = {
    forsale: ['ia-badge--forsale', 'For sale'], special: ['ia-badge--special', 'Special order'],
    out: ['ia-badge--out', 'Out of stock'], offline: ['ia-badge--offline', 'Offline']
  };
  function badge(st) { var b = BADGE[st]; return '<span class="ia-badge ' + b[0] + '">' + b[1] + '</span>'; }
  function tg(id, kind, on) { return '<label class="tg"><input type="checkbox" data-id="' + esc(id) + '" data-k="' + kind + '"' + (on ? ' checked' : '') + ' aria-label="' + (kind === 'online' ? 'Online' : 'Oversell') + '"><i></i></label>'; }
  function rowHtml(r) {
    var nm = '<div class="nm">' + esc(r.name) + '<small>' + esc([r.sku, r.brand].filter(Boolean).join(' · ')) + '</small></div>';
    return '<tr data-id="' + esc(r.id) + '"><td class="c"><input type="checkbox" class="sel" data-id="' + esc(r.id) + '"' + (state.selected[r.id] ? ' checked' : '') + '></td>'
      + '<td>' + nm + '</td><td>' + esc(r.category) + '</td>'
      + '<td class="ia-num stk' + (r.stock <= 0 ? ' z' : '') + '">' + r.stock + '</td><td class="ia-num">' + money(r.price) + '</td>'
      + '<td class="ctr">' + tg(r.id, 'online', r.online) + '</td><td class="ctr">' + tg(r.id, 'oversell', r.oversell) + '</td>'
      + '<td>' + badge(r.state) + '</td></tr>';
  }
  function cardHtml(r) {
    return '<div class="av-item" data-id="' + esc(r.id) + '"><input type="checkbox" class="sel" data-id="' + esc(r.id) + '"' + (state.selected[r.id] ? ' checked' : '') + '>'
      + '<div><div class="nm">' + esc(r.name) + '</div><div class="meta">' + badge(r.state) + '<span class="stk' + (r.stock <= 0 ? ' z' : '') + '">' + r.stock + ' in stock</span><span>' + money(r.price) + '</span></div></div>'
      + '<div class="tgs"><label>Online' + tg(r.id, 'online', r.online) + '</label><label>Oversell' + tg(r.id, 'oversell', r.oversell) + '</label></div></div>';
  }
  function render() {
    var rows = state.rows;
    $('tbody').innerHTML = rows.length ? rows.map(rowHtml).join('') : '<tr><td colspan="8"><div class="av-empty">No items match. Clear the search or filters to see everything.</div></td></tr>';
    $('list').innerHTML = rows.length ? rows.map(cardHtml).join('') : '<div class="av-empty">No items match. Clear the search or filters to see everything.</div>';
    updateBulk();
  }

  var seq = 0;
  function loadItems(reset) {
    if (reset) { state.page = 1; state.rows = []; }
    var mine = ++seq;
    return api('GET', URLS.items + '?' + qs({ page: state.page })).then(function (j) {
      if (mine !== seq) return;
      state.rows = state.rows.concat(j.items);
      state.more = j.more; state.total = j.total;
      setSummary(j.summary);
      $('countLine').textContent = 'Showing ' + state.rows.length + ' of ' + j.total;
      $('more').style.display = j.more ? '' : 'none';
      render();
    }).catch(function (e) { $('countLine').textContent = e.message; });
  }

  /* ---------- selection + bulk bar ---------- */
  function selIds() { return Object.keys(state.selected).filter(function (k) { return state.selected[k]; }); }
  function updateBulk() {
    var n = selIds().length;
    $('bulk').classList.toggle('on', n > 0 && state.view === 'items');
    $('bulkN').textContent = n;
    var all = $('selAll'); all.checked = state.rows.length > 0 && state.rows.every(function (r) { return state.selected[r.id]; });
  }
  function onChange(e) {
    var t = e.target;
    if (t.classList.contains('sel')) { state.selected[t.dataset.id] = t.checked; updateBulk(); return; }
    if (t.dataset && t.dataset.k) {
      var id = t.dataset.id, body = {}; body[t.dataset.k] = t.checked;
      api('PATCH', URLS.item + '/' + encodeURIComponent(id), body).then(function (j) {
        var row = state.rows.filter(function (r) { return r.id === id; })[0];
        if (row) { row.online = j.online; row.oversell = j.oversell; row.state = j.state; }
        setSummary(j.summary); say('Saved'); render();
      }).catch(problem);
    }
  }
  $('viewItems').addEventListener('change', onChange);
  $('selAll').addEventListener('change', function () {
    var on = this.checked; state.rows.forEach(function (r) { state.selected[r.id] = on; }); render();
  });
  $('more').addEventListener('click', function () { state.page++; loadItems(false); });

  /* ---------- confirm + apply a bulk change ---------- */
  function ask(title, message, confirmText) {
    if (window.IntakeConfirm && IntakeConfirm.show) return IntakeConfirm.show({ title: title, message: message, confirmText: confirmText || 'Apply' });
    return Promise.resolve(false); // never a native dialog; without the app dialog nothing is applied
  }
  function runBulk(payload, describe) {
    return api('POST', URLS.bulk, Object.assign({ apply: false }, payload)).then(function (j) {
      if (j.count === 0) { return IntakeConfirm && IntakeConfirm.alert ? IntakeConfirm.alert({ title: 'Nothing to change', message: 'Every item this applies to is already set that way.' }) : null; }
      return ask(describe.title, describe.message(j.count), describe.confirm).then(function (ok) {
        if (!ok) return;
        return api('POST', URLS.bulk, Object.assign({ apply: true }, payload)).then(function (r) {
          setSummary(r.summary); state.selected = {};
          toast(describe.done(r.count), r.undo);
          loadItems(true); if (state.view === 'cats') loadCats();
        });
      });
    }).catch(problem);
  }

  var FIELD = { 'on': ['online', true], 'off': ['online', false], 'over-on': ['oversell', true], 'over-off': ['oversell', false] };
  $('bulk').addEventListener('click', function (e) {
    var b = e.target.closest('button'); if (!b) return;
    var k = b.dataset.b;
    if (k === 'clear') { state.selected = {}; render(); return; }
    var f = FIELD[k], ids = selIds(); if (!f || !ids.length) return;
    var label = { 'on': 'Put online', 'off': 'Take offline', 'over-on': 'Allow oversell', 'over-off': 'Stop overselling' }[k];
    runBulk({ kind: 'ids', ids: ids, field: f[0], value: f[1] }, {
      title: label + '?',
      message: function (n) { return n + ' of the ' + ids.length + ' selected ' + (n === 1 ? 'item changes' : 'items change') + '. ' + (f[0] === 'online' ? (f[1] ? 'They will appear in your store.' : 'They will disappear from your store.') : (f[1] ? 'At 0 stock they stay orderable as special orders.' : 'At 0 stock they will show Out of stock and can\'t be ordered.')); },
      confirm: label,
      done: function (n) { return plural(n, 'item') + ' updated'; }
    });
  });

  /* ---------- quick rules ---------- */
  var RULES = {
    'instock-online': { title: 'Put every in-stock item online?', text: 'will appear in your store', done: 'put online' },
    'catalog-online': { title: 'Put every catalog-linked item online?', text: 'will appear in your store', done: 'put online' },
    'catalog-over': { title: 'Allow oversell on catalog-linked items?', text: 'will stay orderable at 0 stock as special orders', done: 'set to oversell' },
    'over-off': { title: 'Stop overselling everywhere?', text: 'will show Out of stock at 0 stock and can\'t be ordered', done: 'set to stop overselling' },
    'all-off': { title: 'Take everything offline?', text: 'will disappear from your store until you publish again', done: 'taken offline' }
  };
  $('rulesBtn').addEventListener('click', function (e) { e.stopPropagation(); $('rulesPop').classList.toggle('on'); });
  document.addEventListener('click', function () { $('rulesPop').classList.remove('on'); });
  $('rulesPop').addEventListener('click', function (e) {
    var b = e.target.closest('button'); if (!b) return;
    $('rulesPop').classList.remove('on');
    var r = RULES[b.dataset.r];
    runBulk({ kind: 'rule', rule: b.dataset.r }, {
      title: r.title, confirm: 'Apply',
      message: function (n) { return plural(n, 'item') + ' ' + r.text + '. You can undo this right after.'; },
      done: function (n) { return plural(n, 'item') + ' ' + r.done; }
    });
  });

  /* ---------- undo toast ---------- */
  function toast(msg, token) {
    $('toastT').textContent = msg;
    var u = $('toastU'); u.style.display = token ? '' : 'none';
    u.onclick = function () {
      api('POST', URLS.undo, { token: token }).then(function (j) {
        setSummary(j.summary); hideToast(); say('Undone'); loadItems(true); if (state.view === 'cats') loadCats();
      }).catch(function (e) { hideToast(); problem(e); });
    };
    $('toast').classList.add('on'); clearTimeout(state.undoTimer); state.undoTimer = setTimeout(hideToast, 10000);
  }
  function hideToast() { $('toast').classList.remove('on'); }

  /* ---------- filters ---------- */
  var deb; $('q').addEventListener('input', function () { clearTimeout(deb); deb = setTimeout(function () { loadItems(true); }, 250); });
  ['fCat', 'fStock', 'fState'].forEach(function (id) { $(id).addEventListener('change', function () { loadItems(true); }); });

  /* ---------- categories view ---------- */
  document.querySelectorAll('.av-view button').forEach(function (b) {
    b.addEventListener('click', function () {
      state.view = b.dataset.v;
      document.querySelectorAll('.av-view button').forEach(function (x) { x.classList.toggle('on', x === b); });
      $('viewItems').classList.toggle('hide', state.view !== 'items');
      $('viewCats').classList.toggle('hide', state.view !== 'cats');
      if (state.view === 'cats') loadCats();
      updateBulk();
    });
  });
  function triState(on, total) { return on === 0 ? 0 : (on === total ? 2 : 1); }
  function catTg(id, field, on, total) {
    var t = triState(on, total);
    return '<label class="tg"><input type="checkbox" class="cat-tg" data-cat="' + esc(id) + '" data-f="' + field + '" data-s="' + t + '"' + (t === 2 ? ' checked' : '') + ' aria-label="' + field + '"><i></i></label>';
  }
  function loadCats() {
    api('GET', URLS.cats).then(function (j) {
      setSummary(j.summary);
      $('catBody').innerHTML = j.categories.map(function (c) {
        var pct = c.total ? Math.round(100 * c.online / c.total) : 0;
        return '<tr><td><div class="nm">' + esc(c.name) + '</div></td><td class="ia-num">' + c.total + '</td>'
          + '<td><div style="display:flex;gap:8px;align-items:center"><div class="cat-bar"><i style="width:' + pct + '%"></i></div><span class="ec-note">' + c.online + ' of ' + c.total + '</span></div></td>'
          + '<td class="ctr">' + catTg(c.id, 'online', c.online, c.total) + '</td><td class="ctr">' + catTg(c.id, 'oversell', c.oversell, c.total) + '</td></tr>';
      }).join('') || '<tr><td colspan="5"><div class="av-empty">No active items yet.</div></td></tr>';
      $('catList').innerHTML = j.categories.map(function (c) {
        return '<div class="av-item" style="grid-template-columns:1fr auto"><div><div class="nm">' + esc(c.name) + '</div><div class="meta"><span>' + c.online + ' of ' + c.total + ' online</span></div></div>'
          + '<div class="tgs"><label>Online' + catTg(c.id, 'online', c.online, c.total) + '</label><label>Oversell' + catTg(c.id, 'oversell', c.oversell, c.total) + '</label></div></div>';
      }).join('');
      document.querySelectorAll('.cat-tg').forEach(function (el) { el.indeterminate = el.dataset.s === '1'; });
      if ($('fCat').options.length <= 1) {
        j.categories.forEach(function (c) { var o = document.createElement('option'); o.value = c.id; o.textContent = c.name; $('fCat').appendChild(o); });
      }
    }).catch(function (e) { $('catBody').innerHTML = '<tr><td colspan="5"><div class="av-empty">' + esc(e.message) + '</div></td></tr>'; });
  }
  function onCatChange(e) {
    var t = e.target; if (!t.classList.contains('cat-tg')) return;
    var s = parseInt(t.dataset.s, 10), turnOn = s !== 2; // off or half-way -> all on; all on -> all off
    var name = (t.closest('tr') || t.closest('.av-item')).querySelector('.nm').textContent;
    var field = t.dataset.f;
    t.checked = s === 2; t.indeterminate = s === 1; // hold the old look until it's confirmed
    runBulk({ kind: 'category', category_id: t.dataset.cat, field: field, value: turnOn }, {
      title: (field === 'online' ? (turnOn ? 'Put ' : 'Take ') : (turnOn ? 'Allow oversell on ' : 'Stop overselling on ')) + name + (field === 'online' ? (turnOn ? ' online?' : ' offline?') : '?'),
      confirm: 'Apply',
      message: function (n) { return plural(n, 'item') + ' in ' + name + ' ' + (field === 'online' ? (turnOn ? 'will appear in your store.' : 'will disappear from your store.') : (turnOn ? 'will stay orderable at 0 stock as special orders.' : 'will show Out of stock at 0 stock.')); },
      done: function (n) { return plural(n, 'item') + ' in ' + name + ' updated'; }
    });
  }
  $('viewCats').addEventListener('change', onCatChange);

  /* ---------- start ---------- */
  function init() {
    if (state.loaded) return; state.loaded = true;
    loadItems(true);
    api('GET', URLS.cats).then(function (j) {
      j.categories.forEach(function (c) { var o = document.createElement('option'); o.value = c.id; o.textContent = c.name; $('fCat').appendChild(o); });
      setSummary(j.summary);
    }).catch(function () { });
  }
  if ($('p-avail').classList.contains('on')) init();
})();
