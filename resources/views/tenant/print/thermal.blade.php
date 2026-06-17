{{-- MARKER-PATCH-336 — single data-driven 80/58mm thermal renderer.
     Receives the print identity ($identity), the section model ($doc), and
     $embed. Renders a page shell (header/footer/feed from identity) and walks
     each slip's ordered sections by type. It never branches on document type —
     a tag, receipt, or invoice differ only in the sections the builder emits. --}}
@php
  $pageMm    = ($identity['paper'] ?? '80mm') === '58mm' ? '46mm' : '70mm';
  $logoMax   = ['small'=>'12mm','medium'=>'18mm','large'=>'26mm','xl'=>'34mm'][$identity['logo_size'] ?? 'medium'] ?? '18mm';
  $logoUrl   = !empty($identity['logo_path']) ? asset('storage/' . ltrim($identity['logo_path'], '/')) : null;
  $headerText = trim((string) ($identity['header_text'] ?? ''));
  $footerText = trim((string) ($identity['footer_text'] ?? ''));
  $feedRows  = (int) ceil(((int) ($identity['feed_mm'] ?? 0)) / 3);
  $showHeader = $showHeader ?? true;
  $sym       = $tenant->currency_symbol ?: '$';
  $m         = fn($c) => $sym . number_format(((int) $c) / 100, 2);
  $qfmt      = fn($q) => rtrim(rtrim(number_format((float) $q, 3), '0'), '.');
  $title     = ($doc['doc_type'] ?? 'doc') . ' ' . ($doc['number'] ?? '');
  $hasQr     = collect($doc['slips'] ?? [])->contains(fn($s) => collect($s['sections'])->contains(fn($x) => ($x['type'] ?? '') === 'job' && !empty($x['qr'])));
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $title }}</title>
<style>
  @page { size: {{ $pageMm }} auto; margin: 0; }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; background: #fff; }
  body { width: {{ $pageMm }}; color: #000; font-family: "JetBrains Mono", ui-monospace, Menlo, Consolas, monospace; }
  .slip { width: 100%; margin: 0; padding: 4mm 3mm 2mm; font-size: 11px; line-height: 1.45; overflow: hidden; }
  .slip * { max-width: 100%; }
  .slip img { max-width: 100%; height: auto; }
  .slip + .slip { page-break-before: always; }
  .ctr { text-align: center; }
  .hr  { border: 0; border-top: 1px dashed #000; margin: 6px 0; }
  .hr2 { border: 0; border-top: 2px solid #000; margin: 6px 0; }
  .shop { font-size: 14px; font-weight: 700; letter-spacing: .04em; }
  .logo { max-width: 100%; max-height: {{ $logoMax }}; display: block; margin: 0 auto 4px; }
  .lbl { font-size: 10px; letter-spacing: .16em; }
  .meta { font-size: 10px; }
  .jobrow { display: flex; align-items: center; gap: 8px; margin: 4px 0; }
  .jobrow > div:first-child { min-width: 0; flex: 1; }
  .jobnum { font-size: 24px; font-weight: 700; line-height: 1; }
  table { width: 100%; border-collapse: collapse; table-layout: fixed; }
  td { padding: 2px 0; font-size: 11px; vertical-align: top; word-break: break-word; overflow-wrap: anywhere; }
  td.r { text-align: right; white-space: nowrap; }
  .kv td.r { font-weight: 700; }
  .tot td.r { font-weight: 700; }
  .grand td { font-size: 14px; font-weight: 700; padding-top: 3px; }
  .svc { font-size: 11px; }
  .note { font-size: 10px; }
  .led td { font-size: 10px; }
  .led .h td { letter-spacing: .12em; }
  .assethdr td { font-weight: 700; padding-top: 4px; }
  .foot { text-align: center; font-size: 10px; margin-top: 8px; }
  .stub { border: 1.5px solid #000; padding: 6px 8px; margin-top: 2px; }
  .stub .sj { font-size: 19px; font-weight: 700; }
  .scissors { text-align: center; font-size: 10px; letter-spacing: .14em; margin: 8px 0 4px; }
  .qr { width: 18mm; height: 18mm; flex-shrink: 0; }
  .qr img, .qr canvas { width: 100% !important; height: 100% !important; }
  .qrfallback { font-size: 8px; word-break: break-all; }
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
  <span>{{ ucfirst($doc['doc_type'] ?? 'Document') }} {{ $doc['number'] ?? '' }} ({{ count($doc['slips'] ?? []) }} {{ \Illuminate\Support\Str::plural('slip', count($doc['slips'] ?? [])) }})</span>
  <button onclick="window.print()">Print</button>
</div>
@endunless

@foreach(($doc['slips'] ?? []) as $slip)
  <div class="slip">

    @if($showHeader)
      <div class="ctr" style="border-bottom:1px dashed #000;padding-bottom:6px;margin-bottom:6px;">
        @if($logoUrl)
          <img class="logo" src="{{ $logoUrl }}" alt="{{ $tenant->name }}">
        @else
          <div class="shop">{{ strtoupper($tenant->name ?? 'SHOP') }}</div>
        @endif
        @if($tenant->phone ?? null)<div class="meta">{{ $tenant->phone }}</div>@endif
        @if($headerText)<div class="meta">{!! nl2br(e($headerText)) !!}</div>@endif
      </div>
    @endif

    @foreach($slip['sections'] as $s)
      @switch($s['type'])

        @case('doc_label')
          <div class="ctr lbl">{{ $s['text'] }}</div>
          @break

        @case('job')
          <div class="jobrow">
            <div>
              <div class="lbl">{{ $s['label'] ?? 'JOB' }}</div>
              <div class="jobnum">{{ $s['value'] }}</div>
            </div>
            @if(!empty($s['qr']))<div class="qr" data-qr="{{ $s['qr'] }}"></div>@endif
          </div>
          @break

        @case('meta')
          <table class="kv" style="margin-top:4px">
            @foreach($s['rows'] as $row)
              <tr><td>{{ $row[0] }}</td><td class="r" style="white-space:normal;font-weight:400">{{ $row[1] }}</td></tr>
            @endforeach
          </table>
          @break

        @case('line_items')
          <hr class="hr">
          <table>
            @foreach($s['groups'] as $g)
              @if(!empty($g['name']))
                <tr class="assethdr"><td colspan="2">{{ $g['name'] }}</td></tr>
              @endif
              @foreach($g['lines'] as $ln)
                <tr>
                  <td>{{ isset($ln['qty']) ? $qfmt($ln['qty']).' × '.$ln['name'] : (!empty($ln['add']) ? '+ '.$ln['name'] : $ln['name']) }}</td>
                  @if($s['show_prices'])<td class="r">{{ $m($ln['cents']) }}</td>@endif
                </tr>
              @endforeach
            @endforeach
          </table>
          @break

        @case('services')
          <hr class="hr">
          <div class="lbl" style="margin-bottom:2px;">{{ $s['label'] ?? 'SERVICE' }}</div>
          @foreach($s['items'] as $svc)
            <div class="svc">— {{ $svc }}</div>
          @endforeach
          @break

        @case('totals')
          <hr class="hr">
          <table class="tot">
            @foreach($s['rows'] as $row)
              <tr><td>{{ $row[0] }}</td><td class="r">{{ !empty($row[2]) ? '−' : '' }}{{ $m($row[1]) }}</td></tr>
            @endforeach
          </table>
          <hr class="hr2">
          <table class="grand"><tr><td>{{ $s['grand'][0] }}</td><td class="r">{{ $m($s['grand'][1]) }}</td></tr></table>
          @break

        @case('ledger')
          <hr class="hr">
          <div class="lbl" style="margin-bottom:2px;">PAYMENTS</div>
          <table class="led">
            <tr class="h"><td>METHOD</td><td class="r">AMOUNT</td><td class="r">BALANCE</td></tr>
            @foreach($s['rows'] as $row)
              <tr>
                <td>{{ $row['label'] }}{{ !empty($row['at']) ? ' · '.$row['at'] : '' }}</td>
                <td class="r">{{ !empty($row['refund']) ? '−' : '' }}{{ $m(abs($row['cents'])) }}</td>
                <td class="r">{{ $m($row['balance']) }}</td>
              </tr>
            @endforeach
          </table>
          @break

        @case('notes')
          <hr class="hr">
          @foreach($s['items'] as $n)
            <div class="note">{{ $n['customer'] ? 'NOTE' : 'STAFF' }}: {{ \Illuminate\Support\Str::limit($n['content'], 160) }}</div>
          @endforeach
          @break

        @case('stub')
          <div class="scissors">&#9986; &mdash; &mdash; &mdash; &mdash; &mdash; &mdash; &mdash; &mdash;</div>
          <div class="stub">
            <div class="ctr lbl">CUSTOMER CLAIM STUB</div>
            <div style="display:flex;justify-content:space-between;align-items:baseline;margin-top:4px;">
              <span class="sj">{{ $s['job'] }}</span>
              @if(!empty($s['promised']))<span style="font-size:11px;text-align:right;">Ready<br>{{ $s['promised'] }}</span>@endif
            </div>
            @if(!empty($s['name']))<div style="font-size:11px;margin-top:2px;">{{ $s['name'] }}</div>@endif
          </div>
          @break

      @endswitch
    @endforeach

    <div class="foot">
      @if($footerText){!! nl2br(e($footerText)) !!}@else Thank you!<br>{{ $tenant->name }}@endif
    </div>

    @if($feedRows > 0)<div aria-hidden="true" style="line-height:3mm;font-size:9px;color:#000">{!! str_repeat('&nbsp;<br>', $feedRows) !!}</div>@endif
  </div>
@endforeach

@if($hasQr)
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
@endif
<script>
  (function () {
    @if($hasQr)
    var ok = (typeof QRCode !== 'undefined');
    document.querySelectorAll('.qr').forEach(function (el) {
      var url = el.getAttribute('data-qr');
      if (ok) {
        try { new QRCode(el, { text: url, width: 76, height: 76, correctLevel: QRCode.CorrectLevel.M }); return; }
        catch (e) {}
      }
      el.classList.add('qrfallback');
      el.textContent = url;
    });
    @endif
    @unless($embed ?? false) setTimeout(function () { window.print(); }, 350); @endunless
  })();
</script>
</body>
</html>
