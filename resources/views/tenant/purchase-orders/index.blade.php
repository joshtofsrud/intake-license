@extends('layouts.tenant.app')
@php $pageTitle = 'Purchase orders'; $money = fn ($c) => '$' . number_format(((int) $c) / 100, 2); @endphp

@section('content')
{{-- MARKER-PO: purchase orders list --}}
<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Ordering</h1>
    <p class="ia-page-subtitle">
      {{ $summary['open'] }} open {{ \Illuminate\Support\Str::plural('PO', $summary['open']) }}
      @if($canCost) · {{ $money($summary['value']) }} on order @endif
      @if($summary['overdue']) · <span style="color:#F87171">{{ $summary['overdue'] }} overdue</span>@endif
    </p>
  </div>
  <div class="ia-page-actions">
    <button type="button" class="ia-btn ia-btn--primary" id="po-new">+ New PO</button>
  </div>
</div>

@include('tenant.purchase-orders._area-tabs', ['soOpen' => $soCount])

@if(session('flash'))
  <div class="ia-flash ia-flash--{{ session('flash')['type'] }}">{{ session('flash')['message'] }}</div>
@endif

<div class="so-tabs">
  @foreach(['draft' => 'Drafts', 'open' => 'Open', 'received' => 'Received', 'closed' => 'Closed & cancelled'] as $k => $label)
    <a href="{{ route('tenant.purchase-orders.index', array_filter(['tab' => $k, 'vendor' => request('vendor'), 'location' => request('location')])) }}" class="so-tab {{ $tab === $k ? 'active' : '' }}">
      {{ $label }} <span class="so-tab-count">{{ $counts[$k] }}</span>
    </a>
  @endforeach
</div>

<div class="po-legend">
  <b>On order</b> is what's still owed on placed POs. It's what counts as coming in, and each line's expected date is when it counts as arriving. Drafts aren't on order until they're placed.
</div>

<form method="GET" class="po-filters">
  <input type="hidden" name="tab" value="{{ $tab }}">
  <select name="vendor" class="ia-input" onchange="this.form.submit()">
    <option value="">All vendors</option>
    @foreach($vendors as $v)<option value="{{ $v->id }}" @selected(request('vendor') === $v->id)>{{ $v->name }}</option>@endforeach
  </select>
  @if($locations->count() > 1)
    <select name="location" class="ia-input" onchange="this.form.submit()">
      <option value="">All locations</option>
      @foreach($locations as $l)<option value="{{ $l->id }}" @selected(request('location') === $l->id)>{{ $l->name }}</option>@endforeach
    </select>
  @endif
</form>

