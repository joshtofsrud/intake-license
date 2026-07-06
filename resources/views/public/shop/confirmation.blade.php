<!DOCTYPE html>
{{-- MARKER-PATCH-566 — Online Retail Wave 4: order confirmation / status.
     The /order/{token} page: receipt when paid, honest holding state when
     the payment hasn't landed yet (webhook may still finalize). --}}
@php
  $accent = $tenant->accent_color ?? '#BEF264';
  $tname  = $tenant->name ?? 'Shop';
  $money  = fn ($c) => '$' . number_format(($c ?? 0) / 100, 2);
  $paid   = in_array($order->status, ['paid', 'fulfilling', 'fulfilled', 'completed']);
@endphp
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Order {{ $order->order_number }} — {{ $tname }}</title>
@unless($paid)<meta http-equiv="refresh" content="6">@endunless
<style>
  :root { --acc: {{ $accent }}; }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Inter', sans-serif; color: #161616; background: #fafafa; line-height: 1.55; -webkit-font-smoothing: antialiased; }
  a { color: inherit; text-decoration: none; }
  .wrap { max-width: 620px; margin: 0 auto; padding: 34px 20px 80px; }
  .home { font-weight: 700; font-size: 16px; display: inline-block; margin-bottom: 28px; }
  .hero { text-align: center; margin-bottom: 26px; }
  .ic { width: 58px; height: 58px; border-radius: 50%; background: var(--acc); display: grid; place-items: center; font-size: 25px; margin: 0 auto 14px; }
  h1 { font-size: 23px; font-weight: 750; letter-spacing: -.015em; }
  .sub { font-size: 14px; opacity: .6; margin-top: 5px; }
  .card { background: #fff; border: 1.5px solid rgba(0,0,0,.09); border-radius: 16px; padding: 6px 20px; margin-bottom: 14px; }
  .line { display: flex; gap: 12px; padding: 13px 0; border-bottom: 1px solid rgba(0,0,0,.06); font-size: 13.5px; align-items: center; }
  .line:last-child { border-bottom: 0; }
  .line img { width: 40px; height: 40px; object-fit: contain; border: 1px solid rgba(0,0,0,.07); border-radius: 8px; }
  .line .q { opacity: .5; }
  .line .p { margin-left: auto; font-weight: 650; }
  .tot { display: flex; justify-content: space-between; padding: 13px 0; font-size: 13.5px; }
  .tot.grand { font-size: 16px; font-weight: 800; border-top: 1.5px solid rgba(0,0,0,.09); }
  .next { background: rgba(0,0,0,.03); border-radius: 14px; padding: 16px 18px; font-size: 13.5px; line-height: 1.65; }
  .next b { display: block; margin-bottom: 3px; }
  .meta { font-size: 12px; opacity: .5; text-align: center; margin-top: 22px; }
</style>
</head>
<body>
<div class="wrap">
  <a class="home" href="/">{{ $tname }}</a>

  <div class="hero">
    @if($paid)
      <div class="ic">✓</div>
      <h1>Order confirmed</h1>
      <div class="sub">{{ $order->order_number }} · a receipt is on its way to {{ $order->contact_email }}</div>
    @else
      <div class="ic" style="background:rgba(0,0,0,.07)">⋯</div>
      <h1>Finishing up…</h1>
      <div class="sub">We're confirming your payment — this page refreshes itself.</div>
    @endif
  </div>

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
      @if($order->fulfillment_type === 'local_delivery')
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
</body>
</html>
