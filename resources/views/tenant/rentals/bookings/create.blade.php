@extends('layouts.tenant.app')
@php $pageTitle = 'New Rental'; @endphp

{{-- MARKER-RENTAL-NEW-STUDIO: desk reservation flow in the Studio design.
     customer -> window -> what's free, with a live "this rental" rail.
     Same endpoints and payload as before (customers.search, rentals.availability, bookings.store). --}}

@section('mobile-fab', 'new-rental')
@include('layouts.tenant._rental-styles')

@section('content')
@php
  $oldCust = old('customer_id')
    ? \App\Models\Tenant\TenantCustomer::where('tenant_id', tenant()->id)->where('id', old('customer_id'))->first()
    : null;
@endphp
<div class="sd rf" id="sd">
  <header class="sd-head" id="sdHead">
    <div class="sd-head-l">
      <h1 class="sd-hi">New rental</h1>
      <p class="sd-sub">Pick the customer, set the window, choose what's free.</p>
    </div>
    <div class="sd-head-r">
      <a href="{{ route('tenant.rentals.bookings.index') }}" class="ia-btn ia-btn--sm">Back to bookings</a>
    </div>
  </header>

  @include('layouts.tenant._rental-nav', ['active' => 'bookings'])

  @if($errors->any())<div class="ia-flash ia-flash--error" style="margin-bottom:16px">{{ $errors->first() }}</div>@endif

  <form method="POST" action="{{ route('tenant.rentals.bookings.store') }}" id="nr-form" autocomplete="off">
    @csrf
    <input type="hidden" name="customer_id" id="nr-customer-id" value="{{ old('customer_id') }}">

    <div class="dash-row">
      <div class="dash-col" style="grid-column:span 8">

        <div class="rf-blk sd-in">
          <div class="sd-sec-h"><div><h2>Customer</h2><span>Search by name, phone or email</span></div></div>
          <div class="rf-cust" id="nrCust">
            <div id="nr-cust-picked" class="rf-picked-c" hidden>
              <div class="sd-av" id="nr-cust-av">?</div>
              <div><b id="nr-cust-label"></b><small id="nr-cust-sub"></small></div>
              <button type="button" class="ia-btn ia-btn--sm" id="nr-cust-clear">Change</button>
            </div>
            <div id="nr-cust-search-wrap">
              <div class="rf-searchbox">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input class="rf-bare" id="nr-cust-search" type="text" placeholder="Start typing a name…" aria-label="Search customers" autocomplete="off">
              </div>
              <div class="rf-drop" id="nr-cust-results" hidden></div>
              <div class="rf-newc" id="nr-newc" {{ old('first_name') || old('email') ? '' : 'hidden' }}>
                <p class="rf-fine" style="text-align:left;margin:12px 0 8px">New customer — a record is created with the rental.</p>
                <div class="rf-newc-g">
                  <input type="text" name="first_name" value="{{ old('first_name') }}" maxlength="120" placeholder="First name" class="ia-input" aria-label="First name">
                  <input type="text" name="last_name" value="{{ old('last_name') }}" maxlength="120" placeholder="Last name" class="ia-input" aria-label="Last name">
                  <input type="email" name="email" value="{{ old('email') }}" maxlength="190" placeholder="Email" class="ia-input" aria-label="Email">
                  <input type="text" name="phone" value="{{ old('phone') }}" maxlength="40" placeholder="Phone" class="ia-input" aria-label="Phone">
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="rf-blk sd-in" style="--d:80ms">
          <div class="sd-sec-h"><div><h2>Window</h2><span>When it goes out and when it's due back</span></div>
            <div class="sd-seg" id="nrQuick" role="group" aria-label="Quick window">
              <button type="button" data-h="2">2 hours</button>
              <button type="button" data-h="4">Half day</button>
              <button type="button" data-h="8">Day</button>
              <button type="button" data-h="weekend">Weekend</button>
              <button type="button" data-h="week">Week</button>
            </div>
          </div>
          <div class="rf-win">
            <label><span class="rf-lbl">Goes out</span><input type="datetime-local" name="starts_at" id="nr-starts" value="{{ old('starts_at') }}" required class="ia-input"></label>
            <i>→</i>
            <label><span class="rf-lbl">Due back</span><input type="datetime-local" name="due_at" id="nr-due" value="{{ old('due_at') }}" required class="ia-input"></label>
            <span class="rf-win-len" id="nrLen"></span>
          </div>
        </div>

        <div class="rf-blk sd-in" style="--d:160ms">
          <div class="sd-sec-h"><div><h2>What's free</h2><span id="nrFreeSub">For the window above</span></div>
            <div class="rf-pills" id="nrCats"></div>
          </div>
          <div id="nrFree"></div>
        </div>
      </div>

      <div class="dash-col" style="grid-column:span 4">
        <aside class="sd-rail rf-rail sd-in" style="--d:120ms">
          <div class="rf-rh"><b>This rental</b><span id="nrCount">Nothing picked yet</span></div>
          <div id="nrPicked" class="rf-picked"></div>
          <dl class="rf-tot" id="nrTot" hidden></dl>
          <label class="rf-note"><span class="rf-lbl">Notes (optional)</span>
            <textarea name="notes" rows="2" maxlength="2000" class="ia-input" placeholder="Anything the counter should know">{{ old('notes') }}</textarea>
          </label>
          <button type="submit" class="ia-btn ia-btn--primary rf-wide" id="nr-submit" disabled>Create reservation</button>
          <p class="rf-fine" id="nrWhy">Pick a customer and at least one unit to create it.</p>
        </aside>
      </div>
    </div>
    <div id="nr-hidden"></div>
  </form>

  <p class="sd-legend">What's free is checked against every booking and lease for the window you set, including units in maintenance. Change the window and the list refreshes; anything you'd picked that's no longer free is taken off with a note. Availability is checked again when you create the reservation.</p>