<div class="ia-table-wrap po-flat">
  <table class="ia-table">
    <thead>
      <tr><th>PO</th><th>Vendor</th><th>Ship to</th><th>Status</th><th class="po-r">Lines</th><th class="po-r">Still owed</th>@if($canCost)<th class="po-r">Value</th>@endif<th>Expected</th></tr>
    </thead>
    <tbody>
      @forelse($pos as $po)
        @php
          $dates = $po->lines->filter(fn ($l) => $l->owed() > 0)->map(fn ($l) => $l->expected_date ?? $po->expected_date)->filter()->unique(fn ($d) => $d->toDateString())->sort()->values();
        @endphp
        <tr class="po-click" onclick="window.location.href='{{ route('tenant.purchase-orders.show', ['id' => $po->id]) }}'">
          <td><a href="{{ route('tenant.purchase-orders.show', ['id' => $po->id]) }}" class="po-num">{{ $po->po_number }}</a></td>
          <td>{{ $po->vendor?->name ?? '—' }}</td>
          <td>{{ $po->location?->name ?? '—' }}</td>
          <td>
            <span class="so-status so-status--{{ $po->statusPill() }}">{{ $po->statusLabel() }}</span>
            @if($po->isOverdue())<span class="so-status so-status--overdue">Overdue</span>@endif
            @if($po->changed_since_placed)<span class="so-status po-changed">Changed</span>@endif
          </td>
          <td class="po-r">{{ $po->lines->count() }}</td>
          <td class="po-r">
            @if($po->isDraft())<span class="po-muted">not placed</span>
            @elseif($po->owedUnits())<b>{{ $po->owedUnits() }}</b> units
            @else — @endif
          </td>
          @if($canCost)<td class="po-r">{{ $money($po->valueCents()) }}</td>@endif
          <td>
            @if($po->isDraft())<span class="po-muted">set when placed</span>
            @elseif($dates->isNotEmpty()){{ $dates->map(fn ($d) => $d->format('M j'))->implode(' · ') }}
            @else {{ $po->expected_date?->format('M j') ?? '—' }} @endif
          </td>
        </tr>
      @empty
        <tr><td colspan="{{ $canCost ? 8 : 7 }}" class="po-empty">
          @if($tab === 'draft')
            No drafts. <a href="#" onclick="document.getElementById('po-new').click();return false">Start a PO</a>, or add special orders to one from <a href="{{ route('tenant.special-orders.index') }}">Special orders</a>.
          @elseif($tab === 'open')
            Nothing on order. Place a draft, or <a href="#" onclick="document.getElementById('po-new').click();return false">start a PO</a>.
          @else
            Nothing here yet. <a href="{{ route('tenant.purchase-orders.index', ['tab' => 'open']) }}">See open POs</a>.
          @endif
        </td></tr>
      @endforelse
    </tbody>
  </table>
</div>

