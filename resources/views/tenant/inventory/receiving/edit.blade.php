@extends('layouts.tenant.app')
@php
  $pageTitle = 'Editing ' . $shipment->shipment_number;
  $statusOptions = [
    'expected' => 'Expected',
    'received' => 'Received',
    'backorder' => 'Backorder',
    'unexpected_pending' => 'Pending',
    'unexpected_added' => 'Added',
    'unexpected_hold' => 'On hold',
  ];
@endphp


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
    <h1 class="ia-page-title">{{ $shipment->shipment_number }}</h1>
    <p class="ia-page-subtitle">
      Draft · {{ $vendor ? $vendor->name . ' · ' : '' }}{{ $shipment->location?->name ?? '—' }} ·
      Started {{ $shipment->created_at->diffForHumans() }}
      {{-- MARKER-PO --}}
      @if($pos->isNotEmpty())
        · from @foreach($pos as $p)<a class="rcv-a" href="{{ route('tenant.purchase-orders.show', ['id' => $p->id]) }}">{{ $p->po_number }}</a>@if(! $loop->last), @endif @endforeach
      @endif
    </p>
  </div>
  <div class="ia-page-actions">
    <a href="{{ route('tenant.inventory.receiving.index') }}" class="ia-btn ia-btn--ghost">← Back</a>
    {{-- in-app confirm (this was the browser's native confirm box) --}}
    <form method="POST" action="{{ route('tenant.inventory.receiving.destroy', ['id' => $shipment->id]) }}"
          style="display:inline" id="rcv-delete-form">
      @csrf @method('DELETE')
      <button type="button" class="ia-btn ia-btn--ghost" style="color:var(--ia-danger,#ff8080)"
              onclick="IntakeConfirm.show({ title: 'Delete this draft shipment?', message: @js($pos->isNotEmpty() ? 'Nothing has gone into stock yet. The POs stay exactly as they are.' : 'Nothing has gone into stock yet.'), confirmText: 'Delete draft', cancelText: 'Keep it', danger: true }).then(function (ok) { if (ok) document.getElementById('rcv-delete-form').submit(); })">Delete draft</button>
    </form>
  </div>
</div>

@if(session('flash'))
  <div class="ia-flash ia-flash--{{ session('flash')['type'] }}">{{ session('flash')['message'] }}</div>
@endif

<form method="POST" action="{{ route('tenant.inventory.receiving.update', ['id' => $shipment->id]) }}"
      class="ia-card" style="margin-bottom:14px">
  @csrf @method('PATCH')
  <div class="ia-card-body" style="padding:16px 20px">
    <div style="display:grid;grid-template-columns:1.2fr 1fr 1fr;gap:12px 16px">
      <div class="ia-field">
        <label class="ia-label">Shipment number</label>
        <input name="shipment_number" class="ia-input" value="{{ $shipment->shipment_number }}" required maxlength="30">
      </div>
      <div class="ia-field">
        <label class="ia-label">Received date</label>
        <input name="received_date" type="date" class="ia-input" value="{{ $shipment->received_date?->toDateString() }}" required>
      </div>
      @if(! $vendor)
      <div class="ia-field">
        <label class="ia-label">Distributor</label>
        <input name="distributor_name" class="ia-input" value="{{ $shipment->distributor_name }}" maxlength="128">
      </div>
      <div class="ia-field">
        <label class="ia-label">Distributor code</label>
        <input name="distributor_code" class="ia-input" value="{{ $shipment->distributor_code }}" maxlength="32">
      </div>
      @endif
      {{-- MARKER-PO: the vendor's invoice, checked against what's received --}}
      <div class="ia-field">
        <label class="ia-label">Invoice #</label>
        <input name="vendor_invoice_number" class="ia-input" value="{{ $shipment->vendor_invoice_number }}" maxlength="64">
      </div>
      <div class="ia-field">
        <label class="ia-label">Invoice date</label>
        <input name="vendor_invoice_date" type="date" class="ia-input" value="{{ $shipment->vendor_invoice_date?->toDateString() }}">
      </div>
      <div class="ia-field">
        <label class="ia-label">Invoice total</label>
        <input name="invoice_total_dollars" type="text" inputmode="decimal" class="ia-input" style="text-align:right"
               value="{{ $shipment->invoice_total_cents !== null ? number_format($shipment->invoice_total_cents / 100, 2, '.', '') : '' }}" placeholder="0.00">
      </div>
      <div class="ia-field">
        <label class="ia-label">Freight</label>
        <input name="shipping_cost_dollars" type="text" inputmode="decimal" class="ia-input" style="text-align:right"
               value="{{ number_format($shipment->shipping_cost_cents / 100, 2, '.', '') }}"
               placeholder="0.00">
      </div>
      <div class="ia-field">
        <label class="ia-label">Spread freight</label>
        <select name="freight_method" class="ia-input">
          @foreach(['value' => 'By line value', 'units' => 'By units', 'off' => "Don't spread"] as $fk => $fl)
            <option value="{{ $fk }}" @selected(($shipment->freight_method ?: 'value') === $fk)>{{ $fl }}</option>
          @endforeach
        </select>
      </div>
      <div class="ia-field" style="grid-column:1 / -1">
        <label class="ia-label">Notes</label>
        <textarea name="notes" class="ia-input" rows="2" maxlength="2000">{{ $shipment->notes }}</textarea>
      </div>
    </div>
    <div style="display:flex;justify-content:flex-end;margin-top:14px">
      <button type="submit" class="ia-btn ia-btn--secondary">Save details</button>
    </div>
  </div>
</form>

<div id="rcv-summary">
  @include('tenant.inventory.receiving._partials.summary')
</div>

<div class="rcv-legend">
  <b>Nothing changes in stock until you commit.</b> Received units go into stock at {{ $shipment->location?->name ?? 'this location' }} and their cost rolls onto the item, plus its share of freight.
  @if($pos->isNotEmpty()) Special orders on a PO line are filled oldest first, customers before stock. Whatever isn't received stays owed on its PO unless you cancel it. @endif
</div>

<div style="display:flex;justify-content:space-between;align-items:center;margin:0 0 8px 0;gap:8px;flex-wrap:wrap">
  <h2 style="font-size:15px;margin:0">Line items</h2>
  <span style="display:flex;gap:6px">
    @if($vendor)
      <button type="button" class="ia-btn ia-btn--ghost" id="rcv-pull" style="padding:4px 10px;font-size:12.5px">+ Lines from another {{ $vendor->name }} PO</button>
    @endif
    <button type="button" class="ia-btn ia-btn--ghost" onclick="rcvNewItem()"
            style="padding:4px 10px;font-size:12.5px;color:var(--ia-accent,#BEF264)">+ New item</button>
  </span>
</div>

<div class="ia-table-wrap">
<table class="ia-table" id="rcv-lines">
  <thead>
    <tr>
      <th>Item</th>
      <th>PO</th>
      <th>For</th>
      <th class="rcv-r">{{ $pos->isNotEmpty() ? 'Still owed' : 'Expected' }}</th>
      <th class="rcv-r">Received</th>
      <th>Result</th>
      <th class="rcv-r">Unit cost</th>
      <th class="rcv-r">Landed</th>
      <th></th>
    </tr>
  </thead>
  <tbody id="rcv-tbody">
    @include('tenant.inventory.receiving._partials.lines')
  </tbody>
</table>
</div>

<style>
  .rcv-legend{font-size:12.5px;color:var(--ia-text-muted);line-height:1.55;border-left:2px solid var(--ia-border-strong,var(--ia-border));padding:2px 0 2px 12px;margin:0 0 14px;max-width:900px}
  .rcv-legend b{color:var(--ia-text);font-weight:600}
  .rcv-banner{border:0.5px solid rgba(245,196,81,.4);background:rgba(245,196,81,.08);border-radius:var(--ia-r-md);padding:10px 12px;font-size:13px;margin:0 0 14px;line-height:1.5}
  .rcv-banner b{color:#c9972a}
  .rcv-stats{display:grid;grid-template-columns:repeat(4,1fr);border-top:1px solid var(--ia-border);border-bottom:1px solid var(--ia-border);margin-bottom:14px}
  .rcv-stats div{padding:10px 14px;border-right:1px solid var(--ia-border)}
  .rcv-stats div:last-child{border-right:0}
  .rcv-stats span{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--ia-text-muted)}
  .rcv-stats b{font-size:22px;font-weight:600}
  .rcv-stats b.acc{color:var(--ia-accent)} .rcv-stats b.amb{color:#c9972a}
  body.ia-theme-b .rcv-stats b.acc{color:#3B6D11}
  #rcv-lines td{vertical-align:top}
  .rcv-item{min-width:170px}
  .rcv-nm{font-weight:500}
  .rcv-sub{font-size:11.5px;color:var(--ia-text-muted);margin-top:2px}
  .rcv-sub code{font-size:11px;color:var(--ia-accent)}
  .rcv-warn{color:#c9972a}
  .rcv-muted{color:var(--ia-text-muted)}
  .rcv-r{text-align:right}
  .rcv-nw{white-space:nowrap}
  .rcv-a{color:var(--ia-accent);text-decoration:none}
  body.ia-theme-b .rcv-a{color:#3B6D11}
  .rcv-num{width:70px;padding:3px 6px;text-align:right}
  .rcv-cost{width:80px}
  .rcv-sel{padding:3px 6px;font-size:12px}
  .rcv-date{width:136px;padding:3px 6px;font-size:12px}
  .rcv-icon{padding:2px 6px;color:var(--ia-text-muted)}
  .rcv-unexp td{background:rgba(244,180,0,.05)}
  .rcv-chip{display:inline-block;font-size:11px;font-weight:600;border-radius:99px;padding:2px 8px;margin:1px 4px 1px 0;background:rgba(143,184,240,.10);color:#8FB8F0;white-space:nowrap}
  .rcv-chip.done{background:rgba(190,242,100,.10);color:var(--ia-accent)}
  body.ia-theme-b .rcv-chip.done{color:#3B6D11}
  .rcv-chip.stock{background:rgba(200,200,200,.08);color:var(--ia-text-muted)}
  .rcv-pill{display:inline-block;font-size:11px;font-weight:700;border-radius:99px;padding:2px 9px;background:rgba(200,200,200,.08);color:var(--ia-text-muted);margin-right:6px}
  .rcv-pill.ok{background:rgba(190,242,100,.10);color:var(--ia-accent)}
  body.ia-theme-b .rcv-pill.ok{color:#3B6D11}
  .rcv-pill.warn{background:rgba(245,196,81,.12);color:#c9972a}
  .rcv-pill.bad{background:rgba(248,113,113,.12);color:#F87171}
  .rcv-rest{display:flex;flex-direction:column;gap:4px;margin-top:6px;font-size:12px}
  .rcv-rest label{display:flex;align-items:center;gap:6px;white-space:nowrap}
  .rcv-rest input[type=radio]{accent-color:var(--ia-accent)}
  .rcv-link{display:inline-block;font-size:11.5px;color:var(--ia-text-muted);text-decoration:underline;margin-right:6px}
  .rcv-addlbl{color:var(--ia-accent,#BEF264);font-weight:500;font-size:13px}
  #rcv-newline td{background:rgba(190,242,100,.03)}
  #rcv-newline-sku{padding:5px 9px;font-size:12.5px;width:100%;max-width:360px}
  .rcv-dlg .ia-confirm-card{max-width:560px}
  .rcv-dlg table{width:100%;border-collapse:collapse;font-size:13px;margin-bottom:14px}
  .rcv-dlg td{padding:8px 4px;border-bottom:0.5px solid var(--ia-border);vertical-align:top;color:var(--ia-text)}
  .rcv-dlg td:last-child{text-align:right;color:var(--ia-text-muted)}
  .rcv-dlg .rcv-f{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px}
  .rcv-dlg label.rcv-fl{display:flex;flex-direction:column;gap:4px;font-size:12px;color:var(--ia-text-muted)}
  @media(max-width:700px){.rcv-stats{grid-template-columns:1fr 1fr}}
</style>

{{-- serial numbers for serialized lines. Scan each
     unit; the shipment commits only when every serialized line has one serial
     per unit received. Filled from /serials so lines added on the page appear. --}}
<div id="rcv-serials" class="ia-card" style="margin-top:14px" hidden>
  <div class="ia-card-head"><span class="ia-card-title">Serial numbers</span></div>
  <div class="ia-card-body">
    <div style="font-size:12.5px;color:var(--ia-text-muted);line-height:1.6;margin-bottom:10px">
      These items track serial numbers. Scan or type each unit's serial and press Enter if it's handy — scanning past the count
      raises Received. It's optional: commit whenever you're ready, and any you skip show as <b>needs a serial</b> on the item.
    </div>
    <div id="rcv-serials-body"></div>
  </div>
</div>
<style>
  .rcvs-line { padding: 10px 0; border-top: .5px solid var(--ia-border); }
  .rcvs-line:first-child { border-top: 0; }
  .rcvs-h { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 6px; }
  .rcvs-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }
  .rcvs-chip { display: inline-flex; align-items: center; gap: 6px; font-family: var(--ia-font-mono, monospace); font-size: 12px;
    background: var(--ia-surface-2, rgba(255,255,255,.05)); border: .5px solid var(--ia-border); border-radius: 99px; padding: 3px 4px 3px 10px; }
  .rcvs-chip button { background: none; border: 0; color: var(--ia-text-muted); cursor: pointer; font-size: 13px; padding: 0 4px; }
  .rcvs-ok { color: var(--ia-accent); font-size: 12px; font-weight: 600; }
  .rcvs-short { color: var(--ia-text-muted); font-size: 12px; font-weight: 500; } /* not a warning */
</style>
<script>
(function () {
  var box = document.getElementById('rcv-serials'), body = document.getElementById('rcv-serials-body');
  if (!box) { return; }
  var urlList = @json(route('tenant.inventory.receiving.serials', $shipment->id)); var urlLine = {{ \Illuminate\Support\Js::from(route('tenant.inventory.receiving.serials.add', ['id' => $shipment->id, 'itemId' => '__L__'])) }};
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content;
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };
  var toast = function (ok, m) { if (window.IntakeToast) { IntakeToast[ok ? 'success' : 'error'](m); } };
  var req = function (method, url, data) {
    return fetch(url, { method: method, headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
      body: data ? JSON.stringify(data) : undefined }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); });
  };
  var syncQty = function (lineId, received) {
    var inp = document.querySelector('#rcv-tbody tr[data-line-id="' + lineId + '"] input[data-field="received_quantity"]');
    if (inp && String(inp.value) !== String(received)) { inp.value = received; }
  };
  var render = function (lines, draft) {
    box.hidden = !lines.length;
    body.innerHTML = lines.map(function (l) {
      var n = l.serials.length, short = n !== l.received;
      return '<div class="rcvs-line" data-line="' + esc(l.id) + '">'
        + '<div class="rcvs-h"><div><b>' + esc(l.name) + '</b>' + (l.sku ? ' <span style="color:var(--ia-text-muted);font-size:12px">' + esc(l.sku) + '</span>' : '') + '</div>'
        + '<span class="' + (short ? 'rcvs-short' : 'rcvs-ok') + '">' + n + ' of ' + l.received + (short ? ' scanned' : ' ✓') + '</span></div>'
        + '<div class="rcvs-chips">' + l.serials.map(function (s) {
            return '<span class="rcvs-chip">' + esc(s) + (draft ? '<button type="button" data-rm="' + esc(s) + '" aria-label="Remove">×</button>' : '') + '</span>';
          }).join('') + '</div>'
        + (draft ? '<input class="ia-input rcvs-in" placeholder="Scan or type the next serial, then Enter" maxlength="100" style="max-width:360px;font-family:var(--ia-font-mono,monospace)">' : '')
        + '</div>';
    }).join('');
  };
  var load = function () {
    fetch(urlList, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); }).then(function (j) { if (j && j.ok) { render(j.lines, j.draft); } }).catch(function () {});
  };
  body.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' || !e.target.classList.contains('rcvs-in')) { return; }
    e.preventDefault();
    var inp = e.target, v = inp.value.trim(), line = inp.closest('[data-line]').getAttribute('data-line');
    if (!v) { return; }
    inp.disabled = true;
    req('POST', urlLine.replace('__L__', line), { serial: v }).then(function (res) {
      inp.disabled = false;
      if (res.ok && res.body.ok) { syncQty(line, res.body.received); load(); setTimeout(function () {
          var again = body.querySelector('[data-line="' + line + '"] .rcvs-in'); if (again) { again.focus(); } }, 250);
      } else { toast(false, (res.body && res.body.message) || 'Could not add that serial.'); inp.select(); }
    }).catch(function () { inp.disabled = false; toast(false, 'Network error.'); });
  });
  body.addEventListener('click', function (e) {
    var b = e.target.closest('[data-rm]'); if (!b) { return; }
    var line = b.closest('[data-line]').getAttribute('data-line');
    req('DELETE', urlLine.replace('__L__', line), { serial: b.getAttribute('data-rm') }).then(function (res) {
      if (res.ok && res.body.ok) { load(); } else { toast(false, (res.body && res.body.message) || 'Could not remove it.'); }
    });
  });
  // Lines are added and edited in place above; follow them.
  var t = null, soon = function () { clearTimeout(t); t = setTimeout(load, 700); };
  var tb = document.getElementById('rcv-tbody');
  if (tb) {
    tb.addEventListener('change', soon);
    if (window.MutationObserver) { new MutationObserver(soon).observe(tb, { childList: true }); }
  }
  load();
})();
</script>

<div style="display:flex;justify-content:space-between;align-items:center;margin-top:18px;padding-top:16px;border-top:1px solid var(--ia-border)">
  <div id="rcv-commit-note" style="font-size:13px;color:var(--ia-text-muted)">
    Commits <strong id="rcv-commit-lines" style="color:var(--ia-accent)">0 items</strong>,
    <strong id="rcv-commit-units" style="color:var(--ia-accent)">0 units</strong>.
    @if($pos->isNotEmpty()) Lines left at 0 stay owed on their PO unless you cancel them. @else Backorder + unexpected lines stay on the shipment but won't write movements. @endif
  </div>
  {{-- patch-90 SO auto-link card — appears before commit form.
       Lists matched 'ordered' SOs for received items.
       Hidden inputs (so_arrivals[so_id] = qty) ride along with the commit POST. --}}
  @isset($matchedSos)
    @if($matchedSos->isNotEmpty() || ($neededHintCount ?? 0) > 0)
      <div class="ia-card" style="margin-bottom:14px;border-left:3px solid var(--ia-accent)">
        <div class="ia-card-body" style="padding:14px 18px">
          <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px">
            <strong style="font-size:13px">Match special orders</strong>
            <span style="font-size:11.5px;color:var(--ia-text-muted)">
              {{ $matchedSos->count() }} open SO{{ $matchedSos->count() === 1 ? '' : 's' }} match received items
            </span>
          </div>

          @if($matchedSos->isNotEmpty())
            <p style="font-size:12.5px;color:var(--ia-text-muted);margin:0 0 12px;line-height:1.55">
              Auto-matching these special orders will mark them arrived during commit. Uncheck any you don't want to claim from this shipment. Set received qty below the SO total for a partial receipt (the remainder stays on order as a sibling row).
            </p>

            <table class="ia-table" style="margin-bottom:8px">
              <thead>
                <tr>
                  <th style="width:32px"></th>
                  <th>SO</th>
                  <th>Item</th>
                  <th>For</th>
                  <th style="width:90px">SO qty</th>
                  <th style="width:120px">Mark arrived</th>
                </tr>
              </thead>
              <tbody>
                @foreach($matchedSos as $so)
                  <tr>
                    <td>
                      <input type="checkbox" class="rcv-so-match-cb" checked
                             data-so-id="{{ $so->id }}"
                             onchange="rcvToggleSoMatch(this)">
                    </td>
                    <td>
                      <a href="{{ route('tenant.special-orders.show', ['id' => $so->id]) }}"
                         target="_blank" style="font-weight:600">{{ $so->so_number }}</a>
                    </td>
                    <td>
                      {{ $so->item_name_snapshot }}
                      @if($so->vendor)<div style="font-size:11px;color:var(--ia-text-muted)">via {{ $so->vendor->name }}</div>@endif
                    </td>
                    <td>
                      @if($so->customer)
                        {{ $so->customer->fullName() }}
                        @if($so->appointment)<div style="font-size:11px;color:var(--ia-text-muted)">{{ $so->appointment->ra_number }}</div>@endif
                      @else
                        <span style="color:var(--ia-text-muted)">Shop stock</span>
                      @endif
                    </td>
                    <td>{{ $so->quantity }}</td>
                    <td>
                      <input type="number" class="ia-input rcv-so-qty" min="1" max="{{ $so->quantity }}"
                             value="{{ $so->quantity }}"
                             data-so-id="{{ $so->id }}"
                             style="padding:4px 8px;font-size:12px"
                             onchange="rcvUpdateSoQty(this)">
                    </td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          @endif

          @if(($neededHintCount ?? 0) > 0)
            <p style="font-size:12px;color:var(--ia-amber);margin:8px 0 0;padding:8px 10px;background:rgba(245,158,11,0.06);border-radius:4px">
              <strong>{{ $neededHintCount }} 'needed' SO{{ $neededHintCount === 1 ? '' : 's' }}</strong> also exist{{ $neededHintCount === 1 ? 's' : '' }} for these items. They won't auto-link — promote them to 'ordered' first if you want to fulfill from this shipment.
            </p>
          @endif
        </div>
      </div>
    @endif
  @endisset

    <form method="POST" action="{{ route('tenant.inventory.receiving.commit', ['id' => $shipment->id]) }}"
        id="rcv-commit-form" onsubmit="return rcvConfirmCommit(event);">
    @csrf
    {{-- patch-90 SO hidden fields — populated by JS from the SO match card.
         Format: so_arrivals[<so_uuid>] = <received_qty> --}}
    <div id="rcv-so-hidden-fields">
      @isset($matchedSos)
        @foreach($matchedSos as $so)
          <input type="hidden" name="so_arrivals[{{ $so->id }}]" value="{{ $so->quantity }}" data-so-id="{{ $so->id }}">
        @endforeach
      @endisset
    </div>
    {{-- MARKER-LABELS: counts what has been received so far --}}
    <button type="button" class="ia-btn ia-btn--secondary" style="margin-right:8px"
            onclick="if (document.activeElement) document.activeElement.blur(); setTimeout(function () { openLabels({ shipment: @js((string) $shipment->id) }); }, 400)">Print labels</button>
    <button type="submit" class="ia-btn ia-btn--primary" id="rcv-commit-btn"
            @if($shipment->received_count === 0 && $pos->isEmpty()) disabled @endif>
      Commit shipment
    </button>
  </form>
</div>

<div id="rcv-item-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:200;align-items:flex-start;justify-content:center;padding-top:60px;overflow-y:auto">
  <div style="background:var(--ia-card,#111);border:1px solid var(--ia-border);border-radius:8px;padding:18px 22px;width:94%;max-width:680px;margin-bottom:60px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
      <h3 style="font-size:15px;margin:0" id="rcv-item-modal-title">Item</h3>
      <button type="button" class="ia-btn ia-btn--ghost" onclick="rcvCloseItemModal()" style="padding:2px 8px">×</button>
    </div>

    <div id="rcv-item-modal-error" style="display:none;padding:8px 12px;background:rgba(255,80,80,.12);border:1px solid rgba(255,80,80,.3);border-radius:4px;margin-bottom:12px;font-size:12.5px;color:#ff8080"></div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px 16px">
      <div class="ia-field">
        <label class="ia-label">SKU *</label>
        <input type="text" class="ia-input" id="rcv-item-sku" maxlength="64" required>
      </div>
      <div class="ia-field">
        <label class="ia-label">Category *</label>
        <select class="ia-input" id="rcv-item-category" required>
          <option value="">— pick category —</option>
        </select>
      </div>
      <div class="ia-field" style="grid-column:1 / -1">
        <label class="ia-label">Name *</label>
        <input type="text" class="ia-input" id="rcv-item-name" maxlength="255" required>
      </div>
      <div class="ia-field" style="grid-column:1 / -1">
        <label class="ia-label">Description</label>
        <textarea class="ia-input" id="rcv-item-description" rows="2"></textarea>
      </div>
      <div class="ia-field">
        <label class="ia-label">Cost</label>
        <input type="text" inputmode="decimal" class="ia-input" id="rcv-item-cost" placeholder="0.00">
      </div>
      <div class="ia-field">
        <label class="ia-label">Sell price</label>
        <input type="text" inputmode="decimal" class="ia-input" id="rcv-item-sell" placeholder="0.00">
      </div>
      <div class="ia-field">
        <label class="ia-label">Case quantity</label>
        <input type="number" min="1" class="ia-input" id="rcv-item-case-qty">
      </div>
      <div class="ia-field">
        <label class="ia-label">Reorder threshold</label>
        <input type="number" min="0" class="ia-input" id="rcv-item-reorder-threshold">
      </div>
      <div class="ia-field">
        <label class="ia-label">Reorder quantity</label>
        <input type="number" min="1" class="ia-input" id="rcv-item-reorder-qty">
      </div>
      <div class="ia-field">
        <label class="ia-label">Bin location</label>
        <input type="text" maxlength="50" class="ia-input" id="rcv-item-bin">
      </div>
      <div class="ia-field" style="display:flex;align-items:center;gap:10px">
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px">
          <input type="checkbox" id="rcv-item-active" checked> Active
        </label>
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px">
          <input type="checkbox" id="rcv-item-oversell"> Allow oversell
        </label>
      </div>
      <div class="ia-field" id="rcv-item-catalog-info" style="display:none;font-size:11.5px;color:var(--ia-text-muted)">
        <span id="rcv-item-catalog-upc"></span>
      </div>
    </div>

    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px">
      <button type="button" class="ia-btn ia-btn--ghost" onclick="rcvCloseItemModal()">Cancel</button>
      <button type="button" class="ia-btn ia-btn--primary" id="rcv-item-save-btn" onclick="rcvSaveItem()">Save</button>
    </div>
  </div>
</div>

<script>
(function () {
  var csrf = '{{ csrf_token() }}';
  var urls = {
    addItem:    '{{ route("tenant.inventory.receiving.items.store",  ["id" => $shipment->id]) }}',
    updateItem: function (lineId) { return '{{ route("tenant.inventory.receiving.items.update", ["id" => $shipment->id, "itemId" => "__LINE__"]) }}'.replace('__LINE__', lineId); },
    removeItem: function (lineId) { return '{{ route("tenant.inventory.receiving.items.destroy", ["id" => $shipment->id, "itemId" => "__LINE__"]) }}'.replace('__LINE__', lineId); },
    reject:     function (lineId) { return '{{ route("tenant.inventory.receiving.items.reject", ["id" => $shipment->id, "itemId" => "__LINE__"]) }}'.replace('__LINE__', lineId); },
    pull:       '{{ route("tenant.inventory.receiving.pull-po", ["id" => $shipment->id]) }}',
    search:     '{{ route("tenant.inventory.items.search") }}',
  };
  // MARKER-PO: open POs that can be pulled onto this shipment
  var PULLABLE = {{ \Illuminate\Support\Js::from($pullable->map(fn ($p) => ['id' => $p->id, 'po_number' => $p->po_number, 'units' => $p->owedUnits(), 'placed' => $p->placed_at?->format('M j')])->values()) }};

  function jsonReq(method, url, body) {
    var opts = {
      method: method,
      headers: {
        'X-CSRF-TOKEN': csrf,
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json',
        'Content-Type': 'application/json',
      },
    };
    if (body) opts.body = JSON.stringify(body);
    return fetch(url, opts).then(function (r) {
      return r.json().then(function (j) { return { ok: r.ok, status: r.status, body: j }; });
    });
  }

  function toastOk(msg)  { if (window.IntakeToast) window.IntakeToast.success(msg); }
  function toastErr(msg) { if (window.IntakeToast) window.IntakeToast.error(msg); }
  function toastInfo(msg){ if (window.IntakeToast) window.IntakeToast.info(msg); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function money(c) { return '$' + (Number(c || 0) / 100).toFixed(2); }

  function applyTotals(t) {
    if (!t) return;
    var lines = document.getElementById('rcv-commit-lines'), units = document.getElementById('rcv-commit-units');
    if (lines) lines.textContent = t.commit_lines + ' items';
    if (units) units.textContent = t.commit_units + ' units';
  }

  // MARKER-PO: the server re-renders the lines and the summary after every
  // change (freight shares and special-order fills depend on all lines).
  function applyPayload(body, focusNew) {
    if (!body) return;
    if (body.tbody != null) { document.getElementById('rcv-tbody').innerHTML = body.tbody; }
    if (body.summary != null) { document.getElementById('rcv-summary').innerHTML = body.summary; }
    applyTotals(body.totals);
    if (focusNew) { var n = document.getElementById('rcv-newline-sku'); if (n) n.focus(); }
  }
  window.rcvApplyPayload = applyPayload;

  function patch(lineId, payload) {
    return jsonReq('PATCH', urls.updateItem(lineId), payload).then(function (res) {
      if (res.ok && res.body && res.body.ok) { applyPayload(res.body); }
      else { toastErr((res.body && res.body.message) || 'Could not save.'); }
    }).catch(function () { toastErr('Network error. Try again.'); });
  }

  var tbody = document.getElementById('rcv-tbody');

  tbody.addEventListener('change', function (e) {
    var cell = e.target;
    var row = cell.closest('tr[data-line-id]');
    if (!row) return;
    var lineId = row.getAttribute('data-line-id');
    if (cell.hasAttribute('data-rcv-rest')) { patch(lineId, { rest_action: cell.value }); return; }
    if (cell.hasAttribute('data-rcv-restdate')) { patch(lineId, { rest_expected_date: cell.value || null }); return; }
    if (!cell.classList.contains('rcv-cell')) return;
    var field = cell.getAttribute('data-field');
    var v = cell.value, payload = {};
    payload[field] = field === 'unit_cost_dollars' ? v : (cell.type === 'number' ? (v === '' ? null : parseInt(v, 10)) : v);
    patch(lineId, payload);
  });

  tbody.addEventListener('keydown', function (e) {
    if (e.target.id === 'rcv-newline-sku') { if (e.key === 'Enter') { e.preventDefault(); addFromInput(e.target); } return; }
    if (e.key === 'Enter' && e.target.classList.contains('rcv-cell')) {
      e.preventDefault();
      e.target.blur();
      setTimeout(function () { var sku = document.getElementById('rcv-newline-sku'); if (sku) sku.focus(); }, 50);
    }
  });

  tbody.addEventListener('click', function (e) {
    var rj = e.target.closest('[data-rcv-reject]');
    if (rj) { e.preventDefault(); rejectDialog(rj); return; }
    var un = e.target.closest('[data-rcv-unreject]');
    if (un) {
      e.preventDefault();
      jsonReq('POST', urls.reject(un.getAttribute('data-rcv-unreject')), { quantity: 0 }).then(function (res) {
        if (res.ok && res.body.ok) { applyPayload(res.body); } else { toastErr((res.body && res.body.message) || 'Could not undo.'); }
      });
    }
  });

  window.rcvRemoveLine = async function (lineId) {
    var row = document.querySelector('#rcv-tbody tr[data-line-id="' + lineId + '"]');
    var onPo = row && row.querySelector('.rcv-a');
    if (!(await iaConfirm(onPo ? 'Remove this line? It stays owed on its PO.' : 'Remove this line?'))) return;
    jsonReq('DELETE', urls.removeItem(lineId)).then(function (res) {
      if (res.ok && res.body && res.body.ok) { applyPayload(res.body); toastOk(res.body.message || 'Line removed'); }
      else { toastErr((res.body && res.body.message) || 'Could not remove.'); }
    }).catch(function () { toastErr('Network error. Try again.'); });
  };

  var submittingNewLine = false;
  function addFromInput(input) {
    if (submittingNewLine) return;
    var raw = input.value.trim();
    if (raw.length < 1) return;
    submittingNewLine = true;
    input.disabled = true;
    fetch(urls.search + '?q=' + encodeURIComponent(raw), { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); }).then(function (j) {
        var results = (j.ok && j.results) ? j.results : [];
        if (results.length === 1) {
          return post({ mode: 'expected', inventory_item_id: results[0].id, expected_quantity: 1, received_quantity: 1 });
        }
        if (results.length > 1) {
          submittingNewLine = false; input.disabled = false; input.focus();
          toastInfo(results.length + ' matches — type more to narrow');
          return;
        }
        var upc = /^[0-9]{8,14}$/.test(raw);
        return post({ mode: 'unexpected', name: raw, sku: upc ? null : raw, upc: upc ? raw : null, expected_quantity: 0, received_quantity: 1 });
      }).catch(function () { submittingNewLine = false; input.disabled = false; toastErr('Network error. Try again.'); });
  }
  function post(payload) {
    return jsonReq('POST', urls.addItem, payload).then(function (res) {
      submittingNewLine = false;
      if (res.ok && res.body && res.body.ok) { applyPayload(res.body, true); if (res.body.message) toastOk(res.body.message); }
      else { var i = document.getElementById('rcv-newline-sku'); if (i) { i.disabled = false; i.select(); } toastErr((res.body && res.body.message) || 'Could not add line.'); }
    });
  }

  // ── small in-app dialog in the app's confirm style ──
  function dialog(title, message, bodyHtml, actions) {
    var back = document.createElement('div');
    back.className = 'ia-confirm-backdrop rcv-dlg';
    back.innerHTML = '<div class="ia-confirm-card" role="dialog" aria-modal="true"><div class="ia-confirm-title"></div>'
      + (message ? '<div class="ia-confirm-message"></div>' : '') + '<div class="rcv-dlg-body"></div><div class="ia-confirm-actions"></div></div>';
    back.querySelector('.ia-confirm-title').textContent = title;
    if (message) back.querySelector('.ia-confirm-message').textContent = message;
    back.querySelector('.rcv-dlg-body').innerHTML = bodyHtml || '';
    var row = back.querySelector('.ia-confirm-actions');
    function close() { back.classList.remove('is-shown'); setTimeout(function () { back.remove(); }, 160); }
    actions.forEach(function (a) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'ia-confirm-btn' + (a.kind ? ' ia-confirm-btn--' + a.kind : '');
      b.textContent = a.label;
      b.addEventListener('click', function () { if (a.run && a.run(back) === false) return; close(); });
      row.appendChild(b);
    });
    back.addEventListener('click', function (e) { if (e.target === back) close(); });
    document.body.appendChild(back);
    void back.offsetWidth; back.classList.add('is-shown');
    return back;
  }

  function rejectDialog(a) {
    var max = parseInt(a.getAttribute('data-max'), 10) || 1, po = a.getAttribute('data-po'), sos = parseInt(a.getAttribute('data-sos'), 10) || 0;
    var body = '<div class="rcv-f"><label class="rcv-fl">How many<input class="ia-input" type="number" min="1" max="' + max + '" value="' + max + '" data-q></label>'
      + '<label class="rcv-fl">Why<select class="ia-input" data-w><option>Damaged</option><option>Wrong item</option><option>Other</option></select></label></div>'
      + (po ? '<div class="so-dlg-field-h" style="margin-bottom:6px">Is the vendor replacing them?</div>'
        + '<label class="so-choice"><input type="radio" name="rcv-rp" value="1" checked><div><b>Yes, they\'re sending replacements</b><span>They stay owed on ' + esc(po) + '.</span></div></label>'
        + '<label class="so-choice"><input type="radio" name="rcv-rp" value="0"><div><b>No</b><span>Cancelled on ' + esc(po) + '.' + (sos ? ' Special orders still waiting on them go back to Needed with a note.' : '') + '</span></div></label>' : '')
      + '<input class="ia-input ia-confirm-input" data-n placeholder="Note (optional, e.g. return authorization number)" style="margin:6px 0 14px">';
    dialog(a.getAttribute('data-name') + ': not accepted', 'Units not accepted don\'t go into stock.', body, [
      { label: 'Go back' },
      { label: 'Not accepted', kind: 'danger', run: function (d) {
        var rp = d.querySelector('input[name="rcv-rp"]:checked');
        jsonReq('POST', urls.reject(a.getAttribute('data-rcv-reject')), {
          quantity: parseInt(d.querySelector('[data-q]').value, 10) || max, reason: d.querySelector('[data-w]').value,
          replace: rp ? rp.value === '1' : true, note: d.querySelector('[data-n]').value || null,
        }).then(function (res) { if (res.ok && res.body.ok) { applyPayload(res.body); toastOk(res.body.message); } else { toastErr((res.body && res.body.message) || 'Could not save.'); } });
      } },
    ]);
  }

  var pullBtn = document.getElementById('rcv-pull');
  if (pullBtn) {
    pullBtn.addEventListener('click', function () {
      if (!PULLABLE.length) {
        dialog('Lines from another PO', 'Every open PO from this vendor that ships here is already on this shipment.', '', [{ label: 'OK', kind: 'primary' }]);
        return;
      }
      dialog('Lines from another PO', 'Adds every line still owed on the PO you pick.',
        PULLABLE.map(function (p, i) {
          return '<label class="so-choice"><input type="radio" name="rcv-pull" value="' + esc(p.id) + '"' + (i ? '' : ' checked') + '><div><b>' + esc(p.po_number) + '</b><span>' + p.units + ' still owed' + (p.placed ? ' · placed ' + esc(p.placed) : '') + '</span></div></label>';
        }).join(''),
        [{ label: 'Cancel' }, { label: 'Add lines', kind: 'primary', run: function (d) {
          var v = d.querySelector('input[name="rcv-pull"]:checked'); if (!v) return false;
          jsonReq('POST', urls.pull, { po_id: v.value }).then(function (res) {
            if (res.ok && res.body.ok) { applyPayload(res.body); toastOk(res.body.message); PULLABLE = PULLABLE.filter(function (p) { return p.id !== v.value; }); }
            else { toastErr((res.body && res.body.message) || 'Could not add those lines.'); }
          });
        } }]);
    });
  }

  // MARKER-PO: commit asks in app with what will happen (was a one-line confirm)
  window.rcvConfirmCommit = function (e) {
    var form = e.target;
    // own flag: confirm.js clears iaConfirmed in its capture listener before this runs
    if (form.dataset.rcvOk === '1') { delete form.dataset.rcvOk; return true; }
    e.preventDefault();
    var plan = {};
    try { plan = JSON.parse(document.getElementById('rcv-plan').textContent || '{}'); } catch (x) {}
    if (plan.blockers && plan.blockers.length) {
      dialog('Not in your catalog yet', plan.blockers.join(', ') + ' has to be created before it can go into stock. Use "Create item" on its line, or remove the line.', '', [{ label: 'OK', kind: 'primary' }]);
      return false;
    }
    var rows = '<tr><td>Into stock at ' + esc(plan.location || 'this location') + '</td><td>' + (plan.units || 0) + ' units, ' + (plan.items || 0) + ' items</td></tr>'
      + (plan.freight ? '<tr><td>Freight on cost</td><td>' + money(plan.freight) + ' ' + esc(plan.freightMethod) + '</td></tr>' : '')
      + ((plan.fill || []).length ? '<tr><td>Special orders arrive</td><td>' + plan.fill.map(esc).join(', ') + '</td></tr>' : '')
      + (plan.still || []).map(function (x) { return '<tr><td>' + esc(x.name) + ' on ' + esc(x.po) + '</td><td>' + (x.cancel ? x.qty + ' cancelled' : x.qty + ' still owed' + (x.date ? ', expected ' + esc(x.date) : '')) + '</td></tr>'; }).join('')
      + (plan.rejected || []).map(function (x) { return '<tr><td>' + esc(x.name) + ' not accepted</td><td>' + x.qty + (x.replace ? ' still owed' : ' cancelled') + '</td></tr>'; }).join('')
      + (plan.invoiceFlag ? '<tr><td colspan="2" style="color:#c9972a;text-align:left">Invoice doesn\'t match — the shipment stays flagged.</td></tr>' : '');
    dialog('Commit {{ $shipment->shipment_number }}?', 'This puts the received units into stock. It can\'t be edited afterwards.', '<table>' + rows + '</table>', [
      { label: 'Go back' },
      { label: 'Commit', kind: 'primary', run: function () { form.dataset.rcvOk = '1'; if (typeof form.requestSubmit === 'function') { form.requestSubmit(); } else { form.submit(); } } },
    ]);
    return false;
  };

  setTimeout(function () { var n = document.getElementById('rcv-newline-sku'); if (n) n.focus(); }, 100);

  // ─── Item modal (edit existing + create new) ──────────────────────
  var modalMode = null;             // 'edit' | 'create'
  var modalEditingItemId = null;    // when mode=edit
  var modalLineId = null;           // the receiving line that opened the modal (mode=edit)
  var modalCategoriesLoaded = false;

  function ensureCategoriesLoaded() {
    if (modalCategoriesLoaded) return Promise.resolve();
    return fetch('{{ route("tenant.inventory.receiving.categories.list") }}', {
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j.ok) return;
      var sel = document.getElementById('rcv-item-category');
      sel.innerHTML = '<option value="">— pick category —</option>';
      j.categories.forEach(function (c) {
        var opt = document.createElement('option');
        opt.value = c.id;
        opt.textContent = c.name;
        sel.appendChild(opt);
      });
      modalCategoriesLoaded = true;
    });
  }

  function clearItemModal() {
    document.getElementById('rcv-item-sku').value = '';
    document.getElementById('rcv-item-name').value = '';
    document.getElementById('rcv-item-description').value = '';
    document.getElementById('rcv-item-cost').value = '';
    document.getElementById('rcv-item-sell').value = '';
    document.getElementById('rcv-item-case-qty').value = '';
    document.getElementById('rcv-item-reorder-threshold').value = '';
    document.getElementById('rcv-item-reorder-qty').value = '';
    document.getElementById('rcv-item-bin').value = '';
    document.getElementById('rcv-item-active').checked = true;
    document.getElementById('rcv-item-oversell').checked = false;
    document.getElementById('rcv-item-category').value = '';
    document.getElementById('rcv-item-catalog-info').style.display = 'none';
    document.getElementById('rcv-item-modal-error').style.display = 'none';
  }

  function showModalError(msg) {
    var box = document.getElementById('rcv-item-modal-error');
    box.textContent = msg;
    box.style.display = '';
  }

  // MARKER-PO: with a line id, the new item is that line's (and its PO line's)
  window.rcvNewItem = function (lineId, name, sku) {
    modalMode = 'create';
    modalEditingItemId = null;
    modalLineId = lineId || null;
    document.getElementById('rcv-item-modal-title').textContent = lineId ? 'Create the item for this line' : '+ New item';
    document.getElementById('rcv-item-save-btn').textContent = lineId ? 'Create item' : 'Create + add line';
    clearItemModal();
    if (name) { document.getElementById('rcv-item-name').value = name; }
    if (sku) { document.getElementById('rcv-item-sku').value = sku; }
    ensureCategoriesLoaded().then(function () {
      document.getElementById('rcv-item-modal').style.display = 'flex';
      setTimeout(function () { document.getElementById('rcv-item-sku').focus(); }, 50);
    });
  };

  window.rcvEditItem = function (itemId, lineId) {
    modalMode = 'edit';
    modalEditingItemId = itemId;
    modalLineId = lineId;
    document.getElementById('rcv-item-modal-title').textContent = 'Edit item';
    document.getElementById('rcv-item-save-btn').textContent = 'Save';
    clearItemModal();
    ensureCategoriesLoaded().then(function () {
      var url = '{{ route("tenant.inventory.receiving.items.quick.show", ["id" => "__ID__"]) }}'.replace('__ID__', itemId);
      return fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } });
    }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j.ok || !j.item) {
        toastErr(j.message || 'Could not load item.');
        return;
      }
      var it = j.item;
      document.getElementById('rcv-item-sku').value = it.sku || '';
      document.getElementById('rcv-item-name').value = it.name || '';
      document.getElementById('rcv-item-description').value = it.description || '';
      document.getElementById('rcv-item-cost').value = it.shop_cost_dollars || '';
      document.getElementById('rcv-item-sell').value = it.shop_sell_price_dollars || '';
      document.getElementById('rcv-item-case-qty').value = it.shop_case_quantity || '';
      document.getElementById('rcv-item-reorder-threshold').value = it.shop_reorder_threshold || '';
      document.getElementById('rcv-item-reorder-qty').value = it.shop_reorder_quantity || '';
      document.getElementById('rcv-item-bin').value = it.shop_bin_location || '';
      document.getElementById('rcv-item-active').checked = !!it.is_active;
      document.getElementById('rcv-item-oversell').checked = !!it.allow_oversell;
      document.getElementById('rcv-item-category').value = it.category_id || '';
      if (it.catalog_upc) {
        document.getElementById('rcv-item-catalog-info').style.display = '';
        document.getElementById('rcv-item-catalog-upc').textContent = 'Catalog UPC: ' + it.catalog_upc;
      }
      document.getElementById('rcv-item-modal').style.display = 'flex';
      setTimeout(function () { document.getElementById('rcv-item-name').focus(); }, 50);
    });
  };

  window.rcvCloseItemModal = function () {
    document.getElementById('rcv-item-modal').style.display = 'none';
    modalMode = null;
    modalEditingItemId = null;
    modalLineId = null;
  };

  window.rcvSaveItem = function () {
    var btn = document.getElementById('rcv-item-save-btn');
    var origLabel = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Saving…';
    document.getElementById('rcv-item-modal-error').style.display = 'none';

    var payload = {
      category_id: document.getElementById('rcv-item-category').value,
      sku:         document.getElementById('rcv-item-sku').value.trim(),
      name:        document.getElementById('rcv-item-name').value.trim(),
      description: document.getElementById('rcv-item-description').value.trim() || null,
      shop_cost_dollars:       document.getElementById('rcv-item-cost').value.trim() || null,
      shop_sell_price_dollars: document.getElementById('rcv-item-sell').value.trim() || null,
      shop_case_quantity:      document.getElementById('rcv-item-case-qty').value || null,
      shop_reorder_threshold:  document.getElementById('rcv-item-reorder-threshold').value || null,
      shop_reorder_quantity:   document.getElementById('rcv-item-reorder-qty').value || null,
      shop_bin_location:       document.getElementById('rcv-item-bin').value.trim() || null,
      is_active:      document.getElementById('rcv-item-active').checked,
      allow_oversell: document.getElementById('rcv-item-oversell').checked,
    };

    if (!payload.sku || !payload.name || !payload.category_id) {
      btn.disabled = false; btn.textContent = origLabel;
      showModalError('SKU, name, and category are required.');
      return;
    }

    if (modalMode === 'edit') {
      var url = '{{ route("tenant.inventory.receiving.items.quick.update", ["id" => "__ID__"]) }}'.replace('__ID__', modalEditingItemId);
      jsonReq('PATCH', url, payload).then(function (res) {
        btn.disabled = false; btn.textContent = origLabel;
        if (res.ok && res.body && res.body.ok) {
          if (modalLineId) {
            var row = document.querySelector('#rcv-tbody tr[data-line-id="' + modalLineId + '"]');
            if (row) {
              var nameDiv = row.querySelector('td:first-child div:first-child');
              if (nameDiv) nameDiv.textContent = res.body.item.name;
              var skuCode = row.querySelector('td:nth-child(2) code');
              if (skuCode) skuCode.textContent = res.body.item.sku;
            }
          }
          rcvCloseItemModal();
          toastOk('Item saved');
        } else {
          showModalError((res.body && res.body.message) || 'Could not save.');
        }
      }).catch(function () {
        btn.disabled = false; btn.textContent = origLabel;
        showModalError('Network error. Try again.');
      });
    } else {
      if (modalLineId) { payload.line_id = modalLineId; } else { payload.add_as_line = true; payload.received_quantity = 1; }
      var createUrl = '{{ route("tenant.inventory.receiving.items.quick.create", ["id" => $shipment->id]) }}';
      jsonReq('POST', createUrl, payload).then(function (res) {
        btn.disabled = false; btn.textContent = origLabel;
        if (res.ok && res.body && res.body.ok) {
          applyPayload(res.body);
          rcvCloseItemModal();
          toastOk('Item created and added to shipment');
        } else {
          showModalError((res.body && res.body.message) || 'Could not create.');
        }
      }).catch(function () {
        btn.disabled = false; btn.textContent = origLabel;
        showModalError('Network error. Try again.');
      });
    }
  };

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && document.getElementById('rcv-item-modal').style.display === 'flex') {
      rcvCloseItemModal();
    }
  });
})();
</script>

@include('tenant.inventory._labels')
@endsection

@push('scripts')
<script>
// patch-90 SO match card handlers — keep hidden so_arrivals[] in sync
// with checkbox state and qty inputs.
window.rcvToggleSoMatch = function (cb) {
  var soId = cb.dataset.soId;
  var hidden = document.querySelector('input[type="hidden"][data-so-id="' + soId + '"]');
  if (!hidden) return;
  if (cb.checked) {
    // restore from the qty input
    var qtyInput = document.querySelector('.rcv-so-qty[data-so-id="' + soId + '"]');
    hidden.value = qtyInput ? qtyInput.value : '0';
    hidden.name = 'so_arrivals[' + soId + ']';
  } else {
    // disable participation by clearing name (browser won't submit it)
    hidden.name = '__skipped_so_' + soId;
  }
};
window.rcvUpdateSoQty = function (input) {
  var soId = input.dataset.soId;
  var cb = document.querySelector('.rcv-so-match-cb[data-so-id="' + soId + '"]');
  var hidden = document.querySelector('input[type="hidden"][data-so-id="' + soId + '"]');
  if (!hidden || !cb) return;
  if (cb.checked) {
    hidden.value = input.value;
  }
};
</script>
@endpush

