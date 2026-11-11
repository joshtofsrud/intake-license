@extends('layouts.tenant.app')
@php $pageTitle = 'New Lease'; @endphp

{{-- MARKER-RENTAL-LEASE-NEW-STUDIO: the lease counter in the Studio design.
     Pick a package, fill each slot from the live fleet (by hand or auto-pick),
     set the season, take the deposit. Same endpoints and payload as before. --}}

@section('mobile-fab', 'new-rental')
@include('layouts.tenant._rental-styles')

@section('content')
@php
  $ini = fn ($n) => mb_strtoupper(mb_substr(implode('', array_map(fn ($w) => mb_substr($w, 0, 1), preg_split('/\s+/', trim((string) $n)))), 0, 2)) ?: '?';
  $sStart = \Illuminate\Support\Carbon::parse($seasonStart);
  $sEnd   = \Illuminate\Support\Carbon::parse($seasonEnd);
  $slotTotal = $selected ? $selected->slots->sum('quantity') : 0;
@endphp

<div class="sd rf" id="sd">
  <header class="sd-head" id="sdHead">
    <div class="sd-head-l">
      <div class="rf-crumb"><a href="{{ route('tenant.rentals.leases.index') }}">Leases</a> / New lease</div>
      <h1 class="sd-hi">New lease</h1>
      <p class="sd-sub">Fill a package from your fleet, set the season, take the deposit.</p>
    </div>
    <div class="sd-head-r"><a href="{{ route('tenant.rentals.leases.index') }}" class="ia-btn ia-btn--sm">All leases</a></div>
  </header>

  @include('layouts.tenant._rental-nav', ['active' => 'leases'])

  @if($errors->any())<div class="ia-flash ia-flash--error" style="margin-bottom:16px">{{ $errors->first() }}</div>@endif

  @if(! $selected)
    <section class="rf-blk sd-in">
      <div class="sd-sec-h"><div><h2>Choose a package</h2><span>The package decides what the lease includes</span></div></div>
      @if($packages->isEmpty())
        <div class="sd-empty">No packages yet.
          <div class="sd-empty-acts"><a href="{{ route('tenant.rentals.leases.packages') }}" class="ia-btn ia-btn--sm ia-btn--primary">Create a package first</a></div>
        </div>
      @else
        <div class="ls-pkgs">
          @foreach($packages as $pkg)
            <a href="{{ route('tenant.rentals.leases.create', ['package' => $pkg->id]) }}" class="ls-pick">
              <b>{{ $pkg->name }}</b>
              <span class="ls-price">{{ format_money($pkg->season_price_cents) }}</span>
              <small>{{ $pkg->slots->sum('quantity') }} {{ \Illuminate\Support\Str::plural('slot', $pkg->slots->sum('quantity')) }} · {{ format_money($pkg->deposit_cents) }} deposit</small>
            </a>
          @endforeach
        </div>
      @endif
    </section>
    <p class="sd-legend">Pick a package, then fill each of its slots with a real unit from the fleet. Nothing is created or charged until the last step.</p>
  @else
    <form method="POST" action="{{ route('tenant.rentals.leases.store') }}" id="lf-form">
      @csrf
      <input type="hidden" name="package_id" value="{{ $selected->id }}">
      <input type="hidden" name="customer_id" id="lf-customer-id" value="{{ old('customer_id') }}">

      <div class="dash-row">
        <div class="dash-col" style="grid-column:span 8">
          <section class="rf-blk sd-in">
            <div class="sd-sec-h"><div><h2>Package</h2></div><a href="{{ route('tenant.rentals.leases.create') }}" class="rf-linkbtn">Change</a></div>
            <div class="ls-pkg-h" style="margin:0"><div><h2 style="font-size:15px;margin:0">{{ $selected->name }}</h2><small>{{ format_money($selected->season_price_cents) }} per season · {{ format_money($selected->deposit_cents) }} deposit</small></div></div>
          </section>

          <section class="rf-blk sd-in" style="--d:60ms">
            <div class="sd-sec-h"><div><h2>Lessee</h2><span>Who the season is for</span></div></div>
            <div class="rf-picked-c" id="lf-cust-picked" hidden>
              <div class="sd-av" id="lf-cust-ini"></div>
              <div><b id="lf-cust-label"></b><small id="lf-cust-sub"></small></div>
              <button type="button" class="ia-btn ia-btn--sm" id="lf-cust-clear">Change</button>
            </div>
            <div class="rf-cust" id="lf-cust-search-wrap">
              <div class="rf-searchbox">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input class="rf-bare" id="lf-cust-search" placeholder="Search by name, email or phone…" autocomplete="off">
              </div>
              <div class="rf-drop" id="lf-cust-results" hidden></div>
            </div>
          </section>

          <section class="rf-blk sd-in" style="--d:120ms">
            <div class="sd-sec-h"><div><h2>Fill the slots</h2><span>Size is a suggestion — any unit in the slot's category works</span></div><span class="rf-mono rf-dim" id="lf-count">0 of {{ $slotTotal }}</span></div>
            @php $slotIndex = 0; @endphp
            @foreach($selected->slots as $slot)
              @php $opts = $slotOptions[$slot->id] ?? collect(); @endphp
              @for($q = 0; $q < $slot->quantity; $q++)
                <div class="ls-slot lf-slot">
                  <div><b>{{ $slot->category->name ?? 'Unit' }}</b><small>{{ $slot->size_filter ?: 'Any size' }}</small></div>
                  <select name="assignments[{{ $slotIndex }}][unit_id]" class="ia-input lf-unit-select" data-slotidx="{{ $slotIndex }}">
                    <option value="">— pick a unit —</option>
                    @foreach($opts as $u)<option value="{{ $u->id }}">{{ $u->name }}{{ $u->identifier ? ' · ' . $u->identifier : '' }}{{ $u->size ? ' · ' . $u->size : '' }}</option>@endforeach
                  </select>
                  <input type="hidden" name="assignments[{{ $slotIndex }}][slot_id]" value="{{ $slot->id }}">
                  <button type="button" class="ia-btn ia-btn--sm lf-auto" data-slotidx="{{ $slotIndex }}">Auto-pick</button>
                  @if($opts->isEmpty())<span class="ls-warn" style="grid-column:1/-1">No available units match this slot right now.</span>@endif
                </div>
                @php $slotIndex++; @endphp
              @endfor
            @endforeach
          </section>

          <section class="rf-blk sd-in" style="--d:180ms">
            <div class="sd-sec-h"><div><h2>Season &amp; deposit</h2><span>Defaults come from Settings</span></div></div>
            <div class="ls-season">
              <label><span class="rf-lbl">Season start</span><input type="datetime-local" name="season_start" class="ia-input" value="{{ $sStart->format('Y-m-d\TH:i') }}" required></label>
              <label><span class="rf-lbl">Season end</span><input type="datetime-local" name="season_end" class="ia-input" value="{{ $sEnd->format('Y-m-d\TH:i') }}" required></label>
              <label><span class="rf-lbl">Deposit hold ($)</span>
                <input type="number" step="0.01" min="0" name="deposit_dollars" id="lf-deposit-d" class="ia-input" value="{{ number_format($selected->deposit_cents / 100, 2, '.', '') }}" style="width:130px;text-align:right">
                <input type="hidden" name="deposit_cents" id="lf-deposit-c" value="{{ $selected->deposit_cents }}">
              </label>
            </div>
          </section>
        </div>

        <div class="dash-col" style="grid-column:span 4">
          <aside class="sd-rail rf-rail sd-in" style="--d:100ms">
            <div class="rf-rh"><b>This lease</b><span>{{ $selected->name }}</span></div>
            <dl class="rf-kv">
              <dt>Lessee</dt><dd id="lf-sum-cust"><span class="rf-dim">Not picked</span></dd>
              <dt>Slots</dt><dd id="lf-sum-slots">0 of {{ $slotTotal }} filled</dd>
              <dt>Season</dt><dd>{{ $sStart->format('M j') }} → {{ $sEnd->format('M j') }}</dd>
            </dl>
            <dl class="rf-tot">
              <dt class="big">Season price</dt><dd>{{ format_money($selected->season_price_cents) }}</dd>
              <dt>Deposit hold</dt><dd id="lf-sum-dep">{{ format_money($selected->deposit_cents) }}</dd>
            </dl>
            <button type="submit" class="ia-btn ia-btn--primary rf-wide">Create lease &amp; collect</button>
            <p class="ls-err" id="lf-err" role="alert" hidden></p>
            <p class="rf-fine">Assigns the units for the season, records the deposit hold, and sends {{ format_money($selected->season_price_cents) }} to the register.</p>
          </aside>
        </div>
      </div>
    </form>

    <p class="sd-legend">Every slot needs a unit, and the units are checked again when the lease is created — if one has been taken in the meantime you'll be told which. Leased units show as lease bars on Availability for the whole season.</p>
  @endif
