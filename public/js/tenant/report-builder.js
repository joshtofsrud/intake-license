/* Custom reports — the report builder on Reports > Custom. MARKER-REPORT-BUILDER-V2
 *
 * Everything here works on the shop's live data through the report endpoints.
 * The page never computes a figure itself except calculations, which use the
 * same numbers shown in the table. Reports are read-only.
 */
(function () {
'use strict';
var BOOT = JSON.parse(document.getElementById('rb-boot').textContent);
var DS = BOOT.catalog, URLS = BOOT.urls, ME = BOOT.me;
var RANGES = BOOT.ranges;
var SAVED = BOOT.saved || [], MYCALCS = BOOT.calcs || [];
var ALLM = {};
Object.keys(DS).forEach(function (d) { DS[d].m.forEach(function (m) { if (!ALLM[m[1]]) ALLM[m[1]] = d + ':' + m[0]; }); });
var ADD = { n: 1, '$': 1, h: 1 };
var ORD = { mband: 1, pband: 1, bucket: 1, hist: 1 };
var CSRF = (document.querySelector('meta[name=csrf-token]') || {}).content || '';

/* ===================== helpers ===================== */
function $(s, r) { return (r || document).querySelector(s); }
function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
function toast(t) { var e = $('#toast'); e.textContent = t; e.classList.add('on'); clearTimeout(toast._t); toast._t = setTimeout(function () { e.classList.remove('on'); }, 2800); }
function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;'); }
function opt(arr, sel) { return arr.map(function (a) { var v = Array.isArray(a) ? a[0] : a, l = Array.isArray(a) ? a[1] : a; return '<option value="' + esc(v) + '"' + (String(v) === String(sel) ? ' selected' : '') + '>' + esc(l) + '</option>'; }).join(''); }
function fmt(v, t) {
  if (v == null || isNaN(v) || !isFinite(v)) return '—';
  if (t === '$') return (v < 0 ? '−$' : '$') + Math.abs(v).toLocaleString('en-US', { minimumFractionDigits: Math.abs(v) < 100 ? 2 : 0, maximumFractionDigits: Math.abs(v) < 100 ? 2 : 0 });
  if (t === '%') return v.toFixed(1) + '%';
  if (t === 'd' || t === 'h') return v.toFixed(1);
  return Math.round(v).toLocaleString('en-US');
}
function post(url, body) {
  return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF, 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify(body) })
    .then(function (r) { return r.json().then(function (j) { if (!r.ok) throw (j && j.message) || 'Something went wrong.'; return j; }); });
}
function get(url) {
  return fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
    .then(function (r) { return r.json(); });
}
function dirty() { dirtyOn = true; $('#dirty').classList.add('on'); }
function clean() { dirtyOn = false; $('#dirty').classList.remove('on'); }
function dimName(ds, dk) { var d = DS[ds].d.filter(function (x) { return x[0] === dk; })[0]; return d ? d[1].replace(' (rang up by)', '') : dk; }
function cmpLabel(S) { return S.compare === 'yoy' ? 'vs last year' : 'vs before'; }
function dsOf(S, mk) { return mk.indexOf(':') > 0 && mk.indexOf('calc:') !== 0 ? mk.split(':')[0] : S.ds; }
function mdef(S, mk) {
  if (mk.indexOf('calc:') === 0) { var c = S.calcs.filter(function (x) { return x.id === mk; })[0]; return c ? { l: c.name, t: c.t, calc: c, ds: S.ds } : { l: mk, t: 'd', ds: S.ds }; }
  var p = mk.indexOf(':') > 0 ? mk.split(':') : [S.ds, mk];
  var d = DS[p[0]] && DS[p[0]].m.filter(function (x) { return x[0] === p[1]; })[0];
  return d ? { l: d[1], t: d[2], ds: p[0], k: p[1] } : { l: mk, t: 'n', ds: p[0], k: p[1] };
}
function normKey(S, key) { var p = key.split(':'); return p[0] === S.ds ? p[1] : key; }
function fullKey(S, mk) { return mk.indexOf(':') > 0 ? mk : S.ds + ':' + mk; }
function dimsOfDs(ds) { return DS[ds] ? DS[ds].d.map(function (x) { return x[0]; }) : []; }
function isTimeDim(ds, k) { var d = DS[ds].d.filter(function (x) { return x[0] === k; })[0]; return !!d && d[2] === 't'; }
function canSplit(S, ds) { var need = [S.dim].concat(S.dim2 ? [S.dim2] : []); var have = dimsOfDs(ds); return need.every(function (d) { return have.indexOf(d) > -1; }); }
function labelToKey(l) { return ALLM[l]; }
function calcTokens(f) { var out = [], re = /\[([^\]]+)\]/g, m; while ((m = re.exec(f))) out.push(m[1]); return out; }
function evalCalcWith(f, valFor) {
  var bad = false;
  var expr = f.replace(/\[([^\]]+)\]/g, function (_, l) { var k = labelToKey(l); if (!k) { bad = true; return '0'; } var v = valFor(k); if (v == null || !isFinite(v)) { bad = true; return '0'; } return '(' + v + ')'; });
  if (bad || !/^[0-9eE+\-*\/().\s]+$/.test(expr)) return NaN;
  try { var r = Function('return ' + expr)(); return isFinite(r) ? r : NaN; } catch (e) { return NaN; }
}
/* A measure's value from a set of values keyed "ds:measure" (a row, a cell, the totals). */
function cellVal(S, mk, vals) {
  if (!vals) return null;
  if (mk.indexOf('calc:') === 0) { var cd = S.calcs.filter(function (x) { return x.id === mk; })[0]; if (!cd) return null; var r = evalCalcWith(cd.f, function (k) { return vals[k]; }); return isNaN(r) ? null : r; }
  var v = vals[fullKey(S, mk)]; return v == null ? null : v;
}
function pct(v, p) { return (v == null || p == null || p === 0) ? null : (v - p) / Math.abs(p) * 100; }
function deltaCell(dl) { return dl == null ? '<span class="rb-dim">—</span>' : '<span class="' + (dl >= 0 ? 'd-up' : 'd-dn') + '" style="margin:0">' + (dl >= 0 ? '+' : '') + dl.toFixed(1) + '%</span>'; }

