@extends('layouts.tenant.app')
@php $pageTitle = 'New Rental'; @endphp

{{-- MARKER-PATCH-219 — desk reservation flow: customer -> window -> units. --}}

@section('content')

@include('layouts.tenant._rental-nav', ['active' => 'bookings'])

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">New Rental</h1>
    <p class="ia-page-subtitle">Pick the customer, set the window, choose what's free.</p>
  </div>
  <a href="{{ route('tenant.rentals.bookings.index') }}" class="ia-btn">Back to bookings</a>
</div>

@if($errors->any())
  <div class="ia-flash ia-flash--error" style="margin-bottom:16px">{{ $errors->first() }}</div>
@endif

<form method="POST" action="{{ route('tenant.rentals.bookings.store') }}" id="nr-form">
  @csrf
  <input type="hidden" name="customer_id" id="nr-customer-id" value="{{ old('customer_id') }}">

  <div class="ia-card" style="padding:18px 20px;margin-bottom:16px">
    <h2 class="ia-h3" style="margin-bottom:10px">Customer</h2>
    <div id="nr-cust-picked" style="display:none;align-items:center;gap:10px;margin-bottom:10px">
      <span id="nr-cust-label" style="font-size:13.5px;font-weight:600"></span>
      <button type="button" class="ia-btn" id="nr-cust-clear">Change</button>
    </div>
    <div id="nr-cust-search-wrap">
      <input type="text" id="nr-cust-search" class="ia-input" placeholder="Search by name, email, or phone…" style="width:100%;max-width:480px" autocomplete="off">
      <div id="nr-cust-results" style="margin-top:6px;max-width:480px"></div>
      <p style="font-size:12px;opacity:.55;margin-top:8px">No match? Fill in the fields below and a customer record is created with the rental.</p>
      <div style="display:grid;grid-template-columns:repeat(4, 1fr);gap:10px;margin-top:6px;max-width:760px">
        <input type="text" name="first_name" value="{{ old('first_name') }}" maxlength="120" placeholder="First name" class="ia-input">
        <input type="text" name="last_name" value="{{ old('last_name') }}" maxlength="120" placeholder="Last name" class="ia-input">
        <input type="email" name="email" value="{{ old('email') }}" maxlength="190" placeholder="Email" class="ia-input">
        <input type="text" name="phone" value="{{ old('phone') }}" maxlength="40" placeholder="Phone" class="ia-input">
      </div>
    </div>
  </div>

  <div class="ia-card" style="padding:18px 20px;margin-bottom:16px">
    <h2 class="ia-h3" style="margin-bottom:10px">Window</h2>
    <div style="display:flex;gap:12px;align-items:end;flex-wrap:wrap">
      <div>
        <label class="ia-label" style="display:block;margin-bottom:5px">Starts</label>
        <input type="datetime-local" name="starts_at" id="nr-starts" value="{{ old('starts_at') }}" required class="ia-input">
      </div>
      <div>
        <label class="ia-label" style="display:block;margin-bottom:5px">Due back</label>
        <input type="datetime-local" name="due_at" id="nr-due" value="{{ old('due_at') }}" required class="ia-input">
      </div>
      <button type="button" class="ia-btn ia-btn--primary" id="nr-find">Find available units</button>
    </div>
  </div>

  <div class="ia-card" style="padding:18px 20px;margin-bottom:16px">
    <h2 class="ia-h3" style="margin-bottom:10px">Units</h2>
    <div id="nr-units"><p style="font-size:12.5px;opacity:.55">Set the window, then find what's free.</p></div>
    <div id="nr-summary" style="display:none;margin-top:12px;padding-top:12px;border-top:0.5px solid var(--ia-border);font-size:13.5px;font-weight:700"></div>
  </div>

  <div class="ia-card" style="padding:18px 20px;margin-bottom:16px">
    <label class="ia-label" style="display:block;margin-bottom:5px">Notes (optional)</label>
    <textarea name="notes" rows="2" maxlength="2000" class="ia-input" style="width:100%;resize:vertical">{{ old('notes') }}</textarea>
  </div>

  <button type="submit" class="ia-btn ia-btn--primary" id="nr-submit" disabled>Create reservation</button>
</form>

