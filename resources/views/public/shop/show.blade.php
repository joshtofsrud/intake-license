<!DOCTYPE html>
{{-- MARKER-PATCH-561 — Online Retail Wave 2: product page. Gallery, specs
     from canonical attributes, availability panel. Add-to-cart renders
     disabled until Wave 3 wires the cart. --}}
@php
  $accent = $tenant->accent_color ?? '#BEF264';
  $tname  = $tenant->name ?? 'Shop';
  $money = fn ($c) => $c !== null ? '$' . number_format($c / 100, 2) : '';
  $inStock = ($item->computed_stock_count ?? 0) > 0;
@endphp
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $item->name }} — {{ $tname }}</title>
<style>
  :root { --acc: {{ $accent }}; }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Inter', sans-serif; color: #161616; background: #fafafa; line-height: 1.55; -webkit-font-smoothing: antialiased; }
  a { color: inherit; text-decoration: none; }
  .wrap { max-width: 1020px; margin: 0 auto; padding: 28px 20px 80px; }
  .top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
  .top a.home { font-weight: 700; font-size: 16px; }
  .crumb { font-size: 13px; opacity: .55; margin-bottom: 18px; }
  .cols { display: grid; grid-template-columns: 1fr 1fr; gap: 34px; align-items: start; }
  @media (max-width: 760px) { .cols { grid-template-columns: 1fr; } }
  .gal { background: #fff; border: 1.5px solid rgba(0,0,0,.09); border-radius: 16px; overflow: hidden; }
  .gal .main { aspect-ratio: 1; display: grid; place-items: center; }
  .gal .main img { width: 100%; height: 100%; object-fit: contain; padding: 24px; }
  .gal .main span { opacity: .3; font-size: 13px; }
  .thumbs { display: flex; gap: 8px; padding: 12px; border-top: 1px solid rgba(0,0,0,.06); }
  .thumbs img { width: 54px; height: 54px; object-fit: contain; background: #fff; border: 1.5px solid rgba(0,0,0,.1); border-radius: 9px; cursor: pointer; padding: 5px; }
  .thumbs img.on { border-color: #161616; }
  .brand { font-size: 11px; text-transform: uppercase; letter-spacing: .09em; opacity: .5; font-weight: 700; }
  h1 { font-size: 23px; font-weight: 700; letter-spacing: -.015em; line-height: 1.3; margin: 4px 0 6px; }
  .subt { font-size: 14px; opacity: .6; }
  .price { font-size: 26px; font-weight: 800; margin: 18px 0 4px; }
  .avail { display: inline-flex; align-items: center; gap: 7px; font-size: 13px; font-weight: 650; margin: 8px 0 20px; }
  .avail .dot { width: 8px; height: 8px; border-radius: 50%; }
  .avail.in .dot { background: #1d9a4b; } .avail.in { color: #1d7a3a; }
  .avail.order .dot { background: #888; } .avail.order { color: #555; }
  .qtybox { display: flex; align-items: stretch; border: 1.5px solid rgba(0,0,0,.14); border-radius: 11px; overflow: hidden; background: #fff; flex: none; }
  .qtybox button { font: inherit; font-size: 17px; width: 40px; border: 0; background: #fff; cursor: pointer; }
  .qtybox input { width: 46px; text-align: center; font: inherit; font-size: 15px; font-weight: 700; border: 0; -moz-appearance: textfield; }
  .qtybox input::-webkit-outer-spin-button, .qtybox input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
  .cta { display: block; width: 100%; text-align: center; font: inherit; font-size: 15px; font-weight: 700; padding: 14px 0; border: 0; border-radius: 11px; background: var(--acc); cursor: not-allowed; opacity: .55; }
  .cta-note { font-size: 12px; opacity: .5; text-align: center; margin-top: 8px; }
  .desc { font-size: 14px; opacity: .8; margin-top: 22px; line-height: 1.65; }
  .specs { margin-top: 26px; background: #fff; border: 1.5px solid rgba(0,0,0,.09); border-radius: 14px; padding: 6px 20px; }
  .specs h2 { font-size: 12px; text-transform: uppercase; letter-spacing: .08em; opacity: .45; font-weight: 700; padding: 14px 0 4px; }
  .specs .row { display: flex; gap: 18px; padding: 9px 0; border-top: 1px solid rgba(0,0,0,.05); font-size: 13.5px; }
  .specs .row:first-of-type { border-top: 0; }
  .specs .k { flex: none; width: 42%; opacity: .55; }
  .idline { font-size: 12px; opacity: .45; margin-top: 18px; font-family: ui-monospace, monospace; }
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <a class="home" href="/">{{ $tname }}</a>
    <div style="display:flex;gap:16px;align-items:center">
      <a href="/shop" style="font-size:13.5px;opacity:.6">← Back to shop</a>
      <a href="/cart" style="font-size:13.5px;font-weight:700">Cart{{ ($cartCount ?? 0) > 0 ? ' (' . $cartCount . ')' : '' }}</a>
    </div>
  </div>

  <div class="crumb">Shop{{ $item->category ? ' · ' . $item->category->name : '' }}</div>

  <div class="cols">
    <div class="gal">
      <div class="main">
        @if(!empty($images))<img id="sp-main" src="{{ $images[0] }}" alt="{{ $item->name }}">@else<span>No image yet</span>@endif
      </div>
      @if(count($images) > 1)
        <div class="thumbs">
          @foreach($images as $i => $u)
            <img src="{{ $u }}" class="{{ $i === 0 ? 'on' : '' }}" onclick="document.getElementById('sp-main').src=this.src;document.querySelectorAll('.thumbs img').forEach(t=>t.classList.remove('on'));this.classList.add('on')">
          @endforeach
        </div>
      @endif
    </div>

    <div>
      @if($brand)<div class="brand">{{ $brand }}</div>@endif
      <h1>{{ $item->name }}</h1>
      @if($item->display_subtitle)<div class="subt">{{ $item->display_subtitle }}</div>@endif

      <div class="price">{{ $money($item->effectiveSellPriceCents()) }}</div>
      <div class="avail {{ $inStock ? 'in' : 'order' }}">
        <span class="dot"></span>
        {{ $inStock ? 'In stock — ready for pickup' : 'Special order — usually a few days' }}
      </div>

      {{-- MARKER-PATCH-565 — qty + add-to-cart --}}
      <form method="POST" action="/cart/items" style="display:flex;gap:10px;align-items:stretch">
        @csrf
        <input type="hidden" name="item_id" value="{{ $item->id }}">
        <div class="qtybox">
          <button type="button" onclick="spQty(-1)">−</button>
          <input id="sp-qty" name="quantity" type="number" value="1" min="1" max="99" inputmode="numeric">
          <button type="button" onclick="spQty(1)">+</button>
        </div>
        <button class="cta" style="cursor:pointer;opacity:1;flex:1">Add to cart</button>
      </form>
      <script>
        function spQty(d) {
          var el = document.getElementById('sp-qty');
          el.value = Math.max(1, Math.min(99, (parseInt(el.value, 10) || 1) + d));
        }
      </script>
      <div class="cta-note">Pickup in store — checkout online is on its way.</div>

      @if($item->description)<div class="desc">{{ $item->description }}</div>@endif

      @if(!empty($attrs))
        <div class="specs">
          <h2>Specs</h2>
          @foreach($attrs as $a)
            <div class="row"><span class="k">{{ $a['name'] }}</span><span>{{ $a['value'] }}</span></div>
          @endforeach
        </div>
      @endif

      <div class="idline">{{ $item->sku }}@if($item->catalog_upc) · UPC {{ $item->catalog_upc }}@endif</div>
    </div>
  </div>
</div>
</body>
</html>
