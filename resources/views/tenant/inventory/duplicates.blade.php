{{-- MARKER-DUP-MERGE — products that are in the inventory more than once. --}}
@extends('layouts.tenant.app')
@php $pageTitle = 'Duplicate items'; @endphp
@section('content')
@php
  $money = fn ($c) => $c === null ? '—' : '$' . number_format($c / 100, 2);
  $reasonText = [
    'part_number'    => ['Part number only', 'No barcode on any copy. They share a brand and part number, which is weaker evidence than a barcode.'],
    'stock_on_both'  => ['Stock on more than one copy', 'Merging adds the counts together. If the same stock was counted on each copy, keep one count instead.'],
    'price_conflict' => ['Different prices', 'You set a different price on each copy. Choose the one to keep.'],
  ];
@endphp
<style>
  .dp-card{background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);padding:16px 18px;margin-bottom:16px}
  .dp-row{display:grid;grid-template-columns:minmax(0,1.2fr) 16px minmax(0,1fr) 120px;gap:10px;align-items:center;padding:9px 0;border-top:0.5px solid var(--ia-border);font-size:13px}
  .dp-dim{color:var(--ia-text-dim)}
  .dp-pill{font-size:11px;padding:2px 9px;border-radius:99px;border:0.5px solid var(--ia-accent);color:var(--ia-accent);white-space:nowrap}
  .dp-copies{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:8px;margin:10px 0}
  .dp-copy{background:var(--ia-input-bg);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-sm);padding:9px 11px;font-size:12.5px;line-height:1.5}
  .dp-opts{display:flex;gap:8px;flex-wrap:wrap;margin:6px 0 12px}
  .dp-opt{display:flex;align-items:center;gap:7px;font-size:13px;padding:7px 11px;border:0.5px solid var(--ia-border);border-radius:var(--ia-r-sm);cursor:pointer}
  .dp-opt:has(input:checked){border-color:var(--ia-accent);color:var(--ia-accent)}
  @media(max-width:720px){.dp-row{grid-template-columns:1fr}.dp-row .dp-arrow{display:none}}
</style>

