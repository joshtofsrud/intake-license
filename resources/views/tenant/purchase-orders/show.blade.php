@extends('layouts.tenant.app')
@php
  $pageTitle = $po->po_number;
  $money = fn ($c) => $c === null ? '—' : '$' . number_format(((int) $c) / 100, 2);
  $isDraft = $po->isDraft();
  $isOpen = $po->isOpen();
  $vname = $po->vendor?->name ?? 'the vendor';
  $subtotal = $po->lines->sum(fn ($l) => $l->qty_ordered * (int) $l->unit_cost_cents);
  $steps = in_array($po->status, ['closed_short', 'cancelled'], true)
    ? ['draft' => 'Draft', 'placed' => 'Placed', $po->status => $po->statusLabel()]
    : ['draft' => 'Draft', 'placed' => 'Placed', 'partial' => 'Partly received', 'received' => 'Received'];
  $order = array_keys($steps);
  $cur = array_search($po->status, $order, true);
  $copyRows = $po->lines->filter(fn ($l) => $l->vendor_sku && ($isDraft ? $l->qty_ordered : $l->owed()) > 0)
    ->map(fn ($l) => [$l->vendor_sku, $isDraft ? $l->qty_ordered : $l->owed()])->values();
  $noSku = $po->lines->filter(fn ($l) => ! $l->vendor_sku)->count();
@endphp

@section('content')
{{-- MARKER-PO: one purchase order, draft or placed --}}
<div class="ia-page-head">
  <div class="ia-page-head-left">
    <div class="po-crumb"><a href="{{ route('tenant.purchase-orders.index', ['tab' => $isDraft ? 'draft' : ($isOpen ? 'open' : null)]) }}">← Purchase orders</a></div>
    <h1 class="ia-page-title">{{ $po->po_number }} <span class="so-status so-status--{{ $po->statusPill() }}">{{ $po->statusLabel() }}</span>
      @if($po->isOverdue())<span class="so-status so-status--overdue">Overdue</span>@endif</h1>
    <p class="ia-page-subtitle">
      {{ $vname }} · ships to {{ $po->location?->name ?? '—' }}
      @if($po->placed_at)
        · placed {{ tlocal_date($po->placed_at, 'M j') }}{{ $po->placedBy ? ' by ' . $po->placedBy->name : '' }}
        @if($po->placed_via) · {{ \App\Models\Tenant\TenantPurchaseOrder::VIA[$po->placed_via] ?? '' }}{{ $po->sent_to_email ? ' to ' . $po->sent_to_email : '' }}@endif
        @if($po->vendor_reference) · their ref <b>{{ $po->vendor_reference }}</b>@endif
      @else
        · started {{ $po->created_at->diffForHumans() }}
      @endif
    </p>
  </div>
  <div class="ia-page-actions">
    @if($isDraft)
      <button type="button" class="ia-btn ia-btn--ghost" data-po-act="delete">Delete draft</button>
    @else
      <a class="ia-btn ia-btn--ghost" href="{{ route('tenant.purchase-orders.pdf', ['id' => $po->id]) }}" target="_blank">Print / PDF</a>
      @if($isOpen)<button type="button" class="ia-btn ia-btn--ghost" data-po-act="email">Email {{ $po->placed_via === 'email' ? 'again' : 'to ' . $vname }}</button>@endif
    @endif
    <a class="ia-btn ia-btn--ghost" href="{{ route('tenant.purchase-orders.csv', ['id' => $po->id]) }}">Export CSV</a>
    @if($copyRows->isNotEmpty() && ($isDraft || $isOpen))
      <button type="button" class="ia-btn ia-btn--ghost" data-po-copy='@json($copyRows)' title="Part numbers and quantities, tab-separated, for a vendor's quick-order box{{ $noSku ? " ({$noSku} without a part number left out)" : '' }}">Copy order</button>
    @endif
    @if($isDraft)
      <button type="button" class="ia-btn ia-btn--primary" data-po-act="place" @disabled($po->lines->isEmpty())>Place order</button>
    @elseif($isOpen)
      <button type="button" class="ia-btn ia-btn--primary" data-po-act="receive">{{ $draftShipment ? 'Continue receiving ' . $draftShipment->shipment_number : 'Receive' }}</button>
    @endif
  </div>
</div>

@if(session('flash'))
  <div class="ia-flash ia-flash--{{ session('flash')['type'] }}">{{ session('flash')['message'] }}</div>
@endif

<div class="so-state-strip">
  @foreach($steps as $k => $label)
    @php $i = array_search($k, $order, true); @endphp
    <div class="so-state-step {{ $cur !== false && $i < $cur ? 'done' : '' }} {{ $k === $po->status ? 'current' : '' }} {{ in_array($k, ['closed_short', 'cancelled'], true) ? 'cancelled' : '' }}">{{ $label }}</div>
  @endforeach
</div>

@if($po->changed_since_placed && $isOpen)
  <div class="po-banner">
    <b>Changed since placed.</b> {{ $po->changes_note }} Tell {{ $vname }}, then
    <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" data-po-act="email">Email updated PO</button>
    <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" data-po-act="told">Mark vendor told</button>
  </div>
@endif

<div class="po-legend">
  @if($isDraft)
    <b>A draft isn't sent anywhere and isn't on order yet.</b> Special orders on it stay <b>Needed</b> until you place it; placing it marks them <b>Ordered</b> with this PO's number and expected date.
  @elseif($isOpen)
    <b>Still owed</b> = ordered − received − cancelled. Only what's still owed counts as on order. Receiving happens on a shipment; <b>Receive</b> opens one with every line that's still owed filled in.
  @else
    {{ $po->statusLabel() }}. Nothing on it is on order any more.
    @if($po->closed_reason) Reason: {{ $po->closed_reason }}. @endif
  @endif
