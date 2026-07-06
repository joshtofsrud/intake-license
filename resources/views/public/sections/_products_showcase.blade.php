{{--
  MARKER-PATCH-575 — products_showcase public render. Live pull from the
  online store: show_online items in the chosen category. Renders nothing
  when the store is gated off (addon, master switch, or no matches) — a
  stale section never shows an empty shell or leaks a disabled feature.
--}}
@php
  $psTenant = $tenant ?? $currentTenant ?? null;
  $psItems = collect();
  $psStoreOpen = $psTenant
      && $psTenant->online_store_enabled
      && (bool) (($psTenant->settings['storefront']['enabled'] ?? true));
  if ($psStoreOpen) {
      $psItems = \App\Models\Tenant\TenantInventoryItem::where('tenant_id', $psTenant->id)
          ->where('is_active', true)
          ->where('show_online', true)
          ->when(!empty($c['category_id']), fn ($q) => $q->where('category_id', $c['category_id']))
          ->when(($c['in_stock_only'] ?? '0') === '1', fn ($q) => $q->where('computed_stock_count', '>', 0))
          ->with('distributorCatalog:id,manufacturer,images')
          ->orderByDesc('computed_stock_count')->orderBy('name')
          ->limit(max(1, min(24, (int) ($c['max_items'] ?? 8))))
          ->get();
  }
  $psShowPrices = ($c['show_prices'] ?? '1') === '1';
  $psShowSearch = ($c['show_search'] ?? '0') === '1';
  $psImg = function ($item) {
      $ims = (array) ($item->distributorCatalog->images ?? []);
      $f = $ims[0] ?? null;
      if (is_array($f)) return $f['Url'] ?? $f['url'] ?? $f['src'] ?? null;
      return is_string($f) ? $f : null;
  };
@endphp

@if($psItems->isNotEmpty())
<section class="p-section" id="shop" @if(!empty($c['bg_color'])) style="background:{{ $c['bg_color'] }}" @endif>
  <div class="p-container">
    <div class="p-section-head-wrap" style="text-align:center">
      @if(!empty($c['eyebrow']))<div class="p-eyebrow">{{ $c['eyebrow'] }}</div>@endif
      @if(!empty($c['heading']))<h2 class="p-section-heading">{{ $c['heading'] }}</h2>@endif
      @if(!empty($c['body']))<p style="max-width:560px;margin:10px auto 0;opacity:.65;font-size:15px;line-height:1.6">{{ $c['body'] }}</p>@endif
    </div>

    @if($psShowSearch)
      <form method="GET" action="/shop" style="display:flex;gap:10px;max-width:520px;margin:26px auto 0">
        <input type="search" name="q" placeholder="{{ $c['search_placeholder'] ?? 'Search the shop…' }}"
               style="flex:1;font:inherit;font-size:14px;padding:11px 15px;border:1.5px solid rgba(0,0,0,.14);border-radius:10px;background:#fff">
        <button class="p-btn p-btn--primary" style="border:0;cursor:pointer;font:inherit">Search</button>
      </form>
    @endif

    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:14px;margin-top:32px">
      @foreach($psItems as $item)
        <a href="/shop/{{ $item->id }}" style="text-decoration:none;color:inherit;border:1.5px solid rgba(0,0,0,.1);border-radius:var(--p-r-lg,14px);background:rgba(255,255,255,.7);overflow:hidden;display:flex;flex-direction:column">
          <div style="aspect-ratio:1;background:#fff;display:grid;place-items:center;border-bottom:1px solid rgba(0,0,0,.05)">
            @if($u = $psImg($item))
              <img src="{{ $u }}" alt="" loading="lazy" style="width:100%;height:100%;object-fit:contain;padding:14px">
            @else
              <span style="font-size:11px;opacity:.3">{{ $psTenant->name }}</span>
            @endif
          </div>
          <div style="padding:13px 15px 15px;display:flex;flex-direction:column;flex:1">
            @if($item->distributorCatalog?->manufacturer)
              <div style="font-size:10px;text-transform:uppercase;letter-spacing:.07em;opacity:.45;font-weight:700">{{ $item->distributorCatalog->manufacturer }}</div>
            @endif
            <div style="font-size:13.5px;font-weight:650;line-height:1.32;margin-top:2px">{{ $item->name }}</div>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:auto;padding-top:11px">
              @if($psShowPrices)
                <div style="font-size:14.5px;font-weight:750">${{ number_format(($item->effectiveSellPriceCents() ?? 0) / 100, 2) }}</div>
              @else<div></div>@endif
              @if(($item->computed_stock_count ?? 0) > 0)
                <span style="font-size:10px;font-weight:650;border-radius:99px;padding:2px 8px;background:rgba(52,168,83,.1);color:#1d7a3a">In stock</span>
              @endif
            </div>
          </div>
        </a>
      @endforeach
    </div>

    @if(!empty($c['cta_label']))
      <div style="text-align:center;margin-top:32px">
        <a href="{{ $c['cta_url'] ?: '/shop' }}" class="p-btn p-btn--primary">{{ $c['cta_label'] }}</a>
      </div>
    @endif
  </div>
</section>
@endif
