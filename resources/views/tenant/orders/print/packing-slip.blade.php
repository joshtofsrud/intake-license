{{-- MARKER-ORDER-PRINT: the full-page packing slip and receipt that goes in the box.
     Per shipment when one is chosen: what's in this box, and what comes separately. --}}
@php
  $sym   = $tenant->currency_symbol ?: '$';
  $m     = fn ($c) => $sym . number_format(((int) $c) / 100, 2);
  $logo  = $tenant->logo_url ?: (! empty($print['logo_path']) ? asset('storage/' . ltrim($print['logo_path'], '/')) : null);
  $boxes = $one ? collect([$one]) : ($ships->isNotEmpty() ? $ships->where('status', '!=', 'cancelled') : collect([null]));
  $addr  = (array) ($order->fulfillment_address ?? []);
  $isShip = $order->fulfillment_type === 'ship';
  $docName = $isShip ? 'Packing slip' : 'Receipt';
  // the shop's own contact details: its main location's phone, and where replies go
  $shopPhone = $tenant->phone;
  $shopEmail = $tenant->email_reply_to ?: $tenant->notification_email;
@endphp
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $docName }} {{ $order->order_number }}</title>
<style>
  @page { size: letter; margin: 14mm; }
  * { box-sizing: border-box; }
  body { margin: 0; color: #111; font-family: Inter, system-ui, -apple-system, "Segoe UI", sans-serif; font-size: 12.5px; line-height: 1.5; background: #fff; }
  .page { max-width: 188mm; margin: 0 auto; }
  .page + .page { break-before: page; page-break-before: always; }
  .top { display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; border-bottom: 2px solid #111; padding-bottom: 12px; }
  .top img { max-height: 46px; max-width: 200px; }
  .shop { font-size: 18px; font-weight: 800; }
  .doc { text-align: right; } .doc b { font-size: 18px; display: block; }
  .cols { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin: 16px 0; }
  .k { font-size: 10px; letter-spacing: .14em; text-transform: uppercase; color: #666; margin-bottom: 3px; }
  table { width: 100%; border-collapse: collapse; }
  th { text-align: left; font-size: 10px; letter-spacing: .12em; text-transform: uppercase; color: #666; border-bottom: 1px solid #111; padding: 6px 4px; }
  td { padding: 8px 4px; border-bottom: 1px solid #ddd; vertical-align: top; }
  .r { text-align: right; white-space: nowrap; } .c { text-align: center; width: 18mm; }
  .sep { color: #666; font-size: 11.5px; margin-top: 10px; }
  .tot { width: 70mm; margin: 12px 0 0 auto; } .tot td { border: 0; padding: 3px 4px; } .tot .g td { font-weight: 800; font-size: 14px; border-top: 1px solid #111; padding-top: 7px; }
  .foot { margin-top: 26px; border-top: 1px solid #ddd; padding-top: 10px; font-size: 11.5px; color: #444; }
  @media screen { body { background: #eee; padding: 64px 0 30px; } .page { background: #fff; padding: 14mm; margin-bottom: 18px; box-shadow: 0 2px 12px rgba(0,0,0,.12); }
    .printbar { position: fixed; top: 0; left: 0; right: 0; background: #111; color: #fff; font-size: 13px; padding: 10px 16px; display: flex; gap: 12px; align-items: center; justify-content: center; z-index: 5; }
    .printbar button { background: #BEF264; color: #0a0a0a; border: 0; font-weight: 600; padding: 7px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; } }
  @media print { .printbar { display: none; } }
</style>
</head><body>
<div class="printbar"><span>{{ $docName }} {{ $order->order_number }}{{ $boxes->count() > 1 ? ' · ' . $boxes->count() . ' shipments, one per page' : '' }}</span><button onclick="window.print()">Print</button></div>
@foreach($boxes as $bi => $sh)
  @php
    $here = $sh ? $sh->items->groupBy(fn ($it) => (string) $it->order_item_id)->map(fn ($g) => (int) $g->sum('quantity'))->all() : null;
    $elsewhere = $sh ? $order->items->filter(fn ($l) => ($here[(string) $l->id] ?? 0) < (int) $l->quantity) : collect();
  @endphp
  <div class="page">
    <div class="top">
      <div>@if($logo)<img src="{{ $logo }}" alt="{{ $tenant->name }}">@else<div class="shop">{{ $tenant->name }}</div>@endif
        <div style="color:#555;margin-top:4px">{{ $shopPhone }}@if($shopPhone && $shopEmail) · @endif{{ $shopEmail }}</div></div>
      <div class="doc"><b>{{ $docName }}</b>Order {{ $order->order_number }}<br>{{ $order->paid_at ? tlocal($order->paid_at, 'M j, Y') : '' }}@if($sh && $ships->count() > 1)<br>Shipment {{ $ships->search(fn ($x) => $x->id === $sh->id) + 1 }} of {{ $ships->where('status', '!=', 'cancelled')->count() }}@endif</div>
    </div>
    <div class="cols">
      <div><div class="k">{{ $isShip ? 'Ship to' : ($order->fulfillment_type === 'local_delivery' ? 'Deliver to' : 'Customer') }}</div><b>{{ $order->contactName() }}</b><br>{{ $addr['line1'] ?? ($addr['line'] ?? '') }}@if(! empty($addr['line2']))<br>{{ $addr['line2'] }}@endif @if(! empty($addr['city']))<br>{{ $addr['city'] }}, {{ $addr['state'] ?? '' }} {{ $addr['zip'] ?? '' }}@endif</div>
      <div><div class="k">Order</div>{{ $order->contact_email }}@if($order->contact_phone)<br>{{ $order->contact_phone }}@endif
        @if($sh && $sh->tracking_number)<br><span class="k" style="display:inline">Tracking</span> {{ \App\Services\Tenant\ShipmentFlow::carriers()[$sh->carrier] ?? '' }} {{ $sh->tracking_number }}@endif</div>
    </div>
    <table>
      <thead><tr><th>Item</th><th class="c">{{ $sh ? 'In this box' : 'Qty' }}</th><th class="r">Price</th><th class="r">Total</th></tr></thead>
      <tbody>
        @foreach($order->items as $l)
          @php $q = $here === null ? (int) $l->quantity : (int) ($here[(string) $l->id] ?? 0); @endphp
          @continue($q <= 0)
          <tr><td>{{ $l->name_snapshot }}</td><td class="c">{{ $q }}</td><td class="r">{{ $m($l->unit_price_cents) }}</td><td class="r">{{ $m($l->unit_price_cents * $q) }}</td></tr>
        @endforeach
      </tbody>
    </table>
    @if($elsewhere->isNotEmpty())
      <div class="sep">Coming separately: @foreach($elsewhere as $l){{ $l->name_snapshot }} ×{{ (int) $l->quantity - (int) ($here[(string) $l->id] ?? 0) }}@if(! $loop->last), @endif @endforeach</div>
    @endif
    @if($loop->first)
      <table class="tot">
        <tr><td>Subtotal</td><td class="r">{{ $m($order->subtotal_cents) }}</td></tr>
        @if((int) $order->discount_cents > 0)<tr><td>Discount{{ $order->discount_code ? ' (' . $order->discount_code . ')' : '' }}</td><td class="r">−{{ $m($order->discount_cents) }}</td></tr>@endif
        @if((int) $order->shipping_cents > 0)<tr><td>Shipping</td><td class="r">{{ $m($order->shipping_cents) }}</td></tr>@endif
        <tr><td>Tax</td><td class="r">{{ $m($order->tax_cents) }}</td></tr>
        <tr class="g"><td>Total paid</td><td class="r">{{ $m($order->total_cents) }}</td></tr>
        <tr><td colspan="2" style="color:#666;font-size:11px">{{ $order->card_brand ? ucfirst($order->card_brand) . ' •' . $order->card_last4 : ($order->payment_method ? tender_label($order->payment_method) : 'Card') }}{{ $order->sale?->sale_number ? ' · receipt ' . $order->sale->sale_number : '' }}</td></tr>
      </table>
    @else
      <div class="sep">Totals for the whole order are on the slip in the first box.</div>
    @endif
    <div class="foot">
      Thanks for your order.@if($returns) Returns: {{ rtrim($tenant->publicUrl(), '/') . $returns }}@endif @if($shopEmail || $shopPhone) Questions? {{ $shopEmail ?: $shopPhone }}@endif
    </div>
  </div>
@endforeach
</body></html>