</div>

@if($isDraft)
  @if($freeFreight && $canCost)
    @php $left = $freeFreight - $subtotal; $pct = min(100, (int) round($subtotal / max(1, $freeFreight) * 100)); @endphp
    <div class="po-freight" data-free="{{ $freeFreight }}">
      <span data-freight-note>@if($left > 0)<b>{{ $money($left) }}</b> more to free freight with {{ $vname }} ({{ $money($freeFreight) }})@else<b>Free freight reached</b> with {{ $vname }} ({{ $money($freeFreight) }})@endif</span>
      <span class="po-bar"><span data-freight-bar style="width:{{ $pct }}%"></span></span>
    </div>
  @endif
  <div class="po-add">
    <input type="text" class="ia-input" id="po-search" autocomplete="off" placeholder="Add an item — search name, SKU or {{ $vname }} part number">
    <div class="po-results" id="po-results" hidden></div>
  </div>
@endif

<div class="ia-table-wrap po-flat">
  <table class="ia-table po-lines" id="po-lines">
    <thead>
      @if($isDraft)
        <tr><th>Item</th><th>{{ $vname }} part #</th><th>For</th><th class="po-r">On hand</th><th class="po-r">Qty</th>@if($canCost)<th class="po-r">Unit cost</th><th class="po-r">Line total</th>@endif<th></th></tr>
      @else
        <tr><th>Item</th><th>{{ $vname }} part #</th><th>For</th><th class="po-r">Ordered</th><th class="po-r">Received</th><th class="po-r">Cancelled</th><th class="po-r">Still owed</th><th>Expected</th>@if($canCost)<th class="po-r">Unit cost</th>@endif</tr>
      @endif
    </thead>
    <tbody>
      @forelse($po->lines as $line)
        @php
          $sos = $line->specialOrders->whereIn('status', ['needed', 'ordered', 'arrived', 'pulled']);
          $soUnits = (int) $line->specialOrders->whereIn('status', ['needed', 'ordered'])->sum('quantity');
          $stock = max(0, $line->qty_ordered - $line->qty_cancelled - (int) $sos->sum('quantity'));
          $owed = $line->owed();
          $exp = $line->expected_date ?? $po->expected_date;
        @endphp
        <tr data-line="{{ $line->id }}" data-cost="{{ (int) $line->unit_cost_cents }}">
          <td class="po-item"><b>{{ $line->name_snapshot }}</b>
            @if(! $line->inventory_item_id)<span class="po-sub po-warn">Not in your catalog — created when it's received</span>@endif
            @if($line->added_after_placed)<span class="po-tag">Added after placing</span>@endif
          </td>
          <td class="po-muted po-nw">{{ $line->vendor_sku ?: '—' }}</td>
          <td>
            @foreach($sos as $so)
              @php $who = $so->customer?->fullName() ?? ($so->appointment ? $so->appointment->ra_number : 'Shop stock'); $done = in_array($so->status, ['arrived', 'pulled'], true); @endphp
              <a href="{{ route('tenant.special-orders.show', ['id' => $so->id]) }}" class="po-chip {{ $done ? 'done' : '' }}" title="{{ $so->so_number }} · {{ $so->statusLabel() }}">{{ $who }}{{ $so->quantity > 1 ? ' ×' . $so->quantity : '' }}{{ $done ? ' ✓' : '' }}</a>
            @endforeach
            @if($stock)<span class="po-chip stock">{{ $stock }} stock</span>@endif
          </td>
          @if($isDraft)
            <td class="po-r">{{ $line->inventory_item_id ? ($hand[$line->inventory_item_id] ?? 0) : '—' }}</td>
            <td class="po-r"><input class="ia-input po-num" type="number" min="{{ max(1, $soUnits) }}" value="{{ $line->qty_ordered }}" data-po-qty></td>
            @if($canCost)
              <td class="po-r"><input class="ia-input po-num po-cost" type="text" inputmode="decimal" value="{{ $line->unit_cost_cents !== null ? number_format($line->unit_cost_cents / 100, 2, '.', '') : '' }}" placeholder="0.00" data-po-cost></td>
              <td class="po-r po-nw" data-line-total>{{ $money($line->qty_ordered * (int) $line->unit_cost_cents) }}</td>
            @endif
            <td class="po-r"><button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" data-po-remove data-name="{{ $line->name_snapshot }}" data-sos="{{ $line->specialOrders->where('status', 'needed')->map(fn ($s) => $s->customer?->fullName() ?? $s->so_number)->implode(', ') }}">Remove</button></td>
          @else
            <td class="po-r">{{ $line->qty_ordered }}</td>
            <td class="po-r">{{ $line->qty_received }}@if($line->over())<span class="po-sub po-warn">over by {{ $line->over() }}</span>@endif</td>
            <td class="po-r">{{ $line->qty_cancelled ?: '—' }}</td>
            <td class="po-r"><b>{{ $owed ?: '—' }}</b></td>
            <td>
              @if($owed > 0 && $isOpen)
                <input class="ia-input po-date" type="date" value="{{ $exp?->toDateString() }}" data-po-date>
                @if($exp && $exp->toDateString() < now()->toDateString())<span class="so-status so-status--overdue">Overdue</span>@endif
                <a href="#" class="po-link" data-po-cantsupply data-name="{{ $line->name_snapshot }}" data-owed="{{ $owed }}"
                   data-sos="{{ $line->specialOrders->where('status', 'ordered')->map(fn ($s) => $s->so_number . ' for ' . ($s->customer?->fullName() ?? ($s->appointment ? $s->appointment->ra_number : 'shop stock')))->implode(', ') }}">Can't supply…</a>
              @elseif($owed > 0)
                {{ $exp?->format('M j') ?? '—' }}
              @else
                <span class="po-muted">{{ $line->qty_received ? 'All in' : 'Cancelled' }}{{ $line->cancel_reason && $line->qty_cancelled ? ' · ' . $line->cancel_reason : '' }}</span>
              @endif
            </td>
            @if($canCost)<td class="po-r">{{ $money($line->unit_cost_cents) }}</td>@endif
          @endif
        </tr>
      @empty
        <tr><td colspan="9" class="po-empty">No lines yet. Search above to add items, or add special orders to this PO from <a href="{{ route('tenant.special-orders.index') }}">Special orders</a>.</td></tr>
      @endforelse
    </tbody>
    @if($isDraft && $canCost && $po->lines->isNotEmpty())
      <tfoot><tr><td colspan="6" class="po-r po-muted">Subtotal</td><td class="po-r"><b data-subtotal>{{ $money($subtotal) }}</b></td><td></td></tr></tfoot>
    @endif
  </table>
