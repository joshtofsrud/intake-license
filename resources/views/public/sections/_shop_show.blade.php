{{-- chrome-wrapped shop body (show); rendered
     through public.layout via SiteChromeService between the tenant own
     nav + footer sections. Original standalone blade retired. --}}
@include('public._launch-ribbon', ['lrModule' => 'ecom'])
@include('public.sections._shop-theme')
@php
  // layout's mobile-nav loop clobbers $item in scope;
  // rebind from the alias before anything reads it.
  $item = $shopItem ?? $item;
  $accent = $tenant->accent_color ?? '#BEF264';
  $tname  = $tenant->name ?? 'Shop';
  $money = fn ($c) => $c !== null ? '$' . number_format($c / 100, 2) : '';
  // MARKER-ORDER-SHIPMENTS: a supplier warehouse that can ship it counts as in stock
  $supBacked = \App\Services\Tenant\OrderStock::forTenant($tenant)->supplierBacked([$item->id]);
  $inStock = ($item->computed_stock_count ?? 0) > 0 || isset($supBacked[$item->id]);
  // MARKER-ECOM-SETTINGS: in stock / special order (oversell on) / out of stock (can't be ordered)
  $words   = \App\Services\Tenant\OrderService::wording($tenant);
  $canBuy  = $inStock || (bool) $item->allow_oversell;