/* ===================== Lucide icons (ISC licence) ===================== */
var LU = {"truck": "<path d=\"M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2\" /> <path d=\"M15 18H9\" /> <path d=\"M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14\" /> <circle cx=\"17\" cy=\"18\" r=\"2\" /> <circle cx=\"7\" cy=\"18\" r=\"2\" />", "alarm-clock": "<circle cx=\"12\" cy=\"13\" r=\"8\" /> <path d=\"M12 9v4l2 2\" /> <path d=\"M5 3 2 6\" /> <path d=\"m22 6-3-3\" /> <path d=\"M6.38 18.7 4 21\" /> <path d=\"M17.64 18.67 20 21\" />", "calendar-days": "<path d=\"M8 2v4\" /> <path d=\"M16 2v4\" /> <rect width=\"18\" height=\"18\" x=\"3\" y=\"4\" rx=\"2\" /> <path d=\"M3 10h18\" /> <path d=\"M8 14h.01\" /> <path d=\"M12 14h.01\" /> <path d=\"M16 14h.01\" /> <path d=\"M8 18h.01\" /> <path d=\"M12 18h.01\" /> <path d=\"M16 18h.01\" />", "percent": "<line x1=\"19\" x2=\"5\" y1=\"5\" y2=\"19\" /> <circle cx=\"6.5\" cy=\"6.5\" r=\"2.5\" /> <circle cx=\"17.5\" cy=\"17.5\" r=\"2.5\" />", "map-pin": "<path d=\"M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0\" /> <circle cx=\"12\" cy=\"10\" r=\"3\" />", "receipt": "<path d=\"M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z\" /> <path d=\"M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8\" /> <path d=\"M12 17.5v-11\" />", "clock": "<circle cx=\"12\" cy=\"12\" r=\"10\" /> <polyline points=\"12 6 12 12 16 14\" />", "timer": "<line x1=\"10\" x2=\"14\" y1=\"2\" y2=\"2\" /> <line x1=\"12\" x2=\"15\" y1=\"14\" y2=\"11\" /> <circle cx=\"12\" cy=\"14\" r=\"8\" />", "user-plus": "<path d=\"M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2\" /> <circle cx=\"9\" cy=\"7\" r=\"4\" /> <line x1=\"19\" x2=\"19\" y1=\"8\" y2=\"14\" /> <line x1=\"22\" x2=\"16\" y1=\"11\" y2=\"11\" />", "crown": "<path d=\"M11.562 3.266a.5.5 0 0 1 .876 0L15.39 8.87a1 1 0 0 0 1.516.294L21.183 5.5a.5.5 0 0 1 .798.519l-2.834 10.246a1 1 0 0 1-.956.734H5.81a1 1 0 0 1-.957-.734L2.02 6.02a.5.5 0 0 1 .798-.519l4.276 3.664a1 1 0 0 0 1.516-.294z\" /> <path d=\"M5 21h14\" />", "history": "<path d=\"M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8\" /> <path d=\"M3 3v5h5\" /> <path d=\"M12 7v5l4 2\" />", "inbox": "<polyline points=\"22 12 16 12 14 15 10 15 8 12 2 12\" /> <path d=\"M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z\" />", "calendar-check": "<path d=\"M8 2v4\" /> <path d=\"M16 2v4\" /> <rect width=\"18\" height=\"18\" x=\"3\" y=\"4\" rx=\"2\" /> <path d=\"M3 10h18\" /> <path d=\"m9 16 2 2 4-4\" />", "package-plus": "<path d=\"M16 16h6\" /> <path d=\"M19 13v6\" /> <path d=\"M21 10V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l2-1.14\" /> <path d=\"m7.5 4.27 9 5.15\" /> <polyline points=\"3.29 7 12 12 20.71 7\" /> <line x1=\"12\" x2=\"12\" y1=\"22\" y2=\"12\" />", "boxes": "<path d=\"M2.97 12.92A2 2 0 0 0 2 14.63v3.24a2 2 0 0 0 .97 1.71l3 1.8a2 2 0 0 0 2.06 0L12 19v-5.5l-5-3-4.03 2.42Z\" /> <path d=\"m7 16.5-4.74-2.85\" /> <path d=\"m7 16.5 5-3\" /> <path d=\"M7 16.5v5.17\" /> <path d=\"M12 13.5V19l3.97 2.38a2 2 0 0 0 2.06 0l3-1.8a2 2 0 0 0 .97-1.71v-3.24a2 2 0 0 0-.97-1.71L17 10.5l-5 3Z\" /> <path d=\"m17 16.5-5-3\" /> <path d=\"m17 16.5 4.74-2.85\" /> <path d=\"M17 16.5v5.17\" /> <path d=\"M7.97 4.42A2 2 0 0 0 7 6.13v4.37l5 3 5-3V6.13a2 2 0 0 0-.97-1.71l-3-1.8a2 2 0 0 0-2.06 0l-3 1.8Z\" /> <path d=\"M12 8 7.26 5.15\" /> <path d=\"m12 8 4.74-2.85\" /> <path d=\"M12 13.5V8\" />", "banknote": "<rect width=\"20\" height=\"12\" x=\"2\" y=\"6\" rx=\"2\" /> <circle cx=\"12\" cy=\"12\" r=\"2\" /> <path d=\"M6 12h.01M18 12h.01\" />", "tags": "<path d=\"m15 5 6.3 6.3a2.4 2.4 0 0 1 0 3.4L17 19\" /> <path d=\"M9.586 5.586A2 2 0 0 0 8.172 5H3a1 1 0 0 0-1 1v5.172a2 2 0 0 0 .586 1.414L8.29 18.29a2.426 2.426 0 0 0 3.42 0l3.58-3.58a2.426 2.426 0 0 0 0-3.42z\" /> <circle cx=\"6.5\" cy=\"9.5\" r=\".5\" fill=\"currentColor\" />", "package-open": "<path d=\"M12 22v-9\" /> <path d=\"M15.17 2.21a1.67 1.67 0 0 1 1.63 0L21 4.57a1.93 1.93 0 0 1 0 3.36L8.82 14.79a1.655 1.655 0 0 1-1.64 0L3 12.43a1.93 1.93 0 0 1 0-3.36z\" /> <path d=\"M20 13v3.87a2.06 2.06 0 0 1-1.11 1.83l-6 3.08a1.93 1.93 0 0 1-1.78 0l-6-3.08A2.06 2.06 0 0 1 4 16.87V13\" /> <path d=\"M21 12.43a1.93 1.93 0 0 0 0-3.36L8.83 2.2a1.64 1.64 0 0 0-1.63 0L3 4.57a1.93 1.93 0 0 0 0 3.36l12.18 6.86a1.636 1.636 0 0 0 1.63 0z\" />", "archive": "<rect width=\"20\" height=\"5\" x=\"2\" y=\"3\" rx=\"1\" /> <path d=\"M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8\" /> <path d=\"M10 12h4\" />", "trending-up": "<polyline points=\"22 7 13.5 15.5 8.5 10.5 2 17\" /> <polyline points=\"16 7 22 7 22 13\" />"};
function lu(n, sz) { return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"' + (sz ? ' width="' + sz + '" height="' + sz + '"' : '') + '>' + (LU[n] || LU['calendar-days']) + '</svg>'; }

/* ===================== state ===================== */
var S, SAVED_ID = null, titleAuto = true, dirtyOn = false, OUT = 'table', CM = null, EDIT_CALC = null, calcSeq = 1;
var RES = null, REQ = 0;
function firstDs() { return DS.sales ? 'sales' : Object.keys(DS)[0]; }
function blank(ds) { var d = DS[ds]; return { ds: ds, measures: [d.m[0][0]], calcs: [], dim: d.d[0][0], dim2: '', filters: [], range: 'Last 30 days', compare: 'off', sortDir: 'desc', limit: 0, title: '', mode: 'report', tevents: { sale: 1, appt: 1, msg: 1, note: 1, class: 1, rental: 1 }, tcust: '', tcustName: '', why: '', read: null, span: null }; }
function autoTitle() {
  if (S.mode === 'timeline') return S.tcustName ? 'Timeline · ' + S.tcustName : 'Customer timeline';
  var ms = S.measures.map(function (m) { return mdef(S, m).l; });
  var t = (ms.length > 1 ? ms[0] + ' + ' + (ms.length - 1) + ' more' : (ms[0] || 'Report')) + ' by ' + dimName(S.ds, S.dim).toLowerCase();
  if (S.dim2) t += ' and ' + dimName(S.ds, S.dim2).toLowerCase();
  if (S.span) t += ' · ' + S.span.l;
  return t;
}
function payload(s) {
  return { ds: s.ds, mode: s.mode, measures: s.measures, calcs: s.calcs, dim: s.dim, dim2: s.dim2, filters: s.filters.filter(function (f) { return f.v !== ''; }),
    range: s.range, compare: s.compare, sortDir: s.sortDir, limit: s.limit, tcust: s.tcust, tevents: s.tevents, span: s.span || null };
}

/* ===================== templates ===================== */
var TPL = (BOOT.templates || []).filter(function (t) {
  var c = t[3], d = DS[c.ds]; if (!d) return false;
  if (c.mode === 'timeline') return true;
  var mk = d.m.map(function (x) { return x[0]; }), dk = dimsOfDs(c.ds);
  if (dk.indexOf(c.dim) < 0 || (c.dim2 && dk.indexOf(c.dim2) < 0)) return false;
  return c.measures.every(function (m) {
    if (m.indexOf('calc:') === 0) { var cd = (c.calcs || []).filter(function (x) { return x.id === m; })[0]; return cd && calcTokens(cd.f).every(function (l) { var k = labelToKey(l); return k && DS[k.split(':')[0]] && dimsOfDs(k.split(':')[0]).indexOf(c.dim) > -1; }); }
    return mk.indexOf(m) > -1;
  });
});

/* ===================== ask in plain words ===================== */
var MSYN = [
  ['dist:ddays', /days (left|of stock|of cover) (at|with) (the )?(distributors?|distro)|running out at|left at (the )?(distributors?|[a-z]+)\b/], ['dist:dpace', /(distributors?|distro).*\b(moving|pace|velocity)\b|\b(moving|pace|velocity)\b.*(distributors?|distro)/], ['dist:dmine', /your stock|my stock|we have|do i have/], ['dist:dstock', /distributors? stock|stock (at|with|from) (the )?distributors?|(at|with) (the )?distributors?\b/],
  ['recv:rst', /recvst/], ['recv:rcost', /cost received|received cost|amount received|spent on stock/], ['recv:runits', /received|receiving|receipts|units in\b/],
  ['calc:mlh', /margin\s+per\s+(labor\s+|work\s+)?hour|profit\s+per\s+(labor\s+|work\s+)?hour/],
  ['calc:slh', /(sales|revenue)\s+per\s+(labor\s+|work\s+)?hour/],
  ['calc:rpa', /(sales|revenue)\s+per\s+appointment/],
  ['sales:marginp', /margin\s*(%|percent|pct|rate)|margin percentage/], ['sales:margin', /margin|profit/],
  ['sales:ipt', /items per ticket|attach rate|basket size/], ['sales:items', /items sold|units sold/], ['sales:tickets', /tickets|transactions/],
  ['sales:avg', /average ticket|avg ticket/], ['sales:disc', /discount/], ['sales:refunds', /refund/],
  ['customers:newc', /new customers|sign-?ups/], ['customers:growth', /growth/], ['customers:ltvt', /lifetime value|\bltv\b/], ['customers:visits', /visits per customer/], ['customers:inbox', /inbox|contacts|messages/], ['customers:spend', /customer spend|spend by customer|spending/],
  ['appts:cancp', /cancellation rate/], ['appts:canc', /cancel/], ['appts:done', /completed/], ['appts:aval', /average (appointment )?value/], ['appts:booked', /booked value/], ['appts:ap', /appointments|bookings/],
  ['staff:ot', /overtime/], ['staff:sph', /sales per hour/], ['staff:hrs', /hours|labor|shifts?|time ?clock/],
  ['inv:turns', /\bmoving\b|fast[- ]?movers?/], ['inv:dleft', /days (of stock|in stock|left)|how long.{0,20}last/], ['inv:cover', /weeks? of cover/], ['inv:turns', /turns|turnover/], ['inv:st', /sell[- ]?through/], ['inv:dead', /dead stock|stale|slow movers?/], ['inv:a4', /aged over|over 180|older than 180|oldest/], ['inv:a3', /aged 91|91.{0,4}180/], ['inv:cost', /inventory|stock value|value at cost|on hand/],
  ['sales:rev', /revenue|sales|income|takings/], ['customers:custs', /customers/]
];
var DSYN = [['mband', /mbandword/], ['pband', /pbandword/], ['item', /\b(items?|products?|skus?)\b/], ['day', /\b(day|days|daily)\b/], ['week', /\b(week|weeks|weekly)\b/], ['month', /\b(month|months|monthly)\b/], ['hour', /\bhour of day|\bby hour\b/], ['loc', /\b(location|locations|store|stores|shop|shops)\b/], ['staff', /\b(staff|employee|employees|cashier|person|people|team)\b/], ['res', /\bresources?\b/], ['cat', /\bcategor/], ['pay', /\bpayment/], ['ctype', /\bcustomer type|walk-?ins?\b/], ['tag', /\btags?\b/], ['vip', /\bvip\b/], ['cust', /\bcustomer(s)?\b/], ['svc', /\bservice(s)?\b/], ['stat', /\bstatus/], ['pp', /\bpay period/], ['vend', /\b(vendor|vendors|supplier|suppliers)\b/], ['brand', /\bbrand/], ['dist', /\b(distributor|distributors|distro|distros)\b/], ['hist', /\bhistory\b/], ['bucket', /\bage\b|\baging\b/]];
var CALCDEF = { slh: { name: 'Sales per labor hour', f: '[Revenue] / [Hours worked]', t: '$', own: 'sales' }, mlh: { name: 'Margin per labor hour', f: '[Margin $] / [Hours worked]', t: '$', own: 'sales' }, rpa: { name: 'Revenue per appointment', f: '[Revenue] / [Appointments]', t: '$', own: 'sales' } };
function parseAsk(txt) {
  var s = ' ' + String(txt).toLowerCase().replace(/[?!,.;:"“”]/g, ' ') + ' ', read = [], notes = [], cfg = { filters: [], calcs: [], measures: [], compare: 'off' }, m;
  s = s.replace(/margin\s+(range|band|bracket)s?|margins? (ranges|bands)/g, ' mbandword ').replace(/price\s+(range|band|point|bracket|tier)s?|(at|by)\s+(certain\s+|each\s+|different\s+)?prices?\b/g, ' pbandword ');
  if (/received|pbandword|mbandword/.test(s)) s = s.replace(/sell[- ]?through/g, ' recvst ');
  if (/(vs|versus|compared? (to|with)|against)\s+(the\s+)?(same\s+(period\s+)?)?(last|prior|previous)\s+year|year[- ]over[- ]year|\byoy\b/.test(s)) { cfg.compare = 'yoy'; s = s.replace(/(vs|versus|compared? (to|with)|against)\s+(the\s+)?(same\s+(period\s+)?)?(last|prior|previous)\s+year|year[- ]over[- ]year|\byoy\b/g, ' '); }
  else if (/\b(compare[ds]?|vs|versus|previous period|prior period|period before|than before)\b/.test(s)) { cfg.compare = 'prev'; s = s.replace(/\b(compare[ds]?( to| with)?( the)?|vs|versus|previous period|prior period|period before|than before)\b/g, ' '); }
  var RR = [[/last 12 months|past year|last year|trailing 12|past 12 months/, 'Last 12 months'], [/this year|year to date|ytd/, 'This year'], [/last 90 days|last quarter|this quarter|past 90 days|quarter/, 'Last 90 days'], [/last month|previous month/, 'Last month'], [/this month|month to date/, 'This month'], [/last 30 days|past 30 days|past month/, 'Last 30 days'], [/last 7 days|this week|last week|past week|past 7 days/, 'Last 7 days'], [/\btoday\b/, 'Today']];
  RR.forEach(function (r) { if (!cfg.range && r[0].test(s)) { cfg.range = r[1]; s = s.replace(r[0], ' '); } });
  if ((m = s.match(/\b(top|best|highest)\s+(\d+)\b/))) { cfg.limit = +m[2]; cfg.sortDir = 'desc'; s = s.replace(m[0], ' '); }
  else if ((m = s.match(/\b(bottom|worst|lowest)\s+(\d+)\b/))) { cfg.limit = +m[2]; cfg.sortDir = 'asc'; s = s.replace(m[0], ' '); }
  else if (/\b(top|best)\b/.test(s)) { cfg.limit = 10; cfg.sortDir = 'desc'; } else if (/\b(worst|bottom|lowest)\b/.test(s)) { cfg.limit = 10; cfg.sortDir = 'asc'; }
  var calcsUsed = [];
  MSYN.forEach(function (x) { var ds = x[0].split(':')[0]; if (x[0].indexOf('calc:') !== 0 && !DS[ds]) return; if (x[1].test(s)) { s = s.replace(x[1], ' '); if (x[0].indexOf('calc:') === 0) { calcsUsed.push(x[0].slice(5)); } else cfg.measures.push(x[0]); } });
  calcsUsed = calcsUsed.filter(function (k) { return calcTokens(CALCDEF[k].f).every(function (l) { return !!labelToKey(l); }); });
  var primary = cfg.measures.length ? cfg.measures[0].split(':')[0] : (calcsUsed.length ? CALCDEF[calcsUsed[0]].own : null);
  if (!primary) { var dsw = [['distributor', 'dist'], ['received', 'recv'], ['inventory', 'inv'], ['customers', 'customers'], ['appointments', 'appts'], ['staff', 'staff'], ['sales', 'sales']].filter(function (x) { return s.indexOf(x[0]) > -1 && DS[x[1]]; })[0]; if (dsw) { primary = dsw[1]; cfg.measures.push(dsw[1] + ':' + DS[dsw[1]].m[0][0]); notes.push('You didn\'t name a measure, so I started with ' + DS[dsw[1]].m[0][1].toLowerCase() + '.'); } }
  if (!primary || !DS[primary]) return { ok: false };
  cfg.ds = primary;
  cfg.measures = cfg.measures.map(function (k) { return k.split(':')[0] === primary ? k.split(':')[1] : k; });
  var seen = {}; cfg.measures = cfg.measures.filter(function (k) { if (seen[k]) return false; seen[k] = 1; return true; });
  calcsUsed.forEach(function (k) { var cd = CALCDEF[k], id = 'calc:a' + (calcSeq++); cfg.calcs.push({ id: id, name: cd.name, f: cd.f, t: cd.t }); cfg.measures.unshift(id); });
  var after = s.split(/\b(?:by|per|each|across|split by|broken out by|for each|over)\b/).slice(1).join(' ') + (s.indexOf('mbandword') > -1 ? ' mbandword' : '') + (s.indexOf('pbandword') > -1 ? ' pbandword' : '');
  var dims = []; DSYN.forEach(function (d) { var mm = after.match(d[1]); if (mm) dims.push({ k: d[0], at: after.indexOf(mm[0]) }); });
  dims.sort(function (a, b) { return a.at - b.at; });
  var have = dimsOfDs(primary);
  if (primary === 'appts') dims.forEach(function (d) { if (d.k === 'staff') d.k = 'res'; });
  var dlist = dims.map(function (d) { return d.k; }).filter(function (k) { return have.indexOf(k) > -1; });
  dims.forEach(function (d) { if (have.indexOf(d.k) < 0) notes.push('I can\'t split ' + DS[primary].l.toLowerCase() + ' that way — leaving that out.'); });
  if (!dlist.length && primary === 'inv' && /\bmoving\b|movers?/.test(txt.toLowerCase())) dlist = ['item'];
  if (!dlist.length && primary === 'dist' && /\b(moving|left|items?)\b/.test(s)) dlist = ['item'];
  if (!dlist.length) { dlist = [/over time|trend|history|growth/.test(s) ? (have.indexOf('month') > -1 ? 'month' : have[0]) : have[0]]; }
  cfg.dim = dlist[0];
  if (primary === 'inv' && dlist[0] === 'item' && /\bmoving\b|movers?/.test(txt.toLowerCase()) && !cfg.limit) { cfg.limit = 25; cfg.sortDir = 'desc'; ['units', 'st', 'dleft'].forEach(function (x) { if (cfg.measures.indexOf(x) < 0 && cfg.measures.length < 5) cfg.measures.push(x); }); notes.push('Showing the 25 fastest-moving items. Change the limit to see more.'); }
  if (primary === 'dist' && dlist[0] === 'item' && !cfg.limit) { cfg.limit = 25; cfg.sortDir = 'desc'; if (cfg.measures.indexOf('dstock') < 0) cfg.measures.splice(1, 0, 'dstock'); if (cfg.measures.indexOf('dmine') < 0 && cfg.measures.length < 5) cfg.measures.push('dmine'); notes.push('Showing the 25 items moving fastest. Change the limit to see more.'); }
  if (dlist[1] && dlist[1] !== dlist[0] && !(isTimeDim(primary, dlist[0]) && isTimeDim(primary, dlist[1]))) cfg.dim2 = dlist[1];
  // filters: any of this data's filter values named in the sentence
  DS[primary].f.forEach(function (f) {
    if (cfg.filters.some(function (x) { return x.f === f[0]; })) return;
    var hit = f[2].filter(function (o) { var l = String(o[1]).toLowerCase(); return l.length >= 3 && new RegExp('(^|\\W)' + l.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '(s)?(\\W|$)').test(s); })[0];
    if (hit) cfg.filters.push({ f: f[0], op: 'is', v: hit[0] });
  });
  if (!cfg.range) cfg.range = DS[primary].snap ? 'Last 30 days' : (cfg.dim === 'month' && have.indexOf('month') > -1 ? 'Last 12 months' : 'Last 30 days');
  var bad = []; cfg.measures = cfg.measures.filter(function (k) { if (k.indexOf(':') > 0 && k.indexOf('calc:') !== 0) { var d = k.split(':')[0]; var ok = [cfg.dim].concat(cfg.dim2 ? [cfg.dim2] : []).every(function (x) { return dimsOfDs(d).indexOf(x) > -1; }); if (!ok) { bad.push(k); return false; } } return true; });
  bad.forEach(function (k) { var p = k.split(':'); notes.push(mdef({ ds: primary, calcs: [] }, k).l + ' can\'t be split by ' + dimName(primary, cfg.dim).toLowerCase() + ', so I left it out.'); });
  cfg.measures = cfg.measures.slice(0, 5);
  if (!cfg.measures.length) cfg.measures = [DS[primary].m[0][0]];
  var tmp = Object.assign(blank(primary), cfg);
  read.push({ t: 'from ' + DS[primary].l.toLowerCase(), f: 'pDs' });
  tmp.measures.forEach(function (k) { read.push({ t: mdef(tmp, k).l, f: 'panelShow' }); });
  read.push({ t: 'by ' + dimName(primary, cfg.dim).toLowerCase(), f: 'pDim' });
  if (cfg.dim2) read.push({ t: 'then ' + dimName(primary, cfg.dim2).toLowerCase(), f: 'pDim2' });
  cfg.filters.forEach(function (f) { var fd = DS[primary].f.filter(function (x) { return x[0] === f.f; })[0]; var o = fd && fd[2].filter(function (x) { return x[0] === f.v; })[0]; read.push({ t: o ? o[1] : f.v, f: 'panelFilter' }); });
  read.push({ t: cfg.range.toLowerCase(), f: 'pRange' });
  if (cfg.compare !== 'off') read.push({ t: cfg.compare === 'yoy' ? 'vs last year' : 'vs period before', f: 'pCmp' });
  if (cfg.limit) read.push({ t: (cfg.sortDir === 'asc' ? 'lowest ' : 'top ') + cfg.limit, f: 'pLimit' });
  return { ok: true, cfg: cfg, read: read, notes: notes };
}
var EXAMPLES = ['revenue by month this year', 'margin by category compared to last year', 'sales per labor hour by week', 'top 10 items by revenue', 'appointments by service and location', 'new customers by month', 'received by category and item', 'sell-through by price band', 'what is moving in my inventory', 'inventory aged over 180 days by vendor', 'hours worked by staff last 7 days', 'days left at distributors by brand']
  .filter(function (x) { var r = parseAsk(x); return r.ok && !(r.notes || []).some(function (n) { return /can't/.test(n); }); });
function suggestBlock(msg, target) {
  return '<div class="rb-read"><div class="rb-hint" style="margin-bottom:8px">' + msg + '</div><div class="rb-ex">' + EXAMPLES.slice(0, 6).map(function (x) { return '<button class="rb-chip" data-ex="' + EXAMPLES.indexOf(x) + '" data-target="' + target + '">' + esc(x) + '</button>'; }).join('') + '</div>' +
    '<div class="rb-hint" style="margin-top:10px">Things I can report on: ' + Object.keys(DS).map(function (k) { return DS[k].l.toLowerCase(); }).join(', ') + '.</div></div>';
}
function runAsk(inputId) {
  var el = $('#' + inputId), txt = el.value.trim(), tgt = inputId === 'askList' ? '#askOutList' : '#askOutBuild';
  if (!txt) { $(tgt).innerHTML = suggestBlock('Type what you want to see, or pick one:', inputId); return; }
  var r = parseAsk(txt);
  if (!r.ok) { $(tgt).innerHTML = suggestBlock('I couldn\'t tell which data you meant by “' + esc(txt) + '”. Pick one of these, or reword it:', inputId); return; }
  var cfg = r.cfg; cfg.why = 'Built from your request: “' + txt + '”.' + (r.notes.length ? ' ' + r.notes.join(' ') : '');
  openReport(cfg, null, null, r.read); dirty(); $('#askBuild').value = txt; $('#askOutBuild').innerHTML = ''; $(tgt).innerHTML = '';
}

/* ===================== list ===================== */
var listF = 'all', tplAll = false, listSort = 'new';
var VIS = { me: 'Only me', mgr: 'Owner and managers', all: 'Everyone with Reports access' };
function crLabel(d) { var m = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'], x = String(d).split('-'); return x.length < 3 ? d : m[+x[1] - 1] + ' ' + (+x[2]) + ', ' + x[0]; }
function savedById(id) { return SAVED.filter(function (r) { return r.id === id; })[0]; }
function renderList() {
  var q = ($('#listQ').value || '').toLowerCase();
  $('#exList').innerHTML = '<span>Or start with</span>' + EXAMPLES.slice(0, 6).map(function (x, i) { return '<button class="rb-chip" data-ex="' + i + '" data-target="askList">' + esc(x) + '</button>'; }).join('');
  var rows = SAVED.filter(function (r) { return (listF === 'all' || (listF === 'mine' ? r.own : !r.own)) && (!q || r.name.toLowerCase().indexOf(q) > -1); });
  rows = rows.slice().sort(function (a, b) { return listSort === 'name' ? a.name.localeCompare(b.name) : listSort === 'old' ? a.cr.localeCompare(b.cr) : b.cr.localeCompare(a.cr); });
  $('#listSort').value = listSort;
  $('#listCount').textContent = rows.length + ' report' + (rows.length === 1 ? '' : 's');
  var body;
  if (!SAVED.length) body = '<tr><td colspan="7"><div class="rb-empty">Nothing saved yet. Open a template or ask for a report, then press Save.<div class="rb-ex" style="justify-content:center;margin-top:10px"><button class="rb-chip" data-new>Start a new report</button>' + (TPL[0] ? '<button class="rb-chip" data-tpl="0">' + esc(TPL[0][0]) + '</button>' : '') + '</div></div></td></tr>';
  else if (!rows.length) body = '<tr><td colspan="7"><div class="rb-empty">No reports match' + (q ? ' “' + esc(q) + '”' : '') + '.<div class="rb-ex" style="justify-content:center;margin-top:10px"><button class="rb-chip" data-clearq>Clear search</button><button class="rb-chip" data-new>Start a new report</button></div></div></td></tr>';
  else body = rows.map(function (r) {
    var d = DS[r.ds], shows;
    if (!d) shows = '<span class="rb-dim">This data isn\'t available to you</span>';
    else if ((r.cfg || {}).mode === 'timeline') shows = 'One customer, in order';
    else { var tmp = Object.assign(blank(r.ds), r.cfg || {}); shows = (tmp.measures || []).map(function (m) { return mdef(tmp, m).l; }).slice(0, 3).join(', ') + (tmp.measures.length > 3 ? ' +' + (tmp.measures.length - 3) : '') + ' · by ' + dimName(r.ds, tmp.dim).toLowerCase() + (tmp.dim2 ? ' × ' + dimName(r.ds, tmp.dim2).toLowerCase() : ''); }
    var canDel = r.own || ME.manager;
    return '<tr class="click" data-open="' + esc(r.id) + '"><td><b>' + esc(r.name) + '</b></td><td>' + (d ? esc(d.l) : '—') + '</td><td style="font-size:12px;color:var(--ia-text-muted);max-width:320px">' + shows + '</td><td style="white-space:nowrap">' + crLabel(r.cr) + '<div class="sub" style="font-size:11px;color:var(--ia-text-muted)">by ' + (r.own ? 'You' : esc(r.by)) + '</div></td><td>' + esc(VIS[r.vis] || VIS.me) + '</td><td class="rb-dim">' + (r.last ? esc(r.last) : '—') + '</td>' +
      '<td style="text-align:right;white-space:nowrap"><span class="rb-delconf" id="dc-' + esc(r.id) + '">Delete this report? <button class="ia-btn ia-btn--sm" data-delyes="' + esc(r.id) + '">Delete</button><button class="ia-btn ia-btn--sm" data-delno="' + esc(r.id) + '">Keep</button></span>' +
      '<span id="db-' + esc(r.id) + '">' + (canDel ? '<button class="ia-btn ia-btn--sm" data-del="' + esc(r.id) + '">Delete</button> ' : '') + '<button class="ia-btn ia-btn--sm" data-open="' + esc(r.id) + '"' + (d ? '' : ' disabled') + '>Open</button></span></td></tr>';
  }).join('');
  $('#savedTbl').innerHTML = '<thead><tr><th>Name</th><th>Data</th><th>Shows</th><th>Created</th><th>Who can open</th><th>Last opened</th><th></th></tr></thead><tbody>' + body + '</tbody>';
  var tshow = tplAll ? TPL.length : 8;
  $('#tplList').innerHTML = TPL.slice(0, tshow).map(function (t, i) { return '<button class="rb-tile" data-tpl="' + i + '"><span class="ico c-' + t[3].ds + '">' + lu(t[2]) + '</span><span class="nm">' + esc(t[0]) + '</span><span class="ds">' + esc(t[1]) + '</span><span class="go">' + esc(DS[t[3].ds].l) + '</span></button>'; }).join('');
  $('#tplMore').textContent = TPL.length > 8 ? (tplAll ? 'Show fewer' : 'Show all ' + TPL.length) : '';
  $('#headBtns').innerHTML = '<button class="ia-btn ia-btn--sm ia-btn--primary" type="button" data-new>New report</button>';
}

/* ===================== builder panel ===================== */
function renderPanel() {
  var d = DS[S.ds];
  if (S.mode === 'timeline') {
    $('#panel').innerHTML =
      '<div class="rb-sect"><div class="rb-lbl">Data</div><select class="rb-sel rb-w" id="pDs">' + opt(Object.keys(DS).map(function (k) { return [k, DS[k].l]; }), S.ds) + '</select></div>' +
      '<div class="rb-sect"><div class="rb-lbl">Customer</div><div class="rb-cust"><input class="rb-inp" id="pCust" placeholder="Search by name, email or phone" autocomplete="off" value="' + esc(S.tcustName) + '"><div class="rb-cust-list" id="custList" style="display:none"></div></div></div>' +
      '<div class="rb-sect"><div class="rb-lbl">Include</div><div class="rb-chips" id="pEv">' + [['sale', 'Sales'], ['appt', 'Appointments'], ['msg', 'Messages'], ['note', 'Notes'], ['class', 'Classes'], ['rental', 'Rentals']].map(function (e) { return '<button class="rb-chip' + (S.tevents[e[0]] ? ' on' : '') + '" data-ev="' + e[0] + '">' + e[1] + '</button>'; }).join('') + '</div></div>' +
      '<div class="rb-sect"><div class="rb-lbl">Date range</div><select class="rb-sel rb-w" id="pRange">' + opt(['All time', 'Last 12 months', 'Last 90 days'], S.range) + '</select></div>';
    return;
  }
  var full = S.measures.length >= 5;
  function mrow(key, lab, unit, on, dis, reason, tag) { return '<label class="' + (dis ? 'dis' : '') + '" ' + (dis && reason ? 'data-why="' + esc(reason) + '"' : '') + '><input type="checkbox" class="rb-chk" data-m="' + esc(key) + '"' + (on ? ' checked' : '') + (dis && !on ? ' disabled' : '') + '>' + esc(lab) + (tag ? '<span class="ds">' + esc(tag) + '</span>' : '') + '<span class="u">' + unit + '</span></label>'; }
  var UN = { '$': '$', 'n': '#', '%': '%', 'd': 'avg', 'h': 'hrs' };
  var own = d.m.map(function (m) { var on = S.measures.indexOf(m[0]) > -1; return mrow(m[0], m[1], UN[m[2]], on, !on && full, 'You can show up to 5 things at once — untick one first.'); }).join('');
  var calcs = S.calcs.map(function (c) { var on = S.measures.indexOf(c.id) > -1; return '<label><input type="checkbox" class="rb-chk" data-m="' + c.id + '"' + (on ? ' checked' : '') + '><span class="fx">ƒ</span>' + esc(c.name) + '<span class="u"><button class="rb-link" data-cedit="' + c.id + '" style="font-size:11px">edit</button></span></label>'; }).join('');
  var others = Object.keys(DS).filter(function (k) { return k !== S.ds; }).map(function (k) {
    var ok = canSplit(S, k); var rows = DS[k].m.map(function (m) {
      var key = k + ':' + m[0], on = S.measures.indexOf(key) > -1;
      var reason = !ok ? (DS[k].l + ' can\'t be split by ' + dimName(S.ds, S.dim).toLowerCase() + (S.dim2 ? ' and ' + dimName(S.ds, S.dim2).toLowerCase() : '') + '. It can be split by ' + DS[k].d.slice(0, 4).map(function (x) { return x[1].replace(' (rang up by)', '').toLowerCase(); }).join(', ') + '.') : (full && !on ? 'You can show up to 5 things at once — untick one first.' : '');
      return mrow(key, m[1], UN[m[2]], on, !ok || (full && !on), reason, DS[k].l);
    }).join('');
    return '<div class="rb-otherhead' + (ok ? '' : ' off') + '">' + esc(DS[k].l) + (ok ? '' : ' <span>not available for this breakdown</span>') + '</div>' + rows;
  }).join('');
  var fr = S.filters.map(function (f, i) {
    var fd = d.f.filter(function (x) { return x[0] === f.f; })[0] || d.f[0];
    return '<div class="rb-frow"><select class="rb-sel" data-fk="' + i + '">' + opt(d.f.map(function (x) { return [x[0], x[1]]; }), f.f) + '</select><select class="rb-sel" data-fo="' + i + '">' + opt([['is', 'is'], ['not', 'is not']], f.op) + '</select><select class="rb-sel" data-fv="' + i + '"><option value="">choose…</option>' + opt(fd[2], f.v) + '</select><button class="rb-rm" data-frm="' + i + '" aria-label="Remove filter">×</button></div>';
  }).join('');
  var dim2opts = [['', 'Nothing — one breakdown']].concat(d.d.filter(function (x) { return x[0] !== S.dim && !(isTimeDim(S.ds, S.dim) && x[2] === 't'); }).map(function (x) { return [x[0], x[1].replace(' (rang up by)', '')]; }));
  $('#panel').innerHTML =
    '<div class="rb-sect"><div class="rb-lbl">Data' + (d.lock ? '<span class="n">' + esc(d.lock) + '</span>' : '') + '</div><select class="rb-sel rb-w" id="pDs">' + opt(Object.keys(DS).map(function (k) { return [k, DS[k].l]; }), S.ds) + '</select><div class="rb-hint" style="margin-top:6px">' + esc(d.note) + '</div>' + (d.cav ? '<ul class="rb-cav">' + d.cav.slice(0, 2).map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ul>' : '') + '</div>' +
    '<div class="rb-sect" id="panelShow"><div class="rb-lbl">Show<span class="n">' + S.measures.length + ' of 5 max</span></div><div class="rb-mlist">' + own + (calcs ? '<div class="rb-otherhead">Calculations</div>' + calcs : '') + '</div>' +
    '<div class="rb-bar" style="margin:8px 0 0"><button class="rb-link" data-cnew>+ Add a calculation</button></div>' +
    (others ? '<details class="rb-more"' + (S.measures.some(function (m) { return m.indexOf(':') > 0 && m.indexOf('calc:') !== 0; }) ? ' open' : '') + '><summary>From other data</summary><div class="rb-mlist" style="max-height:240px">' + others + '</div></details>' : '') + '</div>' +
    '<div class="rb-sect"><div class="rb-lbl">Break down by</div><select class="rb-sel rb-w" id="pDim">' + opt(d.d.map(function (x) { return [x[0], x[1]]; }), S.dim) + '</select>' +
    '<div class="rb-lbl" style="margin-top:12px">Then split by</div><select class="rb-sel rb-w" id="pDim2">' + opt(dim2opts, S.dim2) + '</select>' + (S.dim2 ? '<div class="rb-hint" style="margin-top:6px">A split shows one measure at a time, as a grid.</div>' : '') + '</div>' +
    '<div class="rb-sect" id="panelFilter"><div class="rb-lbl">Only include</div>' + (S.span ? '<div class="rb-bar" style="margin:0 0 8px"><span class="rb-pill info">Only ' + esc(S.span.l) + '</span><button class="rb-rm" data-spanx aria-label="Show the whole date range">×</button></div>' : '') + fr + (d.f.length ? '<button class="rb-link" data-fadd>+ Add a filter</button>' : '<div class="rb-hint">This data has no filters.</div>') + '</div>' +
    '<div class="rb-sect"><div class="rb-lbl">' + (d.snap ? 'Date range <span class="n">for pace measures</span>' : 'Date range') + '</div><select class="rb-sel rb-w" id="pRange">' + opt(RANGES, S.range) + '</select>' +
    '<select class="rb-sel rb-w" id="pCmp" style="margin-top:8px">' + opt([['off', 'Don\'t compare'], ['prev', 'Compare with the period before'], ['yoy', 'Compare with the same period last year']], S.compare) + '</select></div>' +
    '<div class="rb-sect"><div class="rb-lbl">Order and limit</div><div class="rb-2col"><select class="rb-sel" id="pSort">' + opt([['desc', 'Highest first'], ['asc', 'Lowest first']], S.sortDir) + '</select><select class="rb-sel" id="pLimit">' + opt([['0', 'Show all'], ['5', 'Top 5'], ['10', 'Top 10'], ['15', 'Top 15'], ['25', 'Top 25'], ['50', 'Top 50']], String(S.limit)) + '</select></div><div class="rb-hint" style="margin-top:6px">Time breakdowns stay in date order.</div></div>';
}

/* ===================== preview ===================== */
var PAL = ['var(--ia-accent)', 'var(--rb-info)', 'var(--rb-warn)', 'var(--rb-good)', 'var(--rb-bad)', 'var(--ia-text-dim)'];
function renderHeader() {
  $('#readBox').innerHTML = S.read ? '<div class="rb-read"><span class="rb-hint">How I read your request:</span><div class="rb-pillrow">' + S.read.map(function (p) { return '<button class="rb-pill info rb-readpill" data-focus="' + p.f + '">' + esc(p.t) + '</button>'; }).join('') + '</div><div class="rb-hint" style="margin-top:6px">Click any of these to change it.</div></div>' : '';
  $('#whyBanner').innerHTML = S.why ? '<div class="rb-note"><div>' + esc(S.why) + '</div><button class="rb-x" data-why-x aria-label="Dismiss" style="margin-left:auto">×</button></div>' : '';
}
function renderPreview() {
  var tl = S.mode === 'timeline';
  $('#viewBar').style.display = tl ? 'none' : 'flex'; $('#stands').innerHTML = ''; $('#related').innerHTML = '';
  renderHeader(); renderLegend();
  if (!tl && !S.measures.length) { $('#preview').innerHTML = '<div class="rb-empty">Pick at least one thing to show.<div class="rb-ex" style="justify-content:center;margin-top:10px">' + DS[S.ds].m.slice(0, 3).map(function (m) { return '<button class="rb-chip" data-addm="' + m[0] + '">' + esc(m[1]) + '</button>'; }).join('') + '</div></div>'; $('#rowHint').textContent = ''; return; }
  if (tl && !S.tcust) { renderTimeline(null); return; }
  var id = ++REQ;
  $('#preview').classList.add('rb-loading');
  if (!$('#preview').innerHTML) $('#preview').innerHTML = '<div class="rb-empty">Working…</div>';
  post(URLS.data, { cfg: payload(S) }).then(function (res) {
    if (id !== REQ) return;
    $('#preview').classList.remove('rb-loading');
    RES = res;
    if (tl) { renderTimeline(res); return; }
    $$('#outSeg button').forEach(function (b) { b.classList.toggle('on', b.dataset.o === OUT); });
    if (!CM || S.measures.indexOf(CM) < 0) CM = S.measures[0];
    var piv = res.kind === 'pivot';
    $('#chartMeasure').style.display = (piv || OUT !== 'table') ? 'inline-block' : 'none';
    $('#chartMeasure').innerHTML = opt(S.measures.map(function (m) { return [m, mdef(S, m).l]; }), CM);
    if (piv) renderPivot(res); else renderTable(res);
    if (res.notes && res.notes.length) $('#preview').insertAdjacentHTML('beforeend', '<div class="rb-hint" style="margin-top:8px">' + res.notes.map(esc).join(' ') + '</div>');
  }).catch(function (e) {
    if (id !== REQ) return;
    $('#preview').classList.remove('rb-loading');
    $('#preview').innerHTML = '<div class="rb-err">That report couldn\'t run: ' + esc(e) + '<div class="rb-ex" style="justify-content:center;margin-top:10px"><button class="rb-chip" data-retry>Try again</button><button class="rb-chip" data-clearf>Clear the filters</button></div></div>';
  });
}
function rowsOf(res) { return res.rows.map(function (r) { return { k: r.k, label: r.l, vals: r.vals, prev: r.prev }; }); }
function heat(v, mn, mx) { var p = mx > mn ? ((v || 0) - mn) / (mx - mn) : 0; return 'background:color-mix(in srgb,var(--ia-accent) ' + Math.round(4 + p * 34) + '%,transparent)'; }
function renderTable(res) {
  var rows = rowsOf(res), dn = dimName(S.ds, S.dim), cmp = S.compare !== 'off';
  $('#rowHint').textContent = rows.length + ' row' + (rows.length === 1 ? '' : 's') + ' · ' + (DS[S.ds].snap ? 'as of today' : S.range.toLowerCase()) + (res.truncated ? ' · first 5,000 shown' : '');
  if (OUT === 'table' || !rows.length) {
    var h = '<div class="rb-tbl"><table class="ia-table"><thead><tr><th>' + esc(dn) + '</th>';
    S.measures.forEach(function (m) { h += '<th class="ia-num">' + esc(mdef(S, m).l) + '</th>' + (cmp ? '<th class="ia-num dl">' + cmpLabel(S) + '</th>' : ''); });
    h += '</tr></thead><tbody>';
    rows.forEach(function (r) { h += '<tr class="click" data-drill="' + esc(r.k) + '"><td>' + esc(r.label) + '</td>'; S.measures.forEach(function (m) { var t = mdef(S, m).t, v = cellVal(S, m, r.vals); h += '<td class="ia-num">' + fmt(v, t) + '</td>' + (cmp ? '<td class="ia-num dl">' + deltaCell(pct(v, cellVal(S, m, r.prev))) + '</td>' : ''); }); h += '</tr>'; });
    if (!rows.length) h += '<tr><td colspan="11"><div class="rb-empty">Nothing matches these settings.<div class="rb-ex" style="justify-content:center;margin-top:10px">' + (S.filters.length ? '<button class="rb-chip" data-clearf>Clear the filters</button>' : '') + (DS[S.ds].snap ? '' : '<button class="rb-chip" data-range="Last 12 months">Try the last 12 months</button>') + '<button class="rb-chip" data-new>Start over</button></div></div></td></tr>';
    else if (!res.truncated) {
      h += '<tr class="total"><td>' + (res.time || S.measures.every(function (m) { return ADD[mdef(S, m).t]; }) ? 'Total' : 'Total / average') + (S.limit > 0 && rows.length >= S.limit ? ' <span class="rb-dim" style="font-weight:500">(all rows, not just these)</span>' : '') + '</td>';
      S.measures.forEach(function (m) { var t = mdef(S, m).t, tv = cellVal(S, m, res.totals); h += '<td class="ia-num">' + fmt(tv, t) + '</td>' + (cmp ? '<td class="ia-num dl">' + deltaCell(pct(tv, cellVal(S, m, res.prevTotals))) + '</td>' : ''); });
      h += '</tr>';
    }
    $('#preview').innerHTML = h + '</tbody></table></div>';
  } else {
    var t = mdef(S, CM).t, vs = rows.map(function (r) { return cellVal(S, CM, r.vals) || 0; }), mx = Math.max.apply(null, vs.concat([0])) * 1.1 || 1;
    $('#preview').innerHTML = '<div class="rb-chartwrap">' + chartSVG(rows.map(function (r) { return r.label; }), [{ name: mdef(S, CM).l, vals: vs }], t, mx, OUT === 'bar' ? 'bar' : 'line', false) + '</div>';
  }
  renderStands(res, rows); renderRelated();
}
function chartSVG(labels, series, t, mx, kind, stacked) {
  var W = 760, H = 290, L = 54, B = 44, T = 12, R = 12, pw = W - L - R, ph = H - T - B, svg = '<svg viewBox="0 0 ' + W + ' ' + H + '" role="img">';
  if (stacked) { mx = Math.max.apply(null, labels.map(function (_, i) { return series.reduce(function (a, s) { return a + (s.vals[i] || 0); }, 0); })) * 1.1 || 1; }
  for (var g = 0; g <= 4; g++) { var y = T + ph - g * ph / 4; svg += '<line class="gl" x1="' + L + '" x2="' + (W - R) + '" y1="' + y + '" y2="' + y + '"/><text x="' + (L - 8) + '" y="' + (y + 3) + '" text-anchor="end">' + fmt(mx * g / 4, t).replace(/^\$(\d{1,3}),(\d{3})$/, '$$$1k') + '</text>'; }
  var n = Math.max(1, labels.length), step = pw / n;
  if (kind === 'bar') { labels.forEach(function (lb, i) { var base = 0; series.forEach(function (s, si) { var v = Math.max(0, s.vals[i] || 0), bh = v / mx * ph, w = stacked ? step * .64 : step * .64 / series.length, x = L + i * step + step * .18 + (stacked ? 0 : si * w); svg += '<rect x="' + x + '" y="' + (T + ph - bh - (stacked ? base : 0)) + '" width="' + w + '" height="' + bh + '" rx="2" fill="' + PAL[si % 6] + '" opacity=".88"><title>' + esc(lb) + ' · ' + esc(s.name) + ': ' + fmt(s.vals[i], t) + '</title></rect>'; if (stacked) base += bh; }); }); }
  else { series.forEach(function (s, si) { var pts = s.vals.map(function (v, i) { return [L + i * step + step / 2, T + ph - Math.max(0, v || 0) / mx * ph]; }); svg += '<polyline fill="none" stroke="' + PAL[si % 6] + '" stroke-width="2.2" stroke-linejoin="round" points="' + pts.map(function (p) { return p.join(','); }).join(' ') + '"/>' + pts.map(function (p, i) { return '<circle cx="' + p[0] + '" cy="' + p[1] + '" r="3" fill="' + PAL[si % 6] + '"><title>' + esc(labels[i]) + ' · ' + esc(s.name) + ': ' + fmt(s.vals[i], t) + '</title></circle>'; }).join(''); }); }
  var skip = Math.ceil(n / 10); labels.forEach(function (lb, i) { if (i % skip === 0) { var lab = lb.length > 13 ? lb.slice(0, 12) + '…' : lb; svg += '<text x="' + (L + i * step + step / 2) + '" y="' + (H - B + 16) + '" text-anchor="middle">' + esc(lab) + '</text>'; } });
  svg += '</svg>';
  if (series.length > 1) svg += '<div class="rb-lg">' + series.map(function (s, si) { return '<span><i style="background:' + PAL[si % 6] + '"></i>' + esc(s.name) + '</span>'; }).join('') + '</div>';
  return svg;
}
function pivotView(res) {
  var mk = CM, cols = res.cols.map(function (l, j) { return { l: l, j: j }; });
  var colTot = cols.map(function (c) { var v = {}; Object.keys(res.colTotals).forEach(function (k) { v[k] = res.colTotals[k][c.j]; }); return cellVal(S, mk, v); });
  var trimmed = 0, CAP = 5;
  if (cols.length > CAP && !res.timeCols && !ORD[S.dim2]) {
    var keep = cols.map(function (c, j) { return { j: j, t: colTot[j] || 0 }; }).sort(function (a, b) { return b.t - a.t; }).slice(0, CAP).map(function (x) { return x.j; }).sort(function (a, b) { return a - b; });
    trimmed = cols.length - CAP; cols = keep.map(function (j) { return cols[j]; }); colTot = keep.map(function (j) { return colTot[j]; });
  }
  var rows = res.rows.map(function (r) {
    return { k: r.k, label: r.l, cells: cols.map(function (c) { var v = {}; Object.keys(r.cells).forEach(function (k) { v[k] = r.cells[k][c.j]; }); return cellVal(S, mk, v); }), tot: cellVal(S, mk, r.total) };
  });
  return { rows: rows, cols: cols.map(function (c) { return c.l; }), colTot: colTot, grand: cellVal(S, mk, res.grand), trimmed: trimmed };
}
function renderPivot(res) {
  var t = mdef(S, CM).t, pv = pivotView(res), dn = dimName(S.ds, S.dim), dn2 = dimName(S.ds, S.dim2);
  $('#rowHint').textContent = pv.rows.length + ' × ' + pv.cols.length + ' · ' + (DS[S.ds].snap ? 'as of today' : S.range.toLowerCase());
  if (!pv.rows.length) { $('#preview').innerHTML = '<div class="rb-empty">Nothing matches these settings.<div class="rb-ex" style="justify-content:center;margin-top:10px">' + (S.filters.length ? '<button class="rb-chip" data-clearf>Clear the filters</button>' : '') + '<button class="rb-chip" data-nosplit>Remove the split</button></div></div>'; return; }
  var all = []; pv.rows.forEach(function (r) { all = all.concat(r.cells.map(function (v) { return v || 0; })); }); var mn = Math.min.apply(null, all), mx = Math.max.apply(null, all);
  if (OUT === 'table') {
    var h = '<div class="rb-tbl"><table class="ia-table rb-heat"><thead><tr><th>' + esc(dn) + ' ↓ · ' + esc(dn2) + ' →</th>' + pv.cols.map(function (c) { return '<th class="ia-num">' + esc(c) + '</th>'; }).join('') + '<th class="ia-num">Total</th></tr></thead><tbody>';
    pv.rows.forEach(function (r) { h += '<tr class="click" data-drill="' + esc(r.k) + '"><td>' + esc(r.label) + '</td>' + r.cells.map(function (v) { return '<td class="ia-num" style="' + heat(v, mn, mx) + '">' + fmt(v, t) + '</td>'; }).join('') + '<td class="ia-num"><b>' + fmt(r.tot, t) + '</b></td></tr>'; });
    h += '<tr class="total"><td>Total</td>' + pv.colTot.map(function (v) { return '<td class="ia-num">' + fmt(v, t) + '</td>'; }).join('') + '<td class="ia-num">' + fmt(pv.grand, t) + '</td></tr>';
    $('#preview').innerHTML = h + '</tbody></table></div><div class="rb-hint" style="margin-top:8px">Darker cells are higher. Totals are worked out from all the data, not by adding the cells. Click a row to look inside it.' + (pv.trimmed ? ' Showing the 5 largest of ' + (pv.cols.length + pv.trimmed) + ' ' + esc(dn2.toLowerCase()) + ' columns — add a filter to see the rest.' : '') + '</div>';
  } else {
    var labels = pv.rows.map(function (r) { return r.label; }), series = pv.cols.slice(0, 6).map(function (c, j) { return { name: c, vals: pv.rows.map(function (r) { return r.cells[j] || 0; }) }; });
    $('#preview').innerHTML = '<div class="rb-chartwrap">' + chartSVG(labels, series, t, mx * 1.1 || 1, OUT === 'bar' ? 'bar' : 'line', OUT === 'bar' && ADD[t]) + '</div>';
  }
  renderRelated();
}

/* ===================== what stands out, try next ===================== */
var INS = [];
function renderStands(res, rows) {
  INS = []; var mk = S.measures[0]; if (!rows.length || !mk) return;
  var md = mdef(S, mk), add = ADD[md.t] && mk !== 'custs', valOf = function (r) { return cellVal(S, mk, r.vals); };
  var withV = rows.filter(function (r) { return valOf(r) != null; }); if (!withV.length) return;
  var tot = withV.reduce(function (a, r) { return a + valOf(r); }, 0), L = md.l.toLowerCase();
  if (!res.time && withV.length >= 3) {
    var srt = withV.slice().sort(function (a, b) { return valOf(b) - valOf(a); }), top = srt[0], bot = srt[srt.length - 1];
    INS.push({ t: '<b>' + esc(top.label) + '</b> leads on ' + esc(L) + ' at ' + fmt(valOf(top), md.t) + (add && tot > 0 ? ' — ' + Math.round(valOf(top) / tot * 100) + '% of the total' : ''), b: 'Look inside ' + top.label, act: { drill: top.k } });
    if (add && withV.length >= 5 && tot > 0) { var t3 = srt.slice(0, 3).reduce(function (a, r) { return a + valOf(r); }, 0); INS.push({ t: 'The top 3 account for <b>' + Math.round(t3 / tot * 100) + '%</b> of ' + esc(L) + '; the other ' + (withV.length - 3) + ' share the rest', b: 'Show top 3 only', act: { limit: 3 } }); }
    INS.push({ t: '<b>' + esc(bot.label) + '</b> is lowest at ' + fmt(valOf(bot), md.t), b: 'Look inside ' + bot.label, act: { drill: bot.k } });
  }
  if (res.time && withV.length >= 3) {
    var vals = rows.map(function (r) { return valOf(r) || 0; }), a = vals[vals.length - 1], b = vals[vals.length - 2], pc = pct(a, b), mxv = Math.max.apply(null, vals), pk = rows[vals.indexOf(mxv)];
    INS.push({ t: 'The latest ' + dimName(S.ds, S.dim).toLowerCase() + ' is <b>' + fmt(a, md.t) + '</b>' + (pc == null ? '' : ', ' + (pc >= 0 ? 'up ' : 'down ') + Math.abs(pc).toFixed(1) + '% from the one before'), b: S.compare === 'yoy' ? 'Look inside ' + rows[rows.length - 1].label : 'Compare with last year', act: S.compare === 'yoy' ? { drill: rows[rows.length - 1].k } : { compare: 'yoy' } });
    INS.push({ t: 'Peak was <b>' + esc(pk.label) + '</b> at ' + fmt(mxv, md.t), b: 'Look inside ' + pk.label, act: { drill: pk.k } });
  }
  if (S.compare !== 'off' && withV.length >= 2) {
    var ch = withV.map(function (r) { return { r: r, c: pct(valOf(r), cellVal(S, mk, r.prev)) }; }).filter(function (x) { return x.c != null; }).sort(function (x, y) { return Math.abs(y.c) - Math.abs(x.c); })[0];
    if (ch) INS.push({ t: 'Biggest change ' + cmpLabel(S) + ': <b>' + esc(ch.r.label) + '</b> (' + (ch.c >= 0 ? '+' : '') + ch.c.toFixed(1) + '%)', b: 'Look inside ' + ch.r.label, act: { drill: ch.r.k } });
  }
  if (!INS.length) return;
  $('#stands').innerHTML = '<div class="rb-sec" style="margin-top:22px"><div class="rb-h"><h2>What stands out</h2><span class="rb-hint">worked out from the numbers above</span></div>' + INS.slice(0, 4).map(function (x, i) { return '<div class="rb-ins"><span class="dot"></span><span>' + x.t + '</span><button class="ia-btn ia-btn--sm" data-ins="' + i + '">' + esc(x.b) + '</button></div>'; }).join('') + '</div>';
}
var REL = [];
function renderRelated() {
  REL = []; var d = DS[S.ds];
  if (!S.dim2) { var alt = d.d.filter(function (x) { return x[0] !== S.dim && x[2] === 'c' && ['loc', 'staff', 'cat', 'svc', 'vend', 'res', 'tag', 'brand', 'dist'].indexOf(x[0]) > -1; })[0]; if (alt) REL.push({ t: 'Split by ' + alt[1].replace(' (rang up by)', '').toLowerCase(), f: function () { S.dim2 = alt[0]; } }); }
  else REL.push({ t: 'Remove the split', f: function () { S.dim2 = ''; } });
  if (S.compare === 'off' && !d.snap) REL.push({ t: 'Compare with last year', f: function () { S.compare = 'yoy'; } });
  var un = d.m.filter(function (m) { return S.measures.indexOf(m[0]) < 0; })[0]; if (un && S.measures.length < 5) REL.push({ t: 'Add ' + un[1].toLowerCase(), f: function () { S.measures.push(un[0]); } });
  if (S.ds === 'sales' && DS.staff && canSplit(S, 'staff') && !S.calcs.some(function (c) { return c.name === 'Sales per labor hour'; }) && S.measures.length < 5) REL.push({ t: 'Add sales per labor hour', f: function () { var id = 'calc:a' + (calcSeq++); S.calcs.push({ id: id, name: 'Sales per labor hour', f: '[Revenue] / [Hours worked]', t: '$' }); S.measures.push(id); } });
  if (!S.dim2 && S.limit !== 5 && !isTimeDim(S.ds, S.dim)) REL.push({ t: 'Show only the lowest 5', f: function () { S.limit = 5; S.sortDir = 'asc'; } });
  $('#related').innerHTML = REL.length ? '<div class="rb-sec" style="margin-top:22px"><div class="rb-h"><h2>Try next</h2></div><div class="rb-chips">' + REL.slice(0, 5).map(function (r, i) { return '<button class="rb-chip" data-rel="' + i + '">' + esc(r.t) + '</button>'; }).join('') + '</div></div>' : '';
}
function renderLegend() {
  var d = DS[S.ds];
  $('#legend').innerHTML = '<b>How this behaves.</b>' +
    '<div class="row"><span>Read-only:</span><span>reports never change anything in Intake.</span></div>' +
    '<div class="row"><span>Whole shop:</span><span>reports cover every location unless you filter by one.' + (d.lock ? ' ' + esc(d.l) + ' is limited to ' + esc(d.lock.toLowerCase()) + '.' : '') + '</span></div>' +
    (d.cav ? '<div class="row"><span>About this data:</span><span>' + d.cav.map(esc).join(' ') + '</span></div>' : '') +
    '<div class="row"><span>Ask:</span><span>matches the words you type to the fields on this page, in your browser. Nothing you type is sent anywhere, and it only fills in the settings.</span></div>' +
    '<div class="row"><span>Totals:</span><span>are worked out from all the data, not by adding the rows, so a ticket in two categories counts once. Calculations use the same figures as the table.</span></div>' +
    '<div class="row"><span>Size:</span><span>a run shows up to 5,000 rows. Export gives the same rows as a CSV.</span></div>';
}

/* ===================== customer timeline ===================== */
function renderTimeline(res) {
  $('#rowHint').textContent = '';
  if (!res || !res.customer) {
    $('#preview').innerHTML = '<div class="rb-empty">Search for a customer on the left to see everything that happened with them, in order.<div class="rb-ex" style="justify-content:center;margin-top:10px" id="custSugg"></div></div>';
    get(URLS.customers + '?q=').then(function (j) { var box = $('#custSugg'); if (box) box.innerHTML = (j.customers || []).slice(0, 6).map(function (c) { return '<button class="rb-chip" data-pickcust="' + esc(c.id) + '" data-pickname="' + esc(c.name) + '">' + esc(c.name) + '</button>'; }).join(''); });
    return;
  }
  var st = res.stats, ds = function (d) { if (!d) return '—'; var x = new Date(d + 'T12:00:00'); return ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][x.getMonth()] + ' ' + x.getDate() + ', ' + x.getFullYear(); };
  var h = '<div class="rb-strip">' +
    '<div class="rb-fig"><div class="l">Customer since</div><div class="v" style="font-size:20px">' + ds(st.since) + '</div><div class="d">' + esc(res.customer.name) + '</div></div>' +
    '<div class="rb-fig"><div class="l">Lifetime value</div><div class="v">' + fmt(st.ltv, '$') + '</div><div class="d">paid sales, net of refunds</div></div>' +
    '<div class="rb-fig"><div class="l">Visits</div><div class="v">' + st.visits + '</div><div class="d">' + (st.gap ? 'every ' + st.gap + ' days' : '&nbsp;') + '</div></div>' +
    '<div class="rb-fig"><div class="l">Average ticket</div><div class="v">' + fmt(st.avg, '$') + '</div><div class="d">before tax</div></div>' +
    '<div class="rb-fig"><div class="l">Last sale</div><div class="v" style="font-size:20px">' + ds(st.last) + '</div><div class="d">&nbsp;</div></div></div>';
  var yr = null; h += '<div class="rb-tl">';
  res.events.forEach(function (e) {
    var y = e.date.slice(0, 4); if (y !== yr) { yr = y; h += '<div class="yr">' + y + '</div>'; }
    h += '<div class="ev t-' + e.kind + '"><div><div class="d">' + ds(e.date) + '</div><div class="t">' + esc(e.title) + '</div><div class="s">' + esc(e.sub) + '</div></div><div class="r">' + (e.amount != null ? (e.refund ? '<s>' + fmt(e.amount, '$') + '</s>' : fmt(e.amount, '$')) : '') + (e.ltv != null ? '<small>LTV ' + fmt(e.ltv, '$') + '</small>' : '') + '</div></div>';
  });
  h += '</div>';
  if (!res.events.length) h += '<div class="rb-empty">Nothing in this date range for the event types picked.<div class="rb-ex" style="justify-content:center;margin-top:10px"><button class="rb-chip" data-range="All time">Show all time</button></div></div>';
  $('#preview').innerHTML = h;
}
var custTimer = null;
function searchCust(q) {
  clearTimeout(custTimer);
  custTimer = setTimeout(function () {
    get(URLS.customers + '?q=' + encodeURIComponent(q)).then(function (j) {
      var box = $('#custList'); if (!box) return;
      var list = j.customers || [];
      box.innerHTML = list.length ? list.map(function (c) { return '<button type="button" data-pickcust="' + esc(c.id) + '" data-pickname="' + esc(c.name) + '">' + esc(c.name) + (c.email ? '<small>' + esc(c.email) + '</small>' : '') + '</button>'; }).join('') : '<div class="rb-hint" style="padding:8px 10px">No customers match “' + esc(q) + '”.</div>';
      box.style.display = 'block';
    });
  }, 220);
}

/* ===================== drill-down ===================== */
var DRILL = null;
function openDrill(key, dimKey) {
  if (S.mode === 'timeline' || !RES) return;
  var dk = dimKey || S.dim, d = DS[S.ds];
  var row = (RES.rows || []).filter(function (r) { return r.k === key; })[0]; if (!row) return;
  var alts = d.d.filter(function (x) { return x[0] !== dk && x[0] !== S.dim2 && !(isTimeDim(S.ds, dk) && x[2] === 't'); });
  if (!alts.length) { toast('There is nothing else to break this down by.'); return; }
  var next = (DRILL && DRILL.key === key && DRILL.by) || (alts.filter(function (x) { return x[2] === 'c'; })[0] || alts[0])[0];
  var isSpan = isTimeDim(S.ds, dk) && dk !== 'hour';
  var inFilters = isSpan || d.f.some(function (x) { return x[0] === dk; });
  DRILL = { key: key, label: row.l, dim: dk, by: next, inFilters: inFilters, span: isSpan ? { dim: dk, key: key, l: row.l } : null };
  var h = '<div class="rb-drawer-h"><div><h3>' + esc(row.l) + '</h3><div class="rb-hint">' + esc(dimName(S.ds, dk)) + ' · ' + (d.snap ? 'as of today' : S.range.toLowerCase()) + '</div></div><button class="rb-x" data-close>×</button></div><div class="rb-drawer-b">';
  var vals = row.vals || row.total || {};
  S.measures.forEach(function (m) { var md = mdef(S, m), v = cellVal(S, m, vals), dl = row.prev ? pct(v, cellVal(S, m, row.prev)) : null; h += '<div class="rb-kv"><span>' + esc(md.l) + '</span><span>' + fmt(v, md.t) + (S.compare !== 'off' && dl != null ? '<span class="' + (dl >= 0 ? 'd-up' : 'd-dn') + '">' + (dl >= 0 ? '+' : '') + dl.toFixed(1) + '%</span>' : '') + '</span></div>'; });
  h += '<div class="rb-bar" style="margin:22px 0 8px"><div class="rb-lbl" style="margin:0">Break this down by</div><select class="rb-sel" id="drillBy">' + opt(alts.map(function (x) { return [x[0], x[1].replace(' (rang up by)', '')]; }), next) + '</select></div><div id="drillBody">' + (inFilters ? '<div class="rb-empty">Working…</div>' : '') + '</div>';
  if (!inFilters) h += '<div class="rb-empty" style="padding:18px 0">This can\'t be narrowed further here — it isn\'t something you can filter on.<div class="rb-ex" style="justify-content:center;margin-top:10px"><button class="rb-chip" data-drill-new>Open it as its own report</button></div></div>';
  h += '</div><div class="rb-drawer-f"><button class="ia-btn ia-btn--sm" data-close>Close</button><button class="ia-btn ia-btn--sm" data-drill-new>Open as new report</button>' + (inFilters ? '<button class="ia-btn ia-btn--sm ia-btn--primary" data-drill-only>Only show this</button>' : '') + '</div>';
  $('#drawerDrill').innerHTML = h; $('#scrim').classList.add('on'); $('#drawerDrill').classList.add('on');
  if (inFilters) {
    var S2 = JSON.parse(JSON.stringify(S)); S2.dim = next; S2.dim2 = ''; if (DRILL.span) S2.span = DRILL.span; else S2.filters = S2.filters.concat([{ f: dk, op: 'is', v: key }]); S2.compare = 'off'; S2.limit = 0;
    post(URLS.data, { cfg: payload(S2) }).then(function (res) {
      var box = $('#drillBody'); if (!box || !DRILL || DRILL.key !== key) return;
      var m0 = S.measures[0], md0 = mdef(S, m0), rows = rowsOf(res).filter(function (r) { return cellVal(S, m0, r.vals) != null; });
      if (!rows.length) { box.innerHTML = '<div class="rb-empty" style="padding:18px 0">Nothing to show for this one.<div class="rb-ex" style="justify-content:center;margin-top:10px"><button class="rb-chip" data-drill-new>Open it as its own report</button></div></div>'; return; }
      var mx = Math.max.apply(null, rows.map(function (r) { return Math.abs(cellVal(S, m0, r.vals)); })) || 1;
      box.innerHTML = '<div class="rb-hint" style="margin-bottom:6px">' + esc(md0.l) + '</div>' + rows.slice(0, 10).map(function (r) { var v = cellVal(S, m0, r.vals); return '<div class="rb-dr"><span>' + esc(r.label) + '</span><span class="bar"><i style="width:' + Math.max(3, Math.abs(v) / mx * 100) + '%"></i></span><span>' + fmt(v, md0.t) + '</span></div>'; }).join('') + (rows.length > 10 ? '<div class="rb-hint" style="margin-top:6px">and ' + (rows.length - 10) + ' more — open it as its own report to see them all.</div>' : '');
    }).catch(function (e) { var box = $('#drillBody'); if (box) box.innerHTML = '<div class="rb-err">' + esc(e) + '</div>'; });
  }
}

/* ===================== calculations ===================== */
var SUGG_CALCS = [
  { name: 'Sales per labor hour', f: '[Revenue] / [Hours worked]', t: '$' },
  { name: 'Margin per labor hour', f: '[Margin $] / [Hours worked]', t: '$' },
  { name: 'Revenue per appointment', f: '[Revenue] / [Appointments]', t: '$' },
  { name: 'Margin after discounts', f: '[Margin $] - [Discounts given]', t: '$' },
  { name: 'Profit per ticket', f: '[Margin $] / [Tickets]', t: '$' },
  { name: 'Units received less items sold', f: '[Units received] - [Items sold]', t: 'n' },
  { name: 'Received cost per item sold', f: '[Cost received] / [Items sold]', t: '$' },
  { name: 'New customers as a share of all', f: '[New customers] / [Customers] * 100', t: '%' }
].filter(function (c) { return calcTokens(c.f).every(function (l) { return !!labelToKey(l); }); });
function calcAvailability(f) { var toks = calcTokens(f), why = null; toks.forEach(function (l) { var k = labelToKey(l); if (!k) { if (!why) why = '“' + l + '” isn\'t a measure I know.'; return; } var ds = k.split(':')[0]; if (ds !== S.ds && !canSplit(S, ds) && !why) why = l + ' comes from ' + DS[ds].l + ', which can\'t be split by ' + dimName(S.ds, S.dim).toLowerCase() + (S.dim2 ? ' and ' + dimName(S.ds, S.dim2).toLowerCase() : '') + '.'; }); return why; }
var calcPrevTimer = null;
function previewCalc() {
  var f = $('#cF').value.trim(), box = $('#cPrev'); if (!f) { box.innerHTML = '<span class="rb-hint">Type a formula, or pick one below.</span>'; return; }
  var toks = calcTokens(f); if (!toks.length) { box.innerHTML = '<span class="rb-hint">Use measures in [square brackets] — insert one from the list above.</span>'; return; }
  var why = calcAvailability(f); if (why) { box.innerHTML = '<span style="color:var(--rb-bad)">' + esc(why) + '</span>'; return; }
  if (!/^[0-9eE+\-*\/().\s]+$/.test(f.replace(/\[[^\]]+\]/g, '1'))) { box.innerHTML = '<span style="color:var(--rb-bad)">Use only numbers, measures and + − × ÷ ( ).</span>'; return; }
  box.innerHTML = '<span class="rb-hint">Working it out…</span>';
  clearTimeout(calcPrevTimer);
  calcPrevTimer = setTimeout(function () {
    var keys = toks.map(labelToKey).filter(function (k, i, a) { return a.indexOf(k) === i; }).map(function (k) { return normKey(S, k); });
    var S2 = JSON.parse(JSON.stringify(S)); S2.measures = keys.slice(0, 5); S2.calcs = []; S2.dim2 = ''; S2.limit = 0; S2.compare = 'off';
    post(URLS.data, { cfg: payload(S2) }).then(function (res) {
      var v = evalCalcWith(f, function (k) { return res.totals[k]; });
      box.innerHTML = isFinite(v) ? '<span class="rb-hint">Total over ' + esc(DS[S.ds].snap ? 'today' : S.range.toLowerCase()) + ':</span> <b>' + fmt(v, $('#cT').value) + '</b> <span class="rb-hint">· ' + res.rows.length + ' ' + esc(dimName(S.ds, S.dim).toLowerCase()) + (res.rows.length === 1 ? '' : 's') + '</span>' : '<span style="color:var(--rb-bad)">That doesn\'t work out — check the operators and brackets, or a measure that is zero.</span>';
    }).catch(function (e) { box.innerHTML = '<span style="color:var(--rb-bad)">' + esc(e) + '</span>'; });
  }, 300);
}
function openCalc(editId) {
  EDIT_CALC = editId || null; var c = editId ? S.calcs.filter(function (x) { return x.id === editId; })[0] : null;
  $('#cTitle').textContent = c ? 'Edit calculation' : 'Add a calculation'; $('#cSave').textContent = c ? 'Save changes' : 'Add to report';
  $('#cName').value = c ? c.name : ''; $('#cF').value = c ? c.f : ''; $('#cT').value = c ? c.t : '$';
  var opts = []; Object.keys(DS).forEach(function (k) { if (k === S.ds || canSplit(S, k)) DS[k].m.forEach(function (m) { if (ALLM[m[1]] === k + ':' + m[0]) opts.push([m[1], DS[k].l + ' · ' + m[1]]); }); });
  $('#cPick').innerHTML = opt(opts, opts[0] && opts[0][0]);
  $('#cSugg').innerHTML = SUGG_CALCS.concat(MYCALCS).map(function (x, i) { var why = calcAvailability(x.f); return '<button class="rb-chip' + (why ? ' off' : '') + '" data-csugg="' + i + '">' + esc(x.name) + '</button>'; }).join('');
  previewCalc(); $('#scrim').classList.add('on'); $('#mCalc').classList.add('on');
}

/* ===================== share (who can open) ===================== */
function openShare() {
  $('#shName').textContent = $('#rTitle').value;
  var cur = SAVED_ID && savedById(SAVED_ID);
  $$('input[name=vis]').forEach(function (x) { x.checked = x.value === ((cur && cur.vis) || 'me'); x.disabled = !!DS[S.ds].lock && x.value === 'all'; });
  $('#shVisNote').textContent = DS[S.ds].lock ? DS[S.ds].l + ' can only be shared with owner and managers.' : '';
  $('#scrim').classList.add('on'); $('#drawer').classList.add('on');
}
function saveReport(vis) {
  var nm = ($('#rTitle').value || autoTitle()).trim();
  var cur = SAVED_ID && savedById(SAVED_ID);
  var body = { id: cur && (cur.own || ME.manager) ? cur.id : null, name: nm, vis: vis || (cur ? cur.vis : 'me'), cfg: payload(S) };
  return post(URLS.save, body).then(function (j) { SAVED = j.saved; SAVED_ID = j.id; clean(); toast(body.id ? 'Saved' : 'Saved to Your reports'); return j; })
    .catch(function (e) { toast('Couldn\'t save: ' + e); throw e; });
}

/* ===================== open / navigate ===================== */
function view(v) {
  $$('.rb-view').forEach(function (x) { x.classList.toggle('on', x.id === 'v-' + v); });
  if (v === 'list') { renderList(); $('#subTitle').textContent = 'Build your own report, ask for one in plain words, and save it for your team.'; }
  else { $('#headBtns').innerHTML = ''; $('#subTitle').textContent = 'Pick the data, what to show and how to break it down. The preview updates as you go.'; }
  window.scrollTo(0, 0);
}
function openReport(cfg, name, id, read) {
  var ds = cfg.ds && DS[cfg.ds] ? cfg.ds : firstDs();
  S = Object.assign(blank(ds), JSON.parse(JSON.stringify(cfg)));
  S.ds = ds;
  if (!S.tevents.rental && S.tevents.rental !== 0) S.tevents.rental = 1;
  (S.calcs || []).forEach(function (c) { var n = +String(c.id).replace(/\D/g, ''); if (n >= calcSeq) calcSeq = n + 1; });
  SAVED_ID = id || null; OUT = 'table'; CM = null; RES = null;
  if (S.mode === 'timeline') S.ds = DS.customers ? 'customers' : ds;
  S.read = read || null;
  titleAuto = !name; S.title = name || autoTitle();
  $('#rTitle').value = S.title; clean(); $('#askBuild').value = ''; $('#askOutBuild').innerHTML = ''; $('#preview').innerHTML = '';
  renderPanel(); renderPreview(); view('build');
  if (id) post(URLS.opened.replace('__ID__', id), {}).catch(function () {});
}
function applyAsk(txt, target) { $('#' + target).value = txt; runAsk(target); }
function closeAll() { $('#scrim').classList.remove('on'); $$('.rb-drawer').forEach(function (x) { x.classList.remove('on'); }); $$('.rb-modal').forEach(function (x) { x.classList.remove('on'); }); }
function afterChange() { S.read = null; dirty(); if (titleAuto) { S.title = autoTitle(); $('#rTitle').value = S.title; } renderPanel(); renderPreview(); }

/* ===================== events ===================== */
document.addEventListener('click', function (e) {
  var t = e.target.closest('button,a,tr,label[data-why],[data-open]'); if (!t || !t.closest('#rb-app')) return;
  if (t.dataset.why && t.tagName === 'LABEL') { var inp = t.querySelector('input'); if (inp && inp.disabled) { toast(t.dataset.why); e.preventDefault(); } return; }
  if (t.dataset.del) { $('#db-' + t.dataset.del).style.display = 'none'; $('#dc-' + t.dataset.del).classList.add('on'); e.stopPropagation(); return; }
  if (t.dataset.delno) { $('#dc-' + t.dataset.delno).classList.remove('on'); $('#db-' + t.dataset.delno).style.display = ''; e.stopPropagation(); return; }
  if (t.dataset.delyes) { e.stopPropagation(); post(URLS['delete'].replace('__ID__', t.dataset.delyes), {}).then(function (j) { SAVED = j.saved; renderList(); toast('Report deleted'); }).catch(function (er) { toast(String(er)); }); return; }
  if (t.dataset.open != null && t.closest('#v-list')) { if (t.disabled) return; var r = savedById(t.dataset.open); if (r && DS[r.ds]) openReport(Object.assign({ ds: r.ds }, r.cfg), r.name, r.id); return; }
  if (t.dataset.tpl != null) { var tp = TPL[+t.dataset.tpl]; openReport(Object.assign({}, tp[3]), null, null); S.title = tp[0]; $('#rTitle').value = tp[0]; return; }
  if (t.id === 'tplMore') { tplAll = !tplAll; renderList(); return; }
  if (t.dataset.new != null) { var nds = firstDs(); openReport({ ds: nds, measures: [DS[nds].m[0][0]], dim: isTimeDim(nds, 'month') ? 'month' : DS[nds].d[0][0], range: 'Last 12 months' }, null, null); return; }
  if (t.dataset.clearq != null) { $('#listQ').value = ''; renderList(); return; }
  if (t.dataset.ask) { runAsk(t.dataset.ask); return; }
  if (t.dataset.ex != null) { applyAsk(EXAMPLES[+t.dataset.ex], t.dataset.target); return; }
  if (t.id === 'backLink') { if (dirtyOn && !confirmLeave) { confirmLeave = true; toast('You have unsaved changes. Press All reports again to leave without saving.'); setTimeout(function () { confirmLeave = false; }, 4000); return; } confirmLeave = false; view('list'); return; }
  if (t.closest('#listSeg') && t.dataset.f) { listF = t.dataset.f; $$('#listSeg button').forEach(function (b) { b.classList.toggle('on', b === t); }); renderList(); return; }
  if (t.closest('#outSeg') && t.dataset.o) { OUT = t.dataset.o; if (RES) { $$('#outSeg button').forEach(function (b) { b.classList.toggle('on', b.dataset.o === OUT); }); $('#chartMeasure').style.display = (RES.kind === 'pivot' || OUT !== 'table') ? 'inline-block' : 'none'; if (RES.kind === 'pivot') renderPivot(RES); else renderTable(RES); } return; }
  if (t.dataset.ev) { S.tevents[t.dataset.ev] = S.tevents[t.dataset.ev] ? 0 : 1; dirty(); renderPanel(); renderPreview(); return; }
  if (t.dataset.pickcust) { S.tcust = t.dataset.pickcust; S.tcustName = t.dataset.pickname; afterChange(); return; }
  if (t.dataset.fadd != null) { S.filters.push({ f: DS[S.ds].f[0][0], op: 'is', v: '' }); dirty(); renderPanel(); return; }
  if (t.dataset.frm != null) { S.filters.splice(+t.dataset.frm, 1); afterChange(); return; }
  if (t.dataset.clearf != null) { S.filters = []; afterChange(); return; }
  if (t.dataset.retry != null) { renderPreview(); return; }
  if (t.dataset.nosplit != null) { S.dim2 = ''; afterChange(); return; }
  if (t.dataset.range) { S.range = t.dataset.range; afterChange(); return; }
  if (t.dataset.addm) { S.measures.push(t.dataset.addm); afterChange(); return; }
  if (t.dataset.whyX != null) { S.why = ''; renderHeader(); return; }
  if (t.dataset.focus) { var fe = document.getElementById(t.dataset.focus); if (fe) { fe.scrollIntoView({ block: 'center', behavior: 'smooth' }); if (fe.focus) fe.focus(); fe.classList.add('rb-flash'); setTimeout(function () { fe.classList.remove('rb-flash'); }, 1400); } return; }
  if (t.dataset.drill != null && t.tagName === 'TR') { openDrill(t.dataset.drill); return; }
  if (t.dataset.ins != null) { var x = INS[+t.dataset.ins].act; if (x.drill) { openDrill(x.drill); return; } if (x.limit) { S.limit = x.limit; S.sortDir = 'desc'; } if (x.compare) S.compare = x.compare; afterChange(); return; }
  if (t.dataset.rel != null) { REL[+t.dataset.rel].f(); afterChange(); return; }
  if (t.dataset.drillOnly != null) { if (DRILL.span) S.span = DRILL.span; else S.filters.push({ f: DRILL.dim, op: 'is', v: DRILL.key }); closeAll(); afterChange(); toast('Showing only ' + DRILL.label); return; }
  if (t.dataset.spanx != null) { S.span = null; afterChange(); return; }
  if (t.dataset.drillNew != null) { var ns = JSON.parse(JSON.stringify(S)); if (DRILL.span) ns.span = DRILL.span; else ns.filters = ns.filters.concat(DRILL && DRILL.inFilters ? [{ f: DRILL.dim, op: 'is', v: DRILL.key }] : []); ns.dim = DRILL.by; ns.dim2 = ''; ns.title = ''; ns.why = 'Opened from “' + DRILL.label + '”.'; closeAll(); openReport(ns, null, null); dirty(); return; }
  if (t.dataset.cnew != null) { openCalc(null); return; }
  if (t.dataset.cedit) { e.preventDefault(); openCalc(t.dataset.cedit); return; }
  if (t.id === 'cIns') { var inp2 = $('#cF'), tok = '[' + $('#cPick').value + ']'; var pos = inp2.selectionStart == null ? inp2.value.length : inp2.selectionStart; inp2.value = inp2.value.slice(0, pos) + tok + inp2.value.slice(pos); previewCalc(); return; }
  if (t.closest('#cOps') && t.dataset.op) { $('#cF').value += t.dataset.op; previewCalc(); return; }
  if (t.dataset.csugg != null) { var sc = SUGG_CALCS.concat(MYCALCS)[+t.dataset.csugg], why = calcAvailability(sc.f); if (why) { toast(why + ' Change the breakdown, or pick another suggestion.'); return; } $('#cName').value = sc.name; $('#cF').value = sc.f; $('#cT').value = sc.t; previewCalc(); return; }
  if (t.id === 'cSave') {
    var nm = $('#cName').value.trim(), f = $('#cF').value.trim(); if (!nm) { toast('Give the calculation a name first.'); $('#cName').focus(); return; }
    var why2 = calcAvailability(f); if (!f || why2 || !calcTokens(f).length) { toast(why2 || 'Add at least one measure in [square brackets].'); return; }
    if (!/^[0-9eE+\-*\/().\s]+$/.test(f.replace(/\[[^\]]+\]/g, '1'))) { toast('Use only numbers, measures and + − × ÷ ( ).'); return; }
    if (EDIT_CALC) { var cc = S.calcs.filter(function (x) { return x.id === EDIT_CALC; })[0]; cc.name = nm; cc.f = f; cc.t = $('#cT').value; }
    else { var id = 'calc:a' + (calcSeq++); S.calcs.push({ id: id, name: nm, f: f, t: $('#cT').value }); if (S.measures.length < 5) S.measures.push(id); else toast('Added, but untick something to show it — 5 is the most.'); }
    if ($('#cLib').checked && !MYCALCS.some(function (x) { return x.f === f; })) post(URLS.calcs, { name: nm, f: f, t: $('#cT').value }).then(function (j) { MYCALCS = j.calcs; }).catch(function () {});
    closeAll(); afterChange(); return;
  }
  if (t.id === 'btnSave') { saveReport(null).catch(function () {}); return; }
  if (t.id === 'btnExport') { var url = URLS.export + '?cfg=' + encodeURIComponent(btoa(unescape(encodeURIComponent(JSON.stringify(payload(S)))))) + '&name=' + encodeURIComponent($('#rTitle').value || 'report'); window.location.href = url; toast('Your CSV is downloading'); return; }
  if (t.id === 'btnShare') { openShare(); return; }
  if (t.id === 'shSave') { var v = ($('input[name=vis]:checked') || {}).value || 'me'; saveReport(v).then(function () { closeAll(); toast('Saved — ' + VIS[v].toLowerCase() + ' can open it'); }).catch(function () {}); return; }
  if (t.dataset.close !== undefined || t.id === 'scrim') { closeAll(); return; }
});
var confirmLeave = false;
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeAll(); if (e.key === 'Enter' && e.target.classList && e.target.classList.contains('rb-askin')) { e.preventDefault(); runAsk(e.target.id); } });
document.addEventListener('input', function (e) {
  if (e.target.id === 'cF' || e.target.id === 'cT') previewCalc();
  if (e.target.id === 'pCust') { var q = e.target.value.trim(); if (q.length >= 2) searchCust(q); else { var b = $('#custList'); if (b) b.style.display = 'none'; } }
});
document.addEventListener('change', function (e) {
  var t = e.target; if (!t.closest || !t.closest('#rb-app')) return;
  if (t.id === 'drillBy') { DRILL.by = t.value; openDrill(DRILL.key, DRILL.dim); return; }
  if (t.id === 'cT') { previewCalc(); return; }
  if (t.id === 'pDs') { var keepTl = false; S = blank(t.value); titleAuto = true; CM = null; RES = null; $('#preview').innerHTML = ''; dirty(); $('#rTitle').value = autoTitle(); renderPanel(); renderPreview(); return; }
  if (t.name === 'vis') return;
  if (t.dataset.m) { var i = S.measures.indexOf(t.dataset.m); if (t.checked && i < 0 && S.measures.length < 5) S.measures.push(t.dataset.m); if (!t.checked && i > -1) S.measures.splice(i, 1); }
  else if (t.id === 'pDim') { S.dim = t.value; if (S.dim2 === S.dim) S.dim2 = ''; if (S.dim2 && isTimeDim(S.ds, S.dim) && isTimeDim(S.ds, S.dim2)) S.dim2 = ''; S.measures = S.measures.filter(function (m) { var k = dsOf(S, m); return k === S.ds || canSplit(S, k); }); }
  else if (t.id === 'pDim2') { S.dim2 = t.value; S.measures = S.measures.filter(function (m) { var k = dsOf(S, m); return k === S.ds || canSplit(S, k); }); if (S.dim2) CM = CM || S.measures[0]; }
  else if (t.id === 'pRange') { S.range = t.value; }
  else if (t.id === 'pCmp') { S.compare = t.value; }
  else if (t.id === 'pSort') { S.sortDir = t.value; }
  else if (t.id === 'pLimit') { S.limit = +t.value; }
  else if (t.id === 'chartMeasure') { CM = t.value; if (RES) { if (RES.kind === 'pivot') renderPivot(RES); else renderTable(RES); } return; }
  else if (t.dataset.fk != null) { var f = S.filters[+t.dataset.fk]; f.f = t.value; f.v = ''; dirty(); renderPanel(); return; }
  else if (t.dataset.fo != null) { S.filters[+t.dataset.fo].op = t.value; if (!S.filters[+t.dataset.fo].v) { dirty(); return; } }
  else if (t.dataset.fv != null) { S.filters[+t.dataset.fv].v = t.value; }
  else return;
  afterChange();
});
$('#rTitle').addEventListener('input', function () { titleAuto = false; dirty(); });
$('#listQ').addEventListener('input', renderList);
$('#listSort').addEventListener('change', function () { listSort = this.value; renderList(); });
renderList();
})();
