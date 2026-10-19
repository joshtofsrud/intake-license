{{-- MARKER-LABELS: printable inventory labels (InventoryLabelController@sheet).
     The Print labels panel shows this page as its preview and prints it, so
     what is seen is what prints. On screen: labels on a grey ground; in print:
     one label per page on a roll, or Avery 5160 sheets. --}}
@php
  $w = $stock['w']; $h = $stock['h']; $roll = $stock['roll'];
  $small = $h <= 1;
  $cells = $roll ? $labels : array_merge(array_fill(0, $start, null), $labels);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Labels</title>
<link rel="stylesheet" href="{{ asset('css/fonts.css') }}">
<style>
  @page { @if($roll) size: {{ $w }}in {{ $h }}in; margin: 0; @else size: letter; margin: 0; @endif }
  html, body { margin: 0; }
  body { font-family: Inter, Helvetica, Arial, sans-serif; color: #000; }
  .lab { box-sizing: border-box; width: {{ $w }}in; height: {{ $h }}in; background: #fff; padding: .07in .1in .05in;
    display: flex; flex-direction: column; overflow: hidden; }
  .shop { font-size: 6pt; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; margin-bottom: 1px; }
  .top { display: flex; gap: .06in; align-items: flex-start; }
  .nm { flex: 1; font-size: {{ $small ? '6.5pt' : '7.5pt' }}; font-weight: 600; line-height: 1.15; display: -webkit-box; -webkit-line-clamp: {{ $small ? 1 : 2 }}; -webkit-box-orient: vertical; overflow: hidden; }
  .pr { font-size: {{ $small ? '9pt' : '11pt' }}; font-weight: 700; white-space: nowrap; line-height: 1; }
  .meta { font-size: {{ $small ? '6pt' : '6.5pt' }}; margin-top: 1px; }
  .bcw { flex: 1; min-height: .22in; margin-top: .04in; display: flex; }
  .bc { width: 100%; height: 100%; }
  .hr { font-family: "JetBrains Mono", Menlo, Consolas, monospace; font-size: {{ $small ? '6pt' : '7pt' }}; text-align: center; letter-spacing: .06em; line-height: 1.1; margin-top: 1px; }
  .sheet { box-sizing: border-box; width: 8.5in; height: 11in; background: #fff; padding: .5in .1875in; display: grid;
    grid-template-columns: repeat(3, 2.625in); grid-auto-rows: 1in; column-gap: .125in; align-content: start; }
  .empty { font: 13px Inter, sans-serif; color: #555; padding: 40px; text-align: center; }
  @media screen {
    body { background: #d9d9d6; padding: 18px; display: flex; flex-direction: column; align-items: center; gap: 10px; }
    .roll { display: flex; flex-direction: column; gap: .08in; zoom: 1.35; }
    .roll .lab { border-radius: .06in; box-shadow: 0 1px 3px rgba(0,0,0,.25); }
    .sheets { display: flex; flex-direction: column; gap: 18px; zoom: .62; }
    .sheet { box-shadow: 0 2px 8px rgba(0,0,0,.25); }
    .sheet .lab { outline: 1px dashed #ccc; outline-offset: -1px; }
    .sheet .skip { outline: 1px dashed #e4e4e4; outline-offset: -1px; background: repeating-linear-gradient(45deg, #fafafa 0 6px, #f2f2f2 6px 12px); }
  }
  @media print {
    .roll .lab { page-break-after: always; }
    .roll .lab:last-child { page-break-after: auto; }
    .sheet { page-break-after: always; }
    .sheet:last-child { page-break-after: auto; }
    .note { display: none; }
  }
</style>
</head>
<body>
@if(! $labels)
  <div class="empty">Nothing to print — every quantity is 0.</div>
@else
  @if($roll)<div class="roll">@else<div class="sheets">@endif
  @foreach($roll ? [$cells] : array_chunk($cells, 30) as $page)
    @if(! $roll)<div class="sheet">@endif
    @foreach($page as $l)
      @if($l === null)
        <div class="skip"></div>
      @else
        <div class="lab">
          @if(isset($inc['shop']))<div class="shop">{{ $shop }}</div>@endif
          <div class="top">
            <div class="nm">@if(isset($inc['name'])){{ $l['name'] }}@endif</div>
            @if(isset($inc['price']) && $l['price'] !== '')<div class="pr">{{ $l['price'] }}</div>@endif
          </div>
          @php $meta = array_filter([isset($inc['variant']) ? $l['variant'] : '', isset($inc['bin']) && $l['bin'] !== '' ? 'Bin ' . $l['bin'] : '']); @endphp
          @if($meta)<div class="meta">{{ implode(' · ', $meta) }}</div>@endif
          <div class="bcw">{!! $l['bc'] !!}</div>
          <div class="hr">{{ $l['code'] }}</div>
        </div>
      @endif
    @endforeach
    @if(! $roll)</div>@endif
  @endforeach
  </div>
  @if($capped)<div class="note" style="font:12px Inter,sans-serif;color:#555">Stopped at 2,000 labels — print the rest in another batch.</div>@endif
@endif
</body>
</html>
