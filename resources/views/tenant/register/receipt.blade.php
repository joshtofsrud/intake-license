{{-- MARKER-PATCH-319 — standalone 80mm sales receipt. Shares the work-order
     tag's print identity (logo, size, paper) and the same printable-width CSS
     so it never clips. Reads the sale off the record. Auto-prints unless embed. --}}
@php
  $pageMm   = ($print['paper'] ?? '80mm') === '58mm' ? '46mm' : '70mm';
  $logoMax  = ['small'=>'12mm','medium'=>'18mm','large'=>'26mm','xl'=>'34mm'][$print['logo_size'] ?? 'medium'] ?? '18mm';
  $logoUrl  = $print['logo_path'] ? asset('storage/' . ltrim($print['logo_path'], '/')) : null;
  $headerText = trim((string) ($print['header_text'] ?? '')); // MARKER-PATCH-330
  $footerText = trim((string) ($print['footer_text'] ?? '')); // MARKER-PATCH-330
  $feedMm   = (int) ($print['feed_mm'] ?? 0) > 0 ? ((int) $print['feed_mm']) . 'mm' : null; // MARKER-PATCH-320
  $sym      = $tenant->currency_symbol ?: '$';
  $m        = fn($c) => $sym . number_format(((int) $c) / 100, 2);
  $qfmt     = fn($q) => rtrim(rtrim(number_format((float) $q, 3), '0'), '.');
  $when     = $sale->paid_at ?? $sale->created_at;
  // MARKER-BIZ-RECEIPT — a business is billed by its business name
  $custName = $sale->customer
                ? trim($sale->customer->fullName())
                : null;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Receipt {{ $sale->sale_number }}</title>
<style>
  @page { size: {{ $pageMm }} auto; margin: 0; }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; background: #fff; }
  body { width: {{ $pageMm }}; color: #000; font-family: "JetBrains Mono", ui-monospace, Menlo, Consolas, monospace; }
  .slip { width: 100%; margin: 0; padding: 4mm 3mm 3mm; font-size: 11px; line-height: 1.45; overflow: hidden; }
  .slip * { max-width: 100%; }
  .slip img { max-width: 100%; height: auto; }
  .ctr { text-align: center; }
  .hr { border: 0; border-top: 1px dashed #000; margin: 6px 0; }
  .hr2 { border: 0; border-top: 2px solid #000; margin: 6px 0; }
  .shop { font-size: 14px; font-weight: 700; letter-spacing: .04em; }
  .logo { max-width: 100%; max-height: {{ $logoMax }}; display: block; margin: 0 auto 4px; }
  .lbl { font-size: 10px; letter-spacing: .16em; }
  .meta { font-size: 10px; }
  table { width: 100%; border-collapse: collapse; table-layout: fixed; }
  td { padding: 2px 0; font-size: 11px; vertical-align: top; word-break: break-word; overflow-wrap: anywhere; }
  td.r { text-align: right; white-space: nowrap; width: 22mm; }
  .tot td.r { font-weight: 700; }
  .grand td { font-size: 14px; font-weight: 700; padding-top: 3px; }
  .foot { text-align: center; font-size: 10px; margin-top: 8px; }
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
  <span>Receipt {{ $sale->sale_number }}</span>
  <button onclick="window.print()">Print</button>
</div>
@endunless

<div class="slip">

  <div class="ctr" style="border-bottom:1px dashed #000;padding-bottom:6px;margin-bottom:6px;">
    @if($logoUrl)
      <img class="logo" src="{{ $logoUrl }}" alt="{{ $tenant->name }}">
    @else
      <div class="shop">{{ strtoupper($tenant->name ?? 'SHOP') }}</div>
    @endif
    @if($tenant->phone ?? null)<div class="meta">{{ $tenant->phone }}</div>@endif
    @if($headerText)<div class="meta">{!! nl2br(e($headerText)) !!}</div>@endif{{-- MARKER-PATCH-330 --}}
  </div>

  <div class="ctr lbl">{{ $sale->isRefunded() ? 'REFUND' : 'RECEIPT' }}</div>

  <table style="margin-top:4px">
    <tr><td>Sale</td><td class="r" style="white-space:normal">{{ $sale->sale_number }}</td></tr>
    <tr><td>Date</td><td class="r" style="white-space:normal">{{ tlocal($when, 'M j, Y g:ia') }}</td></tr>
    @if($custName)<tr><td>Customer</td><td class="r" style="white-space:normal">{{ $custName }}</td></tr>@endif
  </table>

  <hr class="hr">

  <table>
    @foreach($sale->items as $it)
      <tr>
        <td>{{ $qfmt($it->quantity) }} &times; {{ $it->name_snapshot }}</td>
        <td class="r">{{ $m($it->line_total_cents) }}</td>
      </tr>
    @endforeach
  </table>

  <hr class="hr">

  <table class="tot">
    <tr><td>Subtotal</td><td class="r">{{ $m($sale->subtotal_cents) }}</td></tr>
    @if((int) $sale->discount_cents > 0)
      <tr><td>Discount</td><td class="r">&minus;{{ $m($sale->discount_cents) }}</td></tr>
    @endif
    @if((int) $sale->tax_cents > 0)
      <tr><td>Tax</td><td class="r">{{ $m($sale->tax_cents) }}</td></tr>
      {{-- MARKER-BIZ-RECEIPT — an accounts-payable clerk needs to see WHY tax
           is zero, and needs the PO reference to process the invoice. --}}
      @if($sale->tax_exempt_applied)
        <tr><td colspan="2" style="font-size:11px;opacity:.7">
          Tax exempt@if($sale->tax_exempt_certificate) — certificate {{ $sale->tax_exempt_certificate }}@endif
        </td></tr>
      @endif
      @if($sale->po_number)
        <tr><td colspan="2" style="font-size:11px;opacity:.7">PO {{ $sale->po_number }}</td></tr>
      @endif
    @endif
    @if((int) $sale->surcharge_cents > 0)
      <tr><td>Surcharge</td><td class="r">{{ $m($sale->surcharge_cents) }}</td></tr>
    @endif
    @if((int) $sale->tip_cents > 0)
      <tr><td>Tip</td><td class="r">{{ $m($sale->tip_cents) }}</td></tr>
    @endif
  </table>

  <hr class="hr2">
  <table class="grand"><tr><td>TOTAL</td><td class="r">{{ $m($sale->total_cents) }}</td></tr></table>

  @if($sale->payments && $sale->payments->count())
    <hr class="hr">
    <table>
      @foreach($sale->payments as $p)
        <tr>
          <td>{{ method_exists($p, 'methodLabel') ? $p->methodLabel() : ucfirst($p->method ?? 'Payment') }}</td>
          <td class="r">{{ $m($p->amount_cents) }}</td>
        </tr>
      @endforeach
    </table>
  @endif

  <div class="foot">
    @if($footerText){!! nl2br(e($footerText)) !!}@else Thank you!<br>{{ $tenant->name }}@endif{{-- MARKER-PATCH-330 --}}
  </div>

  @php $feedRows = (int) ceil(((int) ($print['feed_mm'] ?? 0)) / 3); @endphp{{-- MARKER-PATCH-327 --}}
  @if($feedRows > 0)<div aria-hidden="true" style="line-height:3mm;font-size:9px;color:#000">{!! str_repeat('&nbsp;<br>', $feedRows) !!}</div>@endif
</div>

<script>
  @unless($embed ?? false) setTimeout(function () { window.print(); }, 300); @endunless
</script>
</body>
</html>
