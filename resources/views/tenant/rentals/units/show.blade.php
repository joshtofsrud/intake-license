@extends('layouts.tenant.app')
@php $pageTitle = ($unit->identifier ?: 'Unit') . ' — ' . ($unit->model?->name ?? 'Fleet'); @endphp

{{-- MARKER-RENTAL-UNIT-STUDIO: unit detail — the serial's whole story — in the
     Studio design. Saves go field by field to the same fleet updateUnit
     endpoint, from an explicit Save unit button. --}}

@section('mobile-fab', 'new-rental')
@include('layouts.tenant._rental-styles')

@section('content')
@php
  [$uCls, $uLabel] = match ($derived) {
      'out'         => ['out', 'out'],
      'reserved'    => ['res', 'reserved'],
      'maintenance' => ['mt', 'maintenance'],
      'retired'     => ['off', 'retired'],
      default       => ['av', 'available'],
  };
  $tn = ['--sd-t1', '--sd-t2', '--sd-t3', '--sd-t4'];
@endphp

<div class="sd rf" id="sd">
  <header class="sd-head" id="sdHead">
    <div class="sd-head-l">
      <div class="rf-crumb"><a href="{{ route('tenant.rentals.fleet') }}">Fleet</a> / {{ $unit->model?->name }} / {{ $unit->identifier ?: 'Unit' }}</div>
      <h1 class="sd-hi">{{ $unit->identifier ?: 'Unit' }} — {{ $unit->model?->name }}{{ $unit->size ? ' (' . $unit->size . ')' : '' }}<span class="rf-hpill"><span class="rf-st {{ $uCls }}">{{ $uLabel }}</span></span></h1>
      <p class="sd-sub">{{ $unit->category?->name }}{{ $unit->name ? ' · ' . $unit->name : '' }} · in fleet since {{ tlocal_date($unit->created_at) }}{{ $unit->conditionTemplate ? ' · ' . $unit->conditionTemplate->name . ' checklist' : '' }}</p>
    </div>
    <div class="sd-head-r"><a href="{{ route('tenant.rentals.fleet') }}" class="ia-btn ia-btn--sm">Back to fleet</a></div>
  </header>

  @include('layouts.tenant._rental-nav', ['active' => 'fleet'])

  <div class="un-kpis sd-in">
    <div class="un-kpi"><small>Utilization 30d</small><b>{{ $utilizationPct }}%</b><span>rented time ÷ window</span></div>
    <div class="un-kpi"><small>Revenue lifetime</small><b>{{ format_money($lifetimeCents) }}</b><span>{{ $rentals->count() }}{{ $rentals->count() === 25 ? '+' : '' }} {{ $rentals->count() === 1 ? 'rental' : 'rentals' }}</span></div>
    <div class="un-kpi"><small>Flagged returns</small><b class="{{ $flaggedReturns > 0 ? 'warn' : '' }}">{{ $flaggedReturns }}</b><span>in-checks with flags</span></div>
    <div class="un-kpi"><small>Rates</small><b style="font-size:17px;line-height:1.6;margin:3px 0">{{ $unit->effectiveDailyCents() ? format_money($unit->effectiveDailyCents()) . ' / day' : '—' }}</b><span>deposit {{ format_money($unit->effectiveDepositCents()) }}</span></div>
  </div>

  <div class="dash-row">
    <div class="dash-col" style="grid-column:span 8">
      <section class="rf-blk sd-in">
        <div class="sd-sec-h"><div><h2>Rental history</h2><span>Every rental this unit has been on</span></div></div>
        @if($rentals->isEmpty())
          <div class="sd-empty">Never been out. Its day will come.
            <div class="sd-empty-acts"><a href="{{ route('tenant.rentals.bookings.create') }}?unit={{ $unit->id }}" class="ia-btn ia-btn--sm ia-btn--primary">New rental with this unit</a></div>
          </div>
        @else
          <div class="un-h"><span>Rental</span><span>Customer</span><span>Window</span><span>Status</span><span style="text-align:right">Revenue</span></div>
          @foreach($rentals as $r)
            @php $inCheck = $r->conditionChecks->firstWhere('phase', 'check_in'); $rev = (int) $r->lines->sum('line_total_cents'); $url = route('tenant.rentals.bookings.show', $r->id); @endphp
            <div class="un-r" data-href="{{ $url }}">
              <span class="rf-mono rf-dim">{{ $r->rental_number }}</span>
              <a href="{{ $url }}" class="rf-brow-link"><b>{{ $r->customer?->fullName() ?: 'Walk-in' }}</b></a>
              <span class="rf-win-t">{{ tlocal_date($r->starts_at, 'M j') }} – {{ tlocal_date($r->returned_at ?? $r->due_at, 'M j') }}</span>
              <span class="un-st">@include('tenant.rentals._status-pill', ['rental' => $r])@if($inCheck?->flagged)<span class="flag" title="Flagged at return">⚑</span>@endif</span>
              <span class="amt">{{ format_money($rev) }}</span>
            </div>
          @endforeach
          <div class="sd-end in"><i></i><span>End of the history</span><i></i></div>
        @endif
      </section>
    </div>

    <div class="dash-col" style="grid-column:span 4">
      <aside class="sd-rail rf-rail sd-in" style="--d:100ms">
        <div class="rf-rh"><b>Edit unit</b><span id="unit-edit-status"></span></div>
        <div class="un-ef" id="unit-edit" data-unit="{{ $unit->id }}">
          <label><span class="rf-lbl">Serial / tag</span><input class="ia-input rf-mono" value="{{ $unit->identifier }}" data-uf="identifier" placeholder="#tag"></label>
          <label><span class="rf-lbl">Size</span><input class="ia-input" value="{{ $unit->size }}" data-uf="size" placeholder="size"></label>
          <label><span class="rf-lbl">Status</span>
            <select class="ia-input" data-uf="status">
              @foreach(['available' => 'Available', 'maintenance' => 'Maintenance', 'retired' => 'Retired'] as $sk => $sv)<option value="{{ $sk }}" {{ $unit->status === $sk ? 'selected' : '' }}>{{ $sv }}</option>@endforeach
            </select>
          </label>
          <label><span class="rf-lbl">Booking</span>
            <select class="ia-input" data-uf="available_for_rent">
              <option value="1" {{ $unit->available_for_rent ? 'selected' : '' }}>Rentable</option>
              <option value="0" {{ $unit->available_for_rent ? '' : 'selected' }}>Off — hidden from booking</option>
            </select>
          </label>
          {{-- the same per-model identifier fields the fleet roster shows for this unit --}}
          @foreach($mIdents as $in)
            <label><span class="rf-lbl">{{ $in }}</span><input class="ia-input" value="{{ ($unit->identifier_values ?? [])[$in] ?? '' }}" data-ui="{{ $in }}" placeholder="—"></label>
          @endforeach
          <div class="c2">
            <span class="rf-lbl" style="display:block;margin-bottom:6px">Photo</span>
            @if(count($mPhotos))
              <div class="un-tiles" id="unit-photos">
                @foreach($mPhotos as $ph)
                  <button type="button" class="un-tile {{ $unit->photo_url === $ph ? 'sel' : '' }}" data-photo="{{ $ph }}" style="background-image:url('{{ $ph }}');background-size:cover;background-position:center" title="Use this photo" aria-label="Use this photo"></button>
                @endforeach
                <button type="button" class="un-tile {{ $unit->photo_url ? '' : 'sel' }}" data-photo="" title="No photo">none</button>
              </div>
              <input type="hidden" data-uf="photo_url" value="{{ $unit->photo_url }}">
            @else
              <div class="rf-dim" style="font-size:12px">No photos on {{ $unit->model?->name ?: 'this model' }} yet — add them in Fleet → Edit → Photos.</div>
            @endif
          </div>
        </div>
        <div class="un-save"><span id="unit-note"></span><button type="button" class="ia-btn ia-btn--primary" id="unit-save" disabled>Save unit</button></div>

        <div class="rf-rh"><b>Notes &amp; maintenance log</b></div>
        @if($unit->notes)
          <p style="font-size:12.5px;white-space:pre-wrap;line-height:1.6;margin:0">{{ $unit->notes }}</p>
        @else
          <p class="rf-dim" style="font-size:12.5px;margin:0">Nothing logged. Return-flow maintenance routing writes dated lines here automatically.</p>
        @endif

        @if($photoChecks->isNotEmpty())
          <div class="rf-rh"><b>Recent check photos</b></div>
          <div>
            @foreach($photoChecks as $check)
              <div class="un-chk">
                <span>{{ $check->phase === 'check_out' ? 'Out-check' : 'In-check' }} · {{ tlocal_date($check->performed_at) }}@if($check->flagged) <span style="color:var(--sd-t4)">⚑</span>@endif</span>
                <span>@foreach($check->photos as $pi => $p)<a href="{{ Storage::disk('public')->url($p) }}" target="_blank" rel="noopener">{{ $pi + 1 }}</a>@endforeach</span>
              </div>
            @endforeach
          </div>
        @endif
      </aside>
    </div>
  </div>

  <p class="sd-legend">Changes here are saved with Save unit. Maintenance notes from the return flow land in the log by themselves, so clear the status above when the work is done. Setting a unit to Maintenance or turning booking off takes it out of what's free, but anything already booked on it stays — clear those from Bookings first. The photo is picked from the model's own photos in Fleet.</p>
