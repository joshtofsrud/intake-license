/* MARKER-BUYING: the Buying page. Data comes in #by-data; filtering, the drawer and
   the Draft POs list run here. Add to PO and Dismiss post to the server. */
(function () {
  'use strict';
  var el = document.getElementById('by-data'); if (!el) return;
  var D = JSON.parse(el.textContent);
  var $ = function (s) { return document.querySelector(s); }, $$ = function (s) { return Array.prototype.slice.call(document.querySelectorAll(s)); };
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content;
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
  var money = function (c) { if (c == null) return '—'; var n = c / 100; return (n < 0 ? '−' : '') + '$' + Math.abs(n).toLocaleString('en-US', { minimumFractionDigits: Math.abs(n) % 1 ? 2 : 0, maximumFractionDigits: 2 }); };
  var money0 = function (c) { if (c == null) return '—'; var n = Math.round(c / 100); return (n < 0 ? '−' : '') + '$' + Math.abs(n).toLocaleString('en-US'); };
  var poUrl = function (id) { return D.urls.po ? D.urls.po.replace('__ID__', id) : null; };
  var ST = { out: ['Out of stock', 'bad'], reorder: ['Order now', 'warn'], soon: ['Order soon', 'info'], ok: ['Healthy', 'good'], over: ['Overstocked', 'mute'] };
  var ORDER = ['out', 'reorder', 'soon', 'ok', 'over'];
  var COL = { out: '#A32D2D', reorder: '#B7791F', soon: '#2F6FB0', ok: '#3B6D11', over: '#6b6b6b' };
  var ROWS = D.rows || [], stF = 'all', openRow = null, limit = 200;
  var V = D.velocity || {};

  function toast(msg, type, link) {
    var stack = document.getElementById('ia-toast-stack');
    if (!stack) { stack = document.createElement('div'); stack.id = 'ia-toast-stack'; stack.className = 'ia-toast-stack'; document.body.appendChild(stack); }
    var t = document.createElement('div'); t.className = 'ia-toast ia-toast--' + (type || 'success');
    t.innerHTML = '<span></span>' + (link ? '<a class="by-toast-a" href="' + esc(link.href) + '">' + esc(link.label) + '</a>' : '');
    t.querySelector('span').textContent = msg; stack.appendChild(t); void t.offsetWidth; t.classList.add('is-shown');
    setTimeout(function () { t.classList.remove('is-shown'); setTimeout(function () { t.remove(); }, 250); }, link ? 4200 : 2600);
  }
  function post(url, body) {
    return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Something went wrong (' + r.status + ').' }; }).then(function (d) { if (!r.ok && d.ok !== false) { d.ok = false; d.error = d.message || 'Something went wrong.'; } return d; }); });
  }
  function nav(params) {
    var u = new URL(D.urls.page, location.href);
    var cur = new URLSearchParams(location.search);
    ['w', 'loc', 'tab'].forEach(function (k) { var v = k in params ? params[k] : cur.get(k); if (v) u.searchParams.set(k, v); });
    location.href = u.toString();
  }
  function showTab(t) {
    $$('#by-tabs .mk-tab').forEach(function (a) { a.classList.toggle('on', a.dataset.tab === t); });
    $$('.mk-pane').forEach(function (p) { p.classList.toggle('on', p.id === 'pane-' + t); });
    try { var u = new URL(location.href); u.searchParams.set('tab', t); history.replaceState(null, '', u.toString()); } catch (e) {}
  }

  /* ---------------- moving ---------------- */
  function vname(r) { return r.vendors && r.vendors[0] ? r.vendors[0].name : ''; }
  function filtered() {
    var fv = $('#by-fv') ? $('#by-fv').value : '', fb = $('#by-fb') ? $('#by-fb').value : '', q = ($('#by-fq') ? $('#by-fq').value : '').trim().toLowerCase();
    return ROWS.filter(function (r) {
      return (stF === 'all' || r.st === stF) && (!fv || vname(r) === fv) && (!fb || r.brand === fb) && (!q || (r.name + ' ' + r.sku + ' ' + r.brand).toLowerCase().indexOf(q) >= 0);
    });
  }
  function spark(a, w, h) {
    w = w || 84; h = h || 22; var m = Math.max.apply(null, a.concat([1])), bw = w / a.length;
    return '<svg width="' + w + '" height="' + h + '" viewBox="0 0 ' + w + ' ' + h + '" aria-hidden="true">' + a.map(function (v, i) {
      var bh = Math.max(1, v / m * (h - 2)); return '<rect x="' + (i * bw + 0.5) + '" y="' + (h - bh) + '" width="' + Math.max(1, bw - 1.5) + '" height="' + bh + '" rx="1" fill="currentColor" opacity="' + (i >= a.length - 4 ? 0.95 : 0.4) + '"/>';
    }).join('') + '</svg>';
  }
  var dueTxt = function (l) { return l.due == null ? 'no date yet' : (l.due < 0 ? '<span class="late">' + (-l.due) + 'd late</span>' : 'due ' + esc(l.date)); };
  function poCell(r) {
    if (!r.lines.length) return (r.st === 'out' || r.st === 'reorder') ? '<span class="mk-po"><span class="gap">nothing on order</span></span>' : '<span class="dash">&mdash;</span>';
    var n = r.next, link = poUrl(n.poId);
    return '<span class="mk-po"><b>' + r.owed + '</b> on ' + (link ? '<a href="' + link + '" data-stop>' + esc(n.po) + '</a>' : esc(n.po)) + (r.lines.length > 1 ? ' +' + (r.lines.length - 1) : '') +
      '<small>' + dueTxt(n) + (r.gap > 0 ? ' · <span class="gap">out ~' + r.gap + 'd first</span>' : '') + '</small>' +
      (r.cust ? '<small class="cust">' + r.cust + ' held for customers</small>' : '') + '</span>';
  }
  function distCell(r) {
    var d = r.dist; if (!d) return '<span class="dash">&mdash;</span>';
    if (d.avail <= 0) return '<span class="mk-dist"><b class="low">0</b><small>' + esc(d.code) + ' · out</small>' + (d.restock ? '<span class="rs">Distributor restocks ~' + esc(d.restock) + '</span>' : '') + '</span>';
    return '<span class="mk-dist"><b class="' + (d.days != null && d.days < 14 ? 'low' : '') + '">' + d.avail.toLocaleString() + '</b><small>' + esc(d.code) + (d.days != null ? ' · ' + d.days + 'd left' : '') + '</small></span>';
  }
  function myDraft(r) {
    var loc = D.loc || (D.locs[0] && D.locs[0].id);
    var v = r.vendors[0] ? r.vendors[0].id : null;
    return (r.draft || []).filter(function (x) { return x.loc === loc && (!v || x.vendor === v); })[0] || (r.draft || [])[0] || null;
  }
  function renderTop() {
    var cnt = {}; ORDER.forEach(function (k) { cnt[k] = ROWS.filter(function (r) { return r.st === k; }).length; });
    $('#by-stack').innerHTML = ORDER.filter(function (k) { return cnt[k]; }).map(function (k) {
      return '<button type="button" class="mk-seg ' + k + ' ' + (stF !== 'all' && stF !== k ? 'dim' : '') + '" data-st="' + k + '" style="flex:' + cnt[k] + '" title="' + ST[k][0] + ': ' + cnt[k] + '">' + cnt[k] + '</button>';
    }).join('');
    $('#by-keys').innerHTML = ORDER.map(function (k) { return '<span><i style="background:' + COL[k] + '"></i><b>' + cnt[k] + '</b>' + ST[k][0] + '</span>'; }).join('');
    var gaps = ROWS.filter(function (r) { return r.gap > 0 || r.st === 'out'; }).length;
    var late = ROWS.filter(function (r) { return r.late > 0; }).length;
    var pos = {}; ROWS.forEach(function (r) { r.lines.forEach(function (l) { pos[l.po] = 1; }); });
    var onOrd = ROWS.reduce(function (a, r) { var c = r.cost; return c == null ? a : a + r.forStock * c; }, 0);
    var over = ROWS.filter(function (r) { return r.st === 'over'; }).reduce(function (a, r) { return r.cost == null ? a : a + Math.max(0, r.onHand) * r.cost; }, 0);
    var fig = function (l, v, d, c) { return '<div class="rb-fig"><div class="l">' + l + '</div><div class="v">' + v + '</div><div class="d ' + (c || '') + '">' + d + '</div></div>'; };
    var units = ROWS.reduce(function (a, r) { return a + r.forStock; }, 0);
    $('#by-strip').innerHTML =
      fig('Items moving', ROWS.length, 'sold in the last ' + esc(D.window)) +
      fig('Run out before it arrives', gaps, 'out now, or out before the PO lands', gaps ? 'bad' : '') +
      fig('On order', D.canCost ? money0(onOrd) : units + ' units', 'for stock on these items, on ' + Object.keys(pos).length + ' placed POs' + (late ? ' · <a href="#" class="mk-link" data-st="reorder">' + late + ' late</a>' : '')) +
      (D.canCost ? fig('Tied up in overstock', money0(over), 'at cost, more than ' + D.set.over_days + ' days of stock') : fig('Overstocked', cnt.over, 'more than ' + D.set.over_days + ' days of stock'));
  }
  function renderSeg() {
    var n = function (k) { return k === 'all' ? ROWS.length : ROWS.filter(function (r) { return r.st === k; }).length; };
    $('#by-seg').innerHTML = ['all'].concat(ORDER).map(function (k) { return '<button type="button" data-st="' + k + '" class="' + (stF === k ? 'on' : '') + '">' + (k === 'all' ? 'All' : ST[k][0]) + '<span class="n">' + n(k) + '</span></button>'; }).join('');
  }
  function renderTable() {
    var rs = filtered(), shown = rs.slice(0, limit);
    $('#by-tbl tbody').innerHTML = shown.map(function (r) {
      var dr = myDraft(r), act = '';
      if (dr && poUrl(dr.poId)) act = '<a class="mk-onlist" href="' + poUrl(dr.poId) + '" data-stop>On draft ' + esc(dr.po) + '</a>';
      else if (D.canPo) act = '<button type="button" class="ia-btn ia-btn--secondary ia-btn--sm" data-add="' + r.id + '">Add to PO</button>';
      var days = r.onHand <= 0 ? '0d' : (r.days == null ? '<span class="dash">&mdash;</span>' : (r.days >= 999 ? '999+' : Math.round(r.days) + 'd'));
      return '<tr class="click" data-id="' + r.id + '"><td class="mk-item">' + esc(r.name) + '<small>' + esc(r.sku) + (r.brand ? ' · ' + esc(r.brand) : '') + '</small></td>' +
        '<td><span class="mk-sold">' + spark(r.wk.slice(-Math.max(D.w, 8))) + '<b style="font-weight:500">' + r.sold + '</b></span></td>' +
        '<td class="ia-num">' + r.pace.toFixed(1) + '</td><td class="ia-num">' + r.onHand + '</td><td class="ia-num">' + days + '</td>' +
        '<td>' + poCell(r) + '</td>' + (V.report_on ? '<td>' + distCell(r) + '</td>' : '') +
        '<td><span class="rb-pill ' + ST[r.st][1] + '">' + ST[r.st][0] + '</span></td><td style="text-align:right">' + act + '</td></tr>';
    }).join('');
    $('#by-empty').hidden = rs.length > 0; $('#by-tbl').style.display = rs.length ? '' : 'none';
    $('#by-more').hidden = rs.length <= limit;
    $('#by-count').textContent = (rs.length > limit ? limit + ' shown of ' : '') + rs.length + ' of ' + ROWS.length + ' items';
  }
  function renderAll() { if (!ROWS.length) return; renderTop(); renderSeg(); renderTable(); }

  if (ROWS.length) {
    Array.from(new Set(ROWS.map(vname).filter(Boolean))).sort().forEach(function (v) { $('#by-fv').insertAdjacentHTML('beforeend', '<option>' + esc(v) + '</option>'); });
    Array.from(new Set(ROWS.map(function (r) { return r.brand; }).filter(Boolean))).sort().forEach(function (b) { $('#by-fb').insertAdjacentHTML('beforeend', '<option>' + esc(b) + '</option>'); });
    ['#by-fv', '#by-fb'].forEach(function (s) { $(s).addEventListener('change', function () { limit = 200; renderTable(); }); });
    $('#by-fq').addEventListener('input', function () { limit = 200; renderTable(); });
  }

  /* ---------------- drawer ---------------- */
  function vendorsFor(r) { return r.vendors && r.vendors.length ? r.vendors : (D.vendorsAll || []).map(function (v) { return { id: v.id, name: v.name, cost: null, lead: v.lead, avail: null }; }); }
  function suggest(r) { var wks = +$('#d-cover').value; return Math.max(1, Math.ceil(r.pace * wks - Math.max(0, r.onHand) - r.forStock)); }
  function costFor(r) { var v = vendorsFor(r).filter(function (x) { return x.id === $('#d-from').value; })[0]; return v && v.cost != null ? v.cost : r.cost; }
  function calcNote() {
    var r = openRow, q = +$('#d-qty').value || 0, c = costFor(r);
    $('#d-calc').textContent = (D.canCost && c != null ? q + ' × ' + money(c) + ' = ' + money(Math.round(q * c)) + ' at cost. ' : '') +
      (r.pace > 0 ? 'Suggested from ' + r.pace.toFixed(1) + ' a week × ' + $('#d-cover').value + ' weeks, less on hand' + (r.forStock ? ' and the ' + r.forStock + ' on order for stock' : '') + '.' : 'No recent sales, so there\'s nothing to suggest from. Enter a quantity.');
  }
  function renderTarget() {
    var vid = $('#d-from').value, lid = $('#d-loc').value;
    var vn = ($('#d-from').selectedOptions[0] || {}).textContent || '', ln = ($('#d-loc').selectedOptions[0] || {}).textContent || '';
    vn = vn.split(' · ')[0];
    var d = (D.drafts || []).filter(function (x) { return x.vendorId === vid && x.locId === lid; }).pop();
    var already = d ? d.lines.filter(function (l) { return l.item === openRow.id; })[0] : null;
    $('#d-target').innerHTML = d
      ? (already ? 'Already on draft <b>' + esc(d.po) + '</b> (' + esc(vn) + ' · ' + esc(ln) + ') with ' + already.qty + '. Adding raises that line.' : 'Adds a line to draft <b>' + esc(d.po) + '</b> (' + esc(vn) + ' · ' + esc(ln) + '), which has ' + d.lines.length + ' ' + (d.lines.length === 1 ? 'line' : 'lines') + '.')
      : 'Starts a new draft for <b>' + esc(vn) + ' · ' + esc(ln) + '</b>. It isn\'t sent until you place it.';
    $('#d-add').textContent = already ? 'Raise the line' : 'Add to PO';
  }
  function openDrawer(r) {
    openRow = r;
    $('#d-name').textContent = r.name; $('#d-sub').textContent = [r.sku, r.brand].filter(Boolean).join(' · ');
    $('#d-pill').innerHTML = r.st ? '<span class="rb-pill ' + ST[r.st][1] + '">' + ST[r.st][0] + '</span>' : '';
    var n = r.next, note = '';
    var lead = r.lead;
    if (r.st == null) note = 'Customers searched for this and found none in stock. It hasn\'t sold recently, so there\'s no pace to go on.';
    else if (r.onHand <= 0) note = 'You\'re out. You sell about ' + r.pace.toFixed(1) + ' a week.' + (n ? ' ' + r.owed + ' are owed on ' + n.po + (n.due == null ? ', with no date yet.' : (n.due < 0 ? ', which is ' + (-n.due) + ' days late.' : ', due ' + n.date + '.')) : ' Nothing is on order.');
    else if (r.gap > 0) note = 'At ' + r.pace.toFixed(1) + ' a week you have about ' + Math.round(r.days) + ' days of stock, but ' + n.po + ' isn\'t due until ' + n.date + '. You\'d be out for around ' + r.gap + ' days.';
    else if (r.st === 'reorder') note = 'About ' + Math.round(r.days) + ' days of stock left and ' + (r.late ? 'a late PO' : 'nothing on order for stock') + '. ' + (lead != null ? 'The lead time is ' + lead + ' days.' : '');
    else if (r.st === 'soon') note = 'About ' + Math.round(r.withPo) + ' days of stock counting what\'s on order. Worth ordering soon.';
    else if (r.st === 'over') note = 'More than ' + D.set.over_days + ' days of stock at the current pace. No need to order.';
    else note = 'About ' + Math.round(r.withPo != null ? r.withPo : r.days) + ' days of stock' + (r.forStock ? ' counting what\'s on order' : '') + '. Nothing needed yet.';
    var d = r.dist;
    if (d && d.days != null && d.days < 14 && d.avail > 0) note += ' ' + d.code + ' has about ' + d.days + ' days of its own stock left, so order before it\'s gone.';
    if (d && d.avail <= 0) note += ' ' + d.code + ' is out' + (d.restock ? '; the distributor restocks around ' + d.restock + '. That date is the distributor\'s, not an order of yours.' : '.');
    if (r.held) note += ' ' + r.held + ' on the shelf ' + (r.held === 1 ? 'is' : 'are') + ' held (layaway or special orders).';
    $('#d-note').textContent = note;
    var w = r.wk.slice(-13), m = Math.max.apply(null, w.concat([1]));
    $('#d-chart-h').textContent = 'last 13 weeks';
    $('#d-chart').innerHTML = '<svg viewBox="0 0 400 110" width="100%" role="img" aria-label="Weekly units sold">' + w.map(function (v, i) { var h = v / m * 80; return '<rect x="' + (i * 30.5 + 4) + '" y="' + (96 - h) + '" width="24" height="' + h + '" rx="3" fill="' + (i >= 13 - D.set.pace_weeks ? 'var(--ia-accent)' : 'var(--ia-border-strong)') + '"/><text x="' + (i * 30.5 + 16) + '" y="' + (90 - h) + '" text-anchor="middle" font-size="9" fill="currentColor" opacity=".7">' + v + '</text>'; }).join('') + '<line x1="0" y1="96.5" x2="400" y2="96.5" stroke="currentColor" opacity=".15"/></svg>';
    var kv = [['On hand' + (D.loc ? '' : ', all locations'), r.onHand], ['Per week (last ' + D.set.pace_weeks + ')', r.pace.toFixed(1)], ['Days of stock', r.onHand <= 0 ? '0' : (r.days == null ? '—' : Math.round(r.days))], ['Sold, ' + D.window, r.sold],
      ['On order for stock', r.forStock || '—'], d ? ['At ' + d.code, d.avail <= 0 ? 'out' : d.avail.toLocaleString()] : ['Lead time', lead != null ? lead + ' days' : '—']];
    if (D.canCost) kv.push(['Cost', money(r.cost)], ['Price', money(r.price)]);
    $('#d-kv').innerHTML = kv.map(function (k) { return '<div><small>' + esc(k[0]) + '</small><b>' + esc(k[1]) + '</b></div>'; }).join('');
    $('#d-onpo').innerHTML = r.lines.length ? 'Already owed: ' + r.lines.map(function (l) { var u = poUrl(l.poId); return '<b>' + l.qty + '</b> on ' + (u ? '<a href="' + u + '">' + esc(l.po) + '</a>' : esc(l.po)) + ' (' + esc(l.loc) + ', ' + (l.due == null ? 'no date yet' : (l.due < 0 ? (-l.due) + 'd late' : 'due ' + esc(l.date))) + (l.cust ? ', ' + l.cust + ' for customers' : '') + ')'; }).join('; ') + '. The suggestion counts these.' : 'Nothing on order for this item.';
    var vs = vendorsFor(r);
    $('#d-from').innerHTML = vs.map(function (v) { return '<option value="' + v.id + '">' + esc(v.name) + (v.avail != null ? ' · ' + (v.avail > 0 ? v.avail + ' avail' : 'none in stock') : '') + (D.canCost && v.cost != null ? ' · ' + money(v.cost) : '') + (v.lead != null ? ' · ' + v.lead + 'd' : '') + '</option>'; }).join('');
    $('#d-orderform').hidden = !D.canPo || !vs.length; $('#d-nopo').hidden = D.canPo; $('#d-add').hidden = !D.canPo || !vs.length;
    if (D.canPo && !vs.length) { $('#d-nopo').hidden = false; $('#d-nopo').textContent = 'Add a vendor first. A purchase order is for one vendor.'; }
    if (vs.length) { $('#d-qty').value = r.pace > 0 ? suggest(r) : 1; calcNote(); renderTarget(); }
    $('#by-scrim').hidden = false; $('#by-drawer').hidden = false;
  }
  function closeDrawer() { $('#by-scrim').hidden = true; $('#by-drawer').hidden = true; openRow = null; }
  function addToPo() {
    var q = parseInt($('#d-qty').value, 10);
    if (!q || q < 1) { toast('Enter a quantity first', 'error'); return; }
    var btn = $('#d-add'); btn.disabled = true;
    post(D.urls.add, { item_id: openRow.id, vendor_id: $('#d-from').value, location_id: $('#d-loc').value, qty: q }).then(function (d) {
      btn.disabled = false;
      if (!d.ok) { toast(d.error || 'That didn\'t work.', 'error'); return; }
      D.drafts = d.drafts; $('#by-dcount').textContent = D.drafts.length;
      var row = ROWS.filter(function (x) { return x.id === openRow.id; })[0];
      if (row) { row.draft = (row.draft || []).filter(function (x) { return x.poId !== d.poId; }).concat([{ po: d.po, poId: d.poId, vendor: $('#d-from').value, loc: $('#d-loc').value, qty: q }]); }
      toast(openRow.name + (d.raised ? ' raised on ' : ' added to ') + (d.made ? 'new draft ' : '') + d.po, 'success', d.url ? { href: d.url, label: 'Open' } : null);
      closeDrawer(); renderAll();
    }).catch(function () { btn.disabled = false; toast('Network error. Try again.', 'error'); });
  }

  /* ---------------- draft POs ---------------- */
  function renderDrafts() {
    var b = $('#by-dbody');
    if (!D.drafts.length) {
      b.innerHTML = '<div class="mk-empty" style="border:0"><div class="t">No draft POs.</div><div class="s">Use Add to PO on an item. Items that need ordering now are a good place to start.</div><button type="button" class="ia-btn ia-btn--secondary ia-btn--sm" id="by-dstart">Show items to order now</button></div>';
      return;
    }
    b.innerHTML = D.drafts.map(function (d) {
      var u = poUrl(d.id);
      return '<div class="mk-ol-g"><h3>' + esc(d.vendor) + ' · ' + esc(d.loc) + (D.canCost ? ' · ' + money0(d.total) : '') + (u ? '<a href="' + u + '">Open ' + esc(d.po) + '</a>' : ' · ' + esc(d.po)) + '</h3>' +
        '<div class="meta">' + d.lines.length + ' ' + (d.lines.length === 1 ? 'line' : 'lines') + ' · draft, not placed</div>' +
        (d.lines.length ? d.lines.map(function (l) { return '<div class="mk-ol-r"><div class="n">' + esc(l.name) + '<small>' + esc(l.sku || '') + '</small></div><div class="q">' + l.qty + (D.canCost && l.cost != null ? ' × ' + money(l.cost) : '') + '</div></div>'; }).join('') : '<div class="mk-ol-r"><div class="n" style="color:var(--ia-text-muted)">No lines yet</div></div>') + '</div>';
    }).join('');
  }

  /* ---------------- searched ---------------- */
  function renderSearches() {
    var S = D.searches || [];
    $('#by-stbl tbody').innerHTML = S.map(function (s, i) {
      var match = s.item ? esc(s.item) + ' (' + s.stock + ' on hand)' : 'No close match';
      var find = D.urls.find + '?q=' + encodeURIComponent(s.query);
      return '<tr data-s="' + i + '"><td class="mk-item">' + esc(s.query) + '</td><td class="ia-num">' + s.times + '</td><td>' + esc(s.where) + '</td><td>' + esc(s.last) + '</td><td class="' + (s.item ? '' : 'dash') + '">' + match + '</td>' +
        '<td style="text-align:right;white-space:nowrap">' + (s.itemId && D.canPo ? '<button type="button" class="ia-btn ia-btn--secondary ia-btn--sm" data-sadd="' + i + '">Add to PO</button> ' : '') +
        '<a class="ia-btn ia-btn--secondary ia-btn--sm" href="' + find + '">Find in catalog</a> <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" data-dismiss="' + i + '">Dismiss</button></td></tr>';
    }).join('');
    $('#by-sempty').hidden = S.length > 0; $('#by-stbl').style.display = S.length ? '' : 'none';
    $('#by-scount').textContent = S.length;
  }
  function searchRow(s) {
    var moving = ROWS.filter(function (r) { return r.id === s.itemId; })[0];
    if (moving) return moving;
    var z = []; for (var i = 0; i < 26; i++) z.push(0);
    return { id: s.itemId, name: s.item, sku: s.sku || '', brand: '', wk: z, pace: 0, sold: 0, onHand: s.stock || 0, held: 0, days: null, withPo: null, lines: [], owed: 0, cust: 0, forStock: 0, next: null, late: 0, gap: 0, st: null, lead: null, vendors: s.vendors || [], dist: null, draft: [], cost: s.cost, price: null };
  }

  /* ---------------- open to buy ---------------- */
  var OTB_GOALS = [];
  function otbCells(r, gv) {
    var o = gv - r.onHand - r.onOrder, pct = gv > 0 ? Math.min(100, Math.round((r.onHand + r.onOrder) / gv * 100)) : 0;
    return {
      cls: gv && o < 0 ? 'cell-bad' : '',
      otb: gv ? money0(o) + (r.drafts && o >= 0 && o - r.drafts < 0 ? '<small style="display:block;font-size:11px;color:var(--rb-warn)">drafts would go ' + money0(r.drafts - o) + ' over</small>' : '') : '<span class="dash">&mdash;</span>',
      meter: gv ? '<div class="mk-meter"><i style="width:' + pct + '%;background:' + (pct >= 100 ? COL.out : pct > 85 ? COL.reorder : COL.ok) + '"></i></div>' : '<span class="dash">set a goal</span>'
    };
  }
  function otbStrip() {
    var R = D.otb.rows, tot = { onHand: 0, units: 0, onOrder: 0, drafts: 0, goal: 0 };
    R.forEach(function (r, i) { ['onHand', 'units', 'onOrder', 'drafts'].forEach(function (k) { tot[k] += r[k]; }); tot.goal += OTB_GOALS[i]; });
    var fig = function (l, v, d, c) { return '<div class="rb-fig"><div class="l">' + l + '</div><div class="v">' + v + '</div><div class="d ' + (c || '') + '">' + d + '</div></div>'; };
    var otb = tot.goal - tot.onHand - tot.onOrder;
    $('#by-ostrip').innerHTML =
      fig('Inventory goal', tot.goal ? money0(tot.goal) : '—', tot.goal ? 'sum of category goals' : 'no goals set yet') +
      fig('On hand, at cost', money0(tot.onHand), tot.units.toLocaleString() + ' units') +
      fig('On order', money0(tot.onOrder), 'still owed on ' + (D.urls.poOpen ? '<a class="mk-link" href="' + D.urls.poOpen + '">' + D.otb.openPos + ' placed POs</a>' : D.otb.openPos + ' placed POs')) +
      fig('In drafts', money0(tot.drafts), 'not counted until placed') +
      fig('Open to buy', tot.goal ? money0(otb) : '—', tot.goal ? 'goal minus stock and on order' : 'set goals below', tot.goal ? (otb < 0 ? 'bad' : 'good') : '');
  }
  function renderOtb() {
    if (!D.otb || !$('#by-otbl')) return;
    var R = D.otb.rows, editable = !!D.loc;
    OTB_GOALS = R.map(function (r) { return r.goal; });
    $('#by-otbl tbody').innerHTML = R.map(function (r, i) {
      var gv = r.goal, c = otbCells(r, gv), name = (r.id === '' ? '_' : r.id);
      return '<tr data-orow="' + i + '"><td class="mk-item">' + esc(r.name) + '</td><td class="ia-num">' + money0(r.onHand) + '</td><td class="ia-num">' + money0(r.onOrder) + '</td>' +
        '<td class="ia-num">' + (r.drafts ? '<span style="color:var(--ia-text-dim)">' + money0(r.drafts) + '</span>' : '<span class="dash">&mdash;</span>') + '</td>' +
        '<td class="ia-num">' + (editable ? '<input class="ia-input mk-goal" name="goals[' + esc(name) + ']" value="' + (gv ? Math.round(gv / 100).toLocaleString('en-US') : '') + '" placeholder="0" data-goal="' + i + '" inputmode="decimal" aria-label="Goal for ' + esc(r.name) + '" />' : (gv ? money0(gv) : '<span class="dash">no goal</span>')) + '</td>' +
        '<td class="ia-num ' + c.cls + '" data-o>' + c.otb + '</td><td data-m>' + c.meter + '</td></tr>';
    }).join('') || '<tr><td colspan="7" class="dash" style="text-align:center;padding:24px">No stock or orders yet.</td></tr>';
    otbStrip();
  }
  function onGoal(inp) {
    var i = +inp.dataset.goal, r = D.otb.rows[i];
    OTB_GOALS[i] = Math.round((parseFloat(String(inp.value).replace(/[^0-9.]/g, '')) || 0) * 100);
    var tr = inp.closest('tr'), c = otbCells(r, OTB_GOALS[i]), o = tr.querySelector('[data-o]');
    o.className = 'ia-num ' + c.cls; o.innerHTML = c.otb; tr.querySelector('[data-m]').innerHTML = c.meter;
    otbStrip();
  }

  /* ---------------- events ---------------- */
  document.addEventListener('click', function (e) {
    var t = e.target, x;
    if ((x = t.closest('[data-tab]'))) { e.preventDefault(); showTab(x.dataset.tab); return; }
    if (t.closest('[data-stop]')) { return; }
    if ((x = t.closest('[data-st]'))) { e.preventDefault(); stF = (stF === x.dataset.st && x.classList.contains('mk-seg')) ? 'all' : x.dataset.st; limit = 200; renderAll(); return; }
    if ((x = t.closest('[data-add]'))) { e.stopPropagation(); openDrawer(ROWS.filter(function (r) { return r.id === x.dataset.add; })[0]); return; }
    if ((x = t.closest('[data-sadd]'))) { openDrawer(searchRow(D.searches[+x.dataset.sadd])); return; }
    if ((x = t.closest('#by-tbl tbody tr'))) { openDrawer(ROWS.filter(function (r) { return r.id === x.dataset.id; })[0]); return; }
    if (t.closest('#d-close,#d-cancel,#by-scrim')) { closeDrawer(); return; }
    if (t.closest('#d-add')) { addToPo(); return; }
    if (t.closest('#q-minus')) { $('#d-qty').value = Math.max(1, (parseInt($('#d-qty').value, 10) || 1) - 1); calcNote(); return; }
    if (t.closest('#q-plus')) { $('#d-qty').value = (parseInt($('#d-qty').value, 10) || 0) + 1; calcNote(); return; }
    if (t.closest('#by-drafts-btn')) { renderDrafts(); $('#by-dmodal').hidden = false; return; }
    if (t.closest('#by-dclose') || t.id === 'by-dmodal') { $('#by-dmodal').hidden = true; return; }
    if (t.closest('#by-dstart')) { $('#by-dmodal').hidden = true; showTab('moving'); stF = 'reorder'; renderAll(); return; }
    if (t.closest('#by-clear')) { stF = 'all'; $('#by-fv').value = ''; $('#by-fb').value = ''; $('#by-fq').value = ''; renderAll(); return; }
    if (t.closest('#by-more button')) { limit += 200; renderTable(); return; }
    if ((x = t.closest('[data-dismiss]'))) {
      var s = D.searches[+x.dataset.dismiss];
      var ask = window.IntakeConfirm && IntakeConfirm.show ? IntakeConfirm.show({ title: 'Dismiss this search?', message: '"' + s.query + '" stops counting as an ordering signal. It comes back if customers search for it again.', confirmText: 'Dismiss' }) : Promise.resolve(false);
      ask.then(function (ok) {
        if (!ok) return;
        post(D.urls.dismiss, { key: s.key }).then(function (d) {
          if (!d.ok) { toast(d.error || 'That didn\'t work.', 'error'); return; }
          D.searches = D.searches.filter(function (y) { return y !== s; }); renderSearches(); toast('Search dismissed');
        });
      });
      return;
    }
  });
  var locSel = $('#by-loc'); if (locSel) locSel.addEventListener('change', function () { nav({ loc: locSel.value }); });
  if ($('#d-cover')) {
    $('#d-cover').addEventListener('change', function () { if (openRow && openRow.pace > 0) { $('#d-qty').value = suggest(openRow); } calcNote(); });
    $('#d-qty').addEventListener('input', calcNote);
    $('#d-from').addEventListener('change', function () { calcNote(); renderTarget(); });
    $('#d-loc').addEventListener('change', renderTarget);
  }
  document.addEventListener('input', function (e) { var g = e.target.closest('[data-goal]'); if (g) onGoal(g); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeDrawer(); if ($('#by-dmodal')) $('#by-dmodal').hidden = true; } });

  renderAll(); renderSearches(); renderOtb();
})();
