{{-- MARKER-PATCH-321 — 80mm pickup/delivery slips for a day. One slip per
     stop: driver reference + customer hand-off. Shares the tag print identity
     and printable-width CSS. Auto-prints unless embed. --}}
@php
  $pageMm  = ($print['paper'] ?? '80mm') === '58mm' ? '46mm' : '70mm';
  $logoMax = ['small'=>'12mm','medium'=>'18mm','large'=>'26mm','xl'=>'34mm'][$print['logo_size'] ?? 'medium'] ?? '18mm';
  $logoUrl = $print['logo_path'] ? asset('storage/' . ltrim($print['logo_path'], '/')) : null;
  $headerText = trim((string) ($print['header_text'] ?? '')); // MARKER-PATCH-330
  $footerText = trim((string) ($print['footer_text'] ?? '')); // MARKER-PATCH-330
  $feedMm  = (int) ($print['feed_mm'] ?? 0) > 0 ? ((int) $print['feed_mm']) . 'mm' : null;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Run slips {{ $dateLabel }}</title>
<style>
  @page { size: {{ $pageMm }} auto; margin: 0; }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; background: #fff; }
  body { width: {{ $pageMm }}; color: #000; font-family: "JetBrains Mono", ui-monospace, Menlo, Consolas, monospace; }
  .slip { width: 100%; margin: 0; padding: 4mm 3mm 2mm; font-size: 11px; line-height: 1.45; overflow: hidden; }
  .slip + .slip { page-break-before: always; }
  .slip * { max-width: 100%; }
  .slip img { max-width: 100%; height: auto; }
  .ctr { text-align: center; }
  .hr { border: 0; border-top: 1px dashed #000; margin: 6px 0; }
  .shop { font-size: 14px; font-weight: 700; letter-spacing: .04em; }
  .logo { max-width: 100%; max-height: {{ $logoMax }}; display: block; margin: 0 auto 4px; }
  .lbl { font-size: 10px; letter-spacing: .16em; }
  .type { font-size: 20px; font-weight: 700; letter-spacing: .04em; text-align: center; margin: 2px 0; }
  .when { text-align: center; font-size: 13px; font-weight: 700; }
  .addr { font-size: 13px; font-weight: 700; word-break: break-word; }
  table { width: 100%; border-collapse: collapse; table-layout: fixed; }
  td { padding: 2px 0; font-size: 11px; vertical-align: top; word-break: break-word; overflow-wrap: anywhere; }
  td.r { text-align: right; }
  .assets { font-size: 11px; }
  .count { font-size: 16px; font-weight: 700; }
  .sig { border-top: 1px solid #000; margin-top: 14px; padding-top: 3px; font-size: 10px; }
  .note { font-size: 10px; }
  @media screen {
    body { width: {{ $pageMm }}; margin: 24px auto; box-shadow: 0 0 0 1px #ddd; }
    .printbar { position: fixed; top: 0; left: 0; right: 0; background: #111; color: #fff;
      font-family: system-ui, sans-serif; font-size: 13px; padding: 10px 16px; display: flex;
      gap: 12px; align-items: center; justify-content: center; }
    .printbar button { background: #BEF264; color: #0a0a0a; border: 0; font-weight: 600;
      padding: 7px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; }
    body { margin-top: 64px; }
  }
  @media print { .printbar { display: none; } }
  @if($embed ?? false) @media screen { body { margin: 0 auto !important; box-shadow: none !important; } } @endif
</style>
</head>
<body>

@unless($embed ?? false)
<div class="printbar">
  <span>Run slips · {{ $dateLabel }} ({{ count($slips) }} {{ \Illuminate\Support\Str::plural('stop', count($slips)) }})</span>
  <button onclick="window.print()">Print</button>
</div>
@endunless

@forelse($slips as $slip)
  <div class="slip">

    <div class="ctr" style="border-bottom:1px dashed #000;padding-bottom:6px;margin-bottom:6px;">
      @if($logoUrl)
        <img class="logo" src="{{ $logoUrl }}" alt="{{ $tenant->name }}">
      @else
        <div class="shop">{{ strtoupper($tenant->name ?? 'SHOP') }}</div>
      @endif
      @if($tenant->phone ?? null)<div style="font-size:10px;">{{ $tenant->phone }}</div>@endif
      @if($headerText)<div style="font-size:10px;">{!! nl2br(e($headerText)) !!}</div>@endif{{-- MARKER-PATCH-330 --}}
    </div>

    <div class="type">{{ $slip['type'] }}</div>
    <div class="when">{{ $slip['time'] }}@if($slip['window_end']) – {{ $slip['window_end'] }}@endif</div>

    <hr class="hr">

    <table>
      <tr><td>CUSTOMER</td><td class="r" style="font-weight:700">{{ $slip['customer'] }}</td></tr>
      @if($slip['phone'])<tr><td>PHONE</td><td class="r">{{ $slip['phone'] }}</td></tr>@endif
      @if($slip['job'])<tr><td>JOB</td><td class="r" style="font-weight:700">{{ $slip['job'] }}</td></tr>@endif
    </table>

    @if($slip['address'])
      <div class="lbl" style="margin-top:6px;">ADDRESS</div>
      <div class="addr">{{ $slip['address'] }}</div>
    @endif

    <hr class="hr">

    <div class="lbl">ITEMS &nbsp; <span class="count">{{ $slip['asset_count'] ?: count($slip['items']) }}</span></div>
    @forelse($slip['assets'] as $a)
      <div class="assets">— {{ $a }}</div>
    @empty
      @foreach($slip['items'] as $it)
        <div class="assets">— {{ $it }}</div>
      @endforeach
    @endforelse

    @if(!empty($slip['notes']))
      <hr class="hr">
      <div class="note">NOTE: {{ \Illuminate\Support\Str::limit($slip['notes'], 140) }}</div>
    @endif

    <div class="sig">Received by / signature</div>

    @if($footerText)<div style="text-align:center;margin-top:6px;font-size:10px;">{!! nl2br(e($footerText)) !!}</div>@endif{{-- MARKER-PATCH-330 --}}

    @php $feedRows = (int) ceil(((int) ($print['feed_mm'] ?? 0)) / 3); @endphp{{-- MARKER-PATCH-327 --}}
    @if($feedRows > 0)<div aria-hidden="true" style="line-height:3mm;font-size:9px;color:#000">{!! str_repeat('&nbsp;<br>', $feedRows) !!}</div>@endif
  </div>
@empty
  <div class="slip">
    <div class="ctr" style="padding:20px 0;">
      <div class="shop">{{ strtoupper($tenant->name ?? 'SHOP') }}</div>
      <div style="margin-top:10px;font-size:12px;">No stops scheduled<br>{{ $dateLabel }}</div>
    </div>
  </div>
@endforelse

<script>
  @unless($embed ?? false) setTimeout(function () { window.print(); }, 350); @endunless
</script>
</body>
</html>
