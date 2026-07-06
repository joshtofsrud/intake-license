{{-- MARKER-PATCH-579 -- chrome-wrapped shop body (cart); rendered
     through public.layout via SiteChromeService between the tenant own
     nav + footer sections. Original standalone blade retired. --}}
@php
  $accent = $tenant->accent_color ?? '#BEF264';
  $tname  = $tenant->name ?? 'Shop';
  $money  = fn ($c) => '$' . number_format(($c ?? 0) / 100, 2);
  $empty  = ! $cart || $cart->items->isEmpty();
@endphp
<style>

  :root { --acc: {{ $accent }}; }

  .spg-cart .wrap { max-width: 760px; margin: 0 auto; padding: 28px 20px 80px; }
  .spg-cart .top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 26px; }
  .spg-cart .top a.home { font-weight: 700; font-size: 16px; }
  .spg-cart h1 { font-size: 24px; font-weight: 650; letter-spacing: -.02em; margin-bottom: 20px; }
  .spg-cart .flash { background: rgba(52,168,83,.09); color: #1d7a3a; border-radius: 10px; padding: 11px 16px; font-size: 13.5px; font-weight: 600; margin-bottom: 16px; }
  .spg-cart .card { background: #fff; border: 1.5px solid rgba(0,0,0,.09); border-radius: 16px; overflow: hidden; }
  .spg-cart .line { display: flex; gap: 14px; padding: 16px; border-bottom: 1px solid rgba(0,0,0,.06); align-items: center; }
  .spg-cart .line:last-child { border-bottom: 0; }
  .spg-cart .line img, .line .ph { width: 62px; height: 62px; object-fit: contain; background: #fff; border: 1px solid rgba(0,0,0,.07); border-radius: 10px; flex: none; }
  .spg-cart .line .ph { display: grid; place-items: center; font-size: 9px; opacity: .35; }
  .spg-cart .line .b { min-width: 0; flex: 1; }
  .spg-cart .line .n { font-size: 14px; font-weight: 650; line-height: 1.3; }
  .spg-cart .line .v { font-size: 12px; opacity: .55; margin-top: 2px; }
  .spg-cart .line .unit { font-size: 12px; opacity: .55; margin-top: 4px; }
  .spg-cart .qty { display: flex; align-items: center; gap: 0; border: 1.5px solid rgba(0,0,0,.12); border-radius: 9px; overflow: hidden; flex: none; }
  .spg-cart .qty button { font: inherit; font-size: 15px; width: 30px; height: 32px; border: 0; background: #fff; cursor: pointer; }
  .spg-cart .qty span { min-width: 30px; text-align: center; font-size: 13.5px; font-weight: 650; }
  .spg-cart .line .tot { font-size: 14.5px; font-weight: 750; width: 76px; text-align: right; flex: none; }
  .spg-cart .line .rm button { font: inherit; background: none; border: 0; font-size: 17px; opacity: .35; cursor: pointer; padding: 4px; }
  .spg-cart .line .rm button:hover { opacity: .8; }
  .spg-cart .sum { display: flex; justify-content: space-between; align-items: baseline; padding: 18px 4px 6px; font-size: 15px; }
  .spg-cart .sum b { font-size: 19px; }
  .spg-cart .sum-note { font-size: 12px; opacity: .5; text-align: right; }
  .spg-cart .cta { display: block; width: 100%; text-align: center; font: inherit; font-size: 15px; font-weight: 700; padding: 15px 0; border: 0; border-radius: 12px; background: var(--acc); margin-top: 16px; cursor: not-allowed; opacity: .55; }
  .spg-cart .cta-note { font-size: 12px; opacity: .5; text-align: center; margin-top: 8px; }
  .spg-cart .empty { text-align: center; padding: 60px 20px; }
  .spg-cart .empty p { opacity: .55; margin-bottom: 18px; }
  .spg-cart .empty a { display: inline-block; font-weight: 700; font-size: 14px; padding: 12px 26px; border-radius: 11px; background: var(--acc); }

</style>
<div class="spg-cart">
  <div class="wrap">
    <div style="display:flex;justify-content:flex-end;padding:14px 0 0">
      <a href="/shop" style="font-size:13.5px;opacity:.6;text-decoration:none;color:inherit">&larr; Keep shopping</a>
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
</div>