<script>
(function () {
  var searchUrl = '{{ route('tenant.customers.search') }}';
  var availUrl  = '{{ route('tenant.rentals.availability') }}';
  var fmt = function (c) { return '$' + (c / 100).toFixed(2); };

  // ---------------- customer search
  var searchInput = document.getElementById('nr-cust-search');
  var resultsEl   = document.getElementById('nr-cust-results');
  var pickedEl    = document.getElementById('nr-cust-picked');
  var labelEl     = document.getElementById('nr-cust-label');
  var idEl        = document.getElementById('nr-customer-id');
  var searchWrap  = document.getElementById('nr-cust-search-wrap');
  var t = null;

  searchInput.addEventListener('input', function () {
    clearTimeout(t);
    var q = searchInput.value.trim();
    if (q.length < 2) { resultsEl.innerHTML = ''; return; }
    t = setTimeout(function () {
      fetch(searchUrl + '?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          var rows = (j.customers || []).map(function (c) {
            var sub = [c.email, c.phone].filter(Boolean).join(' · ');
            return '<button type="button" class="ia-btn nr-cust-pick" data-id="' + c.id + '" data-label="' + (c.label || c.email) +
                   '" style="display:block;width:100%;text-align:left;margin-top:4px">' + (c.label || '(no name)') +
                   ' <span style="opacity:.55;font-size:11.5px">' + sub + '</span></button>';
          });
          resultsEl.innerHTML = rows.join('') || '<p style="font-size:12px;opacity:.5;margin-top:6px">No matches.</p>';
        });
    }, 250);
  });

  resultsEl.addEventListener('click', function (e) {
    var btn = e.target.closest('.nr-cust-pick');
    if (!btn) return;
    idEl.value = btn.getAttribute('data-id');
    labelEl.textContent = btn.getAttribute('data-label');
    pickedEl.style.display = 'flex';
    searchWrap.style.display = 'none';
  });

  document.getElementById('nr-cust-clear').addEventListener('click', function () {
    idEl.value = '';
    pickedEl.style.display = 'none';
    searchWrap.style.display = '';
  });

  // ---------------- defaults: now (rounded up to :00) -> +1 day
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function toLocalValue(d) {
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
  }
  var startsEl = document.getElementById('nr-starts');
  var dueEl    = document.getElementById('nr-due');

  // MARKER-PATCH-223 — availability-timeline handoff: ?starts=&due=&unit=
  // prefills the window, auto-runs the availability check, and pre-checks
  // the dragged unit.
  var params = new URLSearchParams(window.location.search);
  var qsStarts = params.get('starts'), qsDue = params.get('due'), qsUnit = params.get('unit');
  if (qsStarts) startsEl.value = qsStarts;
  if (qsDue) dueEl.value = qsDue;

  if (!startsEl.value) {
    var s = new Date(); s.setMinutes(0, 0, 0); s.setHours(s.getHours() + 1);
    startsEl.value = toLocalValue(s);
    var d = new Date(s.getTime() + 24 * 3600 * 1000);
    dueEl.value = toLocalValue(d);
  }

  // ---------------- availability + pricing preview
  var unitsEl   = document.getElementById('nr-units');
  var summaryEl = document.getElementById('nr-summary');
  var submitEl  = document.getElementById('nr-submit');

  function durationUnits(mode) {
    var ms = new Date(dueEl.value) - new Date(startsEl.value);
    var minutes = Math.max(0, ms / 60000);
    if (mode === 'hourly')  return Math.max(1, Math.ceil(minutes / 60));
    if (mode === 'daily')   return Math.max(1, Math.ceil(minutes / 1440));
    return 1; // weekend & seasonal = flat
  }

  function refreshSummary() {
    var rows = unitsEl.querySelectorAll('.nr-unit-row');
    var total = 0, picked = 0, idx = 0;
    rows.forEach(function (row) {
      var cb = row.querySelector('.nr-unit-cb');
      var sel = row.querySelector('.nr-mode');
      var priceEl = row.querySelector('.nr-price');
      var hidWrap = row.querySelector('.nr-hidden');
      hidWrap.innerHTML = '';
      if (!cb.checked) { priceEl.textContent = ''; return; }
      var mode = sel.value;
      var rate = parseInt(sel.options[sel.selectedIndex].getAttribute('data-rate'), 10);
      var units = durationUnits(mode);
      var line = rate * units;
      total += line; picked++;
      priceEl.textContent = units + ' × ' + fmt(rate) + ' = ' + fmt(line);
      hidWrap.innerHTML =
        '<input type="hidden" name="units[' + idx + '][unit_id]" value="' + cb.value + '">' +
        '<input type="hidden" name="units[' + idx + '][rate_mode]" value="' + mode + '">';
      idx++;
    });
    submitEl.disabled = picked === 0;
    summaryEl.style.display = picked ? 'block' : 'none';
    summaryEl.textContent = picked ? (picked + ' unit' + (picked === 1 ? '' : 's') + ' · subtotal ' + fmt(total) + ' (tax added at save)') : '';
  }

  document.getElementById('nr-find').addEventListener('click', function () {
    if (!startsEl.value || !dueEl.value) { alert('Set the window first.'); return; }
    unitsEl.innerHTML = '<p style="font-size:12.5px;opacity:.55">Checking…</p>';
    var qs = '?starts_at=' + encodeURIComponent(startsEl.value) + '&due_at=' + encodeURIComponent(dueEl.value);
    fetch(availUrl + qs, { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, json: j }; }); })
      .then(function (res) {
        if (!res.ok) { unitsEl.innerHTML = '<p style="font-size:12.5px;color:#ef4444">' + (res.json.message || 'Could not check availability.') + '</p>'; return; }
        var units = res.json.units || [];
        if (!units.length) { unitsEl.innerHTML = '<p style="font-size:12.5px;opacity:.6">Nothing is free for that window.</p>'; refreshSummary(); return; }
        unitsEl.innerHTML = units.map(function (u) {
          var modes = [];
          if (u.hourly_rate_cents !== null)  modes.push(['hourly',  'Hourly · '  + fmt(u.hourly_rate_cents),  u.hourly_rate_cents]);
          if (u.daily_rate_cents !== null)   modes.push(['daily',   'Daily · '   + fmt(u.daily_rate_cents),   u.daily_rate_cents]);
          if (u.weekend_rate_cents !== null) modes.push(['weekend', 'Weekend · ' + fmt(u.weekend_rate_cents), u.weekend_rate_cents]);
          @if(tenant()->leases_enabled)
          if (u.seasonal_rate_cents !== null) modes.push(['seasonal', 'Season · ' + fmt(u.seasonal_rate_cents), u.seasonal_rate_cents]);
          @endif
          if (!modes.length) return ''; // no rates configured -> not bookable
          var opts = modes.map(function (m) { return '<option value="' + m[0] + '" data-rate="' + m[2] + '">' + m[1] + '</option>'; }).join('');
          var meta = [u.identifier, u.category, u.size].filter(Boolean).join(' · ');
          return '<div class="nr-unit-row" style="display:grid;grid-template-columns:auto 1.6fr 1.2fr 1fr;gap:10px;align-items:center;padding:8px 0;border-bottom:0.5px solid var(--ia-border)">' +
            '<input type="checkbox" class="nr-unit-cb" value="' + u.id + '">' +
            '<span style="font-size:13px;font-weight:600">' + u.name + ' <span style="opacity:.5;font-weight:400;font-size:11.5px">' + meta + '</span></span>' +
            '<select class="ia-input nr-mode">' + opts + '</select>' +
            '<span class="nr-price" style="font-size:12.5px;text-align:right;opacity:.8"></span>' +
            '<span class="nr-hidden"></span>' +
            '</div>';
        }).join('');
        refreshSummary();
      })
      .catch(function () { unitsEl.innerHTML = '<p style="font-size:12.5px;color:#ef4444">Could not check availability.</p>'; });
  });

  unitsEl.addEventListener('change', refreshSummary);

  // MARKER-PATCH-223 — finish the timeline handoff after wiring is in place.
  if (qsStarts && qsDue) {
    document.getElementById('nr-find').click();
    if (qsUnit) {
      var tries = 0;
      var timer = setInterval(function () {
        var cb = unitsEl.querySelector('.nr-unit-cb[value="' + qsUnit + '"]');
        if (cb) { cb.checked = true; refreshSummary(); clearInterval(timer); }
        if (++tries > 40) clearInterval(timer); // ~10s, then give up quietly
      }, 250);
    }
  }
  startsEl.addEventListener('change', function () { unitsEl.innerHTML = '<p style="font-size:12.5px;opacity:.55">Window changed — find units again.</p>'; submitEl.disabled = true; summaryEl.style.display = 'none'; });
  dueEl.addEventListener('change', function () { unitsEl.innerHTML = '<p style="font-size:12.5px;opacity:.55">Window changed — find units again.</p>'; submitEl.disabled = true; summaryEl.style.display = 'none'; });
})();
</script>

@endsection
