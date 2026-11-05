{{-- MARKER-PO: the purchase order a vendor gets. Table layout for dompdf.
     Quantities and part numbers only: customer names, special-order details
     and internal notes are never printed. --}}
@php
  $interDir = public_path('fonts/inter');
  $hasInter = is_file($interDir . '/Inter-Regular.ttf');
  $loc = $po->location;
  $money = fn ($c) => $c === null ? '' : '$' . number_format($c / 100, 2);
  $isDraft = $po->isDraft();
  $rows = $po->lines->map(fn ($l) => ['l' => $l, 'q' => $isDraft || $po->status === 'placed' ? $l->qty_ordered - $l->qty_cancelled : $l->owed()])
    ->filter(fn ($r) => $r['q'] > 0 || ! $isDraft);
  $total = $rows->sum(fn ($r) => $r['q'] * (int) $r['l']->unit_cost_cents);
  $addr = fn ($x) => array_filter([$x?->address_line_1, $x?->address_line_2, trim(($x?->city ? $x->city . ', ' : '') . ($x?->state ?? '') . ' ' . ($x?->postal_code ?? ''))]);
@endphp
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  @if($hasInter)
  @font-face { font-family:'Inter'; font-weight:400; src:url("{{ $interDir }}/Inter-Regular.ttf") format("truetype"); }
  @font-face { font-family:'Inter'; font-weight:600; src:url("{{ $interDir }}/Inter-SemiBold.ttf") format("truetype"); }
  @endif
  * { margin:0; padding:0; }
  @page { margin:44px 48px; }
  body { font-family:{{ $hasInter ? "'Inter'," : '' }} 'DejaVu Sans', sans-serif; color:#111; font-size:11.5px; line-height:1.5; }
  table { width:100%; border-collapse:collapse; }
  .shop { font-size:17px; font-weight:600; }
  .title { font-size:19px; letter-spacing:.02em; }
  .l { font-size:9px; text-transform:uppercase; letter-spacing:.08em; color:#777; margin-bottom:2px; }
  .r { text-align:right; }
  .head td { vertical-align:top; padding-bottom:14px; border-bottom:1px solid #ddd; }
  .three td { vertical-align:top; padding:16px 12px 18px 0; width:33%; }
  .lines th { font-size:9px; text-transform:uppercase; letter-spacing:.06em; color:#777; text-align:left; border-bottom:1px solid #ccc; padding:6px 4px; }
  .lines td { padding:7px 4px; border-bottom:1px solid #eee; }
  .lines th.r, .lines td.r { text-align:right; }
  .rev { background:#fff6db; border:1px solid #f0d58a; padding:8px 10px; margin-bottom:14px; }
  .notes { margin-top:20px; }
  .foot { margin-top:26px; font-size:9.5px; color:#999; }
</style>
</head>
<body>
  @if($revised)
    <div class="rev"><b>Revised purchase order.</b> {{ $po->changes_note ?: 'This replaces the copy sent earlier.' }}</div>
  @endif
  <table class="head"><tr>
    <td>
      <div class="shop">{{ $tenant->name }}</div>
      @foreach($addr($loc) as $a)<div>{{ $a }}</div>@endforeach
      @if($loc?->phone || $loc?->email)<div>{{ collect([$loc?->email, $loc?->phone])->filter()->implode(' · ') }}</div>@endif
    </td>
    <td class="r">
      <div class="title">{{ $isDraft ? 'Purchase order (draft)' : 'Purchase order' }}</div>
      <div><b>{{ $po->po_number }}</b></div>
      <div>{{ ($po->placed_at ?? now())->format('M j, Y') }}</div>
    </td>
  </tr></table>

  <table class="three"><tr>
    <td>
      <div class="l">Vendor</div>
      <b>{{ $po->vendor?->name }}</b>
      @if($po->vendor?->account_number)<div>Account {{ $po->vendor->account_number }}</div>@endif
      @if($po->vendor?->contact_email)<div>{{ $po->vendor->contact_email }}</div>@endif
    </td>
    <td>
      <div class="l">Ship to</div>
      <b>{{ $tenant->name }}{{ $loc ? ' — ' . $loc->name : '' }}</b>
      @foreach($addr($loc) as $a)<div>{{ $a }}</div>@endforeach
    </td>
    <td>
      @if($po->expected_date)<div class="l">Expected</div><b>{{ $po->expected_date->format('M j, Y') }}</b>@endif
      @if($po->vendor_reference)<div class="l" style="margin-top:8px">Your reference</div>{{ $po->vendor_reference }}@endif
    </td>
  </tr></table>

  <table class="lines">
    <thead><tr><th>Part #</th><th>Description</th><th class="r">Qty</th><th class="r">Unit</th><th class="r">Total</th></tr></thead>
    <tbody>
      @foreach($rows as $r)
        <tr>
          <td>{{ $r['l']->vendor_sku ?: '—' }}</td>
          <td>{{ $r['l']->name_snapshot }}@if($r['q'] === 0) <i>(cancelled)</i>@endif</td>
          <td class="r">{{ $r['q'] }}</td>
          <td class="r">{{ $money($r['l']->unit_cost_cents) }}</td>
          <td class="r">{{ $r['l']->unit_cost_cents !== null ? $money($r['q'] * $r['l']->unit_cost_cents) : '' }}</td>
        </tr>
      @endforeach
    </tbody>
    <tfoot><tr><td colspan="4" class="r" style="padding-top:10px;border:0">Total</td><td class="r" style="padding-top:10px;border:0"><b>{{ $money($total) }}</b></td></tr></tfoot>
  </table>

  @if($po->notes_vendor)
    <div class="notes"><div class="l">Notes</div>{!! nl2br(e($po->notes_vendor)) !!}</div>
  @endif
  <div class="foot">Please quote {{ $po->po_number }} on the packing slip and invoice.</div>
</body>
</html>