</div>
@if($isDraft)
  <div class="po-fine">
    @if($canCost) Costs come from {{ $vname }}'s prices and can be changed per line. Staff without Costs &amp; margins access see quantities only. @endif
    A line can't go below the special orders on it; remove the line to take them off.
  </div>
@endif

@if($isOpen)
  <div class="po-row">
    <button type="button" class="ia-btn ia-btn--secondary ia-btn--sm" data-po-act="addline">+ Add a line</button>
    <span style="flex:1"></span>
    <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" data-po-act="closeshort">Close short…</button>
    <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" data-po-act="cancelpo">Cancel PO…</button>
  </div>
@endif

<form method="POST" action="{{ route('tenant.purchase-orders.update', ['id' => $po->id]) }}" class="po-notes">
  @csrf @method('PATCH')
  <div>
    <div class="so-detail-label">Notes to vendor</div>
    @if($isDraft)
      <textarea name="notes_vendor" class="ia-input" rows="3" maxlength="2000" placeholder="Printed on the PO (e.g. ship complete, call before delivery)">{{ $po->notes_vendor }}</textarea>
    @else
      <div class="po-ro">{{ $po->notes_vendor ?: '—' }}</div>
    @endif
  </div>
  <div>
    <div class="so-detail-label">Internal notes</div>
    <textarea name="notes_internal" class="ia-input" rows="3" maxlength="2000" placeholder="Staff only; never printed">{{ $po->notes_internal }}</textarea>
  </div>
  <div class="po-notes-save"><button type="submit" class="ia-btn ia-btn--secondary ia-btn--sm">Save notes</button></div>
</form>

<div class="po-cols">
  <div>
    <h3 class="po-h3">Shipments</h3>
    <div class="po-history">
      @forelse($shipments as $sh)
        <div><span>{{ $sh->received_date?->format('M j') }} · {{ $sh->shipment_number }}</span>
          {{ $sh->status === 'draft' ? 'Draft, not committed yet' : 'Committed' }}{{ $sh->vendor_invoice_number ? ' · invoice ' . $sh->vendor_invoice_number : '' }}
          @if($sh->invoice_mismatch_cents) · <span class="po-warn">invoice doesn't match</span>@endif
          <a href="{{ route($sh->status === 'draft' ? 'tenant.inventory.receiving.edit' : 'tenant.inventory.receiving.show', ['id' => $sh->id]) }}">Open</a></div>
      @empty
        <div class="po-muted">Nothing received yet.</div>
      @endforelse
    </div>
  </div>
  <div>
    <h3 class="po-h3">History</h3>
    <div class="po-history">
      @foreach($po->events as $ev)
        <div><span>{{ tlocal_datetime($ev->created_at, 'M j, g:i a') }}</span>{{ $ev->body }}{{ $ev->user ? ' — ' . $ev->user->name : '' }}</div>
      @endforeach
    </div>
  </div>
</div>

