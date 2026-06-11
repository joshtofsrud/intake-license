@extends('layouts.tenant.app')
@php $pageTitle = 'New Lease'; @endphp

{{-- MARKER-PATCH-230 — fulfillment counter: pick package, fill slots from
     live fleet (auto or by serial), season window, deposit. --}}

@section('content')

@include('layouts.tenant._rental-nav', ['active' => 'leases'])

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">New Lease</h1>
    <p class="ia-page-subtitle">Fill a package from your fleet, set the season, take the deposit.</p>
  </div>
  <a href="{{ route('tenant.rentals.leases.index') }}" class="ia-btn">All leases</a>
</div>

@if($errors->any())
  <div class="ia-flash ia-flash--error" style="margin-bottom:16px">{{ $errors->first() }}</div>
@endif

{{-- Step 1: pick a package (GET reload re-renders with slots) --}}
@if(!$selected)
  <div class="ia-card" style="padding:18px 20px">
    <h2 class="ia-h3" style="margin-bottom:12px">Choose a package</h2>
    @if($packages->isEmpty())
      <p style="font-size:13px;opacity:.6">No packages yet. <a href="{{ route('tenant.rentals.leases.packages') }}">Create one first.</a></p>
    @else
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px">
        @foreach($packages as $pkg)
          <a href="{{ route('tenant.rentals.leases.create', ['package' => $pkg->id]) }}" class="ia-card" style="padding:14px;text-decoration:none;color:inherit;display:block">
            <div style="font-size:15px;font-weight:620">{{ $pkg->name }}</div>
            <div style="font-size:18px;font-weight:700;margin-top:6px">{{ format_money($pkg->season_price_cents) }}<span style="font-size:11px;opacity:.5;font-weight:400"> / season</span></div>
            <div style="font-size:11.5px;opacity:.5;margin-top:4px">{{ $pkg->slots->count() }} {{ Str::plural('slot', $pkg->slots->count()) }} · {{ format_money($pkg->deposit_cents) }} deposit</div>
          </a>
        @endforeach
      </div>
    @endif
  </div>