<div style="max-width:1020px">
  <div class="ia-page-head">
    <div class="ia-page-head-left">
      <h1 class="ia-page-title">Duplicate items</h1>
      <p class="ia-page-subtitle">
        Products that are in your inventory more than once
        @if($lastFound) · checked {{ \Illuminate\Support\Carbon::parse($lastFound)->diffForHumans() }} @endif
      </p>
    </div>
  </div>
  @include('layouts.tenant._inventory-tabs')

  {{-- The legend: what a merge does, since most of it happens out of sight. --}}
  <div class="ia-flash" style="margin-bottom:16px;line-height:1.55">
    Merging keeps one item: the catalog's title and details, with <strong>your</strong> price, cost, stock and bin location.
    Sales, receiving and special orders move to it, and every old SKU and barcode keeps scanning.
    <strong>A merge can't be undone.</strong> Duplicates are looked for nightly and after every import.
  </div>

  @if($merging)
    <div class="ia-flash" style="margin-bottom:16px">Merging in the background. This list empties as they finish — refresh to see progress.</div>
  @endif

  @if($readyCount === 0 && $reviewCount === 0)
    <div class="dp-card" style="text-align:center;padding:32px">
      <div style="font-size:15px;font-weight:600">No duplicates</div>
      <div class="dp-dim" style="font-size:13px;margin-top:4px">Every product is in your inventory once.</div>
    </div>
  @endif

  @if($readyCount > 0)
    <div class="dp-card">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
        <div>
          <span style="font-size:15px;font-weight:600">Ready to merge</span>
          <span class="dp-dim" style="font-size:13px"> · {{ number_format($readyCount) }} clear {{ \Illuminate\Support\Str::plural('case', $readyCount) }}</span>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
          <a href="{{ route('tenant.inventory.duplicates.download') }}" class="ia-btn ia-btn--ghost ia-btn--sm">Download list</a>
          <form method="POST" action="{{ route('tenant.inventory.duplicates.merge-all') }}" id="dp-merge-all" data-count="{{ number_format($readyCount) }}">
            @csrf
            <button type="submit" class="ia-btn ia-btn--primary ia-btn--sm" @disabled($merging)>Merge all {{ number_format($readyCount) }}</button>
          </form>
        </div>
      </div>
      <div class="dp-row dp-dim" style="border-top:0;font-size:12px"><span>Kept</span><span></span><span>Merged into it</span><span style="text-align:right">Result</span></div>
      @foreach($ready as $g)
        @php $c = $g->preview['copies'] ?? []; @endphp
        <div class="dp-row">
          <span>{{ $c[0]['name'] ?? '—' }}</span>
          <span class="dp-dim dp-arrow">←</span>
          <span class="dp-dim">
            @foreach(array_slice($c, 1) as $o){{ $o['name'] }}@if(! $loop->last), @endif @endforeach
          </span>
          <span style="text-align:right">{{ $money($g->preview['result_price'] ?? null) }} · {{ $g->preview['result_stock'] ?? 0 }} in stock</span>
        </div>
      @endforeach
      @if($readyCount > $ready->count())
        <div class="dp-dim" style="font-size:12.5px;padding-top:10px">Showing the first {{ $ready->count() }} of {{ number_format($readyCount) }}. Download the list to see them all.</div>
      @endif
    </div>
  @endif

  @if($reviewCount > 0)
    <div class="dp-card">
      <div style="margin-bottom:4px">
        <span style="font-size:15px;font-weight:600">Needs a look</span>
        <span class="dp-dim" style="font-size:13px"> · {{ number_format($reviewCount) }} where a person should decide</span>
      </div>

      @foreach($review as $g)
        @php
          $c = $g->preview['copies'] ?? [];
          $reasons = (array) $g->reasons;
          $prices = collect($c)->filter(fn ($x) => ($x['shop_set'] ?? $x['shop_price'] !== null) && $x['shop_price'] !== null)->unique('shop_price')->values(); // MARKER-DUP-PRICE-RULE
          $stockLoc = $g->preview['stock_location'] ?? null;
          $stockVals = collect($c)->pluck('stock')->filter(fn ($n) => $n > 0)->unique()->values();
        @endphp
        <form method="POST" action="{{ route('tenant.inventory.duplicates.merge', $g->id) }}" style="border-top:0.5px solid var(--ia-border);padding-top:14px;margin-top:14px">
          @csrf
          <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap">
            <span style="font-size:14px;font-weight:600">{{ $c[0]['name'] ?? $g->label }}</span>
            <span style="display:flex;gap:6px;flex-wrap:wrap">
              @if($g->status === 'failed')<span class="dp-pill">Couldn't merge</span>@endif
              @foreach($reasons as $r)<span class="dp-pill">{{ $reasonText[$r][0] ?? $r }}</span>@endforeach
            </span>
          </div>
          @foreach($reasons as $r)
            <div class="dp-dim" style="font-size:12.5px;margin-top:5px">{{ $reasonText[$r][1] ?? '' }}</div>
          @endforeach
          @if($g->status === 'failed' && $g->error)
            <div style="font-size:12.5px;margin-top:5px;color:#f87171">{{ $g->error }}</div>
          @endif

          <div class="dp-copies">
            @foreach($c as $i => $x)
              <div class="dp-copy">
                <div style="font-weight:600">{{ $i === 0 ? 'Kept' : 'Merged into it' }}</div>
                <div>{{ $x['name'] }}</div>
                <div class="dp-dim">
                  {{ $x['sku'] ? 'SKU ' . $x['sku'] . ' · ' : '' }}{{ $x['from'] ? 'from ' . implode(', ', $x['from']) . ' · ' : '' }}{{ ($x['shop_set'] ?? false) ? 'your price ' . $money($x['shop_price']) : 'list price ' . $money($x['shop_price'] ?? $x['list_price']) }}
                  · {{ $x['stock'] }} in stock · {{ $x['sales'] }} {{ \Illuminate\Support\Str::plural('sale', $x['sales']) }}
                </div>
              </div>
            @endforeach
          </div>

          @if(in_array('price_conflict', $reasons, true) && $prices->count() > 1)
            <div class="dp-dim" style="font-size:12.5px">Price to keep</div>
            <div class="dp-opts">
              @foreach($prices as $i => $p)
                <label class="dp-opt"><input type="radio" name="price_from" value="{{ $p['id'] }}" @checked($i === 0)> {{ $money($p['shop_price']) }}</label>
              @endforeach
            </div>
          @endif

          @if(in_array('stock_on_both', $reasons, true))
            @if($stockLoc)
              <div class="dp-dim" style="font-size:12.5px">Stock after merging</div>
              <div class="dp-opts">
                <label class="dp-opt"><input type="radio" name="stock" value="add" checked> Add them up · {{ $g->preview['result_stock'] ?? 0 }}</label>
                @foreach($stockVals as $n)
                  <label class="dp-opt"><input type="radio" name="stock" value="{{ $n }}"> Keep {{ $n }}</label>
                @endforeach
              </div>
            @else
              <div class="dp-dim" style="font-size:12.5px;margin-bottom:10px">This stock is at more than one location, so it will be added together. Adjust it on the item afterwards if needed.</div>
            @endif
          @endif

          <div style="display:flex;gap:8px">
            <button type="submit" class="ia-btn ia-btn--primary ia-btn--sm">Merge</button>
            <button type="submit" class="ia-btn ia-btn--ghost ia-btn--sm" formaction="{{ route('tenant.inventory.duplicates.dismiss', $g->id) }}">Not the same</button>
          </div>
        </form>
      @endforeach
      @if($reviewCount > $review->count())
        <div class="dp-dim" style="font-size:12.5px;padding-top:10px">Showing {{ $review->count() }} of {{ number_format($reviewCount) }}.</div>
      @endif
    </div>
  @endif
</div>
@endsection

@push('scripts')
<script>
// MARKER-DUP-MERGE — Merge all asks first, in the app's own dialog.
(function () {
  var form = document.getElementById('dp-merge-all');
  if (!form) { return; }
  form.addEventListener('submit', function (e) {
    if (form.dataset.confirmed === '1' || !window.IntakeConfirm || typeof IntakeConfirm.show !== 'function') { return; }
    e.preventDefault();
    IntakeConfirm.show({
      title: 'Merge ' + form.dataset.count + ' duplicates?',
      message: 'Each set becomes one item with your price, stock and sales. This can\'t be undone. It runs in the background.',
      confirmText: 'Merge all',
    }).then(function (ok) {
      if (!ok) { return; }
      form.dataset.confirmed = '1';
      form.submit();
    });
  });
})();
</script>
@endpush