@endphp
<style>

  /* MARKER-SHOP-THEME: --acc now comes from the site look (_shop-theme) */

  .spg-show .wrap { max-width: 1020px; margin: 0 auto; padding: 28px 20px 80px; }
  .spg-show .top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
  .spg-show .top a.home { font-weight: 700; font-size: 16px; }
  .spg-show .crumb { font-size: 13px; opacity: .55; margin-bottom: 18px; }
  .spg-show .cols { display: grid; grid-template-columns: 1fr 1fr; gap: 34px; align-items: start; }
  @media (max-width: 760px) { .cols { grid-template-columns: 1fr; } }
  .spg-show .gal { background: var(--s-photo); color: var(--s-photo-ink); border: 1.5px solid color-mix(in srgb, var(--p-text) 9%, transparent); border-radius: var(--s-r); overflow: hidden; }
  .spg-show .gal .main { aspect-ratio: 1; display: grid; place-items: center; }
  .spg-show .gal .main img { width: 100%; height: 100%; object-fit: contain; padding: 24px; }
  .spg-show .gal .main span { font-size: 13px; }
  .spg-show .thumbs { display: flex; gap: 8px; padding: 12px; border-top: 1px solid color-mix(in srgb, var(--p-text) 6%, transparent); }
  .spg-show .thumbs img { width: 54px; height: 54px; object-fit: contain; background: var(--s-photo); border: 1.5px solid color-mix(in srgb, var(--p-text) 10%, transparent); border-radius: 9px; cursor: pointer; padding: 5px; }
  .spg-show .thumbs img.on { border-color: var(--s-ink); }
  .spg-show .brand { font-size: 11px; text-transform: uppercase; letter-spacing: .09em; opacity: .5; font-weight: 700; }
  .spg-show h1 { font-size: 23px; font-weight: 700; letter-spacing: -.015em; line-height: 1.3; margin: 4px 0 6px; }
  .spg-show .subt { font-size: 14px; opacity: .6; }
  .spg-show .price { font-size: 26px; font-weight: 800; margin: 18px 0 4px; }
  .spg-show .avail { display: inline-flex; align-items: center; gap: 7px; font-size: 13px; font-weight: 650; margin: 8px 0 20px; }
  .spg-show .avail .dot { width: 8px; height: 8px; border-radius: 50%; }
  .spg-show .avail.in .dot { background: var(--s-ok); } .avail.in { color: var(--s-ok); }
  .spg-show .avail.order .dot { background: var(--p-muted); } .avail.order { color: var(--p-muted); }
  .spg-show .qtybox { display: flex; align-items: stretch; border: 1.5px solid color-mix(in srgb, var(--p-text) 14%, transparent); border-radius: 11px; overflow: hidden; background: var(--s-card); flex: none; }
  .spg-show .qtybox button { font: inherit; font-size: 17px; width: 40px; border: 0; background: var(--s-card); color: var(--p-text); cursor: pointer; }
  .spg-show .qtybox input { width: 46px; text-align: center; font: inherit; font-size: 15px; font-weight: 700; border: 0; background: var(--s-field); color: var(--p-text); -moz-appearance: textfield; }
  .spg-show .qtybox input::-webkit-outer-spin-button, .qtybox input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
  .spg-show .cta { display: block; width: 100%; text-align: center; font: inherit; font-size: 15px; font-weight: 700; padding: 14px 0; border: 0; border-radius: var(--s-btn-r); background: var(--acc); cursor: not-allowed; opacity: .55; color: var(--s-acc-text);}
  .spg-show .cta-note { font-size: 12px; opacity: .5; text-align: center; margin-top: 8px; }
  .spg-show .desc { font-size: 14px; opacity: .8; margin-top: 22px; line-height: 1.65; }
  .spg-show .specs { margin-top: 26px; background: var(--s-card); border: 1.5px solid color-mix(in srgb, var(--p-text) 9%, transparent); border-radius: var(--s-r); padding: 6px 20px; }
  .spg-show .specs h2 { font-size: 12px; text-transform: uppercase; letter-spacing: .08em; opacity: .45; font-weight: 700; padding: 14px 0 4px; }
  .spg-show .specs .row { display: flex; gap: 18px; padding: 9px 0; border-top: 1px solid color-mix(in srgb, var(--p-text) 5%, transparent); font-size: 13.5px; }
  .spg-show .specs .row:first-of-type { border-top: 0; }
  .spg-show .specs .k { flex: none; width: 42%; opacity: .55; }

  /* MARKER-PRODUCT-DETAILS */
  .spg-show .szrow { margin: 18px 0 6px; }
  .spg-show .szlab { font-size: 13.5px; margin-bottom: 8px; }
  .spg-show .szs { display: grid; grid-template-columns: repeat(auto-fit, minmax(64px, 1fr)); gap: 6px; }
  .spg-show .sz { display: flex; flex-direction: column; align-items: center; gap: 2px; padding: 9px 2px; border: 1.5px solid color-mix(in srgb, var(--p-text) 14%, transparent); border-radius: 10px; text-decoration: none; color: inherit; background: var(--s-card); }
  .spg-show .sz b { font-size: 15px; }
  .spg-show .sz span { font-size: 10.5px; opacity: .6; white-space: nowrap; }
  .spg-show .sz span.in { color: var(--s-ok); opacity: 1; }
  .spg-show .sz.on { border-color: var(--s-ink, var(--p-text)); box-shadow: inset 0 0 0 1px var(--s-ink, var(--p-text)); }
  .spg-show .pdx { margin-top: 48px; }
  .spg-show .pdx-h { font-size: 20px; font-weight: 800; margin: 0 0 18px; }
  .spg-show .pdx-bar { display: flex; gap: 4px; border-bottom: 1.5px solid color-mix(in srgb, var(--p-text) 9%, transparent); margin-bottom: 24px; overflow-x: auto; scrollbar-width: none; }
  .spg-show .pdx-tb { font: inherit; font-size: 15px; font-weight: 650; padding: 12px 18px; border: 0; background: none; color: inherit; opacity: .55; cursor: pointer; border-bottom: 2.5px solid transparent; margin-bottom: -1.5px; white-space: nowrap; }
  .spg-show .pdx-tb.on { opacity: 1; border-bottom-color: var(--s-ink, var(--p-text)); }
  .spg-show .pdx-cols { columns: 2 300px; column-gap: 48px; }
  .spg-show .pdx-g { break-inside: avoid; margin: 0 0 18px; }
  .spg-show .pdx-g h3 { font-size: 11.5px; text-transform: uppercase; letter-spacing: .08em; opacity: .45; font-weight: 700; margin: 0 0 2px; }
  .spg-show .pdx-g .row { display: flex; gap: 18px; padding: 8px 0; border-bottom: 1px solid color-mix(in srgb, var(--p-text) 6%, transparent); font-size: 13.5px; }
  .spg-show .pdx-g .k { flex: none; width: 38%; opacity: .55; }
  .spg-show .pdx-note { font-size: 12.5px; opacity: .55; margin-top: 10px; }
  .spg-show .pdx-geo { width: 100%; max-width: 760px; border-collapse: collapse; font-size: 13px; font-variant-numeric: tabular-nums; }
  .spg-show .pdx-geo th, .spg-show .pdx-geo td { padding: 7px 8px; text-align: right; border-bottom: 1px solid color-mix(in srgb, var(--p-text) 6%, transparent); }
  .spg-show .pdx-geo td:first-child, .spg-show .pdx-geo th:first-child { text-align: left; padding-left: 0; opacity: .75; }
  .spg-show .pdx-geo .u { font-size: 11px; opacity: .6; }
  .spg-show .pdx-lead { font-size: 15px; line-height: 1.6; max-width: 680px; margin: 0 0 16px; opacity: .85; }
  .spg-show .pdx-fit tr.on td { background: color-mix(in srgb, var(--acc) 14%, transparent); font-weight: 650; }
  .spg-show .pdx-fit th, .spg-show .pdx-fit td { text-align: center; }
  .spg-show .pdx-story { max-width: 860px; }
  .spg-show .pdx-story p { font-size: 15px; line-height: 1.65; opacity: .8; margin: 0 0 14px; }
  .spg-show .pdx-story h4 { font-size: 16px; font-weight: 800; margin: 22px 0 8px; }
  .spg-show .pdx-story .imgs { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; margin: 0 0 18px; }
  .spg-show .pdx-story figure { margin: 0; }
  .spg-show .pdx-story img { width: 100%; aspect-ratio: 3/2; object-fit: cover; border-radius: var(--s-r); background: var(--s-photo); display: block; }
  .spg-show .pdx-story figcaption { font-size: 12.5px; opacity: .6; margin-top: 7px; }
  .spg-show .pdx-geo .on { background: color-mix(in srgb, var(--acc) 14%, transparent); font-weight: 700; opacity: 1; }
  .spg-show .idline { font-size: 12px; opacity: .45; margin-top: 18px; font-family: ui-monospace, monospace; }