</div>
@endsection

@push('scripts')
@include('layouts.tenant._rental-boot')
<script>
(function () {
  'use strict';
  var wrap = document.getElementById('unit-edit');
  if (!wrap) return;
  var url = '{{ url('admin/rentals/fleet/units') }}/' + wrap.getAttribute('data-unit');
  var csrf = '{{ csrf_token() }}';
  var statusEl = document.getElementById('unit-edit-status'), note = document.getElementById('unit-note'), saveBtn = document.getElementById('unit-save');
  var dirty = {};

  function say(msg, bad) { note.textContent = msg; note.style.color = bad ? 'var(--sd-bad)' : ''; }
  function mark() { var n = Object.keys(dirty).length; saveBtn.disabled = !n; statusEl.textContent = n ? 'Unsaved changes' : ''; if (n) say(''); }
  function send(field, value) {
    return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'X-HTTP-Method-Override': 'PATCH' }, body: JSON.stringify({ field: field, value: value }) }).then(function (r) { return r.json(); });
  }

  wrap.querySelectorAll('[data-uf]').forEach(function (el) {
    var on = function () { dirty[el.getAttribute('data-uf')] = el.value; mark(); };
    el.addEventListener('change', on); el.addEventListener('input', on);
  });
  // identifier inputs save as one map, like the fleet roster cells
  var idEls = wrap.querySelectorAll('[data-ui]');
  idEls.forEach(function (el) {
    var on = function () {
      var map = {}; idEls.forEach(function (x) { if (x.value.trim() !== '') map[x.getAttribute('data-ui')] = x.value.trim(); });
      dirty['identifier_values'] = JSON.stringify(map); mark();
    };
    el.addEventListener('change', on); el.addEventListener('input', on);
  });
  // photo tiles set the hidden field
  var photoWrap = document.getElementById('unit-photos'), photoHidden = wrap.querySelector('[data-uf="photo_url"]');
  if (photoWrap && photoHidden) {
    photoWrap.querySelectorAll('.un-tile').forEach(function (t) {
      t.addEventListener('click', function () {
        photoWrap.querySelectorAll('.un-tile').forEach(function (x) { x.classList.remove('sel'); });
        t.classList.add('sel'); photoHidden.value = t.getAttribute('data-photo') || '';
        dirty['photo_url'] = photoHidden.value; mark();
      });
    });
  }

  saveBtn.addEventListener('click', function () {
    var fields = Object.keys(dirty); if (!fields.length) return;
    saveBtn.disabled = true; note.style.color = ''; note.textContent = 'Saving…';
    var i = 0;
    function next() {
      if (i >= fields.length) { dirty = {}; statusEl.textContent = ''; say('✓ Saved'); return; }
      var f = fields[i++];
      send(f, dirty[f]).then(function (j) {
        if (j && j.success === false) { saveBtn.disabled = false; say(j.message || 'Could not save.', true); return; }
        next();
      }).catch(function () { saveBtn.disabled = false; say('Could not save.', true); });
    }
    next();
  });

  // a history row opens its booking
  document.addEventListener('click', function (e) {
    var row = e.target.closest('.un-r[data-href]');
    if (row && !e.target.closest('a, button')) location.href = row.getAttribute('data-href');
  });
})();
</script>
@endpush