</div>
@endsection

@push('scripts')
@include('layouts.tenant._rental-boot')
@if($selected)
<script>
(function () {
  'use strict';
  function $(s, e) { return (e || document).querySelector(s); }
  function $$(s, e) { return Array.prototype.slice.call((e || document).querySelectorAll(s)); }
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  var searchUrl = '{{ route('tenant.customers.search') }}';
  var total = {{ $slotTotal }};
  var si = $('#lf-cust-search'), results = $('#lf-cust-results'), picked = $('#lf-cust-picked'), wrap = $('#lf-cust-search-wrap'), hid = $('#lf-customer-id');
  var errEl = $('#lf-err'), t;

  function err(msg) { errEl.textContent = msg; errEl.hidden = !msg; }
  function ini(n) { return String(n).split(/\s+/).map(function (w) { return w[0] || ''; }).join('').slice(0, 2).toUpperCase() || '?'; }

  // ---- lessee search (same customers.search endpoint as before)
  si.addEventListener('input', function () {
    clearTimeout(t);
    var q = si.value.trim();
    if (q.length < 2) { results.hidden = true; return; }
    t = setTimeout(function () {
      fetch(searchUrl + '?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (list) {
          list = (list || []).slice(0, 6);
          results.innerHTML = list.map(function (c) {
            var nm = ((c.first_name || '') + ' ' + (c.last_name || '')).trim() || c.email || 'Customer';
            var sub = [c.phone, c.email].filter(Boolean).join(' · ');
            return '<button type="button" data-id="' + esc(c.id) + '" data-label="' + esc(nm) + '" data-sub="' + esc(sub) + '"><div class="sd-av">' + esc(ini(nm)) + '</div><div><b>' + esc(nm) + '</b><small>' + esc(sub) + '</small></div></button>';
          }).join('') || '<div class="sd-empty" style="padding:10px">No matches.</div>';
          results.hidden = false;
        });
    }, 220);
  });
  results.addEventListener('click', function (e) {
    var o = e.target.closest('button[data-id]'); if (!o) return;
    hid.value = o.dataset.id;
    $('#lf-cust-label').textContent = o.dataset.label; $('#lf-cust-sub').textContent = o.dataset.sub; $('#lf-cust-ini').textContent = ini(o.dataset.label);
    $('#lf-sum-cust').textContent = o.dataset.label;
    wrap.hidden = true; picked.hidden = false; results.hidden = true; err('');
  });
  $('#lf-cust-clear').addEventListener('click', function () {
    hid.value = ''; wrap.hidden = false; picked.hidden = true; si.value = ''; $('#lf-sum-cust').innerHTML = '<span class="rf-dim">Not picked</span>'; si.focus();
  });

  // ---- slots: keep the same unit out of two slots, count what is filled
  function refresh() {
    var sels = $$('.lf-unit-select'), taken = {};
    sels.forEach(function (s) { if (s.value) taken[s.value] = true; });
    sels.forEach(function (s) {
      $$('option', s).forEach(function (o) { if (o.value) o.disabled = !!taken[o.value] && o.value !== s.value; });
      s.closest('.lf-slot').classList.remove('bad');
    });
    var n = sels.filter(function (s) { return s.value; }).length;
    $('#lf-count').textContent = n + ' of ' + total; $('#lf-sum-slots').textContent = n + ' of ' + total + ' filled';
  }
  $$('.lf-unit-select').forEach(function (s) { s.addEventListener('change', function () { err(''); refresh(); }); });
  $$('.lf-auto').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var sel = $('.lf-unit-select[data-slotidx="' + btn.dataset.slotidx + '"]'), taken = {};
      $$('.lf-unit-select').forEach(function (s) { if (s.value) taken[s.value] = true; });
      for (var i = 0; i < sel.options.length; i++) { var v = sel.options[i].value; if (v && !taken[v]) { sel.value = v; refresh(); err(''); return; } }
      err('No other free unit fits that slot.');
    });
  });

  // ---- deposit dollars -> cents
  var dd = $('#lf-deposit-d'), dc = $('#lf-deposit-c');
  dd.addEventListener('input', function () {
    var c = Math.round((parseFloat(dd.value) || 0) * 100); dc.value = c;
    $('#lf-sum-dep').textContent = '$' + (c / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  });

  // ---- guard: a lessee and every slot filled — said inline, no browser dialogs
  $('#lf-form').addEventListener('submit', function (e) {
    var empty = $$('.lf-unit-select').filter(function (s) { return !s.value; });
    if (!hid.value) { e.preventDefault(); err('Pick a lessee first.'); return; }
    if (empty.length) { e.preventDefault(); empty.forEach(function (s) { s.closest('.lf-slot').classList.add('bad'); }); err('Every slot needs a unit. Use Auto-pick to fill the rest.'); }
  });
  refresh();
})();
</script>
@endif
@endpush
