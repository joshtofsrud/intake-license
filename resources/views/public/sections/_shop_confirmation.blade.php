{{-- chrome-wrapped shop body (confirmation); rendered
     through public.layout via SiteChromeService between the tenant own
     nav + footer sections. Original standalone blade retired. --}}
@php
  $accent = $tenant->accent_color ?? '#BEF264';
  $tname  = $tenant->name ?? 'Shop';
  $money  = fn ($c) => '$' . number_format(($c ?? 0) / 100, 2);
  $paid   = in_array($order->status, ['paid', 'fulfilling', 'fulfilled', 'completed']);
  // MARKER-ECOM-STOCK-GUARD: paid, then couldn't be completed, then refunded
  $refunded = $order->payment_status === 'refunded';
@endphp
@unless($paid || $refunded || $order->payment_method)
<script>setTimeout(function () { window.location.reload(); }, 6000);</script>
@endunless
<style>

  :root { --acc: {{ $accent }}; }

  .spg-confirm .wrap { max-width: 620px; margin: 0 auto; padding: 34px 20px 80px; }
  .spg-confirm .home { font-weight: 700; font-size: 16px; display: inline-block; margin-bottom: 28px; }
  .spg-confirm .hero { text-align: center; margin-bottom: 26px; }
  .spg-confirm .ic { width: 58px; height: 58px; border-radius: 50%; background: var(--acc); display: grid; place-items: center; font-size: 25px; margin: 0 auto 14px; }
  .spg-confirm h1 { font-size: 23px; font-weight: 750; letter-spacing: -.015em; }
  .spg-confirm .sub { font-size: 14px; opacity: .6; margin-top: 5px; }
  .spg-confirm .card { background: #fff; border: 1.5px solid rgba(0,0,0,.09); border-radius: 16px; padding: 6px 20px; margin-bottom: 14px; }
  .spg-confirm .line { display: flex; gap: 12px; padding: 13px 0; border-bottom: 1px solid rgba(0,0,0,.06); font-size: 13.5px; align-items: center; }
  .spg-confirm .line:last-child { border-bottom: 0; }
  .spg-confirm .line img { width: 40px; height: 40px; object-fit: contain; border: 1px solid rgba(0,0,0,.07); border-radius: 8px; }
  .spg-confirm .line .q { opacity: .5; }
  .spg-confirm .line .p { margin-left: auto; font-weight: 650; }
  .spg-confirm .tot { display: flex; justify-content: space-between; padding: 13px 0; font-size: 13.5px; }
  .spg-confirm .tot.grand { font-size: 16px; font-weight: 800; border-top: 1.5px solid rgba(0,0,0,.09); }
  .spg-confirm .next { background: rgba(0,0,0,.03); border-radius: 14px; padding: 16px 18px; font-size: 13.5px; line-height: 1.65; }
  .spg-confirm .next b { display: block; margin-bottom: 3px; }
  .spg-confirm .meta { font-size: 12px; opacity: .5; text-align: center; margin-top: 22px; }

</style>
<div class="spg-confirm">
  <div class="wrap">

  <a class="home" href="/">{{ $tname }}</a>

  <div class="hero">
    @if($refunded)
      <div class="ic" style="background:rgba(192,57,43,.12)">!</div>
      <h1>We couldn't complete your order</h1>
      <div class="sub">{{ $order->order_number }} · An item sold out just as you were paying. We've refunded the full {{ $money($order->total_cents) }} to your card (allow 5–10 business days). We've emailed you the details.</div>
    @elseif($paid)
      <div class="ic">✓</div>
      <h1>Order confirmed</h1>
      <div class="sub">{{ $order->order_number }} · a receipt is on its way to {{ $order->contact_email }}</div>
    @elseif($order->payment_method)
      {{-- manual payment: order received, show how to pay --}}
      <div class="ic" style="background:rgba(0,0,0,.07)">⏳</div>
      <h1>Order received — one step left</h1>
      <div class="sub">{{ $order->order_number }} · we'll confirm as soon as your payment lands</div>
    @else
      <div class="ic" style="background:rgba(0,0,0,.07)">⋯</div>
      <h1>Finishing up…</h1>
      <div class="sub">We're confirming your payment — this page refreshes itself.</div>
    @endif
  </div>

  @if(!$paid && $order->payment_method)
    @php
      $pmRow = \App\Models\Tenant\TenantPaymentMethod::where('tenant_id', $order->tenant_id)
          ->where('method_key', $order->payment_method)->first();
      $payLink = $pmRow?->linkTemplate()
          ? str_replace('{amount}', number_format($order->total_cents / 100, 2, '.', ''), $pmRow->linkTemplate())
          : null;
    @endphp
    <div class="card" style="padding:16px 20px;border-color:rgba(180,140,0,.35);background:#fffbe9">
      <b style="font-size:14px">Pay {{ $money($order->total_cents) }} with {{ $pmRow?->name ?? tender_label($order->payment_method) }}</b>
      <div style="font-size:13px;line-height:1.6;margin-top:6px">
        @if($pmRow?->instructions){{ $pmRow->instructions }}@endif
        Include <b>{{ $order->order_number }}</b> in the note so we can match it fast.
      </div>
      @if($payLink)
        <a href="{{ $payLink }}" style="display:inline-block;margin-top:12px;background:#111;color:#fff;font-weight:700;font-size:13px;border-radius:10px;padding:11px 18px;text-decoration:none">Pay now →</a>
        <div style="font-size:11px;opacity:.55;margin-top:8px;word-break:break-all">{{ $payLink }}</div>
      @endif
    </div>
  @endif

  <div class="card">
    @foreach($order->items as $l)
      <div class="line">
        @if($l->image_snapshot)<img src="{{ $l->image_snapshot }}" alt="">@endif
        <span>{{ $l->name_snapshot }} <span class="q">×{{ (int) $l->quantity }}</span></span>
        <span class="p">{{ $money($l->line_total_cents) }}</span>
      </div>
    @endforeach
    <div class="tot"><span>Subtotal</span><span>{{ $money($order->subtotal_cents) }}</span></div>
    <div class="tot"><span>Tax</span><span>{{ $money($order->tax_cents) }}</span></div>
    @if($order->shipping_cents > 0)<div class="tot"><span>Delivery</span><span>{{ $money($order->shipping_cents) }}</span></div>@endif
    <div class="tot grand"><span>Total</span><span>{{ $money($order->total_cents) }}</span></div>
  </div>

  @if($paid)
    <div class="next">
      @if($order->fulfillment_type === 'ship')
        {{-- MARKER-ORDER-SHIPMENTS --}}
        @php $ships = $order->shipments()->with('items.orderItem')->get(); $supLabel = \App\Services\Distributors\WarehouseRegistry::shopConfig($tenant)['label']; @endphp
        <b>{{ $ships->count() > 1 ? 'Shipping in ' . $ships->count() . ' shipments' : 'Shipping' }}</b>
        To {{ $order->fulfillment_address['line'] ?? 'your address' }}.
        @foreach($ships as $i => $sh)
          <div style="margin-top:8px">
            <b style="display:inline">{{ $ships->count() > 1 ? 'Shipment ' . ($i + 1) . ' · ' : '' }}{{ $sh->source === 'supplier' ? $supLabel : 'Ships from our shop' }}</b>{{ $sh->source === 'supplier' && $sh->handling_days > 0 ? ' — ships in about ' . $sh->handling_days . ' business day' . ($sh->handling_days === 1 ? '' : 's') : '' }}
            @if($sh->service_name)<br>{{ $sh->service_name }}@endif
            {{-- MARKER-SHIPMENT-WORKFLOW --}}
            @if(($sh->status ?? 'pending') !== 'pending')<br><b style="display:inline">{{ \App\Services\Tenant\ShipmentFlow::LABELS[$sh->status] ?? $sh->status }}</b>@endif
            @if($sh->tracking_number)@php $tu = \App\Services\Tenant\ShipmentFlow::trackingUrl($sh->carrier, $sh->tracking_number); @endphp<br>Tracking: {{ \App\Services\Tenant\ShipmentFlow::carriers()[$sh->carrier] ?? '' }} @if($tu)<a href="{{ $tu }}" target="_blank" rel="noopener">{{ $sh->tracking_number }}</a>@else{{ $sh->tracking_number }}@endif @endif
            <br>@foreach($sh->items as $it){{ $it->orderItem?->name_snapshot }} ×{{ $it->quantity }}@if(! $loop->last), @endif @endforeach
          </div>
        @endforeach
      @elseif($order->fulfillment_type === 'local_delivery')
        <b>Local delivery</b>
        We'll reach out to line up a delivery window to {{ $order->fulfillment_address['line'] ?? 'your address' }}.
      @else
        <b>Pickup</b>
        We'll text {{ $order->contact_phone ?: 'you' }} as soon as it's ready to grab.
      @endif
      @if($order->wants_install)
        <br><b style="margin-top:8px">Installation</b>
        You asked for install — we'll reach out to get it on the schedule.
      @endif
    </div>
  @endif

  <div class="meta">
    {{ $order->order_number }}
    @if($order->card_last4) · {{ ucfirst($order->card_brand) }} ····{{ $order->card_last4 }}@endif
  </div>

  </div>
</div>

