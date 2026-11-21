{{-- MARKER-ORDER-PRINT: what to pull for each order, one slip per order. --}}
<!DOCTYPE html>
<html lang="en"><head>
<title>Pick {{ $orders->count() === 1 ? 'list ' . $orders->first()->order_number : 'lists (' . $orders->count() . ' orders)' }}</title>
@include('tenant.orders.print._slip-head')
</head><body>
@unless($embed ?? false)<div class="printbar"><span>{{ $orders->count() === 1 ? 'Pick list ' . $orders->first()->order_number : $orders->count() . ' pick lists, one per page' }}</span><button onclick="window.print()">Print</button></div>@endunless
@foreach($orders as $o)
  @php $ships = $o->fulfillment_type === 'ship' ? $o->shipments()->with('items.orderItem')->get() : collect(); @endphp
  <div class="slip">
    <div class="ctr">
      <div class="lbl">PICK LIST</div>
      <div class="shop" style="font-size:16px;margin-top:2px">{{ $o->order_number }}</div>
      <div>{{ $o->contactName() ?: 'Customer' }}</div>
      <div style="font-size:10px">{{ $o->fulfillment_type === 'ship' ? 'SHIP' : ($o->fulfillment_type === 'local_delivery' ? 'LOCAL DELIVERY' : 'PICKUP') }} · paid {{ $o->paid_at ? tlocal($o->paid_at, 'M j, g:ia') : '—' }}</div>
    </div>
    <hr class="hr">
    @php
      // a shipped order pulls only what leaves from the shop; supplier shipments never touch the shelf
      $fromShop = $ships->isNotEmpty()
        ? $ships->where('source', 'shop')->flatMap(fn ($sh) => $sh->items)->groupBy(fn ($it) => (string) $it->order_item_id)->map(fn ($g) => (int) $g->sum('quantity'))->all()
        : null;
    @endphp
    <table>
      @foreach($o->items as $l)
        @php $q = $fromShop === null ? (int) $l->quantity : (int) ($fromShop[(string) $l->id] ?? 0); @endphp
        @continue($q <= 0)
        <tr>
          <td class="box"><span></span></td>
          <td class="qty">{{ $q }}×</td>
          <td>{{ $l->name_snapshot }}@if($l->inventory_item_id && ! empty($bins[(string) $l->inventory_item_id]))<div class="bin">BIN {{ $bins[(string) $l->inventory_item_id] }}</div>@endif</td>
        </tr>
      @endforeach
    </table>
    @if($fromShop !== null && $ships->where('source', 'supplier')->isNotEmpty())
      <hr class="hr">
      <div style="font-size:10px">Not pulled here: {{ $ships->where('source', 'supplier')->flatMap(fn ($sh) => $sh->items)->sum('quantity') }} item(s) ship straight from the supplier.</div>
    @endif
    @if($o->fulfillment_notes)<hr class="hr"><div style="font-size:10px">NOTE: {{ $o->fulfillment_notes }}</div>@endif
    @if($o->wants_install)<div style="font-size:10px;font-weight:700">Customer asked about installation.</div>@endif
    <hr class="hr">
    <div style="font-size:10px">Pulled by ________ &nbsp; Checked ________</div>
    <div class="feed"></div>
  </div>
@endforeach
</body></html>
