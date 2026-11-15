{{-- chrome-wrapped shop body (index); rendered through public.layout via
     SiteChromeService between the tenant's own nav and footer sections.
     MARKER-SHOP-SEARCH-V2: browse and search results in one page, with filters,
     photos, badges, quick view, Add to cart, promotions and a real no-results page. --}}
@include('public._launch-ribbon', ['lrModule' => 'ecom'])
@include('public.sections._shop-theme')
@php
  $tname   = $tenant->name ?? 'Shop';
  $money   = fn ($c) => \App\Support\ShopSearch\ShopSearch::money($c);
  $hl      = $browse['run']['highlight'] ?? [];
  $mark    = function (string $s) use ($hl) {
      $e = e($s);
      $terms = array_values(array_filter($hl, fn ($t) => mb_strlen($t) >= 2));
      if (! $terms) return $e;
      usort($terms, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
      $re = '/(' . implode('|', array_map(fn ($t) => preg_quote(e($t), '/'), $terms)) . ')/iu';
      return preg_replace($re, '<mark>$1</mark>', $e) ?? $e;
  };
  $isSearch = $f['q'] !== '';
  $noMatch  = $isSearch && (int) $browse['baseCount'] === 0;
  $facets   = $browse['facets'];
  $chips    = $browse['chips'];
  $total    = $items->total();
  $title    = $isSearch ? 'Results for "' . ($browse['run']['corrected'] ?? $f['q']) . '"' : ($catName ?: 'Shop');
  $keep     = fn (array $extra = [], array $drop = []) => '/shop?' . http_build_query(array_merge(\Illuminate\Support\Arr::except(request()->query(), array_merge(['page'], $drop)), $extra));
  $wording  = \App\Services\Tenant\OrderService::wording($tenant);
  $outLabel = $wording['out_label'];
@endphp
<style>
  .spg-index .wrap { max-width: 1180px; margin: 0 auto; padding: 22px 20px 80px; }
  .spg-index .crumbs { display: flex; justify-content: space-between; align-items: center; gap: 12px; font-size: 13px; color: var(--p-muted); margin-bottom: 16px; }
  .spg-index .crumbs a { color: inherit; text-decoration: none; }
  .spg-index .crumbs .cart { font-weight: 700; color: var(--p-text); display: inline-flex; gap: 6px; align-items: center; }
  .spg-index .crumbs .cart b { background: var(--acc); color: var(--s-acc-text); font-size: 11px; border-radius: 99px; padding: 1px 7px; }

  .spg-index .banner { position: relative; display: block; border-radius: var(--s-r); overflow: hidden; margin-bottom: 22px; min-height: 180px; background: color-mix(in srgb, var(--p-text) 8%, var(--p-bg)); color: #fff; text-decoration: none; }
  .spg-index .banner img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
  .spg-index .banner::after { content: ''; position: absolute; inset: 0; background: linear-gradient(90deg, rgba(0,0,0,.55), rgba(0,0,0,.05) 70%); }
  .spg-index .banner .tx { position: relative; z-index: 1; padding: 34px 30px; max-width: 560px; }
  .spg-index .banner b { display: block; font-family: var(--p-font-heading); font-size: clamp(22px, 3.4vw, 32px); line-height: 1.15; letter-spacing: -.01em; }
  .spg-index .banner span { display: block; margin-top: 6px; font-size: 15px; opacity: .9; }
  .spg-index .banner em { display: inline-block; margin-top: 16px; font-style: normal; font-weight: 650; font-size: 14px; background: #fff; color: #111; border-radius: 99px; padding: 9px 18px; }

  .spg-index .sbar { display: flex; gap: 8px; margin-bottom: 18px; }
  .spg-index .sbar input { flex: 1; min-width: 0; font: inherit; font-size: 15px; padding: 11px 16px; border: 1.5px solid color-mix(in srgb, var(--p-text) 14%, transparent); border-radius: 99px; background: var(--s-field); }
  .spg-index .sbar button { font: inherit; font-size: 14px; font-weight: 650; padding: 10px 20px; border: 0; border-radius: var(--s-btn-r); background: var(--acc); color: var(--s-acc-text); cursor: pointer; }

  .spg-index .rh { display: flex; align-items: baseline; gap: 12px; flex-wrap: wrap; }
  .spg-index h1 { font-size: 28px; font-weight: var(--p-heading-weight, 650); letter-spacing: -.02em; }
  .spg-index .rh .c { color: var(--p-muted); font-size: 14px; }
  .spg-index .note { margin: 10px 0 0; font-size: 14px; color: var(--p-muted); }
  .spg-index .note a { color: var(--p-text); text-decoration: underline; }
  .spg-index .intro { font-size: 14px; color: var(--p-muted); margin-top: 4px; }

  .spg-index .tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(132px, 1fr)); gap: 10px; margin: 20px 0 6px; }
  .spg-index .tile { display: flex; flex-direction: column; gap: 7px; text-decoration: none; color: inherit; font-size: 13.5px; font-weight: 600; }
  .spg-index .tile .p { aspect-ratio: 4/3; border-radius: calc(var(--s-r) * .75); overflow: hidden; background: var(--s-photo); color: var(--s-photo-ink); border: 1px solid color-mix(in srgb, var(--p-text) 8%, transparent); display: grid; place-items: center; }
  .spg-index .tile .p img { width: 100%; height: 100%; object-fit: cover; }
  .spg-index .tile .p i { font-style: normal; font-size: 26px; font-weight: 700; color: #bbb; }
  .spg-index .tile:hover .p { border-color: color-mix(in srgb, var(--p-text) 30%, transparent); }

  .spg-index .bar { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; border-top: 1px solid color-mix(in srgb, var(--p-text) 10%, transparent); border-bottom: 1px solid color-mix(in srgb, var(--p-text) 10%, transparent); padding: 10px 0; margin: 16px 0 20px; }
  .spg-index .chip { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; padding: 5px 6px 5px 12px; border-radius: 99px; border: 1px solid color-mix(in srgb, var(--p-text) 16%, transparent); text-decoration: none; color: inherit; }
  .spg-index .chip span { display: inline-grid; place-items: center; width: 18px; height: 18px; border-radius: 99px; background: color-mix(in srgb, var(--p-text) 10%, transparent); font-size: 10px; }
  .spg-index .chip.clear { border: 0; text-decoration: underline; color: var(--p-muted); padding-left: 4px; }
  .spg-index .sp { flex: 1; }
  .spg-index .fbtn { display: none; font: inherit; font-size: 13.5px; font-weight: 600; padding: 8px 14px; border-radius: 99px; border: 1px solid color-mix(in srgb, var(--p-text) 16%, transparent); background: var(--s-card); color: inherit; cursor: pointer; }
  .spg-index .vbtn { display: inline-flex; border: 1px solid color-mix(in srgb, var(--p-text) 14%, transparent); border-radius: 9px; overflow: hidden; }
  .spg-index .vbtn a { padding: 7px 10px; display: grid; place-items: center; color: var(--p-muted); }
  .spg-index .vbtn a.on { background: color-mix(in srgb, var(--p-text) 10%, transparent); color: var(--p-text); }
  .spg-index .sort { font: inherit; font-size: 13.5px; padding: 8px 12px; border: 1px solid color-mix(in srgb, var(--p-text) 14%, transparent); border-radius: 9px; background: var(--s-field); }

  .spg-index .lay { display: grid; grid-template-columns: 230px 1fr; gap: 30px; align-items: start; }
  .spg-index .lay.nofac { grid-template-columns: 1fr; }
  .spg-index .fac { position: sticky; top: 90px; max-height: calc(100vh - 110px); overflow: auto; padding-right: 4px; }
  .spg-index .fac .g { padding: 0 0 16px; margin-bottom: 16px; border-bottom: 1px solid color-mix(in srgb, var(--p-text) 9%, transparent); }
  .spg-index .fac h5 { font-size: 11.5px; text-transform: uppercase; letter-spacing: .09em; color: var(--p-muted); font-weight: 700; margin: 0 0 10px; }
  .spg-index .fac label { display: flex; align-items: center; gap: 9px; font-size: 13.5px; padding: 5px 0; cursor: pointer; }
  .spg-index .fac label[hidden] { display: none; }
  .spg-index .fac label input { accent-color: var(--acc); width: 16px; height: 16px; flex: none; }
  .spg-index .fac label .n { margin-left: auto; color: var(--p-muted); font-size: 12px; font-variant-numeric: tabular-nums; }
  .spg-index .fac label .ci { width: 26px; height: 26px; border-radius: 7px; overflow: hidden; flex: none; background: var(--s-photo); border: 1px solid color-mix(in srgb, var(--p-text) 8%, transparent); }
  .spg-index .fac label .ci img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .spg-index .fac label small { display: block; font-size: 11px; color: var(--p-muted); }
  .spg-index .fac .zero { opacity: .45; }
  .spg-index .fac label.sub { padding-left: calc(var(--d) * 22px); font-size: 13px; }
  .spg-index .fac label.sub input { width: 14px; height: 14px; }
  .spg-index .fac .more { font: inherit; font-size: 12.5px; text-decoration: underline; color: var(--p-muted); background: none; border: 0; padding: 4px 0; cursor: pointer; }
  .spg-index .fac .pr { display: flex; gap: 8px; align-items: center; }
  .spg-index .fac .pr input { width: 100%; min-width: 0; font: inherit; font-size: 13.5px; padding: 8px 10px; border: 1px solid color-mix(in srgb, var(--p-text) 14%, transparent); border-radius: 8px; background: var(--s-field); }
  .spg-index .fac .go { margin-top: 8px; font: inherit; font-size: 13px; font-weight: 600; padding: 7px 14px; border-radius: var(--s-btn-r); border: 1px solid color-mix(in srgb, var(--p-text) 16%, transparent); background: var(--s-card); color: inherit; cursor: pointer; }
  .spg-index .fac .cls { display: none; }

  .spg-index .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(205px, 1fr)); gap: 16px; }
  .spg-index .card { position: relative; display: flex; flex-direction: column; background: var(--s-card); border: 1px solid color-mix(in srgb, var(--p-text) 9%, transparent); border-radius: var(--s-r); overflow: hidden; transition: border-color .12s, box-shadow .12s; }
  .spg-index .card:hover { border-color: color-mix(in srgb, var(--p-text) 24%, transparent); box-shadow: 0 8px 26px color-mix(in srgb, var(--p-text) 8%, transparent); }
  .spg-index .card .im { position: relative; display: block; aspect-ratio: var(--p-tile-ratio, 1); background: var(--s-photo); color: var(--s-photo-ink); }
  @media (min-width: 901px) { .spg-index.p-look .grid:not(.list) { grid-template-columns: repeat(var(--p-shop-cols, 4), minmax(0, 1fr)); } } /* MARKER-WEB-TEMPLATES */
  .spg-index .card .im img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: contain; padding: 14px; transition: opacity .2s; }
  .spg-index .card .im img.b { opacity: 0; }
  .spg-index .card:hover .im img.b { opacity: 1; }
  .spg-index .card:hover .im img.a.has-b { opacity: 0; }
  .spg-index .card .im .ph { position: absolute; inset: 0; display: grid; place-items: center; font-size: 12px; color: var(--s-photo-ink); }
  .spg-index .badges { position: absolute; top: 10px; left: 10px; display: flex; gap: 5px; flex-wrap: wrap; z-index: 2; }
  .spg-index .bd { font-size: 11px; font-weight: 700; padding: 3px 9px; border-radius: 99px; }
  .spg-index .bd.sale { background: #e5484d; color: #fff; }
  .spg-index .bd.new { background: var(--acc); color: var(--s-acc-text); }
  .spg-index .bd.so { background: #111; color: #f5c46a; }
  .spg-index .bd.out { background: #111; color: #ddd; }
  .spg-index .card .actwrap { position: relative; }
  .spg-index .acts { position: absolute; left: 10px; right: 10px; bottom: 10px; display: flex; gap: 6px; opacity: 0; transform: translateY(6px); transition: opacity .15s, transform .15s; z-index: 2; }
  .spg-index .card:hover .acts, .spg-index .card:focus-within .acts { opacity: 1; transform: none; }
  .spg-index .acts button { flex: 1; font: inherit; font-size: 13px; font-weight: 650; padding: 9px 8px; border-radius: var(--s-btn-r); border: 0; cursor: pointer; }
  .spg-index .acts .qv { background: rgba(255,255,255,.94); color: #111; box-shadow: 0 1px 4px rgba(0,0,0,.15); }
  .spg-index .acts .add { background: var(--acc); color: var(--s-acc-text); }
  .spg-index .card .b { padding: 12px 14px 14px; display: flex; flex-direction: column; flex: 1; }
  .spg-index .card .br { font-size: 12px; color: var(--p-muted); }
  .spg-index .card .nm { font-size: 14.5px; font-weight: 600; line-height: 1.32; margin-top: 2px; color: inherit; text-decoration: none; }
  .spg-index .card .nm mark { background: color-mix(in srgb, var(--acc) 45%, transparent); color: inherit; border-radius: 3px; padding: 0 1px; }
  .spg-index .card .pc { margin-top: auto; padding-top: 8px; font-size: 15px; font-weight: 700; display: flex; gap: 7px; align-items: baseline; }
  .spg-index .card .pc s { font-size: 13px; font-weight: 500; color: var(--p-muted); }
  .spg-index .card .la { display: none; }

  .spg-index .grid.list { grid-template-columns: 1fr; gap: 10px; }
  .spg-index .grid.list .card { flex-direction: row; align-items: center; }
  .spg-index .grid.list .card .actwrap { width: 120px; flex: none; }
  .spg-index .grid.list .acts { display: none; }
  .spg-index .grid.list .card .la { display: flex; gap: 6px; padding: 0 16px; }
  .spg-index .grid.list .card .la button { font: inherit; font-size: 13px; font-weight: 650; padding: 9px 14px; border-radius: var(--s-btn-r); border: 1px solid color-mix(in srgb, var(--p-text) 16%, transparent); background: var(--s-card); color: inherit; cursor: pointer; white-space: nowrap; }
  .spg-index .grid.list .card .la button.add { background: var(--acc); color: var(--s-acc-text); border: 0; }

  .spg-index .pcard { position: relative; display: flex; flex-direction: column; border-radius: var(--s-r); overflow: hidden; text-decoration: none; color: #fff; min-height: 300px; background: color-mix(in srgb, var(--p-text) 12%, var(--p-bg)); }
  .spg-index .pcard img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
  .spg-index .pcard::after { content: ''; position: absolute; inset: 0; background: linear-gradient(0deg, rgba(0,0,0,.72), rgba(0,0,0,0) 65%); }
  .spg-index .pcard em { position: absolute; top: 10px; left: 10px; z-index: 1; font-style: normal; font-size: 11px; font-weight: 700; background: rgba(0,0,0,.55); padding: 3px 9px; border-radius: 99px; }
  .spg-index .pcard .tx { position: relative; z-index: 1; margin-top: auto; padding: 16px; }
  .spg-index .pcard b { display: block; font-size: 17px; line-height: 1.25; font-family: var(--p-font-heading); }
  .spg-index .pcard span { display: block; font-size: 13px; opacity: .85; margin-top: 4px; }
  .spg-index .pcard u { display: inline-block; margin-top: 10px; font-size: 13px; font-weight: 650; }
  .spg-index .grid.list .pcard { min-height: 150px; }

  .spg-index .pager { display: flex; justify-content: center; gap: 6px; margin-top: 30px; flex-wrap: wrap; }
  .spg-index .pager a, .spg-index .pager span { min-width: 38px; text-align: center; padding: 8px 12px; border-radius: 9px; border: 1px solid color-mix(in srgb, var(--p-text) 12%, transparent); font-size: 13.5px; text-decoration: none; color: inherit; }
  .spg-index .pager .on { background: var(--s-ink); color: var(--s-on-ink); border-color: var(--s-ink); }
  .spg-index .pager .gap { border: 0; }

  .spg-index .empty { text-align: center; padding: 46px 10px 30px; }
  .spg-index .empty h2 { font-size: 24px; letter-spacing: -.01em; }
  .spg-index .empty p { color: var(--p-muted); margin: 8px auto 0; max-width: 520px; }
  .spg-index .empty .tiles { max-width: 860px; margin: 26px auto 10px; text-align: left; }
  .spg-index .pills { display: flex; gap: 8px; justify-content: center; flex-wrap: wrap; margin: 18px 0; }
  .spg-index .pills a { font-size: 13.5px; padding: 6px 14px; border-radius: 99px; border: 1px solid color-mix(in srgb, var(--p-text) 16%, transparent); text-decoration: none; color: inherit; }
  .spg-index .btn { display: inline-block; font: inherit; font-size: 14px; font-weight: 650; padding: 11px 22px; border-radius: var(--s-btn-r); border: 0; background: var(--acc); color: var(--s-acc-text); text-decoration: none; cursor: pointer; }

  /* quick view */
  .spg-qv { position: fixed; inset: 0; z-index: 1000; background: rgba(0,0,0,.5); display: none; align-items: center; justify-content: center; padding: 18px; }
  .spg-qv.on { display: flex; }
  .spg-qv .box { position: relative; width: min(860px, 100%); max-height: 92vh; overflow: auto; background: var(--p-bg); color: var(--p-text); border-radius: var(--s-r); display: grid; grid-template-columns: 1fr 1fr; box-shadow: 0 30px 80px rgba(0,0,0,.35); }
  .spg-qv .gal { background: var(--s-photo); color: var(--s-photo-ink); display: flex; flex-direction: column; }
  .spg-qv .gal .main { aspect-ratio: 1; display: grid; place-items: center; }
  .spg-qv .gal .main img { width: 100%; height: 100%; object-fit: contain; padding: 22px; }
  .spg-qv .gal .th { display: flex; gap: 6px; padding: 0 12px 12px; }
  .spg-qv .gal .th img { width: 52px; height: 52px; object-fit: contain; border: 1px solid #e5e5e5; border-radius: 8px; cursor: pointer; background: #fff; }
  .spg-qv .tx { padding: 28px 26px; display: flex; flex-direction: column; gap: 6px; }
  .spg-qv .x { position: absolute; top: 10px; right: 10px; width: 34px; height: 34px; border-radius: 99px; border: 0; background: color-mix(in srgb, var(--p-text) 8%, var(--p-bg)); color: inherit; font-size: 16px; cursor: pointer; }
  .spg-qv h3 { font-family: var(--p-font-heading); font-size: 22px; line-height: 1.2; padding-right: 30px; }
  .spg-qv .pr { font-size: 21px; font-weight: 700; display: flex; gap: 9px; align-items: baseline; margin-top: 4px; }
  .spg-qv .pr s { font-size: 15px; font-weight: 500; color: var(--p-muted); }
  .spg-qv .st { font-size: 13.5px; }
  .spg-qv .st.in { color: var(--s-ok); } .spg-qv .st.so { color: var(--s-warn); } .spg-qv .st.out { color: var(--s-bad); }
  .spg-qv .bl { font-size: 14px; color: var(--p-muted); line-height: 1.55; margin-top: 6px; }
  .spg-qv .row { display: flex; gap: 10px; margin-top: 16px; align-items: center; }
  .spg-qv .qty { display: inline-flex; align-items: center; border: 1px solid color-mix(in srgb, var(--p-text) 18%, transparent); border-radius: var(--s-btn-r); }
  .spg-qv .qty button { width: 38px; height: 42px; border: 0; background: none; color: inherit; font-size: 18px; cursor: pointer; }
  .spg-qv .qty span { min-width: 26px; text-align: center; font-weight: 650; }
  .spg-qv .full { font-size: 13.5px; color: var(--p-muted); text-decoration: underline; margin-top: 14px; }

  .spg-toast { position: fixed; left: 50%; bottom: 26px; transform: translate(-50%, 20px); opacity: 0; pointer-events: none; z-index: 1100; background: var(--p-text, #111); color: var(--p-bg, #fff); padding: 12px 18px; border-radius: 12px; font-size: 14px; font-weight: 600; transition: opacity .2s, transform .2s; display: flex; gap: 14px; align-items: center; box-shadow: 0 10px 30px rgba(0,0,0,.25); max-width: calc(100vw - 32px); }
  .spg-toast.on { opacity: 1; transform: translate(-50%, 0); pointer-events: auto; }
  .spg-toast a { color: inherit; text-decoration: underline; font-weight: 700; white-space: nowrap; }
  .spg-toast.err { background: #b3261e; color: #fff; }

  @media (max-width: 860px) {
    .spg-index .lay { grid-template-columns: 1fr; }
    .spg-index .fbtn { display: inline-flex; }
    .spg-index .fac { display: none; position: fixed; inset: 0; z-index: 999; background: var(--p-bg); max-height: none; padding: 20px 20px 90px; overflow: auto; top: 0; }
    .spg-index .fac.show { display: block; }
    .spg-index .fac .cls { display: block; position: fixed; left: 20px; right: 20px; bottom: 18px; }
    .spg-index .grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
    .spg-index .acts { opacity: 1; transform: none; position: static; padding: 8px 8px 0; }
    .spg-index .acts .qv { display: none; }
    .spg-index .card .im img { padding: 8px; }
    .spg-qv .box { grid-template-columns: 1fr; }
    .spg-index .grid.list .card .la { padding: 0 10px; }
    .spg-index .grid.list .card .la .qvb { display: none; }
    .spg-index .grid.list .card .actwrap { width: 92px; }
    .spg-index h1 { font-size: 23px; }
  }
</style>

<div class="spg-index {{ \App\Support\SiteLook::for(tenant()) ? 'p-look' : '' }}">
  <div class="wrap">
    <div class="crumbs">
      <div><a href="/shop">Shop</a>@if($isSearch) › Search @elseif($catName) › {{ $catName }}@endif</div>
      <a class="cart" href="/cart">Cart <b data-cart-count>{{ (int) ($cartCount ?? 0) }}</b></a>
    </div>

    @if($browse['banner'])
      <a class="banner" href="{{ $browse['banner']['url'] }}">
        @if($browse['banner']['img'])<img src="{{ $browse['banner']['img'] }}" alt="">@endif
        <div class="tx"><b>{{ $browse['banner']['title'] }}</b>@if($browse['banner']['sub'])<span>{{ $browse['banner']['sub'] }}</span>@endif<em>{{ $browse['banner']['button'] }}</em></div>
      </a>
    @endif

    <form class="sbar" method="GET" action="/shop" role="search">
      <input type="search" name="q" value="{{ $f['q'] }}" placeholder="{{ $display['placeholder'] }}" aria-label="Search the shop" autocomplete="off">
      <button>Search</button>
    </form>

    <div class="rh">
      <h1>{{ $title }}</h1>
      @if(! $noMatch)<span class="c">{{ number_format($total) }} item{{ $total === 1 ? '' : 's' }}</span>@endif
    </div>
    @if(! $isSearch && ! $catName && ! empty($wording['intro']))<div class="intro">{{ $wording['intro'] }}</div>@endif
    @if(! empty($browse['run']['corrected']))
      <div class="note">We searched for <b>{{ $browse['run']['corrected'] }}</b> instead of "{{ $f['q'] }}". <a href="{{ '/shop?' . http_build_query(['q' => $f['q'], 'literal' => 1]) }}">Search for "{{ $f['q'] }}" exactly</a></div>
    @endif

    @if($noMatch)
      {{-- no results: the categories, what others search for, and a way to ask --}}
      <div class="empty">
        <h2>We couldn't find "{{ $f['q'] }}"</h2>
        <p>Nothing in the shop matches yet. Try a category, or ask us: we can often order it in.</p>
        @if($tiles)
          <div class="tiles">
            @foreach(array_slice($tiles, 0, 8) as $t)
              <a class="tile" href="/shop?cat[]={{ urlencode($t['id']) }}"><span class="p">@if($t['img'])<img src="{{ $t['img'] }}" alt="" loading="lazy">@else<i>{{ mb_substr($t['name'], 0, 1) }}</i>@endif</span>{{ $t['name'] }}</a>
            @endforeach
          </div>
        @endif
        @if($popular)
          <div class="pills">@foreach($popular as $p)<a href="/shop?q={{ urlencode($p) }}">{{ $p }}</a>@endforeach</div>
        @endif
        @if($contactUrl)
          <a class="btn" href="{{ $contactUrl }}{{ str_contains($contactUrl, '?') ? '&' : '?' }}ask={{ urlencode($f['q']) }}">Ask us about "{{ $f['q'] }}"</a>
        @endif
      </div>
    @else
      @if(! $isSearch && ! $f['cat'] && ($browseLayout ?? 'chips') !== 'sidebar' && count($tiles) > 1)
        <div class="tiles">
          @foreach($tiles as $t)
            <a class="tile" href="/shop?cat[]={{ urlencode($t['id']) }}"><span class="p">@if($t['img'])<img src="{{ $t['img'] }}" alt="" loading="lazy">@else<i>{{ mb_substr($t['name'], 0, 1) }}</i>@endif</span>{{ $t['name'] }}</a>
          @endforeach
        </div>
      @endif

      <div class="bar">
        @if($facets)<button type="button" class="fbtn" onclick="document.getElementById('spgFac').classList.add('show')">Filters{{ $chips ? ' (' . count($chips) . ')' : '' }}</button>@endif
        @foreach($chips as $c)<a class="chip" href="{{ $c['url'] }}">{{ $c['label'] }} <span aria-label="Remove">✕</span></a>@endforeach
        @if(count($chips) > 1)<a class="chip clear" href="{{ '/shop' . ($isSearch ? '?' . http_build_query(['q' => $f['q']]) : '') }}">Clear all</a>@endif
        <span class="sp"></span>
        <span class="vbtn" role="group" aria-label="Layout">
          <a href="{{ $keep(['view' => 'grid']) }}" class="{{ $f['view'] === 'grid' ? 'on' : '' }}" title="Grid" aria-label="Grid"><svg width="15" height="15" viewBox="0 0 15 15" fill="currentColor"><rect x="1" y="1" width="5.5" height="5.5" rx="1"/><rect x="8.5" y="1" width="5.5" height="5.5" rx="1"/><rect x="1" y="8.5" width="5.5" height="5.5" rx="1"/><rect x="8.5" y="8.5" width="5.5" height="5.5" rx="1"/></svg></a>
          <a href="{{ $keep(['view' => 'list']) }}" class="{{ $f['view'] === 'list' ? 'on' : '' }}" title="List" aria-label="List"><svg width="15" height="15" viewBox="0 0 15 15" fill="currentColor"><rect x="1" y="2" width="13" height="2.2" rx="1"/><rect x="1" y="6.4" width="13" height="2.2" rx="1"/><rect x="1" y="10.8" width="13" height="2.2" rx="1"/></svg></a>
        </span>
        <select class="sort" aria-label="Sort" onchange="location.href=this.value">
          @foreach(array_filter(['best' => $isSearch ? 'Best match' : null, 'featured' => $isSearch ? null : 'Featured', 'price_asc' => 'Price: low to high', 'price_desc' => 'Price: high to low', 'newest' => 'Newest']) as $k => $label)
            <option value="{{ $keep(['sort' => $k]) }}" @selected($f['sort'] === $k)>{{ $label }}</option>
          @endforeach
        </select>
      </div>

      <div class="lay {{ $facets ? '' : 'nofac' }}">
        @if($facets)
          <form class="fac" id="spgFac" method="GET" action="/shop">
            @if($isSearch)<input type="hidden" name="q" value="{{ $f['q'] }}">@endif
            @if($f['literal'])<input type="hidden" name="literal" value="1">@endif
            @if(request()->query('sort'))<input type="hidden" name="sort" value="{{ $f['sort'] }}">@endif
            @if(request()->query('view'))<input type="hidden" name="view" value="{{ $f['view'] }}">@endif

            @if(! empty($facets['category']))
              <div class="g" data-more>
                <h5>Category</h5>
                @php
                  // groups that hold a picked category always show, so it can be unpicked
                  $catOpen = collect($facets['category'])->whereIn('id', $f['cat'])->pluck('group')->flip()->all();
                  $catTops = collect($facets['category'])->where('depth', 0)->count();
                @endphp
                @foreach($facets['category'] as $c)
                  <label class="{{ $c['n'] ? '' : 'zero' }} {{ $c['depth'] ? 'sub' : '' }}" style="--d: {{ (int) $c['depth'] }}" @if($c['group'] >= 8 && ! isset($catOpen[$c['group']])) hidden data-extra @endif>
                    <input type="checkbox" name="cat[]" value="{{ $c['id'] }}" @checked(in_array($c['id'], $f['cat'], true))>
                    @if($display['photos'] && ! $c['depth'])<span class="ci">@if($c['img'])<img src="{{ $c['img'] }}" alt="" loading="lazy">@endif</span>@endif
                    <span>{{ $c['name'] }}</span>
                    <span class="n">{{ $c['n'] }}</span>
                  </label>
                @endforeach
                @if($catTops > 8)<button type="button" class="more">Show all {{ $catTops }}</button>@endif
              </div>
            @endif
            @if(! empty($facets['brand']))
              <div class="g" data-more>
                <h5>Brand</h5>
                @foreach($facets['brand'] as $i => $b)
                  <label class="{{ $b['n'] ? '' : 'zero' }}" @if($i >= 8 && ! in_array($b['id'], $f['brand'], true)) hidden data-extra @endif>
                    <input type="checkbox" name="brand[]" value="{{ $b['id'] }}" @checked(in_array($b['id'], $f['brand'], true))>
                    <span>{{ $b['name'] }}</span><span class="n">{{ $b['n'] }}</span>
                  </label>
                @endforeach
                @if(count($facets['brand']) > 8)<button type="button" class="more">Show all {{ count($facets['brand']) }}</button>@endif
              </div>
            @endif
            @if(! empty($facets['availability']))
              <div class="g">
                <h5>Availability</h5>
                @foreach($facets['availability'] as $a)
                  <label class="{{ $a['n'] ? '' : 'zero' }}"><input type="checkbox" name="avail[]" value="{{ $a['id'] }}" @checked(in_array($a['id'], $f['avail'], true))><span>{{ $a['id'] === 'out' ? $outLabel : $a['name'] }}</span><span class="n">{{ $a['n'] }}</span></label>
                @endforeach
              </div>
            @endif
            @if(isset($facets['sale']))
              <div class="g">
                <h5>Deals</h5>
                <label><input type="checkbox" name="sale" value="1" @checked($f['sale'])><span>On sale</span><span class="n">{{ $facets['sale'] }}</span></label>
              </div>
            @endif
            @if(isset($facets['price']) && $facets['price']['hi'] !== null)
              <div class="g">
                <h5>Price</h5>
                <div class="pr">
                  <input type="number" name="min" min="0" step="1" inputmode="numeric" placeholder="${{ $facets['price']['lo'] }}" value="{{ $f['min'] !== null ? $f['min'] / 100 : '' }}" aria-label="Lowest price">
                  <span>–</span>
                  <input type="number" name="max" min="0" step="1" inputmode="numeric" placeholder="${{ $facets['price']['hi'] }}" value="{{ $f['max'] !== null ? $f['max'] / 100 : '' }}" aria-label="Highest price">
                </div>
                <button class="go">Apply price</button>
              </div>
            @endif
            <button type="submit" class="btn cls">Show results</button>
          </form>
        @endif

        <div>
          @if(! count($cards))
            <div class="empty">
              <h2>No items match those filters</h2>
              <p>Take a filter off to see more.</p>
              <p style="margin-top:16px"><a class="btn" href="{{ '/shop' . ($isSearch ? '?' . http_build_query(['q' => $f['q']]) : '') }}">Clear filters</a></p>
            </div>
          @else
            @php
              $pcardHtml = $browse['card'] && $items->currentPage() === 1
                ? '<a class="pcard" href="' . e($browse['card']['url']) . '"><em>Promotion</em>' . ($browse['card']['img'] ? '<img src="' . e($browse['card']['img']) . '" alt="" loading="lazy">' : '') . '<div class="tx"><b>' . e($browse['card']['title']) . '</b>' . ($browse['card']['sub'] ? '<span>' . e($browse['card']['sub']) . '</span>' : '') . '<u>' . e($browse['card']['button']) . ' →</u></div></a>'
                : null;
            @endphp
            <div class="grid {{ $f['view'] }}" id="spgGrid">
              @foreach($cards as $i => $c)
                @if($i === min(2, count($cards)) && $pcardHtml){!! $pcardHtml !!}@endif
                @php $canAdd = $c['state'] !== 'out' && $c['price'] !== null; @endphp
                <div class="card" data-id="{{ $c['id'] }}" data-idx="{{ $i }}">
                  <div class="actwrap">
                    <a class="im" href="{{ $c['url'] }}" data-open>
                      @if($c['img'])
                        <img class="a {{ $c['img2'] ? 'has-b' : '' }}" src="{{ $c['img'] }}" alt="" loading="lazy">
                        @if($c['img2'])<img class="b" src="{{ $c['img2'] }}" alt="" loading="lazy">@endif
                      @else
                        <span class="ph">{{ $tname }}</span>
                      @endif
                      @if($display['badges'])
                        <span class="badges">
                          @if($c['was'])<span class="bd sale">Sale</span>@endif
                          @if($c['new'])<span class="bd new">New</span>@endif
                          @if($c['state'] === 'so')<span class="bd so">Special order</span>@elseif($c['state'] === 'out')<span class="bd out">{{ $outLabel }}</span>@endif
                        </span>
                      @endif
                    </a>
                    @if($display['quick_view'] || ($display['card_add'] && $canAdd))
                      <div class="acts">
                        @if($display['quick_view'])<button type="button" class="qv" data-qv="{{ $c['id'] }}">Quick view</button>@endif
                        @if($display['card_add'] && $canAdd)<button type="button" class="add" data-add="{{ $c['id'] }}">Add to cart</button>@endif
                      </div>
                    @endif
                  </div>
                  <div class="b">
                    @if($c['brand'])<div class="br">{{ $c['brand'] }}</div>@endif
                    <a class="nm" href="{{ $c['url'] }}" data-open>{!! $mark($c['name']) !!}</a>
                    <div class="pc">{{ $money($c['price']) }}@if($c['was'])<s>{{ $money($c['was']) }}</s>@endif</div>
                  </div>
                  <div class="la">
                    @if($display['quick_view'])<button type="button" class="qvb" data-qv="{{ $c['id'] }}">Quick view</button>@endif
                    @if($display['card_add'] && $canAdd)<button type="button" class="add" data-add="{{ $c['id'] }}">Add to cart</button>@endif
                  </div>
                </div>
              @endforeach
              @if(count($cards) < 2 && $pcardHtml){!! $pcardHtml !!}@endif
            </div>

            @if($items->lastPage() > 1)
              @php
                $cur = $items->currentPage(); $last = $items->lastPage();
                $pages = collect([1, $last, $cur - 1, $cur, $cur + 1])->filter(fn ($p) => $p >= 1 && $p <= $last)->unique()->sort()->values();
              @endphp
              <nav class="pager" aria-label="Pages">
                @if($cur > 1)<a href="{{ $keep(['page' => $cur - 1]) }}" rel="prev">‹ Back</a>@endif
                @foreach($pages as $k => $p)
                  @if($k > 0 && $p - $pages[$k - 1] > 1)<span class="gap">…</span>@endif
                  @if($p === $cur)<span class="on" aria-current="page">{{ $p }}</span>@else<a href="{{ $keep(['page' => $p]) }}">{{ $p }}</a>@endif
                @endforeach
                @if($cur < $last)<a href="{{ $keep(['page' => $cur + 1]) }}" rel="next">Next ›</a>@endif
              </nav>
            @endif
          @endif
        </div>
      </div>
    @endif
  </div>
</div>

<div class="spg-qv spg-index" id="spgQv" role="dialog" aria-modal="true" aria-label="Quick view"><div class="box" id="spgQvBox"></div></div>
<div class="spg-toast" id="spgToast" role="status" aria-live="polite"></div>

<script>
(function () {
  var QID = @json($qid), Q = @json($f['q']);
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  function esc(x) { var d = document.createElement('div'); d.textContent = x == null ? '' : String(x); return d.innerHTML; }
  var tT, toastEl = document.getElementById('spgToast');
  function toast(msg, err, link) {
    toastEl.innerHTML = '<span>' + esc(msg) + '</span>' + (link ? '<a href="/cart">View cart</a>' : '');
    toastEl.classList.toggle('err', !!err); toastEl.classList.add('on');
    clearTimeout(tT); tT = setTimeout(function () { toastEl.classList.remove('on'); }, err ? 4200 : 3000);
  }
  // a click from search, so Reports › Search can follow it to the cart
  function track(id) {
    if (!QID || !id) return;
    try { fetch('/shop/search/click?qid=' + encodeURIComponent(QID) + '&item=' + encodeURIComponent(id), { keepalive: true, credentials: 'same-origin' }); } catch (e) {}
  }
  document.querySelectorAll('#spgGrid [data-open]').forEach(function (a) {
    a.addEventListener('click', function () { var c = a.closest('.card'); if (c) track(c.getAttribute('data-id')); });
  });

  function add(id, qty, btn) {
    if (btn) btn.disabled = true;
    var body = new FormData(); body.append('item_id', id); body.append('quantity', qty || 1); if (QID) body.append('sq', QID);
    return fetch('/cart/items', { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' } })
      .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
      .then(function (j) {
        if (btn) btn.disabled = false;
        if (j.ok) {
          document.querySelectorAll('[data-cart-count]').forEach(function (e) { e.textContent = j.count; });
          toast(j.message, false, true);
          return true;
        }
        toast(j.message || 'Could not add that. Try again.', true);
        return false;
      })
      .catch(function () { if (btn) btn.disabled = false; toast('Could not reach the shop. Check your connection and try again.', true); return false; });
  }
  document.addEventListener('click', function (e) {
    var a = e.target.closest('[data-add]');
    if (a && a.closest('.spg-index')) { e.preventDefault(); add(a.getAttribute('data-add'), 1, a); return; }
    var q = e.target.closest('[data-qv]');
    if (q) { e.preventDefault(); openQv(q.getAttribute('data-qv')); }
  });

  // quick view
  var qv = document.getElementById('spgQv'), box = document.getElementById('spgQvBox'), lastFocus = null;
  function closeQv() { qv.classList.remove('on'); box.innerHTML = ''; if (lastFocus) try { lastFocus.focus(); } catch (e) {} }
  qv.addEventListener('click', function (e) { if (e.target === qv) closeQv(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && qv.classList.contains('on')) closeQv(); });
  function openQv(id) {
    lastFocus = document.activeElement;
    track(id);
    box.innerHTML = '<div class="tx" style="grid-column:1/-1;padding:60px;text-align:center;color:var(--p-muted)">Loading…</div>';
    qv.classList.add('on');
    fetch('/shop/quick/' + encodeURIComponent(id), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error(); return r.json(); })
      .then(function (i) {
        var qty = 1, imgs = i.images && i.images.length ? i.images : (i.img ? [i.img] : []);
        box.innerHTML =
          '<div class="gal"><div class="main">' + (imgs[0] ? '<img id="spgQvMain" src="' + esc(imgs[0]) + '" alt="">' : '') + '</div>' +
            (imgs.length > 1 ? '<div class="th">' + imgs.map(function (u) { return '<img src="' + esc(u) + '" alt="" data-th>'; }).join('') + '</div>' : '') + '</div>' +
          '<div class="tx"><button class="x" type="button" aria-label="Close" data-x>✕</button>' +
            (i.brand ? '<div style="color:var(--p-muted);font-size:13px">' + esc(i.brand) + '</div>' : '') +
            '<h3>' + esc(i.name) + '</h3>' +
            '<div class="pr">' + esc(i.price_text) + (i.was_text ? '<s>' + esc(i.was_text) + '</s>' : '') + '</div>' +
            '<div class="st ' + esc(i.state) + '">' + esc(i.state_text) + '</div>' +
            (i.blurb ? '<div class="bl">' + esc(i.blurb) + '</div>' : '') +
            (i.can_add ? '<div class="row"><span class="qty"><button type="button" data-q="-1" aria-label="One fewer">−</button><span id="spgQvN">1</span><button type="button" data-q="1" aria-label="One more">+</button></span><button type="button" class="btn" data-qvadd>Add to cart</button></div>' : '') +
            '<a class="full" href="' + esc(i.url) + '">See full details</a>' +
          '</div>';
        box.querySelector('[data-x]').onclick = closeQv;
        box.querySelectorAll('[data-th]').forEach(function (t) { t.onclick = function () { document.getElementById('spgQvMain').src = t.src; }; });
        box.querySelectorAll('[data-q]').forEach(function (b) { b.onclick = function () { qty = Math.max(1, Math.min(99, qty + (+b.getAttribute('data-q')))); document.getElementById('spgQvN').textContent = qty; }; });
        var ab = box.querySelector('[data-qvadd]');
        if (ab) ab.onclick = function () { add(i.id, qty, ab).then(function (ok) { if (ok) closeQv(); }); };
        box.querySelector('[data-x]').focus();
      })
      .catch(function () { closeQv(); toast('Could not open that item. Try again.', true); });
  }

  // on a computer a ticked filter applies at once; on a phone it waits for "Show results"
  var fac = document.getElementById('spgFac');
  if (fac) {
    fac.addEventListener('change', function (e) { if (e.target.type === 'checkbox' && innerWidth > 860) fac.submit(); });
    fac.querySelectorAll('[data-more] .more').forEach(function (b) {
      b.onclick = function () { b.parentNode.querySelectorAll('[data-extra]').forEach(function (l) { l.hidden = false; }); b.remove(); };
    });
  }
  // a search from this page joins the shopper's recent searches (kept in their own browser)
  if (Q) { try { var k = 'shop_recent', r = JSON.parse(localStorage.getItem(k) || '[]').filter(function (x) { return x !== Q; }); r.unshift(Q); localStorage.setItem(k, JSON.stringify(r.slice(0, 5))); } catch (e) {} }
})();
</script>

{{-- MARKER-SHOP-GA4: which products were shown, and which one was picked --}}
@if(\App\Support\ShopGa4::on($tenant) && $items->count())
@php
  $gaListName = $isSearch ? 'Search: ' . $f['q'] : ($catName ?: 'Shop');
  $gaItems = $items->getCollection()->values()->map(fn ($it, $i) => \App\Support\ShopGa4::forItem($it) + ['index' => $i])->all();
@endphp
<script>
(function () {
  var list = @json($gaListName), items = @json($gaItems);
  if (!window.shopEv) return;
  shopEv('view_item_list', { item_list_name: list, items: items });
  document.querySelectorAll('#spgGrid .card').forEach(function (c) {
    var i = +c.getAttribute('data-idx');
    c.querySelectorAll('[data-open]').forEach(function (a) {
      a.addEventListener('click', function () { if (items[i]) shopEv('select_item', { item_list_name: list, items: [items[i]], transport_type: 'beacon' }); });
    });
  });
})();
</script>
@endif
