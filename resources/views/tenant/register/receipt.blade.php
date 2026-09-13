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
{{-- MARKER-DOC-STATE — the browser tab and the print header say the same
     thing the document does. --}}
<title>{{ ($plan ?? null) ? (($doc ?? '') === 'payment' ? 'Layaway payment' : 'Layaway agreement') : ($sale->payment_status === 'quote' ? 'Quote' : ($sale->payment_status === 'draft' ? 'Working copy' : 'Receipt')) }} {{ $sale->sale_number }}</title>
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

  {{-- MARKER-DOC-STATE — say what this document IS. Printing "RECEIPT" on a
       draft, a quote or a layaway asserts a transaction that has not
       happened. --}}
  @php
    $docLabel = match (true) {
      $sale->isRefunded()                     => 'REFUND',
      ($plan ?? null) && ($doc ?? '') === 'payment' => 'LAYAWAY PAYMENT',
      ($plan ?? null)                         => 'LAYAWAY AGREEMENT',
      $sale->payment_status === 'draft'       => 'NOT A RECEIPT',
      $sale->payment_status === 'quote'       => 'QUOTE',
      default                                 => 'RECEIPT',
    };
    $docSub = match (true) {
      ($plan ?? null) && ($doc ?? '') === 'payment' => 'Goods remain with the shop until paid and collected',
      ($plan ?? null)                         => 'Goods held for the customer — not yet sold',
      $sale->payment_status === 'draft'       => 'Working copy · nothing has been paid',
      $sale->payment_status === 'quote'       => 'An offer, not a sale',
      default                                 => null,
    };
  @endphp

  <div class="ctr lbl">{{ $docLabel }}</div>
  @if($docSub)
    <div class="ctr" style="font-size:10px;margin-top:-2px">{{ $docSub }}</div>
  @endif

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
    {{-- MARKER-DOC-DISCOUNT — whole-sale discount, separate from the sum of
         item discounts above; without it the receipt doesn't add up. --}}
    @if((int) ($sale->sale_discount_cents ?? 0) > 0)
      <tr><td>Discount</td><td class="r">&minus;{{ $m($sale->sale_discount_cents) }}</td></tr>
    @endif
    @if((int) $sale->tax_cents > 0)
      <tr><td>Tax</td><td class="r">{{ $m($sale->tax_cents) }}</td></tr>
      {{-- MARKER-BIZ-RECEIPT — an accounts-payable clerk needs to see WHY tax
           is zero, and needs the PO reference to process the invoice. --}}
      @if($sale->tax_exempt_applied)
        <tr><td colspan="2" style="font-size:11px;opacity:.7">
          Tax exempt
            @if($sale->tax_exempt_certificate) — certificate {{ $sale->tax_exempt_certificate }}@endif
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

  {{-- MARKER-DOC-STATE — the layaway money picture. A total alone tells the
       customer nothing about what they still owe or when. --}}
  @if($plan ?? null)
    <hr class="hr">
    <table>
      <tr><td>Paid to date</td><td class="r">{{ $m($layawayPaid) }}</td></tr>
      @if(($slipPayment ?? null))
        <tr><td><strong>This payment</strong></td><td class="r"><strong>{{ $m($slipPayment->amount_cents) }}</strong></td></tr>
      @endif
      <tr><td><strong>Balance</strong></td><td class="r"><strong>{{ $m($layawayBalance) }}</strong></td></tr>
      @if($plan->next_due_on && $layawayBalance > 0)
        <tr><td>Next payment</td><td class="r">{{ $m($plan->scheduled_amount_cents ?? 0) }} by {{ $plan->next_due_on->format('M j, Y') }}</td></tr>
      @endif
      @if($plan->collect_by)
        <tr><td>Collect by</td><td class="r">{{ $plan->collect_by->format('M j, Y') }}</td></tr>
      @endif
    </table>

    @if(($doc ?? '') === 'agreement')
      <hr class="hr">
      @php $pol = (array) ($plan->policy ?? []); @endphp
      <div style="font-size:10px;line-height:1.45">
        <div style="font-weight:700;margin-bottom:3px">TERMS</div>
        Goods listed above are held by {{ $tenant->name }} and remain its property until paid in
        full and collected.<br>
        Payments are due {{ $plan->frequency === 'none' ? 'at any time' : str_replace(['biweekly','weekly','monthly'], ['every two weeks','every week','monthly'], $plan->frequency) }},
        with {{ $plan->grace_days }} days' grace before a payment counts as late.<br>
        @if(($pol['cancel_refund'] ?? 'less_fee') === 'full')
          If cancelled, everything paid is refunded in full.
        @elseif(($pol['cancel_refund'] ?? '') === 'store_credit')
          If cancelled, everything paid is returned as store credit.
        @else
          If cancelled, everything paid is refunded less a restocking fee of
          {{ (int) ($pol['restock_fee_pct'] ?? 0) }}% of the value of items held.
        @endif
        <br>
        Any item not in stock is ordered for this plan; the collection date above may move if the
        supplier is late, and the shop will say so.
      </div>

      <hr class="hr">
      <div style="font-size:10px;line-height:2.4">
        Customer signature ______________________________<br>
        Date ____________________
      </div>
      <div style="font-size:8.5px;margin-top:6px;line-height:1.35">
        These terms are set by {{ $tenant->name }}. Layaway is regulated in some states; this
        document is not legal advice and has not been reviewed against the law where you trade.
      </div>
    @endif
  @endif

  @if($sale->payments && $sale->payments->count() && ! (($doc ?? '') === 'agreement'))
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
    {{-- MARKER-DOC-STATE — "Thank you!" belongs on a completed sale. On a
         quote or a working copy it reads as confirmation of something that
         has not happened. --}}
    @if($sale->payment_status === 'draft')
      Working copy — not a receipt<br>{{ $tenant->name }}
    @elseif($sale->payment_status === 'quote')
      Quote only — no payment has been taken<br>{{ $tenant->name }}
    @elseif(($plan ?? null) && ($doc ?? '') === 'agreement')
      Keep this agreement<br>{{ $tenant->name }}
    @elseif(($plan ?? null))
      Keep this slip<br>{{ $tenant->name }}
    @elseif($footerText)
      {!! nl2br(e($footerText)) !!}
    @else
      Thank you!<br>{{ $tenant->name }}
    @endif{{-- MARKER-PATCH-330 --}}
  </div>

  @php $feedRows = (int) ceil(((int) ($print['feed_mm'] ?? 0)) / 3); @endphp{{-- MARKER-PATCH-327 --}}
  @if($feedRows > 0)<div aria-hidden="true" style="line-height:3mm;font-size:9px;color:#000">{!! str_repeat('&nbsp;<br>', $feedRows) !!}</div>@endif
</div>

<script>
  @unless($embed ?? false) setTimeout(function () { window.print(); }, 300); @endunless
</script>
</body>
</html>
