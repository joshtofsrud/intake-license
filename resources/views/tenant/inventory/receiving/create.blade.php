@extends('layouts.tenant.app')
@php $pageTitle = 'New shipment'; @endphp


@push('styles')
<style>
/* "Best on desktop" mobile notice (patch #38). Hidden on >640px. */
.recv-mobile-notice{display:none;background:rgba(250,180,106,.08);border:0.5px solid rgba(250,180,106,.25);border-radius:var(--ia-r-lg);padding:14px 16px;margin-bottom:16px}
.recv-mobile-notice-title{font-size:13px;font-weight:600;color:#FAB46A;margin-bottom:4px;display:flex;align-items:center;gap:6px}
.recv-mobile-notice-body{font-size:12px;color:var(--ia-text-muted);line-height:1.5}
@media(max-width:640px){
  .recv-mobile-notice{display:block}
}
</style>
@endpush

@section('content')


{{-- Mobile "best on desktop" notice (patch #38). Receiving is line-by-line
     entry that doesn't fit a phone — v1.1 will likely add barcode scanning
     and a different mobile flow. For now we surface the limitation rather
     than rebuild the form. --}}
<div class="recv-mobile-notice">
  <div class="recv-mobile-notice-title">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    Best on desktop
  </div>
  <div class="recv-mobile-notice-body">
    Receiving works on mobile, but line-by-line entry is faster on a larger screen. Mobile-optimized receiving (with barcode scanning) is on the roadmap.
  </div>
</div>

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">New shipment</h1>
    <p class="ia-page-subtitle">Start receiving stock against a shipment</p>
  </div>
  <div class="ia-page-actions">
    <a href="{{ route('tenant.inventory.receiving.index') }}" class="ia-btn ia-btn--ghost">← Back</a>
  </div>
</div>

{{-- MARKER-PO --}}
<div class="rcvn-legend">
  Pick the vendor and the POs this box is for. Every line still owed on them is filled in, so you only change what's different. A shipment with no vendor works the way it always has.
</div>

<form method="POST" action="{{ route('tenant.inventory.receiving.store') }}" id="rcvn-form" style="max-width:860px">
  @csrf
  @if($errors->any())
    <div class="ia-flash ia-flash--error">
      @foreach($errors->all() as $err)<div>{{ $err }}</div>@endforeach
    </div>
  @endif

  <div class="rcvn-grid">
    <div class="ia-field">
      <label class="ia-label">Shipment number</label>
      <input name="shipment_number" class="ia-input" value="{{ old('shipment_number', $defaultNumber) }}" required maxlength="30" autocomplete="off">
      <div class="ia-help">Or the vendor's number from the packing slip.</div>
    </div>
    <div class="ia-field">
      <label class="ia-label">Received date</label>
      <input name="received_date" type="date" class="ia-input" value="{{ old('received_date', $today) }}" required>
    </div>
    <div class="ia-field">
      <label class="ia-label">Location</label>
      @if($locations->count() === 1)
        <input class="ia-input" value="{{ $locations->first()->name }}" disabled>
        <input type="hidden" name="location_id" id="rcvn-loc" value="{{ $locations->first()->id }}">
      @else
        <select name="location_id" id="rcvn-loc" class="ia-input" required>
          @foreach($locations as $loc)
            <option value="{{ $loc->id }}" @selected(old('location_id', $defaultLocationId) === $loc->id)>{{ $loc->name }}</option>
          @endforeach
        </select>
      @endif
    </div>
    <div class="ia-field">
      <label class="ia-label">Vendor</label>
      <select name="vendor_id" id="rcvn-vendor" class="ia-input">
        <option value="">No vendor / not on a PO</option>
        @foreach($vendors as $v)
          <option value="{{ $v->id }}" @selected(old('vendor_id', $defaultVendorId) === $v->id)>{{ $v->name }}</option>
        @endforeach
      </select>
    </div>
    <div class="ia-field rcvn-blank" style="grid-column:1 / -1">
      <div class="rcvn-two">
        <div>
          <label class="ia-label">Distributor name</label>
          <input name="distributor_name" class="ia-input" value="{{ old('distributor_name') }}" maxlength="128" placeholder="e.g. QBP, Hawley, J&B">
        </div>
        <div>
          <label class="ia-label">Distributor code <span style="color:var(--ia-text-muted)">(optional)</span></label>
          <input name="distributor_code" class="ia-input" value="{{ old('distributor_code') }}" maxlength="32" placeholder="Account #">
        </div>
      </div>
    </div>
  </div>

  <div id="rcvn-pos" style="margin-top:18px"></div>

  <div class="ia-field" style="margin-top:16px">
    <label class="ia-label">Notes <span style="color:var(--ia-text-muted)">(optional)</span></label>
    <textarea name="notes" class="ia-input" rows="2" maxlength="2000">{{ old('notes') }}</textarea>
  </div>

  <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px">
    <a href="{{ route('tenant.inventory.receiving.index') }}" class="ia-btn ia-btn--ghost">Cancel</a>
    <button type="submit" class="ia-btn ia-btn--primary">Start shipment →</button>
  </div>
</form>

<style>
.rcvn-legend{font-size:12.5px;color:var(--ia-text-muted);line-height:1.55;border-left:2px solid var(--ia-border-strong,var(--ia-border));padding:2px 0 2px 12px;margin:0 0 18px;max-width:860px}
.rcvn-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px 18px}
.rcvn-two{display:grid;grid-template-columns:1fr 1fr;gap:14px 18px}
.rcvn-h{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-muted);margin:0 0 6px}
.rcvn-fine{font-size:12.5px;color:var(--ia-text-muted);line-height:1.5}
.rcvn-pos td{vertical-align:middle}
@media(max-width:640px){.rcvn-grid,.rcvn-two{grid-template-columns:1fr}}
</style>
<script>
(function () {
  var url = @json(route('tenant.inventory.receiving.open-pos'));
  var vendor = document.getElementById('rcvn-vendor'), loc = document.getElementById('rcvn-loc'), box = document.getElementById('rcvn-pos');
  var blank = document.querySelector('.rcvn-blank');
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
  function load() {
    var v = vendor.value, l = loc.value, vname = vendor.options[vendor.selectedIndex].text;
    var lname = loc.tagName === 'SELECT' ? loc.options[loc.selectedIndex].text : @json($locations->first()->name);
    blank.style.display = v ? 'none' : '';
    if (!v) { box.innerHTML = '<div class="rcvn-fine">No vendor: add lines by scanning, as before. Units go into stock; special orders for those items can still be matched.</div>'; return; }
    box.innerHTML = '<div class="rcvn-fine">Looking for open ' + esc(vname) + ' POs…</div>';
    fetch(url + '?vendor=' + encodeURIComponent(v) + '&location=' + encodeURIComponent(l), { headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); }).then(function (d) {
        var other = (d.elsewhere || []).join(', ');
        if (!d.pos || !d.pos.length) {
          box.innerHTML = '<div class="rcvn-fine">No open ' + esc(vname) + ' POs ship to ' + esc(lname) + '.' + (other ? ' (' + esc(other) + ' — change the location to receive those.)' : '')
            + ' You can still start the shipment and scan lines; anything on an open ' + esc(vname) + ' PO links to it as you scan.</div>';
          return;
        }
        box.innerHTML = '<div class="rcvn-h">Open ' + esc(vname) + ' POs for ' + esc(lname) + '</div>'
          + '<div class="ia-table-wrap"><table class="ia-table rcvn-pos"><thead><tr><th style="width:32px"></th><th>PO</th><th>Placed</th><th style="text-align:right">Lines still owed</th><th style="text-align:right">Units still owed</th><th>Expected</th></tr></thead><tbody>'
          + d.pos.map(function (p) {
              return '<tr><td><input type="checkbox" name="po_ids[]" value="' + esc(p.id) + '" checked style="accent-color:var(--ia-accent)"></td><td><b>' + esc(p.po_number) + '</b></td><td>' + esc(p.placed || '') + '</td><td style="text-align:right">' + p.lines + '</td><td style="text-align:right">' + p.units + '</td><td>' + esc(p.expected || '') + '</td></tr>';
            }).join('') + '</tbody></table></div>'
          + (other ? '<div class="rcvn-fine" style="margin-top:8px">' + esc(other) + '. A shipment receives at one location.</div>' : '');
      }).catch(function () { box.innerHTML = '<div class="rcvn-fine">Couldn\'t load POs. You can still start the shipment.</div>'; });
  }
  vendor.addEventListener('change', load);
  if (loc.tagName === 'SELECT') { loc.addEventListener('change', load); }
  load();
})();
</script>

@endsection