<style>
  .po-crumb{font-size:11px;text-transform:uppercase;letter-spacing:.06em;font-weight:600;margin-bottom:4px}
  .po-crumb a{color:var(--ia-text-muted);text-decoration:none}
  .ia-page-title .so-status{vertical-align:middle;margin-left:6px}
  .so-status{display:inline-block;padding:2px 8px;border-radius:99px;font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.05em}
  .so-status--draft{background:rgba(200,200,200,.08);color:var(--ia-text-muted)}
  .so-status--ordered{background:rgba(96,165,250,.10);color:#60A5FA}
  .so-status--arrived{background:rgba(190,242,100,.10);color:var(--ia-accent)}
  .so-status--pulled{background:rgba(200,200,200,.06);color:var(--ia-text-muted)}
  .so-status--cancelled{background:rgba(248,113,113,.10);color:#F87171}
  .so-status--overdue{background:rgba(248,113,113,.15);color:#F87171;margin-left:4px}
  .so-state-strip{display:flex;gap:4px;padding:4px;border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);margin-bottom:16px}
  .so-state-step{flex:1;text-align:center;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-muted);padding:8px 4px;border-radius:6px}
  .so-state-step.done{color:var(--ia-text)}
  .so-state-step.current{background:var(--ia-accent);color:#0a0a0a}
  .so-state-step.cancelled.current{background:rgba(248,113,113,.15);color:#F87171}
  .so-detail-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-muted);margin-bottom:6px}
  .po-banner{border:0.5px solid rgba(245,196,81,.4);background:rgba(245,196,81,.08);border-radius:var(--ia-r-md);padding:10px 12px;font-size:13px;margin:0 0 14px;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
  .po-banner b{color:#c9972a}
  .po-legend{font-size:12.5px;color:var(--ia-text-muted);line-height:1.55;border-left:2px solid var(--ia-border-strong,var(--ia-border));padding:2px 0 2px 12px;margin:0 0 14px;max-width:880px}
  .po-legend b{color:var(--ia-text);font-weight:600}
  .po-freight{display:flex;align-items:center;gap:12px;font-size:12px;color:var(--ia-text-muted);margin:0 0 12px;max-width:620px}
  .po-freight b{color:var(--ia-accent)}
  .po-bar{flex:1;height:4px;border-radius:100px;background:rgba(255,255,255,.09);overflow:hidden;min-width:80px}
  body.ia-theme-b .po-bar{background:rgba(0,0,0,.08)}
  .po-bar span{display:block;height:100%;background:var(--ia-accent)}
  .po-add{position:relative;max-width:620px;margin-bottom:12px}
  .po-results{position:absolute;left:0;right:0;top:calc(100% + 4px);background:var(--ia-surface);border:0.5px solid var(--ia-border-strong,var(--ia-border));border-radius:var(--ia-r-md);z-index:30;box-shadow:0 8px 24px rgba(0,0,0,.3);overflow:hidden;max-height:340px;overflow-y:auto}
  .po-results [data-i]{padding:9px 12px;cursor:pointer;display:flex;justify-content:space-between;gap:12px;font-size:13px}
  .po-results [data-i]:hover{background:var(--ia-hover)}
  .po-results span{color:var(--ia-text-muted);font-size:12px;white-space:nowrap}
  .po-results em{color:#c9972a;font-style:normal}
  .po-flat{border-top:1px solid var(--ia-border)}
  .po-lines td{vertical-align:top}
  .po-item{min-width:170px}
  .po-r{text-align:right}
  .po-nw{white-space:nowrap}
  .po-muted{color:var(--ia-text-muted)}
  .po-warn{color:#c9972a}
  .po-sub{display:block;font-size:11.5px;color:var(--ia-text-muted);font-weight:400;margin-top:2px}
  .po-tag{display:inline-block;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;background:rgba(245,196,81,.14);color:#c9972a;border-radius:99px;padding:2px 7px;margin-left:4px}
  .po-num{width:80px;text-align:right;padding:5px 8px}
  .po-date{width:140px;padding:5px 8px}
  .po-chip{display:inline-block;font-size:11px;font-weight:600;border-radius:99px;padding:2px 8px;margin:1px 4px 1px 0;background:rgba(143,184,240,.10);color:#8FB8F0;white-space:nowrap;text-decoration:none}
  .po-chip.stock{background:rgba(200,200,200,.08);color:var(--ia-text-muted)}
  .po-chip.done{background:rgba(190,242,100,.10);color:var(--ia-accent)}
  body.ia-theme-b .po-chip.done{color:#3B6D11}
  .po-link{display:block;font-size:11.5px;color:var(--ia-text-muted);margin-top:4px;text-decoration:underline}
  .po-link:hover{color:#F87171}
  .po-empty{text-align:center;color:var(--ia-text-muted);padding:28px!important}
  .po-empty a{color:var(--ia-accent)}
  .po-fine{font-size:11.5px;color:var(--ia-text-dim,var(--ia-text-muted));margin:10px 0 0}
  .po-row{display:flex;gap:8px;margin-top:12px;flex-wrap:wrap}
  .po-notes{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:22px}
  .po-notes-save{grid-column:1 / -1;display:flex;justify-content:flex-end}
  .po-ro{font-size:13px;color:var(--ia-text);white-space:pre-wrap;padding:6px 0}
  .po-cols{display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-top:22px}
  .po-h3{font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-muted);font-weight:600;margin:0 0 6px}
  .po-history div{display:flex;gap:14px;padding:8px 0;border-bottom:1px solid var(--ia-border);font-size:13px;flex-wrap:wrap}
  .po-history div:last-child{border-bottom:0}
  .po-history span{color:var(--ia-text-dim,var(--ia-text-muted));font-size:12px;min-width:120px;flex:none}
  .po-history a{color:var(--ia-accent);margin-left:6px}
  .po-dlg .ia-confirm-card{max-width:560px}
  .po-dlg .po-f{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px}
  .po-dlg label.po-fl{display:flex;flex-direction:column;gap:4px;font-size:12px;color:var(--ia-text-muted)}
  .po-dlg table{width:100%;border-collapse:collapse;font-size:13px;margin-bottom:12px}
  .po-dlg td{padding:7px 4px;border-bottom:0.5px solid var(--ia-border);color:var(--ia-text)}
  .po-dlg td:last-child{text-align:right;color:var(--ia-text-muted)}
  .po-dlg .po-fine{margin:0 0 12px}
  @media(max-width:800px){.po-notes,.po-cols,.po-dlg .po-f{grid-template-columns:1fr}}
</style>

<script>
(function () {
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content;
  var PO = {{ \Illuminate\Support\Js::from(['id' => $po->id, 'number' => $po->po_number, 'vendor' => $vname, 'email' => $po->sent_to_email ?: $po->vendor?->contact_email, 'expected' => $defaultExpected, 'leadNote' => $leadNote, 'lines' => $po->lines->count(), 'sos' => (int) $po->lines->sum(fn ($l) => $l->specialOrders->where('status', 'needed')->count()), 'received' => (int) $po->lines->sum('qty_received'), 'owed' => $po->owedUnits(), 'changed' => (bool) $po->changed_since_placed, 'canCost' => $canCost]) }};
  var OWED = {{ \Illuminate\Support\Js::from($po->lines->filter(fn ($l) => $l->owed() > 0)->map(fn ($l) => ['name' => $l->name_snapshot, 'owed' => $l->owed(), 'sos' => $l->specialOrders->where('status', 'ordered')->map(fn ($s) => $s->so_number . ' for ' . ($s->customer?->fullName() ?? 'shop stock'))->values()])->values()) }};
  var U = {
    lines: {{ \Illuminate\Support\Js::from(route('tenant.purchase-orders.lines.store', ['id' => $po->id])) }},
    line: {{ \Illuminate\Support\Js::from(route('tenant.purchase-orders.lines.update', ['id' => $po->id, 'lineId' => '__L__'])) }},
    date: {{ \Illuminate\Support\Js::from(route('tenant.purchase-orders.lines.date', ['id' => $po->id, 'lineId' => '__L__'])) }},
    rest: {{ \Illuminate\Support\Js::from(route('tenant.purchase-orders.lines.cancel-rest', ['id' => $po->id, 'lineId' => '__L__'])) }},
    items: {{ \Illuminate\Support\Js::from(route('tenant.purchase-orders.items', ['id' => $po->id])) }},
    place: {{ \Illuminate\Support\Js::from(route('tenant.purchase-orders.place', ['id' => $po->id])) }},
    email: {{ \Illuminate\Support\Js::from(route('tenant.purchase-orders.email', ['id' => $po->id])) }},
    told: {{ \Illuminate\Support\Js::from(route('tenant.purchase-orders.told', ['id' => $po->id])) }},
    close: {{ \Illuminate\Support\Js::from(route('tenant.purchase-orders.close-short', ['id' => $po->id])) }},
    cancel: {{ \Illuminate\Support\Js::from(route('tenant.purchase-orders.cancel', ['id' => $po->id])) }},
    receive: {{ \Illuminate\Support\Js::from(route('tenant.purchase-orders.receive', ['id' => $po->id])) }},
    destroy: {{ \Illuminate\Support\Js::from(route('tenant.purchase-orders.destroy', ['id' => $po->id])) }},
    show: {{ \Illuminate\Support\Js::from(route('tenant.purchase-orders.show', ['id' => '__ID__'])) }},
  };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var money = function (c) { return '$' + (Number(c || 0) / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  var toast = function (ok, m) { if (window.IntakeToast && m) { IntakeToast[ok ? 'success' : 'error'](m); } };

  function req(method, url, body) {
    return fetch(url, { method: method, headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' }, body: body ? JSON.stringify(body) : undefined })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Something went wrong.' }; }).then(function (j) { if (!r.ok && j.ok === undefined) { j.ok = false; } if (r.status === 422 && j.errors) { j.error = Object.values(j.errors)[0][0]; } return j; }); });
  }
  function reloadWith(msg) { try { sessionStorage.setItem('po-toast', msg || ''); } catch (e) {} location.reload(); }
  try { var t0 = sessionStorage.getItem('po-toast'); if (t0) { sessionStorage.removeItem('po-toast'); setTimeout(function () { toast(true, t0); }, 300); } } catch (e) {}

  // in-app dialog in the app's confirm style
  function dialog(title, message, bodyHtml, actions) {
    var back = document.createElement('div');
    back.className = 'ia-confirm-backdrop po-dlg';
    back.innerHTML = '<div class="ia-confirm-card" role="dialog" aria-modal="true"><div class="ia-confirm-title"></div>' + (message ? '<div class="ia-confirm-message"></div>' : '')
      + '<div data-body></div><div class="ia-confirm-actions"></div></div>';
    back.querySelector('.ia-confirm-title').textContent = title;
    if (message) back.querySelector('.ia-confirm-message').innerHTML = message;
    back.querySelector('[data-body]').innerHTML = bodyHtml || '';
    var row = back.querySelector('.ia-confirm-actions');
    function close() { back.classList.remove('is-shown'); setTimeout(function () { back.remove(); }, 160); }
    actions.forEach(function (a) {
      var b = document.createElement('button'); b.type = 'button';
      b.className = 'ia-confirm-btn' + (a.kind ? ' ia-confirm-btn--' + a.kind : ''); b.textContent = a.label;
      b.addEventListener('click', function () {
        if (!a.run) { close(); return; }
        var r = a.run(back, b);
        if (r === false) return;
        if (r && typeof r.then === 'function') { b.disabled = true; r.then(function (keep) { b.disabled = false; if (keep !== false) close(); }); return; }
        close();
      });
      row.appendChild(b);
    });
    back.addEventListener('click', function (e) { if (e.target === back) close(); });
    document.body.appendChild(back); void back.offsetWidth; back.classList.add('is-shown');
    return back;
  }
  function fail(d, title) { IntakeConfirm.alert({ title: title || 'Couldn\'t do that', message: (d && (d.error || d.message)) || 'Something went wrong.' }); return false; }

  // ── draft lines: quantity and cost update in place ──
  function recalc() {
    var sub = 0;
    document.querySelectorAll('#po-lines tr[data-line]').forEach(function (tr) {
      var q = parseInt((tr.querySelector('[data-po-qty]') || {}).value, 10) || 0;
      var c = parseInt(tr.getAttribute('data-cost'), 10) || 0;
      var lt = tr.querySelector('[data-line-total]'); if (lt) lt.textContent = money(q * c);
      sub += q * c;
    });
    var st = document.querySelector('[data-subtotal]'); if (st) st.textContent = money(sub);
    var fr = document.querySelector('.po-freight');
    if (fr) {
      var free = parseInt(fr.getAttribute('data-free'), 10), left = free - sub;
      fr.querySelector('[data-freight-bar]').style.width = Math.min(100, Math.round(sub / Math.max(1, free) * 100)) + '%';
      fr.querySelector('[data-freight-note]').innerHTML = left > 0 ? '<b>' + money(left) + '</b> more to free freight with ' + esc(PO.vendor) + ' (' + money(free) + ')' : '<b>Free freight reached</b> with ' + esc(PO.vendor) + ' (' + money(free) + ')';
    }
  }
  document.getElementById('po-lines').addEventListener('change', function (e) {
    var tr = e.target.closest('tr[data-line]'); if (!tr) return;
    var id = tr.getAttribute('data-line');
    if (e.target.hasAttribute('data-po-qty')) {
      req('PATCH', U.line.replace('__L__', id), { qty: parseInt(e.target.value, 10) || 0 }).then(function (d) {
        if (!d.ok) { if (d.qty) e.target.value = d.qty; fail(d); }
        recalc();
      });
    } else if (e.target.hasAttribute('data-po-cost')) {
      req('PATCH', U.line.replace('__L__', id), { cost: e.target.value }).then(function (d) {
        if (!d.ok) { fail(d); return; }
        var c = Math.round((parseFloat(String(e.target.value).replace(/[^0-9.]/g, '')) || 0) * 100);
        tr.setAttribute('data-cost', c); e.target.value = e.target.value === '' ? '' : (c / 100).toFixed(2); recalc();
      });
    } else if (e.target.hasAttribute('data-po-date')) {
      if (!e.target.value) return;
      req('POST', U.date.replace('__L__', id), { date: e.target.value }).then(function (d) {
        if (!d.ok) { fail(d); return; }
        if (d.tell && d.tell.length && window.IntakeSO) {
          IntakeSO.notify(d.tell, { kind: 'new_date', title: 'Tell them about the new date?', message: 'Their expected date is updated either way.', reload: true });
        } else { reloadWith(d.message); }
      });
    }
  });

  document.getElementById('po-lines').addEventListener('click', function (e) {
    var rm = e.target.closest('[data-po-remove]');
    if (rm) {
      var tr = rm.closest('tr[data-line]'), sos = rm.getAttribute('data-sos');
      var go = function () { return req('DELETE', U.line.replace('__L__', tr.getAttribute('data-line'))).then(function (d) { if (!d.ok) return fail(d); reloadWith(d.message); }); };
      if (!sos) { go(); return; }
      dialog('Remove ' + rm.getAttribute('data-name') + '?', 'Special orders on this line (' + esc(sos) + ') stay <b>Needed</b>, off this PO, and show on the Special orders board again.', '', [{ label: 'Go back' }, { label: 'Remove line', kind: 'danger', run: go }]);
      return;
    }
    var cs = e.target.closest('[data-po-cantsupply]');
    if (cs) {
      e.preventDefault();
      var line = cs.closest('tr[data-line]').getAttribute('data-line'), sos2 = cs.getAttribute('data-sos'), owed = cs.getAttribute('data-owed');
      dialog(esc(PO.vendor) + ' can\'t supply ' + cs.getAttribute('data-name') + '?', 'Cancels the ' + owed + ' still owed on this line. Nothing received changes.',
        (sos2 ? '<div class="po-fine">' + esc(sos2) + ' ' + (sos2.indexOf(',') > -1 ? 'are' : 'is') + ' waiting on this line and goes back to <b>Needed</b>, off this PO, with a note, so it can be ordered elsewhere. Staff are alerted.</div>' : '')
        + '<input class="ia-input ia-confirm-input" data-r placeholder="Reason (optional, e.g. discontinued)" style="margin:0 0 14px">',
        [{ label: 'Go back' }, { label: 'Cancel ' + owed, kind: 'danger', run: function (d) {
          return req('POST', U.rest.replace('__L__', line), { reason: d.querySelector('[data-r]').value || null }).then(function (r) { if (!r.ok) return fail(r); reloadWith(r.message); });
        } }]);
    }
  });

  // ── add items (draft search, or "Add a line" after placing) ──
  function addItem(it, qty, after) {
    var body = it.id ? { item_id: it.id, qty: qty || 1, cost: it.cost_cents != null ? (it.cost_cents / 100).toFixed(2) : null } : { name: it.name, qty: qty || 1 };
    var send = function (confirm, q) {
      var b = Object.assign({}, body, confirm ? { confirm: confirm } : {}, q ? { qty: q } : {});
      return req('POST', U.lines, b).then(function (d) {
        if (d.ok) { reloadWith(d.message); return; }
        if (d.needs_confirm === 'here') {
          dialog(esc(d.name) + ' is already on this PO', 'That line has ' + d.line_qty + '. Raise it instead of adding a second line?',
            '<label class="po-fl" style="display:flex;flex-direction:row;align-items:center;gap:10px;font-size:13px;color:var(--ia-text);margin-bottom:14px">Add <input class="ia-input po-num" type="number" min="1" value="' + (qty || 1) + '" data-q> to it</label>',
            [{ label: 'Go back' }, { label: 'Raise the line', kind: 'primary', run: function (dd) { return send('raise', parseInt(dd.querySelector('[data-q]').value, 10) || 1); } }]);
          return;
        }
        if (d.needs_confirm === 'elsewhere') {
          var list = d.elsewhere.map(function (x) { return x.po_number + ' (' + x.status.toLowerCase() + ', ' + x.qty + (x.status === 'Draft' ? ' on it' : ' still owed') + (x.expected ? ', due ' + x.expected : '') + ')'; }).join('; ');
          dialog(esc(it.name) + ' is already on order', 'It\'s on ' + esc(list) + '. Adding it here orders more.', '',
            [{ label: 'Go back' }, { label: 'Open ' + d.elsewhere[0].po_number, run: function () { location.href = U.show.replace('__ID__', d.elsewhere[0].po_id); } },
             { label: 'Add anyway', kind: 'primary', run: function () { return send('add'); } }]);
          return;
        }
        fail(d);
      });
    };
    return send(null);
  }

  var search = document.getElementById('po-search'), results = document.getElementById('po-results'), last = [];
  function runSearch(input, box, cb) {
    var q = input.value.trim(), tmr;
    if (q.length < 2) { box.hidden = true; return; }
    fetch(U.items + '?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); }).then(function (d) {
      last = d.results || [];
      box.innerHTML = last.map(function (it, i) {
        return '<div data-i="' + i + '"><b>' + esc(it.name) + '</b><span>' + esc(it.vendor_sku || it.sku || '') + (it.cost_cents != null ? ' · ' + money(it.cost_cents) : '')
          + (it.here ? ' · <em>on this PO</em>' : (it.elsewhere && it.elsewhere.length ? ' · <em>on order</em>' : '')) + '</span></div>';
      }).join('') + '<div data-i="name"><b>Add “' + esc(q) + '” by name</b><span>not in your catalog — created when it\'s received</span></div>';
      box.hidden = false;
      box.onclick = function (e) {
        var row = e.target.closest('[data-i]'); if (!row) return;
        box.hidden = true; var i = row.getAttribute('data-i');
        cb(i === 'name' ? { name: q } : last[parseInt(i, 10)]);
        input.value = '';
      };
    });
  }
  if (search) {
    var st;
    search.addEventListener('input', function () { clearTimeout(st); st = setTimeout(function () { runSearch(search, results, function (it) { addItem(it, 1); }); }, 220); });
    document.addEventListener('mousedown', function (e) { if (!e.target.closest('.po-add')) results.hidden = true; });
  }

  // ── actions ──
  document.addEventListener('click', function (e) {
    var cp = e.target.closest('[data-po-copy]');
    if (cp) {
      var rows = JSON.parse(cp.getAttribute('data-po-copy') || '[]'), text = rows.map(function (r) { return r[0] + '\t' + r[1]; }).join('\n'), label = cp.textContent;
      var done = function (ok) { cp.textContent = ok ? 'Copied ' + rows.length + ' line' + (rows.length === 1 ? '' : 's') : 'Copy failed'; setTimeout(function () { cp.textContent = label; }, 2200); };
      if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(text).then(function () { done(true); }, function () { done(false); }); } else { done(false); }
      return;
    }
    var b = e.target.closest('[data-po-act]'); if (!b) return;
    var act = b.getAttribute('data-po-act');

    if (act === 'delete') {
      dialog('Delete draft ' + PO.number + '?', 'Nothing was sent to ' + esc(PO.vendor) + '.' + (PO.sos ? ' The ' + PO.sos + ' special order' + (PO.sos === 1 ? '' : 's') + ' on it stay Needed and show on the Special orders board again.' : ''), '',
        [{ label: 'Keep it' }, { label: 'Delete draft', kind: 'danger', run: function () { return req('DELETE', U.destroy).then(function (d) { if (!d.ok) return fail(d); location.href = d.url; }); } }]);
    }
    else if (act === 'place') {
      var dl = dialog('Place ' + PO.number + ' with ' + esc(PO.vendor) + '?', PO.lines + ' line' + (PO.lines === 1 ? '' : 's') + '. Placing it puts them on order' + (PO.sos ? ' and marks ' + PO.sos + ' special order' + (PO.sos === 1 ? '' : 's') + ' Ordered' : '') + '.',
        '<div class="so-detail-label">How are you sending it?</div>'
        + '<label class="so-choice"><input type="radio" name="po-via" value="email"' + (PO.email ? ' checked' : '') + '><div><b>Email the PO to ' + esc(PO.vendor) + '</b><span>Sent from your shop\'s address with the PO as a PDF.</span></div></label>'
        + '<input class="ia-input ia-confirm-input" data-email value="' + esc(PO.email || '') + '" placeholder="Their email address" style="margin:-2px 0 10px">'
        + '<label class="so-choice"><input type="radio" name="po-via" value="website"' + (PO.email ? '' : ' checked') + '><div><b>I ordered on their website</b></div></label>'
        + '<label class="so-choice"><input type="radio" name="po-via" value="phone"><div><b>I phoned it in</b></div></label>'
        + '<label class="so-choice"><input type="radio" name="po-via" value="other"><div><b>Other</b></div></label>'
        + '<div class="po-f" style="margin-top:6px"><label class="po-fl">Their confirmation # (optional)<input class="ia-input" data-ref maxlength="64"></label>'
        + '<label class="po-fl">Expected<input class="ia-input" type="date" data-exp value="' + esc(PO.expected || '') + '"><span style="font-size:11.5px">' + esc(PO.leadNote) + '</span></label></div>',
        [{ label: 'Not yet' }, { label: 'Place order', kind: 'primary', run: function (d) {
          var via = (d.querySelector('input[name="po-via"]:checked') || {}).value, em = d.querySelector('[data-email]'), ex = d.querySelector('[data-exp]');
          if (via === 'email' && !/.+@.+\..+/.test(em.value)) { em.style.borderColor = '#F87171'; em.focus(); return false; }
          if (!ex.value) { ex.style.borderColor = '#F87171'; ex.focus(); return false; }
          return req('POST', U.place, { via: via, email: em.value, vendor_reference: d.querySelector('[data-ref]').value || null, expected_date: ex.value })
            .then(function (r) { if (!r.ok) return fail(r); reloadWith(r.message); });
        } }]);
      var syncVia = function (dlg) { var v = (dlg.querySelector('input[name="po-via"]:checked') || {}).value; dlg.querySelector('[data-email]').style.display = v === 'email' ? '' : 'none'; };
      syncVia(dl);
      dl.querySelectorAll('input[name="po-via"]').forEach(function (i) { i.addEventListener('change', function () { syncVia(dl); }); });
    }
    else if (act === 'email') {
      dialog('Email ' + PO.number + ' to ' + esc(PO.vendor) + '?', PO.changed ? 'Sends the current PO marked <b>Revised</b>, with the changes listed at the top.' : 'Sends the PO as a PDF.',
        '<input class="ia-input ia-confirm-input" data-email value="' + esc(PO.email || '') + '" placeholder="Their email address" style="margin:0 0 14px">',
        [{ label: 'Cancel' }, { label: 'Send', kind: 'primary', run: function (d) {
          var em = d.querySelector('[data-email]'); if (!/.+@.+\..+/.test(em.value)) { em.style.borderColor = '#F87171'; return false; }
          return req('POST', U.email, { email: em.value }).then(function (r) { if (!r.ok) return fail(r); reloadWith(r.message); });
        } }]);
    }
    else if (act === 'told') { req('POST', U.told).then(function (r) { if (!r.ok) return fail(r); reloadWith(r.message); }); }
    else if (act === 'receive') { b.disabled = true; req('POST', U.receive).then(function (r) { if (!r.ok) { b.disabled = false; return fail(r); } location.href = r.url; }); }
    else if (act === 'closeshort') {
      if (!OWED.length) { toast(true, 'Nothing is still owed.'); return; }
      var waiting = []; OWED.forEach(function (o) { waiting = waiting.concat(o.sos); });
      dialog('Close ' + PO.number + ' short?', 'Use this when you\'ve stopped waiting on the rest. What\'s still owed is cancelled and no longer counts as on order.',
        '<table>' + OWED.map(function (o) { return '<tr><td>' + esc(o.name) + '</td><td>' + o.owed + ' still owed</td></tr>'; }).join('') + '</table>'
        + (waiting.length ? '<div class="po-fine">' + esc(waiting.join(', ')) + ' go' + (waiting.length > 1 ? '' : 'es') + ' back to Needed, off this PO, with a note.</div>' : '')
        + '<input class="ia-input ia-confirm-input" data-r placeholder="Reason (optional)" style="margin:0 0 14px">',
        [{ label: 'Keep waiting' }, { label: 'Close short', kind: 'danger', run: function (d) {
          return req('POST', U.close, { reason: d.querySelector('[data-r]').value || null }).then(function (r) { if (!r.ok) return fail(r); reloadWith(r.message); });
        } }]);
    }
    else if (act === 'cancelpo') {
      if (PO.received > 0) {
        dialog(PO.number + ' can\'t be cancelled', 'Some of it has already been received. Use <b>Close short</b> to stop waiting on the rest, or cancel single lines with <b>Can\'t supply…</b>.', '',
          [{ label: 'OK' }, { label: 'Close short…', kind: 'primary', run: function () { setTimeout(function () { document.querySelector('[data-po-act="closeshort"]').click(); }, 200); } }]);
        return;
      }
      var all = []; OWED.forEach(function (o) { all = all.concat(o.sos); });
      dialog('Cancel ' + PO.number + '?', 'For when ' + esc(PO.vendor) + ' has cancelled the whole order. Nothing on it stays on order.',
        (all.length ? '<div class="po-fine">' + esc(all.join(', ')) + ' go' + (all.length > 1 ? '' : 'es') + ' back to Needed, off this PO, with a note.</div>' : '')
        + '<input class="ia-input ia-confirm-input" data-r placeholder="Reason (optional)" style="margin:0 0 14px">',
        [{ label: 'Keep it' }, { label: 'Cancel PO', kind: 'danger', run: function (d) {
          return req('POST', U.cancel, { reason: d.querySelector('[data-r]').value || null }).then(function (r) { if (!r.ok) return fail(r); reloadWith(r.message); });
        } }]);
    }
    else if (act === 'addline') {
      var chosen = null;
      var dlg = dialog('Add a line to ' + PO.number + '?', 'It\'s already been sent to ' + esc(PO.vendor) + ', so the PO is marked changed until you email the update or mark them told.',
        '<div class="po-add" style="max-width:none;margin-bottom:10px"><input class="ia-input" data-s placeholder="Search name, SKU or part number"><div class="po-results" data-res hidden></div></div>'
        + '<div data-picked class="po-fine" style="min-height:16px"></div><label class="po-fl" style="margin-bottom:14px">Quantity<input class="ia-input po-num" type="number" min="1" value="1" data-q></label>',
        [{ label: 'Cancel' }, { label: 'Add line', kind: 'primary', run: function (d) {
          if (!chosen) { d.querySelector('[data-s]').focus(); return false; }
          return addItem(chosen, parseInt(d.querySelector('[data-q]').value, 10) || 1, true).then(function () { return true; });
        } }]);
      var s = dlg.querySelector('[data-s]'), res = dlg.querySelector('[data-res]'), tm;
      s.addEventListener('input', function () { clearTimeout(tm); tm = setTimeout(function () { runSearch(s, res, function (it) { chosen = it; dlg.querySelector('[data-picked]').textContent = 'Adding: ' + it.name; }); }, 220); });
      setTimeout(function () { s.focus(); }, 60);
    }
  });
})();
</script>
@endsection
