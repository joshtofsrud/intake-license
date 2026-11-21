{{-- MARKER-ORDER-PRINT: the tag taped to a pickup order's bag at the counter. --}}
<!DOCTYPE html>
<html lang="en"><head>
@php $o = $orders->first(); $logoUrl = ! empty($print['logo_path']) ? asset('storage/' . ltrim($print['logo_path'], '/')) : null; @endphp
<title>Bag tag {{ $o->order_number }}</title>
@include('tenant.orders.print._slip-head')
<style>.who { font-family: system-ui, -apple-system, sans-serif; font-size: 22px; font-weight: 800; line-height: 1.15; margin: 6px 0 4px; word-break: break-word; }</style>
</head><body>
@unless($embed ?? false)<div class="printbar"><span>Bag tag {{ $o->order_number }}</span><button onclick="window.print()">Print</button></div>@endunless
<div class="slip ctr">
  @if($logoUrl)<img class="logo" src="{{ $logoUrl }}" alt="{{ $tenant->name }}">@else<div class="shop">{{ strtoupper($tenant->name) }}</div>@endif
  <hr class="hr">
  <div class="lbl">ONLINE ORDER · {{ $o->fulfillment_type === 'local_delivery' ? 'DELIVERY' : 'PICKUP' }}</div>
  <div class="who">{{ $o->contactName() ?: 'Customer' }}</div>
  <div class="shop" style="font-size:16px">{{ $o->order_number }}</div>
  @if($o->contact_phone)<div style="margin-top:4px">{{ $o->contact_phone }}</div>@endif
  <hr class="hr">
  <div>{{ (int) $o->items->sum('quantity') }} {{ (int) $o->items->sum('quantity') === 1 ? 'item' : 'items' }} · {{ $o->sale_id ? 'PAID' : 'NOT PAID YET' }}</div>
  <div style="font-size:10px">Paid {{ $o->paid_at ? tlocal($o->paid_at, 'M j') : '—' }}</div>
  <div class="feed"></div>
</div>
</body></html>
