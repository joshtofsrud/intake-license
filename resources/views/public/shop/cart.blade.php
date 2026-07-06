<!DOCTYPE html>
{{-- MARKER-PATCH-564 — Online Retail Wave 3: cart page. Plain forms, same
     public design language. Checkout button disabled until Wave 4. --}}
@php
  $accent = $tenant->accent_color ?? '#BEF264';
  $tname  = $tenant->name ?? 'Shop';
  $money  = fn ($c) => '$' . number_format(($c ?? 0) / 100, 2);
  $empty  = ! $cart || $cart->items->isEmpty();
@endphp
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cart — {{ $tname }}</title>
<style>
  :root { --acc: {{ $accent }}; }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Inter', sans-serif; color: #161616; background: #fafafa; line-height: 1.55; -webkit-font-smoothing: antialiased; }
  a { color: inherit; text-decoration: none; }
  .wrap { max-width: 760px; margin: 0 auto; padding: 28px 20px 80px; }
  .top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 26px; }
  .top a.home { font-weight: 700; font-size: 16px; }
  h1 { font-size: 24px; font-weight: 650; letter-spacing: -.02em; margin-bottom: 20px; }
  .flash { background: rgba(52,168,83,.09); color: #1d7a3a; border-radius: 10px; padding: 11px 16px; font-size: 13.5px; font-weight: 600; margin-bottom: 16px; }
  .card { background: #fff; border: 1.5px solid rgba(0,0,0,.09); border-radius: 16px; overflow: hidden; }
  .line { display: flex; gap: 14px; padding: 16px; border-bottom: 1px solid rgba(0,0,0,.06); align-items: center; }
  .line:last-child { border-bottom: 0; }
  .line img, .line .ph { width: 62px; height: 62px; object-fit: contain; background: #fff; border: 1px solid rgba(0,0,0,.07); border-radius: 10px; flex: none; }
  .line .ph { display: grid; place-items: center; font-size: 9px; opacity: .35; }
  .line .b { min-width: 0; flex: 1; }
  .line .n { font-size: 14px; font-weight: 650; line-height: 1.3; }
  .line .v { font-size: 12px; opacity: .55; margin-top: 2px; }
  .line .unit { font-size: 12px; opacity: .55; margin-top: 4px; }
  .qty { display: flex; align-items: center; gap: 0; border: 1.5px solid rgba(0,0,0,.12); border-radius: 9px; overflow: hidden; flex: none; }
  .qty button { font: inherit; font-size: 15px; width: 30px; height: 32px; border: 0; background: #fff; cursor: pointer; }
  .qty span { min-width: 30px; text-align: center; font-size: 13.5px; font-weight: 650; }
  .line .tot { font-size: 14.5px; font-weight: 750; width: 76px; text-align: right; flex: none; }
  .line .rm button { font: inherit; background: none; border: 0; font-size: 17px; opacity: .35; cursor: pointer; padding: 4px; }
  .line .rm button:hover { opacity: .8; }
  .sum { display: flex; justify-content: space-between; align-items: baseline; padding: 18px 4px 6px; font-size: 15px; }
  .sum b { font-size: 19px; }
  .sum-note { font-size: 12px; opacity: .5; text-align: right; }
  .cta { display: block; width: 100%; text-align: center; font: inherit; font-size: 15px; font-weight: 700; padding: 15px 0; border: 0; border-radius: 12px; background: var(--acc); margin-top: 16px; cursor: not-allowed; opacity: .55; }
  .cta-note { font-size: 12px; opacity: .5; text-align: center; margin-top: 8px; }
  .empty { text-align: center; padding: 60px 20px; }
  .empty p { opacity: .55; margin-bottom: 18px; }
  .empty a { display: inline-block; font-weight: 700; font-size: 14px; padding: 12px 26px; border-radius: 11px; background: var(--acc); }
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <a class="home" href="/">{{ $tname }}</a>
    <a href="/shop" style="font-size:13.5px;opacity:.6">← Keep shopping</a>
  </div>

  <h1>Your cart</h1>

  @if(session('added'))
    <div class="flash">Added {{ session('added') }} to your cart.</div>
  @endif

  @if($empty)
    <div class="empty">
      <p>Nothing in here yet.</p>
      <a href="/shop">Browse the shop</a>
    </div>
  @else
    <div class="card">
      @foreach($cart->items as $line)
        <div class="line">
          @if($line->image_snapshot)<img src="{{ $line->image_snapshot }}" alt="">@else<div class="ph">{{ $tname }}</div>@endif
          <div class="b">
            <div class="n">{{ $line->name_snapshot }}</div>
            @if($line->variant_snapshot)<div class="v">{{ $line->variant_snapshot }}</div>@endif
            <div class="unit">{{ $money($line->unit_price_cents) }} each</div>
          </div>
          <div class="qty">
            <form method="POST" action="/cart/items/{{ $line->id }}">@csrf @method('PATCH')
              <input type="hidden" name="quantity" value="{{ (int) $line->quantity - 1 }}">
              <button title="Less">−</button>
            </form>
            <span>{{ (int) $line->quantity }}</span>
            <form method="POST" action="/cart/items/{{ $line->id }}">@csrf @method('PATCH')
              <input type="hidden" name="quantity" value="{{ (int) $line->quantity + 1 }}">
              <button title="More">+</button>
            </form>
          </div>
          <div class="tot">{{ $money($line->line_total_cents) }}</div>
          <div class="rm">
            <form method="POST" action="/cart/items/{{ $line->id }}">@csrf @method('DELETE')
              <button title="Remove">×</button>
            </form>
          </div>
        </div>
      @endforeach
    </div>

    <div class="sum">
      <span>Subtotal</span>
      <b>{{ $money($cart->subtotal_cents) }}</b>
    </div>
    <div class="sum-note">Tax and any delivery fee are calculated at checkout.</div>

    {{-- MARKER-PATCH-566 — checkout is live --}}
    <a class="cta" href="/checkout" style="cursor:pointer;opacity:1;text-decoration:none">Checkout</a>
    <div class="cta-note">Secure payment · pickup or local delivery</div>
  @endif
</div>
</body>
</html>
