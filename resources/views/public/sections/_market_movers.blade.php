{{--
  MARKER-MOVING-FAST: market_movers public render. Items selling fastest at the shop's vendors
  (App\Support\MovingFast). Shows nothing when the online store is off or in preview, or when no
  item matches — except in the builder, where an empty section says so.
--}}
@php
  $mfT = $tenant ?? $currentTenant ?? null;
  $mfOpen = $mfT && \App\Support\MovingFast::available($mfT) && \App\Support\Launch\Launch::visible($mfT, 'ecom');
  $mfRows = [];
  if ($mfOpen) {
      try {
          $mfRows = \App\Support\MovingFast::shown($mfT, $c ?? []);
      } catch (\Throwable $e) {
          report($e);
          $mfRows = [];
      }
  }
  $mfCfg = \App\Support\MovingFast::config($c ?? []);
  $mfSet = \App\Support\MovingFast::settings();
  $mfInBuilder = ! empty($builderPreview);
  $mfDisplay = $mfOpen ? \App\Support\ShopSearch\SearchConfig::display($mfT) : [];
  $mfSup = $mfRows ? \App\Services\Tenant\OrderStock::forTenant($mfT)->supplierBacked(array_map(fn ($r) => (string) $r['item']->id, $mfRows)) : [];
  $mfSpecial = $mfOpen ? \App\Services\Tenant\OrderService::wording($mfT)['special_label'] : '';
  $mfMoney = fn ($c) => \App\Support\ShopSearch\ShopSearch::money($c);
@endphp

@if($mfOpen && ($mfRows || $mfInBuilder))
<section class="p-section p-mf" @if(!empty($c['bg_color'])) style="background:{{ $c['bg_color'] }}" @endif>
  <style>
    .p-mf .p-mf-hdr{display:flex;align-items:flex-end;gap:24px;margin-bottom:24px;flex-wrap:wrap}
    .p-mf .p-mf-eb{font-size:12px;letter-spacing:.18em;text-transform:uppercase;color:var(--p-accent);font-weight:600}
    .p-mf .p-mf-h{margin:8px 0}
    .p-mf .p-mf-sb{color:var(--p-muted);font-size:15px;max-width:640px;line-height:1.55;margin:0}
    .p-mf .p-mf-all{margin-left:auto;color:var(--p-text);font-size:14px;font-weight:600;white-space:nowrap;border-bottom:1.5px solid var(--p-accent);padding-bottom:2px;text-decoration:none}
    .p-mf .p-mf-grid{display:grid;grid-template-columns:repeat(var(--p-shop-cols,4),minmax(0,1fr));gap:16px}
    .p-mf .p-mf-grid.row{grid-template-columns:none;grid-auto-flow:column;grid-auto-columns:calc(25% - 12px);overflow-x:auto;scroll-snap-type:x mandatory;padding-bottom:6px}
    .p-mf .p-mf-grid.row>a{scroll-snap-align:start}
    .p-mf .p-mf-card{display:flex;flex-direction:column;text-decoration:none;color:inherit;background:var(--p-card-bg,var(--p-surface));border:1px solid var(--p-card-border,var(--p-border));box-shadow:var(--p-card-shadow,none);border-radius:var(--p-card-r,10px);overflow:hidden}
    .p-mf .p-mf-im{aspect-ratio:var(--p-tile-ratio,1);background:var(--p-tile-bg,#fff);color:var(--p-tile-ink,#6b7280);display:grid;place-items:center;position:relative}
    .p-mf .p-mf-im img{width:100%;height:100%;object-fit:contain;padding:14px}
    .p-mf .p-mf-ph{font-size:11px;text-align:center;padding:8px}
    .p-mf .p-mf-bd{position:absolute;top:9px;left:9px;display:flex;flex-direction:column;gap:5px;align-items:flex-start}
    .p-mf .p-mf-bd span{font-size:10.5px;font-weight:700;padding:3px 8px;border-radius:99px;background:var(--p-accent);color:var(--p-accent-text)}
    .p-mf .p-mf-bd span.so{background:#17181a;color:#f1eee8}
    .p-mf .p-mf-tx{padding:12px 14px 15px;display:flex;flex-direction:column;flex:1}
    .p-mf .p-mf-br{font-size:11px;color:var(--p-muted);text-transform:uppercase;letter-spacing:.05em}
    .p-mf .p-mf-nm{font-size:14px;font-weight:600;margin:3px 0 7px;line-height:1.3}
    .p-mf .p-mf-pr{font-weight:700;font-size:15.5px;margin-top:auto}
    .p-mf .p-mf-pr s{font-weight:400;color:var(--p-muted);font-size:13px;margin-left:6px}
    .p-mf .p-mf-wh{font-size:12px;color:var(--p-muted);margin-top:3px}
    .p-mf .p-mf-sorry{border:1px dashed var(--p-border);border-radius:10px;padding:26px;text-align:center;color:var(--p-muted);font-size:14px}
    @media(max-width:900px){.p-mf .p-mf-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.p-mf .p-mf-grid.row{grid-auto-columns:calc(50% - 8px)}}
  </style>
  <div class="p-container">
    <div class="p-mf-hdr">
      <div>
        @if(!empty($c['eyebrow']))<div class="p-mf-eb">{{ $c['eyebrow'] }}</div>@endif
        @if(!empty($c['heading']))<h2 class="p-section-heading p-mf-h">{{ $c['heading'] }}</h2>@endif
        @if(!empty($c['body']))<p class="p-mf-sb">{{ $c['body'] }}</p>@endif
      </div>
      @if(!empty($c['btn_label']))<a class="p-mf-all" href="{{ $c['btn_url'] ?: '/shop' }}">{{ $c['btn_label'] }} →</a>@endif
    </div>

    @if($mfRows)
      <div class="p-mf-grid {{ $mfCfg['layout'] === 'row' ? 'row' : '' }}">
        @foreach($mfRows as $r)
          @php
            $card = \App\Support\ShopSearch\ShopSearch::card($r['item'], $mfDisplay, $mfSup);
            $onShelf = (int) $r['item']->computed_stock_count > 0;
            $badges = \App\Support\MovingFast::badges($r['p'], $mfCfg, $mfSet, $r['item']);
          @endphp
          <a class="p-mf-card" href="{{ $card['url'] }}">
            <div class="p-mf-im">
              @if($card['img'])<img src="{{ $card['img'] }}" alt="{{ $card['name'] }}" loading="lazy">@else<span class="p-mf-ph">{{ $mfT->name }}</span>@endif
              @if($badges)<div class="p-mf-bd">@foreach($badges as [$label, $cls])<span class="{{ $cls }}">{{ $label }}</span>@endforeach</div>@endif
            </div>
            <div class="p-mf-tx">
              @if($card['brand'])<div class="p-mf-br">{{ $card['brand'] }}</div>@endif
              <div class="p-mf-nm">{{ $card['name'] }}</div>
              @if($mfCfg['prices'] && $card['price'] !== null)
                <div class="p-mf-pr">{{ $mfMoney($card['price']) }}@if($card['was'])<s>{{ $mfMoney($card['was']) }}</s>@endif</div>
              @endif
              <div class="p-mf-wh">{{ $onShelf ? 'In stock' : $mfSpecial }}</div>
            </div>
          </a>
        @endforeach
      </div>
    @else
      <div class="p-mf-sorry">No items match right now. Shoppers don't see this section until some do. Open Moving fast to loosen a filter.</div>
    @endif
  </div>
</section>
@endif