</style>
{{-- no <section> or <header> inside this page: a floating nav pads the first one it finds, and it must be this wrapper --}}
<div class="spg-show">
  <div class="wrap">
    <div style="display:flex;justify-content:space-between;padding:14px 0 0">
      <a href="/shop" style="font-size:13.5px;opacity:.6;text-decoration:none;color:inherit">&larr; Back to shop</a>
      <a href="/cart" style="font-size:13.5px;font-weight:700;text-decoration:none;color:inherit">Cart{{ ($cartCount ?? 0) > 0 ? ' (' . $cartCount . ')' : '' }}</a>
    </div>

    <div class="crumb" style="margin-top:14px">Shop{{ $item->category ? ' · ' . $item->category->name : '' }}</div>

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

      @if(!empty($pd['fit']['range']) && empty($pdAxes))<div class="subt">Size {{ $pd['size'] }} · fits riders about {{ $pd['fit']['range'] }}</div>@endif
      {{-- MARKER-PRODUCT-DETAILS: the other sizes of this model that are for sale here --}}
      @if(!empty($pdAxes))
        @foreach($pdAxes as $ax)
        <div class="szrow">
          <div class="szlab"><span>{{ $ax['label'] }}: <b>{{ $ax['value'] }}</b></span>@if($ax['key'] === 'size' && !empty($pd['fit']['range']))<span style="opacity:.6;margin-left:10px">fits riders about {{ $pd['fit']['range'] }}</span>@endif</div>
          <div class="szs">
            @foreach($ax['values'] as $z)
              <a @if($z['href']) href="{{ $z['href'] }}" @endif class="sz{{ $z['current'] ? ' on' : '' }}"><b>{{ $z['value'] }}</b><span class="{{ ($z['stock'] ?? 0) > 0 ? 'in' : '' }}">{{ $z['current'] ? (($z['stock'] ?? 0) > 0 ? 'In stock' : 'Special order') : ($z['exact'] ? (($z['stock'] ?? 0) > 0 ? 'In stock' : 'Special order') : 'Other options') }}</span></a>
            @endforeach
          </div>
        </div>
        @endforeach
      @endif

      {{-- MARKER-SHOP-SEARCH-V2: a was price above the price shows crossed out --}}
      @php $wasC = ($item->compare_at_cents && $item->effectiveSellPriceCents() !== null && $item->compare_at_cents > $item->effectiveSellPriceCents()) ? $item->compare_at_cents : null; @endphp
      <div class="price">{{ $money($item->effectiveSellPriceCents()) }}@if($wasC) <s style="font-size:17px;font-weight:500;color:var(--p-muted);margin-left:6px">{{ $money($wasC) }}</s> <span style="font-size:12px;font-weight:700;background:#e5484d;color:#fff;border-radius:99px;padding:3px 9px;vertical-align:middle;margin-left:4px">Sale</span>@endif</div>
      <div class="avail {{ $inStock ? 'in' : 'order' }}">
        <span class="dot"></span>
        {{ $inStock ? $words['stock_label'] : ($canBuy ? $words['special_label'] : $words['out_label']) }}
      </div>
      @if(!$inStock && $canBuy)
        <div class="cta-note" style="text-align:left;margin:-4px 0 12px">{{ $words['special_notice'] }}</div>
      @endif
      @if(session('shop_error'))
        <div role="alert" style="background:rgba(192,57,43,.07);border:1.5px solid rgba(192,57,43,.28);color:var(--s-bad);border-radius:12px;padding:10px 14px;font-size:13.5px;margin-bottom:12px">{{ session('shop_error') }}</div>
      @endif

      {{-- qty + add-to-cart --}}
      <form method="POST" action="/cart/items" style="display:flex;gap:10px;align-items:stretch">
        @csrf
        <input type="hidden" name="item_id" value="{{ $item->id }}">
        <div class="qtybox">
          <button type="button" onclick="spQty(-1)">−</button>
          <input id="sp-qty" name="quantity" type="number" value="1" min="1" max="99" inputmode="numeric">
          <button type="button" onclick="spQty(1)">+</button>
        </div>
        @if($canBuy)
          <button class="cta" style="cursor:pointer;opacity:1;flex:1">Add to cart</button>
        @else
          <button class="cta" type="button" disabled style="cursor:not-allowed;opacity:.5;flex:1">{{ $words['out_label'] }}</button>
        @endif
      </form>
      <script>
        function spQty(d) {
          var el = document.getElementById('sp-qty');
          el.value = Math.max(1, Math.min(99, (parseInt(el.value, 10) || 1) + d));
        }
      </script>

      {{-- MARKER-ITEM-SEO: share this item. Links are tagged utm_source=share so GA4 can show what sharing brings in. --}}
      @if($shareOn ?? true)
      @php
        $shUrl = rtrim($tenant->publicUrl(), '/') . $item->shopPath();
        $shTxt = $item->name . ' — ' . $tname;
        $ch = fn ($c) => \App\Support\ItemSeo::shareUrl($shUrl, $c);
      @endphp
      <div class="sp-share" style="margin-top:14px;position:relative;font-size:13px">
        <button type="button" id="sp-share-btn" style="cursor:pointer;background:none;border:1.5px solid color-mix(in srgb, var(--p-text) 14%, transparent);border-radius:99px;padding:6px 14px;font:inherit;color:inherit">Share</button>
        <span id="sp-share-note" role="status" style="margin-left:8px;opacity:.7"></span>
        <div id="sp-share-menu" hidden style="display:none;margin-top:8px;gap:6px;flex-wrap:wrap">
          <a class="sp-sh" href="sms:?&body={{ rawurlencode($shTxt . ' ' . $ch('text')) }}">Text</a>
          <a class="sp-sh" href="mailto:?subject={{ rawurlencode($shTxt) }}&body={{ rawurlencode($ch('email')) }}">Email</a>
          <a class="sp-sh" target="_blank" rel="noopener" href="https://www.facebook.com/sharer/sharer.php?u={{ rawurlencode($ch('facebook')) }}">Facebook</a>
          <a class="sp-sh" target="_blank" rel="noopener" href="https://twitter.com/intent/tweet?url={{ rawurlencode($ch('x')) }}&text={{ rawurlencode($shTxt) }}">X</a>
          <a class="sp-sh" target="_blank" rel="noopener" href="https://pinterest.com/pin/create/button/?url={{ rawurlencode($ch('pinterest')) }}&description={{ rawurlencode($shTxt) }}@if(!empty($images))&media={{ rawurlencode($images[0]) }}@endif">Pinterest</a>
          <button type="button" class="sp-sh" id="sp-copy">Copy link</button>
        </div>
      </div>
      <style>.sp-sh{display:inline-block;border:1.5px solid color-mix(in srgb, var(--p-text) 14%, transparent);border-radius:99px;padding:5px 12px;font:inherit;font-size:12.5px;color:inherit;text-decoration:none;background:none;cursor:pointer}.sp-sh:hover{border-color:color-mix(in srgb, var(--p-text) 40%, transparent)}</style>
      <script>
        (function () {
          var url = @json($ch('share')), title = @json($shTxt), copyUrl = @json($ch('copy'));
          var btn = document.getElementById('sp-share-btn'), menu = document.getElementById('sp-share-menu'), note = document.getElementById('sp-share-note');
          function say(m) { note.textContent = m; setTimeout(function () { note.textContent = ''; }, 2500); }
          btn.addEventListener('click', function () {
            if (navigator.share) { navigator.share({ title: title, url: url }).catch(function () {}); return; }
            menu.style.display = menu.style.display === 'flex' ? 'none' : 'flex';
          });
          document.getElementById('sp-copy').addEventListener('click', function () {
            function done() { say('Link copied'); }
            if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(copyUrl).then(done, function () { say(copyUrl); }); }
            else { say(copyUrl); }
          });
        })();
      </script>
      @endif

      @if($item->description)<div class="desc">{{ $item->description }}</div>@endif

      <div class="idline">{{ $item->sku }}@if($item->catalog_upc) · UPC {{ $item->catalog_upc }}@endif</div>
    </div>
  </div>

  {{-- MARKER-PRODUCT-DETAILS: specs, geometry and sizes in tabs; a tab only when this item has the data --}}
  @php
    $pdSpecs = ! empty($pd['specs']);
    $pdGeo = ! empty($pd['geometry']['rows']);
    $pdFit = ! empty($pd['fit']);
    $pdStory = ! empty($pd['story']);
    $pdTabs = array_filter(['specs' => $pdSpecs ? 'Specs' : null, 'geometry' => $pdGeo ? 'Geometry' : null, 'fit' => $pdFit ? 'Sizing' : null, 'story' => $pdStory ? ($pd['story']['title'] ?: 'Story') : null]);
    $pdFirst = array_key_first($pdTabs);
  @endphp
  @if($pdTabs)
  <div class="pdx" id="pdx">
    @if(count($pdTabs) > 1)
    <div class="pdx-bar" role="tablist">
      @foreach($pdTabs as $k => $label)<button type="button" class="pdx-tb{{ $k === $pdFirst ? ' on' : '' }}" data-t="{{ $k }}">{{ $label }}</button>@endforeach
    </div>
    @else
    <h2 class="pdx-h">{{ reset($pdTabs) }}</h2>
    @endif
    @if($pdSpecs)
    <div class="pdx-p" data-p="specs" @if($pdFirst !== 'specs') hidden @endif>
      <div class="pdx-cols">
        @foreach($pd['specs'] as $g)
          <div class="pdx-g">
            @if($g['group'])<h3>{{ $g['group'] }}</h3>@endif
            @foreach($g['rows'] as $r)<div class="row"><span class="k">{{ $r['label'] }}</span><span>{{ $r['value'] }}</span></div>@endforeach
          </div>
        @endforeach
      </div>
      @if(collect($pd['specs'])->pluck('rows')->flatten(1)->contains(fn ($r) => $r['all']))
        <p class="pdx-note">Parts that change by frame size are shown for this size ({{ $pd['size'] }}).</p>
      @endif
    </div>
    @endif
    @if($pdGeo)
    <div class="pdx-p" data-p="geometry" @if($pdFirst !== 'geometry') hidden @endif>
      <table class="pdx-geo">
        <tr><th></th>@foreach($pd['geometry']['sizes'] as $z)<th class="{{ (string) $z === (string) $pd['sizeKey'] ? 'on' : '' }}">{{ $z }}</th>@endforeach</tr>
        @foreach($pd['geometry']['rows'] as $i => $r)
          <tr><td>{{ $r['label'] }} <span class="u">{{ $r['unit'] }}</span></td>@foreach($pd['geometry']['sizes'] as $z)<td class="{{ (string) $z === (string) $pd['sizeKey'] ? 'on' : '' }}">{{ $r['values'][$z] ?? '' }}</td>@endforeach</tr>
        @endforeach
      </table>
      <p class="pdx-note">Your size, {{ $pd['size'] }}, is highlighted.</p>
    </div>
    @endif
    @if($pdFit)
    <div class="pdx-p" data-p="fit" @if($pdFirst !== 'fit') hidden @endif>
      @if($pd['fit']['range'])<p class="pdx-lead">The {{ $pd['size'] }} fits riders about <b>{{ $pd['fit']['range'] }}</b>. Not sure, or between sizes? Ask us and we'll help you choose.</p>@endif
      <table class="pdx-geo pdx-fit">
        <tr><th style="text-align:left">Height</th><th>Metric</th><th>Inseam</th><th>Inseam metric</th><th>Size</th></tr>
        @foreach($pd['fit']['rows'] as $r)
          <tr class="{{ $r['fits'] ? 'on' : '' }}"><td style="text-align:left">{{ $r['height_in'] }}</td><td>{{ $r['height_cm'] }}</td><td>{{ $r['inseam_in'] }}</td><td>{{ $r['inseam_cm'] }}</td><td>{{ $r['size'] }}</td></tr>
        @endforeach
      </table>
      <p class="pdx-note">Highlighted rows are where the {{ $pd['size'] }} fits.</p>
    </div>
    @endif
    @if($pdStory)
    <div class="pdx-p" data-p="story" @if($pdFirst !== 'story') hidden @endif>
      <div class="pdx-story">
      @foreach($pd['story']['blocks'] as $b)
        @if($b['t'] === 'p')<p>{{ $b['text'] }}</p>
        @elseif($b['t'] === 'h')<h4>{{ $b['text'] }}</h4>
        @else
          <div class="imgs">@foreach($b['items'] as $im)<figure><img src="{{ $im['src'] }}" alt="" loading="lazy">@if($im['caption'] !== '')<figcaption>{{ $im['caption'] }}</figcaption>@endif</figure>@endforeach</div>
        @endif
      @endforeach
      </div>
    </div>
    @endif
  </div>
  <script>
    (function () {
      var r = document.getElementById('pdx'); if (!r) return;
      r.addEventListener('click', function (e) {
        var b = e.target.closest('.pdx-tb'); if (!b) return;
        r.querySelectorAll('.pdx-tb').forEach(function (x) { x.classList.toggle('on', x === b); });
        r.querySelectorAll('.pdx-p').forEach(function (p) { p.hidden = p.getAttribute('data-p') !== b.getAttribute('data-t'); });
      });
    })();
  </script>
  @endif

  </div>
</div>

{{-- MARKER-SHOP-GA4 --}}
@if(\App\Support\ShopGa4::on($tenant))
@php $gaItem = \App\Support\ShopGa4::forItem($item); @endphp
<script>
(function () {
  var it = @json($gaItem);
  if (!window.shopEv) return;
  shopEv('view_item', { value: it.price, items: [it] });
  var f = document.querySelector('form[action="/cart/items"]');
  if (f) f.addEventListener('submit', function () {
    var q = parseInt((document.getElementById('sp-qty') || {}).value, 10) || 1;
    var line = Object.assign({}, it, { quantity: q });
    shopEv('add_to_cart', { value: Math.round(it.price * q * 100) / 100, items: [line], transport_type: 'beacon' });
  });
})();
</script>
@endif