@else
  {{-- Step 2: fill the selected package --}}
  <form method="POST" action="{{ route('tenant.rentals.leases.store') }}" id="lf-form">
    @csrf
    <input type="hidden" name="package_id" value="{{ $selected->id }}">
    <input type="hidden" name="customer_id" id="lf-customer-id" value="{{ old('customer_id') }}">

    <div class="ia-card" style="padding:16px 20px;margin-bottom:16px;display:flex;justify-content:space-between;align-items:center">
      <div><span style="font-size:13px;opacity:.6">Package:</span> <strong>{{ $selected->name }}</strong> <span style="opacity:.5">— {{ format_money($selected->season_price_cents) }}/season · {{ format_money($selected->deposit_cents) }} deposit</span></div>
      <a href="{{ route('tenant.rentals.leases.create') }}" class="ia-btn ia-btn--sm">Change</a>
    </div>

    {{-- customer --}}
    <div class="ia-card" style="padding:18px 20px;margin-bottom:16px">
      <h2 class="ia-h3" style="margin-bottom:10px">Lessee</h2>
      <div id="lf-cust-picked" style="display:none;align-items:center;gap:10px;margin-bottom:10px">
        <span id="lf-cust-label" style="font-size:13.5px;font-weight:600"></span>
        <button type="button" class="ia-btn ia-btn--sm" id="lf-cust-clear">Change</button>
      </div>
      <div id="lf-cust-search-wrap">
        <input type="text" id="lf-cust-search" class="ia-input" placeholder="Search customer by name, email, or phone…" style="width:100%;max-width:480px" autocomplete="off">
        <div id="lf-cust-results" style="margin-top:6px;max-width:480px"></div>
      </div>
    </div>

    {{-- slots --}}
    <div class="ia-card" style="padding:18px 20px;margin-bottom:16px">
      <h2 class="ia-h3" style="margin-bottom:4px">Fill the slots</h2>
      <p style="font-size:12px;opacity:.55;margin-bottom:14px">Pick a unit for each slot, or auto-pick. Size is a suggestion — any unit in the slot's category works.</p>

      @php $slotIndex = 0; @endphp
      @foreach($selected->slots as $slot)
        @php $opts = $slotOptions[$slot->id] ?? collect(); @endphp
        @for($q = 0; $q < $slot->quantity; $q++)
          <div class="ia-card lf-slot" style="padding:13px 15px;margin-bottom:10px" data-slot="{{ $slot->id }}">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
              <div>
                <div style="font-size:13.5px;font-weight:600">{{ $slot->category->name ?? 'Unit' }}</div>
                @if($slot->size_filter)<div style="font-size:11.5px;opacity:.5">{{ $slot->size_filter }}</div>@endif
              </div>
              <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <select name="assignments[{{ $slotIndex }}][unit_id]" class="ia-input lf-unit-select" style="min-width:240px" data-slotidx="{{ $slotIndex }}">
                  <option value="">— pick a unit —</option>
                  @foreach($opts as $u)
                    <option value="{{ $u->id }}">{{ $u->name }}{{ $u->identifier ? ' · ' . $u->identifier : '' }}{{ $u->size ? ' · ' . $u->size : '' }}</option>
                  @endforeach
                </select>
                <input type="hidden" name="assignments[{{ $slotIndex }}][slot_id]" value="{{ $slot->id }}">
                <button type="button" class="ia-btn ia-btn--sm lf-auto" data-slotidx="{{ $slotIndex }}">Auto-pick</button>
              </div>
            </div>
            @if($opts->isEmpty())
              <div style="font-size:11.5px;color:#E0A82E;margin-top:8px">No available units match this slot right now.</div>
            @endif
          </div>
          @php $slotIndex++; @endphp
        @endfor
      @endforeach
    </div>

    {{-- season + deposit --}}
    <div class="ia-card" style="padding:18px 20px;margin-bottom:16px">
      <h2 class="ia-h3" style="margin-bottom:10px">Season &amp; deposit</h2>
      <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:end">
        <div>
          <label class="ia-label" style="display:block;margin-bottom:5px">Season start</label>
          <input type="datetime-local" name="season_start" class="ia-input" value="{{ \Illuminate\Support\Carbon::parse($seasonStart)->format('Y-m-d\TH:i') }}" required>
        </div>
        <div>
          <label class="ia-label" style="display:block;margin-bottom:5px">Season end</label>
          <input type="datetime-local" name="season_end" class="ia-input" value="{{ \Illuminate\Support\Carbon::parse($seasonEnd)->format('Y-m-d\TH:i') }}" required>
        </div>
        <div>
          <label class="ia-label" style="display:block;margin-bottom:5px">Deposit hold ($)</label>
          <input type="number" step="0.01" min="0" name="deposit_dollars" id="lf-deposit-d" class="ia-input" value="{{ number_format($selected->deposit_cents/100, 2, '.', '') }}" style="width:120px">
          <input type="hidden" name="deposit_cents" id="lf-deposit-c" value="{{ $selected->deposit_cents }}">
        </div>
      </div>
    </div>

    <div style="display:flex;justify-content:flex-end;gap:8px">
      <button type="submit" class="ia-btn ia-btn--primary">Create lease &amp; collect</button>
    </div>
    <p style="font-size:11.5px;opacity:.5;margin-top:10px;text-align:right">
      Assigns the units for the season, records the deposit hold, and sends {{ format_money($selected->season_price_cents) }} to the register.
    </p>
  </form>

  <script>
  (function () {
    var csrf = '{{ csrf_token() }}';
    var searchUrl = '{{ route('tenant.customers.search') }}';

    // ---- customer search (reuses the customers.search endpoint)
    var si = document.getElementById('lf-cust-search');
    var results = document.getElementById('lf-cust-results');
    var picked = document.getElementById('lf-cust-picked');
    var label = document.getElementById('lf-cust-label');
    var wrap = document.getElementById('lf-cust-search-wrap');
    var hid = document.getElementById('lf-customer-id');
    var t;
    if (si) {
      si.addEventListener('input', function () {
        clearTimeout(t);
        var qv = si.value.trim();
        if (qv.length < 2) { results.innerHTML = ''; return; }
        t = setTimeout(function () {
          fetch(searchUrl + '?q=' + encodeURIComponent(qv), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (list) {
              results.innerHTML = (list || []).slice(0, 6).map(function (c) {
                var nm = ((c.first_name || '') + ' ' + (c.last_name || '')).trim() || c.email || 'Customer';
                return '<div class="ia-card lf-cust-opt" data-id="' + c.id + '" data-label="' + nm.replace(/"/g,'') + '" style="padding:8px 12px;cursor:pointer;margin-bottom:4px">' + nm + (c.email ? ' <span style="opacity:.5">· ' + c.email + '</span>' : '') + '</div>';
              }).join('');
            });
        }, 220);
      });
      results.addEventListener('click', function (e) {
        var o = e.target.closest('.lf-cust-opt'); if (!o) return;
        hid.value = o.getAttribute('data-id');
        label.textContent = o.getAttribute('data-label');
        wrap.style.display = 'none'; picked.style.display = 'flex'; results.innerHTML = '';
      });
      document.getElementById('lf-cust-clear').addEventListener('click', function () {
        hid.value = ''; wrap.style.display = 'block'; picked.style.display = 'none';
      });
    }

    // ---- auto-pick: choose first un-taken option in this slot's select
    function takenUnitIds() {
      var ids = {};
      document.querySelectorAll('.lf-unit-select').forEach(function (s) { if (s.value) ids[s.value] = true; });
      return ids;
    }
    document.querySelectorAll('.lf-auto').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var idx = btn.getAttribute('data-slotidx');
        var sel = document.querySelector('.lf-unit-select[data-slotidx="' + idx + '"]');
        if (!sel) return;
        var taken = takenUnitIds();
        for (var i = 0; i < sel.options.length; i++) {
          var v = sel.options[i].value;
          if (v && !taken[v]) { sel.value = v; break; }
        }
      });
    });

    // ---- deposit dollars -> cents
    var dd = document.getElementById('lf-deposit-d');
    var dc = document.getElementById('lf-deposit-c');
    if (dd) dd.addEventListener('input', function () {
      dc.value = Math.round((parseFloat(dd.value) || 0) * 100);
    });

    // ---- guard: require customer + every slot filled
    document.getElementById('lf-form').addEventListener('submit', function (e) {
      if (!hid.value) { e.preventDefault(); alert('Pick a lessee first.'); return; }
      var empty = false;
      document.querySelectorAll('.lf-unit-select').forEach(function (s) { if (!s.value) empty = true; });
      if (empty) { e.preventDefault(); alert('Every slot needs a unit. Use Auto-pick to fill the rest.'); }
    });
  })();
  </script>
@endif

@endsection
