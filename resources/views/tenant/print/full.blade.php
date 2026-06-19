{{-- MARKER-PATCH-341 — plain full-page renderer. Same section model as the
     thermal view, laid out for a letter page on any office printer: black on
     white, larger type, real tables. Receives $identity, $doc, $embed. --}}
@php
  $logoUrl   = !empty($identity['logo_path']) ? asset('storage/' . ltrim($identity['logo_path'], '/')) : null;
  $headerText = trim((string) ($identity['header_text'] ?? ''));
  $footerText = trim((string) ($identity['footer_text'] ?? ''));
  $showHeader = $showHeader ?? true;
  $sym       = $tenant->currency_symbol ?: '$';
  $m         = fn($c) => $sym . number_format(((int) $c) / 100, 2);
  $qfmt      = fn($q) => rtrim(rtrim(number_format((float) $q, 3), '0'), '.');
  $title     = ($doc['doc_type'] ?? 'doc') . ' ' . ($doc['number'] ?? '');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $title }}</title>
<style>
  @page { size: letter; margin: 14mm; }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; background: #fff; color: #16160f; font-family: Inter, system-ui, Arial, sans-serif; }
  .page { max-width: 720px; margin: 0 auto; padding: 24px; font-size: 13px; line-height: 1.55; }
  .top { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #16160f; padding-bottom: 14px; margin-bottom: 18px; }
  .top .logo { max-height: 44px; }
  .top .shop { font-size: 22px; font-weight: 700; }
  .top .meta { font-size: 11px; color: #555; line-height: 1.6; }
  .doclabel { font-size: 12px; letter-spacing: .14em; text-transform: uppercase; color: #888; }
  .kv { margin: 4px 0 14px; }
  .kv span { display: inline-block; min-width: 90px; color: #777; }
  h3 { font-size: 11px; letter-spacing: .1em; text-transform: uppercase; color: #888; margin: 18px 0 6px; }
  table { width: 100%; border-collapse: collapse; }
  td, th { padding: 6px 0; text-align: left; vertical-align: top; }
  td.r, th.r { text-align: right; white-space: nowrap; }
  tbody tr { border-bottom: 1px solid #eee; }
  .assethdr td { font-weight: 700; padding-top: 12px; border: 0; }
  .svc { padding: 3px 0; }
  .totals { margin-top: 10px; margin-left: auto; width: 50%; }
  .totals td { padding: 3px 0; }
  .grand td { font-size: 16px; font-weight: 700; border-top: 2px solid #16160f; padding-top: 8px; }
  .led th { border-bottom: 1px solid #ccc; font-size: 11px; color: #777; letter-spacing: .06em; }
  .note { background: #f5f5f0; border-radius: 6px; padding: 10px 12px; font-size: 12px; color: #444; margin-top: 8px; }
  .note b { color: #16160f; }
  .foot { margin-top: 26px; padding-top: 12px; border-top: 1px solid #ddd; text-align: center; font-size: 11px; color: #666; }
  .docblock + .docblock { page-break-before: always; }
  @media screen {
    body { background: #f3f3f0; }
    .page { background: #fff; margin: 24px auto; box-shadow: 0 2px 18px rgba(0,0,0,.12); }
    .printbar { position: fixed; top: 0; left: 0; right: 0; background: #111; color: #fff; font-size: 13px;
      padding: 10px 16px; display: flex; gap: 12px; align-items: center; justify-content: center; }
    .printbar button { background: #BEF264; color: #0a0a0a; border: 0; font-weight: 600; padding: 7px 16px; border-radius: 6px; cursor: pointer; }
  }
  @media print { .printbar { display: none; } body { background: #fff; } .page { box-shadow: none; margin: 0; } }
</style>
</head>
<body>

@unless($embed ?? false)
<div class="printbar">
  <span>{{ ucfirst($doc['doc_type'] ?? 'Document') }} {{ $doc['number'] ?? '' }}</span>
  <button onclick="window.print()">Print</button>
</div>
@endunless

@foreach(($doc['slips'] ?? []) as $slip)
<div class="page docblock">

  @if($showHeader)
  <div class="top">
    <div>
      @if($logoUrl)<img class="logo" src="{{ $logoUrl }}" alt="{{ $tenant->name }}">@else<div class="shop">{{ $tenant->name }}</div>@endif
      @if($headerText)<div class="meta">{!! nl2br(e($headerText)) !!}</div>@endif
    </div>
    <div class="meta" style="text-align:right">
      @if($tenant->phone ?? null){{ $tenant->phone }}<br>@endif
      {{ $doc['number'] ?? '' }}
    </div>
  </div>
  @endif

  @foreach($slip['sections'] as $s)
    @switch($s['type'])

      @case('doc_label')
        <div class="doclabel">{{ $s['text'] }}</div>
        @break

      @case('job')
        <div style="font-size:26px;font-weight:700;margin:2px 0 6px">{{ $s['value'] }}</div>
        @break

      @case('meta')
        <div class="kv">
          @foreach($s['rows'] as $row)<div><span>{{ $row[0] }}</span>{{ $row[1] }}</div>@endforeach
        </div>
        @break

      @case('line_items')
        <h3>Items</h3>
        <table>
          <tbody>
          @foreach($s['groups'] as $g)
            @if(!empty($g['name']))<tr class="assethdr"><td colspan="2">{{ $g['name'] }}</td></tr>@endif
            @foreach($g['lines'] as $ln)
              <tr>
                <td>{{ (isset($ln['qty']) && $ln['qty'] > 1) ? $qfmt($ln['qty']).' × '.$ln['name'] : (!empty($ln['add']) ? '+ '.$ln['name'] : $ln['name']) }}</td>
                @if($s['show_prices'])<td class="r">{{ $m($ln['cents']) }}</td>@endif
              </tr>
            @endforeach
          @endforeach
          </tbody>
        </table>
        @break

      @case('services')
        <h3>{{ $s['label'] ?? 'Service' }}</h3>
        @foreach($s['items'] as $svc)<div class="svc">— {{ $svc }}</div>@endforeach
        @break

      @case('totals')
        <table class="totals">
          @foreach($s['rows'] as $row)
            <tr><td>{{ $row[0] }}</td><td class="r">{{ !empty($row[2]) ? '−' : '' }}{{ $m($row[1]) }}</td></tr>
          @endforeach
          <tr class="grand"><td>{{ $s['grand'][0] }}</td><td class="r">{{ $m($s['grand'][1]) }}</td></tr>
        </table>
        @break

      @case('ledger')
        <h3>Payments</h3>
        <table class="led">
          <thead><tr><th>Method</th><th class="r">Amount</th><th class="r">Balance</th></tr></thead>
          <tbody>
          @foreach($s['rows'] as $row)
            <tr>
              <td>{{ $row['label'] }}{{ !empty($row['at']) ? ' · '.$row['at'] : '' }}</td>
              <td class="r">{{ !empty($row['refund']) ? '−' : '' }}{{ $m(abs($row['cents'])) }}</td>
              <td class="r">{{ $m($row['balance']) }}</td>
            </tr>
          @endforeach
          </tbody>
        </table>
        @break

      @case('notes')
        @foreach($s['items'] as $n)
          <div class="note"><b>{{ $n['customer'] ? 'Note' : 'Staff note' }}:</b> {{ $n['content'] }}</div>
        @endforeach
        @break

      @case('stub')
        @break

    @endswitch
  @endforeach

  <div class="foot">
    @if($footerText){!! nl2br(e($footerText)) !!}@else Thank you! — {{ $tenant->name }}@endif
  </div>
</div>
@endforeach

<script>
  @unless($embed ?? false) setTimeout(function () { window.print(); }, 350); @endunless
</script>
</body>
</html>
