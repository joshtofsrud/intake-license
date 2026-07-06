{{-- MARKER-PATCH-579 -- chrome-wrapped shop body (index); rendered
     through public.layout via SiteChromeService between the tenant own
     nav + footer sections. Original standalone blade retired. --}}
@php
  $accent = $tenant->accent_color ?? '#BEF264';
  $tname  = $tenant->name ?? 'Shop';
  $img = function ($item) {
      $ims = (array) ($item->distributorCatalog->images ?? []);
      $first = $ims[0] ?? null;
      if (is_array($first)) return $first['Url'] ?? $first['url'] ?? $first['src'] ?? null;
      return is_string($first) ? $first : null;
  };
  $money = fn ($c) => $c !== null ? '$' . number_format($c / 100, 2) : '';
@endphp
<style>

  :root { --acc: {{ $accent }}; }

  .spg-index .wrap { max-width: 1080px; margin: 0 auto; padding: 28px 20px 80px; }
  .spg-index .top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 26px; }
  .spg-index .top a.home { font-weight: 700; font-size: 16px; }
  .spg-index h1 { font-size: 26px; font-weight: 650; letter-spacing: -.02em; }
  .spg-index .sub { font-size: 14px; opacity: .55; margin-top: 4px; }
  .spg-index .bar { display: flex; gap: 10px; margin: 20px 0 14px; flex-wrap: wrap; }
  .spg-index .bar input { flex: 1; min-width: 220px; font: inherit; font-size: 14px; padding: 10px 14px; border: 1.5px solid rgba(0,0,0,.12); border-radius: 10px; background: #fff; }
  .spg-index .bar button { font: inherit; font-size: 14px; font-weight: 650; padding: 10px 20px; border: 0; border-radius: 10px; background: var(--acc); cursor: pointer; }
  .spg-index .cats { display: flex; gap: 7px; flex-wrap: wrap; margin-bottom: 22px; }
  .spg-index .cats a { font-size: 12.5px; font-weight: 600; padding: 5px 13px; border-radius: 99px; border: 1.5px solid rgba(0,0,0,.1); background: #fff; }
  .spg-index .cats a.on { background: #161616; color: #fff; border-color: #161616; }
  .spg-index .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(215px, 1fr)); gap: 14px; }
  .spg-index .card { background: #fff; border: 1.5px solid rgba(0,0,0,.09); border-radius: 14px; overflow: hidden; display: flex; flex-direction: column; transition: border-color .12s; }
  .spg-index .card:hover { border-color: rgba(0,0,0,.28); }
  .spg-index .card .ph { aspect-ratio: 1; background: #fff; display: grid; place-items: center; border-bottom: 1px solid rgba(0,0,0,.05); }
  .spg-index .card .ph img { width: 100%; height: 100%; object-fit: contain; padding: 14px; }
  .spg-index .card .ph span { font-size: 11px; opacity: .3; }
  .spg-index .card .b { padding: 13px 15px 15px; display: flex; flex-direction: column; flex: 1; }
  .spg-index .card .brand { font-size: 10px; text-transform: uppercase; letter-spacing: .08em; opacity: .45; font-weight: 700; }
  .spg-index .card .name { font-size: 14px; font-weight: 650; line-height: 1.32; margin-top: 2px; }
  .spg-index .card .foot { display: flex; justify-content: space-between; align-items: center; margin-top: auto; padding-top: 12px; }
  .spg-index .card .price { font-size: 15px; font-weight: 750; }
  .spg-index .stock { font-size: 10.5px; font-weight: 650; border-radius: 99px; padding: 2px 9px; }
  .spg-index .stock.in { background: rgba(52,168,83,.1); color: #1d7a3a; }
  .spg-index .stock.order { background: rgba(0,0,0,.06); color: #555; }
  .spg-index .empty { text-align: center; padding: 70px 20px; opacity: .55; font-size: 15px; }
  .spg-index .pager { display: flex; justify-content: center; gap: 8px; margin-top: 28px; font-size: 13.5px; }
  .spg-index .pager a, .pager span { padding: 7px 13px; border-radius: 9px; border: 1.5px solid rgba(0,0,0,.1); background: #fff; }
  .spg-index .pager .cur { background: #161616; color: #fff; border-color: #161616; }

</style>
<div class="spg-index">
  <div class="wrap">
    <div style="display:flex;justify-content:flex-end;padding:14px 0 0">
      <a href="/cart" style="font-size:13.5px;font-weight:700;text-decoration:none;color:inherit">Cart{{ ($cartCount ?? 0) > 0 ? ' (' . $cartCount . ')' : '' }}</a>
    </div>

  <h1>Shop</h1>
  <div class="sub">Parts, accessories, and gear — pickup and local delivery available.</div>

  <form class="bar" method="GET" action="/shop">
    @if($activeCat)<input type="hidden" name="category" value="{{ $activeCat }}">@endif
    <input type="search" name="q" value="{{ $q }}" placeholder="Search the shop — brand, part, size…">
    <button>Search</button>
  </form>

  @if($categories->isNotEmpty())
    <div class="cats">
      <a href="/shop{{ $q ? '?q=' . urlencode($q) : '' }}" class="{{ !$activeCat ? 'on' : '' }}">All</a>
      @foreach($categories as $c)
        <a href="/shop?category={{ $c->id }}{{ $q ? '&q=' . urlencode($q) : '' }}" class="{{ $activeCat === $c->id ? 'on' : '' }}">{{ $c->name }}</a>
      @endforeach
    </div>
  @endif

  @if($items->isEmpty())
    <div class="empty">Nothing here yet{{ $q ? ' for "' . $q . '"' : '' }}.</div>
  @else
    <div class="grid">
      @foreach($items as $item)
        <a class="card" href="/shop/{{ $item->id }}">
          <div class="ph">
            @if($u = $img($item))<img src="{{ $u }}" alt="" loading="lazy">@else<span>{{ $tname }}</span>@endif
          </div>
          <div class="b">
            @if($item->distributorCatalog?->manufacturer)<div class="brand">{{ $item->distributorCatalog->manufacturer }}</div>@endif
            <div class="name">{{ $item->name }}</div>
            <div class="foot">
              <div class="price">{{ $money($item->effectiveSellPriceCents()) }}</div>
              @if(($item->computed_stock_count ?? 0) > 0)
                <span class="stock in">In stock</span>
              @else
                <span class="stock order">Special order</span>
              @endif
            </div>
          </div>
        </a>
      @endforeach
    </div>
    <div class="pager">{{ $items->links('pagination::simple-default') }}</div>
  @endif

  </div>
</div>