<style>
  .po-legend{font-size:12.5px;color:var(--ia-text-muted);line-height:1.55;border-left:2px solid var(--ia-border-strong,var(--ia-border));padding:2px 0 2px 12px;margin:0 0 14px;max-width:880px}
  .po-legend b{color:var(--ia-text);font-weight:600}
  .po-filters{display:flex;gap:8px;margin-bottom:10px;flex-wrap:wrap}
  .po-filters .ia-input{width:auto;min-width:160px}
  .po-flat{border-top:1px solid var(--ia-border)}
  .po-r{text-align:right}
  .po-muted{color:var(--ia-text-muted)}
  .po-click{cursor:pointer}
  .po-click:hover td{background:rgba(255,255,255,.02)}
  .po-num{font-weight:700;color:var(--ia-text);text-decoration:none}
  .po-empty{text-align:center;color:var(--ia-text-muted);padding:28px!important}
  .po-empty a{color:var(--ia-accent)}
  .so-tabs{display:flex;gap:4px;border-bottom:1px solid var(--ia-border);margin-bottom:14px;overflow-x:auto}
  .so-tab{padding:10px 14px;font-size:13px;font-weight:500;color:var(--ia-text-muted);border-bottom:2px solid transparent;text-decoration:none;white-space:nowrap;display:inline-flex;align-items:center;gap:6px}
  .so-tab.active{color:var(--ia-text);border-bottom-color:var(--ia-accent)}
  .so-tab-count{font-size:11px;font-weight:600;padding:1px 6px;border-radius:999px;background:var(--ia-surface);color:var(--ia-text-muted)}
  .so-tab.active .so-tab-count{background:var(--ia-accent);color:#000}
  .so-status{display:inline-block;padding:2px 8px;border-radius:99px;font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;margin:1px 4px 1px 0}
  .so-status--draft{background:rgba(200,200,200,.08);color:var(--ia-text-muted)}
  .so-status--ordered{background:rgba(96,165,250,.10);color:#60A5FA}
  .so-status--arrived{background:rgba(190,242,100,.10);color:var(--ia-accent)}
  .so-status--pulled{background:rgba(200,200,200,.06);color:var(--ia-text-muted)}
  .so-status--cancelled{background:rgba(248,113,113,.10);color:#F87171}
  .so-status--overdue{background:rgba(248,113,113,.15);color:#F87171}
  .po-changed{background:rgba(245,196,81,.12);color:#c9972a}
</style>

<script>
(function () {
  var VENDORS = {{ \Illuminate\Support\Js::from($vendors->map(fn ($v) => ['id' => $v->id, 'name' => $v->name])->values()) }};
  var LOCS = {{ \Illuminate\Support\Js::from($locations->map(fn ($l) => ['id' => $l->id, 'name' => $l->name])->values()) }};
  var DRAFTS = {{ \Illuminate\Support\Js::from($drafts->map(fn ($d) => ['id' => $d->id, 'po' => $d->po_number, 'v' => $d->vendor_id, 'l' => $d->location_id])->values()) }};
  var storeUrl = @json(route('tenant.purchase-orders.store'));
  var showUrl = {{ \Illuminate\Support\Js::from(route('tenant.purchase-orders.show', ['id' => '__ID__'])) }};
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content;
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };

  document.getElementById('po-new').addEventListener('click', function () {
    if (!VENDORS.length) {
      IntakeConfirm.alert({ title: 'Add a vendor first', message: 'A purchase order is for one vendor. Add one in Vendors, then start the PO.', okText: 'OK' });
      return;
    }
    var back = document.createElement('div');
    back.className = 'ia-confirm-backdrop';
    back.innerHTML = '<div class="ia-confirm-card" style="max-width:460px"><div class="ia-confirm-title">New purchase order</div>'
      + '<div class="ia-confirm-message">One vendor, one ship-to location. You add items next.</div>'
      + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">'
      + '<label style="display:flex;flex-direction:column;gap:4px;font-size:12px;color:var(--ia-text-muted)">Vendor<select class="ia-input" data-v>' + VENDORS.map(function (v) { return '<option value="' + esc(v.id) + '">' + esc(v.name) + '</option>'; }).join('') + '</select></label>'
      + '<label style="display:flex;flex-direction:column;gap:4px;font-size:12px;color:var(--ia-text-muted)">Ship to<select class="ia-input" data-l>' + LOCS.map(function (l) { return '<option value="' + esc(l.id) + '">' + esc(l.name) + '</option>'; }).join('') + '</select></label></div>'
      + '<div style="font-size:12px;color:var(--ia-text-muted);margin-bottom:16px;min-height:16px" data-note></div>'
      + '<div class="ia-confirm-actions"><button type="button" class="ia-confirm-btn" data-x>Cancel</button><button type="button" class="ia-confirm-btn" data-open hidden></button><button type="button" class="ia-confirm-btn ia-confirm-btn--primary" data-go>Start draft</button></div></div>';
    document.body.appendChild(back); void back.offsetWidth; back.classList.add('is-shown');
    var v = back.querySelector('[data-v]'), l = back.querySelector('[data-l]'), note = back.querySelector('[data-note]'), open = back.querySelector('[data-open]');
    function check() {
      var d = DRAFTS.filter(function (x) { return x.v === v.value && x.l === l.value; })[0];
      note.textContent = d ? 'There\'s already a draft for this vendor and location (' + d.po + '). Starting another keeps them separate.' : '';
      open.hidden = !d; if (d) { open.textContent = 'Open ' + d.po; open.onclick = function () { location.href = showUrl.replace('__ID__', d.id); }; }
    }
    v.addEventListener('change', check); l.addEventListener('change', check); check();
    function close() { back.classList.remove('is-shown'); setTimeout(function () { back.remove(); }, 160); }
    back.querySelector('[data-x]').onclick = close;
    back.addEventListener('click', function (e) { if (e.target === back) close(); });
    back.querySelector('[data-go]').onclick = function () {
      this.disabled = true;
      fetch(storeUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify({ vendor_id: v.value, location_id: l.value }) })
        .then(function (r) { return r.json(); }).then(function (d) {
          if (d.ok) { location.href = d.url; } else { IntakeConfirm.alert({ title: 'Couldn\'t start it', message: d.error || 'Something went wrong.' }); close(); }
        });
    };
  });
})();
</script>
@endsection