</div>
@endsection

@push('scripts')
<script>
(function () {
  'use strict';
  var searchUrl = '{{ route('tenant.customers.search') }}';
  var availUrl  = '{{ route('tenant.rentals.availability') }}';
  var leases    = {{ tenant()->leases_enabled ? 'true' : 'false' }};
  var MODE_LABEL = { hourly: 'Hourly', daily: 'Daily', weekend: 'Weekend', seasonal: 'Season' };

  function $(s, e) { return (e || document).querySelector(s); }
  function $$(s, e) { return Array.prototype.slice.call((e || document).querySelectorAll(s)); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function fmt(c) { return '$' + (c / 100).toFixed(2); }
  function ini(n) { var w = String(n || '').trim().split(/\s+/).filter(Boolean); return ((w[0] || '?').charAt(0) + (w[1] ? w[1].charAt(0) : '')).toUpperCase(); }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function toLocal(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes()); }

  // ---- reveal + header frost (same tiny helper every Studio page carries)
  var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (!reduce) document.documentElement.classList.add('sd-motion');
  function reveal() { var l = innerHeight * 0.92; $$('.sd-in:not(.is-in)').forEach(function (el) { if (el.getBoundingClientRect().top < l) el.classList.add('is-in'); }); }
  reveal(); addEventListener('scroll', reveal, { passive: true }); addEventListener('resize', reveal);
  setTimeout(function () { $$('.sd-in').forEach(function (el) { el.classList.add('is-in'); }); }, 2500);
  var head = document.getElementById('sdHead');
  function frost() { if (head) head.classList.toggle('frost', scrollY > 24 && innerWidth > 1023); }
  addEventListener('scroll', frost, { passive: true }); frost();

  // ---- state
  var st = { free: [], picked: [], cat: '', loaded: false, note: '' };
  var startsEl = $('#nr-starts'), dueEl = $('#nr-due');

  // ================= customer
  var searchInput = $('#nr-cust-search'), dropEl = $('#nr-cust-results'), pickedEl = $('#nr-cust-picked');
  var searchWrap = $('#nr-cust-search-wrap'), idEl = $('#nr-customer-id'), newc = $('#nr-newc'), tmr = null;

  function hasNewCustomer() {
    var f = $('input[name=first_name]'), e = $('input[name=email]');
    return !newc.hidden && f.value.trim() !== '' && e.value.trim() !== '';
  }
  function setCustomer(id, label, sub) {
    idEl.value = id || '';
    if (id) {
      $('#nr-cust-label').textContent = label; $('#nr-cust-sub').textContent = sub || '';
      $('#nr-cust-av').textContent = ini(label);
      pickedEl.hidden = false; searchWrap.hidden = true;
    } else {
      pickedEl.hidden = true; searchWrap.hidden = false;
    }
    refresh();
  }
  searchInput.addEventListener('input', function () {
    clearTimeout(tmr);
    var q = searchInput.value.trim();
    if (q.length < 2) { dropEl.hidden = true; dropEl.innerHTML = ''; return; }
    tmr = setTimeout(function () {
      fetch(searchUrl + '?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          var rows = (j.customers || []).map(function (c) {
            var label = c.label || c.email || '(no name)', sub = [c.email, c.phone].filter(Boolean).join(' · ');
            return '<button type="button" data-id="' + esc(c.id) + '" data-label="' + esc(label) + '" data-sub="' + esc(sub) + '"><div class="sd-av rd-g-out">' + esc(ini(label)) + '</div><div><b>' + esc(label) + '</b><small>' + esc(sub) + '</small></div></button>';
          }).join('');
          dropEl.innerHTML = rows + '<button type="button" class="new" data-new="' + esc(q) + '">+ New customer “' + esc(q) + '”</button>';
          dropEl.hidden = false;
        })
        .catch(function () { dropEl.hidden = true; });
    }, 250);
  });
  dropEl.addEventListener('click', function (e) {
    var b = e.target.closest('button'); if (!b) return;
    if (b.hasAttribute('data-new')) {
      var q = b.getAttribute('data-new'), parts = q.split(/\s+/);
      if (q.indexOf('@') > -1) { $('input[name=email]').value = q; }
      else if (/^[\d\s()+.-]{6,}$/.test(q)) { $('input[name=phone]').value = q; }
      else { $('input[name=first_name]').value = parts.shift() || ''; $('input[name=last_name]').value = parts.join(' '); }
      newc.hidden = false; dropEl.hidden = true; searchInput.value = '';
      var first = $('input[name=first_name]'); (first.value ? $('input[name=email]') : first).focus();
      refresh(); return;
    }
    setCustomer(b.getAttribute('data-id'), b.getAttribute('data-label'), b.getAttribute('data-sub'));
    dropEl.hidden = true; searchInput.value = '';
  });
  document.addEventListener('click', function (e) { if (!e.target.closest('#nrCust')) dropEl.hidden = true; });
  $('#nr-cust-clear').addEventListener('click', function () { setCustomer('', ''); searchInput.focus(); });
  $$('input[name=first_name],input[name=email]').forEach(function (i) { i.addEventListener('input', refresh); });

  // ================= window
  var params = new URLSearchParams(location.search);
  var qsStarts = params.get('starts'), qsDue = params.get('due'), qsUnit = params.get('unit');
  if (qsStarts) startsEl.value = qsStarts;
  if (qsDue) dueEl.value = qsDue;
  if (!startsEl.value) {
    var s0 = new Date(); s0.setMinutes(0, 0, 0); s0.setHours(s0.getHours() + 1);
    startsEl.value = toLocal(s0);
    dueEl.value = toLocal(new Date(s0.getTime() + 8 * 3600 * 1000));
  }
  if (!dueEl.value) dueEl.value = toLocal(new Date(new Date(startsEl.value).getTime() + 8 * 3600 * 1000));

  function minutes() { var ms = new Date(dueEl.value) - new Date(startsEl.value); return isFinite(ms) ? Math.max(0, ms / 60000) : 0; }
  function lenText() {
    var m = minutes();
    if (!m) return 'Set a start and a due time.';
    if (m < 90) return Math.round(m) + ' minutes';
    if (m < 2880) { var h = Math.round(m / 6) / 10; return (h % 1 ? h.toFixed(1) : h) + ' hours'; }
    var d = Math.round(m / 144) / 10; return (d % 1 ? d.toFixed(1) : d) + ' days';
  }
  function syncQuick() {
    var m = minutes(), hit = null;
    if (m === 120) hit = '2'; else if (m === 240) hit = '4'; else if (m === 480) hit = '8'; else if (m === 7 * 1440) hit = 'week';
    var s = new Date(startsEl.value), d = new Date(dueEl.value);
    if (isFinite(s) && isFinite(d) && s.getDay() === 6 && s.getHours() === 9 && m === 32 * 60) hit = 'weekend';
    $$('#nrQuick button').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-h') === hit); });
    $('#nrLen').textContent = lenText();
  }
  $('#nrQuick').addEventListener('click', function (e) {
    var b = e.target.closest('button'); if (!b) return;
    var h = b.getAttribute('data-h'), s = new Date(startsEl.value);
    if (!isFinite(s)) { s = new Date(); s.setMinutes(0, 0, 0); s.setHours(s.getHours() + 1); }
    var d;
    if (h === 'weekend') {
      s = new Date(s); s.setHours(9, 0, 0, 0);
      var add = (6 - s.getDay() + 7) % 7; if (s.getDay() === 0) add = 6;
      s.setDate(s.getDate() + add);
      d = new Date(s.getTime() + 32 * 3600 * 1000); // Sat 9:00 -> Sun 17:00
    } else if (h === 'week') d = new Date(s.getTime() + 7 * 86400000);
    else d = new Date(s.getTime() + parseInt(h, 10) * 3600000);
    startsEl.value = toLocal(s); dueEl.value = toLocal(d);
    windowChanged();
  });
  var wt = null;
  function windowChanged() { syncQuick(); clearTimeout(wt); wt = setTimeout(loadFree, 350); }
  startsEl.addEventListener('change', windowChanged); dueEl.addEventListener('change', windowChanged);

  // ================= pricing
  function modesOf(u) {
    var m = [];
    if (u.hourly_rate_cents != null)  m.push(['hourly',  u.hourly_rate_cents]);
    if (u.daily_rate_cents != null)   m.push(['daily',   u.daily_rate_cents]);
    if (u.weekend_rate_cents != null) m.push(['weekend', u.weekend_rate_cents]);
    if (leases && u.seasonal_rate_cents != null) m.push(['seasonal', u.seasonal_rate_cents]);
    return m;
  }
  function unitsFor(mode) {
    var m = minutes();
    if (mode === 'hourly') return Math.max(1, Math.ceil(m / 60));
    if (mode === 'daily')  return Math.max(1, Math.ceil(m / 1440));
    return 1; // weekend and seasonal are flat
  }
  function rateOf(u, mode) { var f = modesOf(u).filter(function (x) { return x[0] === mode; })[0]; return f ? f[1] : 0; }
  function lineOf(u, mode) { return rateOf(u, mode) * unitsFor(mode); }
  function defaultMode(u) {
    var ms = modesOf(u).map(function (x) { return x[0]; });
    if (!ms.length) return '';
    var m = minutes(), pref = m <= 240 ? ['hourly', 'daily', 'weekend'] : m >= 1440 * 2 && ms.indexOf('weekend') > -1 && new Date(startsEl.value).getDay() === 6 && m <= 1440 * 3 ? ['weekend', 'daily', 'hourly'] : ['daily', 'weekend', 'hourly'];
    for (var i = 0; i < pref.length; i++) if (ms.indexOf(pref[i]) > -1) return pref[i];
    return ms[0];
  }
  function bookable(u) { return modesOf(u).length > 0; }

  // ================= what's free
  var freeEl = $('#nrFree'), catsEl = $('#nrCats');
  function loadFree() {
    if (!startsEl.value || !dueEl.value) return;
    if (minutes() <= 0) { freeEl.innerHTML = '<div class="sd-empty">Due back has to be after the start.</div>'; $('#nrFreeSub').textContent = 'For the window above'; return; }
    freeEl.innerHTML = '<div class="sd-empty">Checking what\'s free…</div>';
    var qs = '?starts_at=' + encodeURIComponent(startsEl.value) + '&due_at=' + encodeURIComponent(dueEl.value);
    fetch(availUrl + qs, { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, json: j }; }); })
      .then(function (res) {
        if (!res.ok) { freeEl.innerHTML = '<div class="sd-empty">' + esc(res.json.message || 'Could not check availability.') + '</div>'; return; }
        st.free = (res.json.units || []).filter(bookable);
        st.loaded = true;
        // drop anything picked that is no longer free; keep the rest, re-priced
        var ids = st.free.map(function (u) { return u.id; }), dropped = [];
        st.picked = st.picked.filter(function (p) { if (ids.indexOf(p.id) > -1) return true; dropped.push(p.name); return false; });
        st.picked.forEach(function (p) {
          var u = byId(p.id); if (!u) return;
          if (!modesOf(u).some(function (x) { return x[0] === p.mode; }) || !p.touched) p.mode = defaultMode(u);
        });
        st.note = dropped.length ? dropped.join(', ') + (dropped.length > 1 ? ' are' : ' is') + ' no longer free for that window and ' + (dropped.length > 1 ? 'were' : 'was') + ' taken off.' : '';
        render();
        if (qsUnit && !loadFree.done) { loadFree.done = true; var u = byId(qsUnit); if (u) pick(u.id); }
      })
      .catch(function () { freeEl.innerHTML = '<div class="sd-empty">Could not check availability.</div>'; });
  }
  function byId(id) { return st.free.filter(function (u) { return u.id === id; })[0]; }
  function pickedIdx(id) { for (var i = 0; i < st.picked.length; i++) if (st.picked[i].id === id) return i; return -1; }
  function pick(id) {
    var u = byId(id); if (!u || pickedIdx(id) > -1) return;
    st.picked.push({ id: id, name: u.name, mode: defaultMode(u), touched: false });
    render();
  }

  catsEl.addEventListener('click', function (e) { var b = e.target.closest('[data-nc]'); if (!b) return; st.cat = b.getAttribute('data-nc'); render(); });
  freeEl.addEventListener('click', function (e) {
    var b = e.target.closest('[data-pick]'); if (b) { var id = b.getAttribute('data-pick'); var i = pickedIdx(id); if (i > -1) st.picked.splice(i, 1), render(); else pick(id); return; }
    if (e.target.closest('[data-nc]')) { st.cat = ''; render(); }
  });
  $('#nrPicked').addEventListener('click', function (e) {
    var x = e.target.closest('[data-unpick]'); if (!x) return;
    st.picked.splice(parseInt(x.getAttribute('data-unpick'), 10), 1); render();
  });
  $('#nrPicked').addEventListener('change', function (e) {
    var sel = e.target.closest('select[data-mode]'); if (!sel) return;
    var p = st.picked[parseInt(sel.getAttribute('data-mode'), 10)]; p.mode = sel.value; p.touched = true; render();
  });

  function render() {
    // category pills come from whatever is free right now
    var cats = []; st.free.forEach(function (u) { var c = u.category || 'Other'; if (cats.indexOf(c) < 0) cats.push(c); });
    if (st.cat && cats.indexOf(st.cat) < 0) st.cat = '';
    catsEl.innerHTML = cats.length > 1 ? '<button type="button" class="rf-pill ' + (!st.cat ? 'on' : '') + '" data-nc="">All</button>' + cats.map(function (c) { return '<button type="button" class="rf-pill ' + (st.cat === c ? 'on' : '') + '" data-nc="' + esc(c) + '">' + esc(c) + '</button>'; }).join('') : '';

    var h = '', by = {};
    st.free.forEach(function (u) { var c = u.category || 'Other'; (by[c] = by[c] || []).push(u); });
    Object.keys(by).forEach(function (c) {
      if (st.cat && st.cat !== c) return;
      h += '<div class="rf-free-cat">' + esc(c) + '</div>';
      by[c].forEach(function (u) {
        var inn = pickedIdx(u.id) > -1, dm = defaultMode(u), meta = [u.identifier, u.size ? 'Size ' + u.size : ''].filter(Boolean).join(' · ');
        h += '<div class="rf-free' + (inn ? ' in' : '') + '"><div class="rf-thumb">' + esc(ini(u.name)) + '</div>' +
          '<div><b>' + esc(u.name) + '</b><small>' + esc(MODE_LABEL[dm] + ' · ' + fmt(rateOf(u, dm))) + '</small></div>' +
          '<span class="rf-mono">' + esc(u.identifier || '') + '</span><span>' + esc(u.size || '') + '</span>' +
          '<span class="amt">' + fmt(lineOf(u, dm)) + '</span>' +
          '<button type="button" class="ia-btn ia-btn--sm' + (inn ? '' : ' ia-btn--primary') + '" data-pick="' + esc(u.id) + '">' + (inn ? 'Added ✓' : 'Add') + '</button></div>';
      });
    });
    if (!st.loaded) h = '<div class="sd-empty">Checking what\'s free…</div>';
    else if (!h) h = '<div class="sd-empty">' + (st.free.length ? 'Nothing in this category is free for that window.<div class="sd-empty-acts"><button type="button" class="ia-btn ia-btn--sm" data-nc="">Show every category</button></div>' : 'Nothing is free for that window.<div class="sd-empty-acts"><a class="ia-btn ia-btn--sm" href="{{ route('tenant.rentals.availability.timeline') }}">See availability</a></div>') + '</div>';
    freeEl.innerHTML = h;
    $('#nrFreeSub').textContent = st.note || (st.loaded ? st.free.length + ' unit' + (st.free.length === 1 ? '' : 's') + ' free for the window above' : 'For the window above');

    // rail
    var sub = 0, hid = '';
    $('#nrPicked').innerHTML = st.picked.length ? st.picked.map(function (p, i) {
      var u = byId(p.id), line = lineOf(u, p.mode); sub += line;
      hid += '<input type="hidden" name="units[' + i + '][unit_id]" value="' + esc(p.id) + '"><input type="hidden" name="units[' + i + '][rate_mode]" value="' + esc(p.mode) + '">';
      var opts = modesOf(u).map(function (m) { return '<option value="' + m[0] + '"' + (m[0] === p.mode ? ' selected' : '') + '>' + esc(MODE_LABEL[m[0]] + ' · ' + fmt(m[1])) + '</option>'; }).join('');
      return '<div class="rf-pk"><div><b>' + esc(u.name) + '</b><small><span class="rf-mono">' + esc(u.identifier || '') + '</span>' + (u.size ? ' · ' + esc(u.size) : '') + ' · ' + unitsFor(p.mode) + ' × ' + fmt(rateOf(u, p.mode)) + '</small>' +
        '<select class="ia-input rf-pk-mode" data-mode="' + i + '" aria-label="Rate for ' + esc(u.name) + '">' + opts + '</select></div>' +
        '<span class="amt">' + fmt(line) + '</span><button type="button" class="rf-ib" data-unpick="' + i + '" aria-label="Remove ' + esc(u.name) + '">×</button></div>';
    }).join('') : '<div class="sd-empty">Pick units from what\'s free. Each one you add shows here with its price for the window.</div>';
    $('#nr-hidden').innerHTML = hid;
    $('#nrCount').textContent = st.picked.length ? st.picked.length + ' unit' + (st.picked.length > 1 ? 's' : '') : 'Nothing picked yet';
    var tot = $('#nrTot');
    tot.hidden = !st.picked.length;
    tot.innerHTML = st.picked.length ? '<dt class="big">Subtotal</dt><dd>' + fmt(sub) + '</dd>' : '';
    refresh();
  }

  function refresh() {
    var hasCust = !!idEl.value || hasNewCustomer(), n = st.picked.length;
    $('#nr-submit').disabled = !(hasCust && n);
    $('#nrWhy').textContent = hasCust && n ? 'Tax is added when it\'s created, and the units are held for this window straight away.'
      : !hasCust && !n ? 'Pick a customer and at least one unit to create it.' : !hasCust ? 'Pick a customer to create it.' : 'Add at least one unit to create it.';
  }

  $('#nr-form').addEventListener('submit', function (e) {
    if ($('#nr-submit').disabled) { e.preventDefault(); return; }
    if (!idEl.value && !hasNewCustomer()) { e.preventDefault(); return; }
    // when an existing customer is picked, don't also send half-typed new-customer fields
    if (idEl.value) $$('#nr-newc input').forEach(function (i) { i.disabled = true; });
    $('#nr-submit').disabled = true;
  });

  // restore a picked customer after a validation bounce
  @if(old('customer_id'))
  setCustomer(@json(old('customer_id')), @json($oldCust?->fullName() ?: 'Selected customer'), @json(trim(($oldCust?->email ?? '') . ' ' . ($oldCust?->phone ?? ''))));
  @endif

  syncQuick(); render(); loadFree();
})();
</script>
@endpush
