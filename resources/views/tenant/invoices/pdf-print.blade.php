{{-- MARKER-PATCH-204 — Print-style invoice. Table-based for dompdf. Inter w/ DejaVu fallback. --}}
@php
  $isPaid   = $terms === 'paid';
  $acc      = $tenant->accent_color ?? '#D94F1E';
  $interDir = public_path('fonts/inter');
  $hasInter = is_file($interDir . '/Inter-Regular.ttf');
@endphp
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  @if($hasInter)
  @font-face { font-family:'Inter'; font-weight:400; src:url("{{ $interDir }}/Inter-Regular.ttf")  format("truetype"); }
  @font-face { font-family:'Inter'; font-weight:500; src:url("{{ $interDir }}/Inter-Medium.ttf")   format("truetype"); }
  @font-face { font-family:'Inter'; font-weight:600; src:url("{{ $interDir }}/Inter-SemiBold.ttf") format("truetype"); }
  @font-face { font-family:'Inter'; font-weight:700; src:url("{{ $interDir }}/Inter-Bold.ttf")     format("truetype"); }
  @endif
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:{{ $hasInter ? "'Inter'," : '' }} 'DejaVu Sans', sans-serif; color:#111; font-size:13px; line-height:1.5; }
  .wrap { padding:54px 56px; }
  .acc { height:3px; background:{{ $acc }}; }
  .lbl { font-size:10px; text-transform:uppercase; letter-spacing:1px; color:#8a8a8a; font-weight:700; }
  table { width:100%; border-collapse:collapse; }
  .num { }
  /* header */
  .hd td { vertical-align:top; padding-bottom:22px; border-bottom:1px solid #111; }
  .shop { font-size:19px; font-weight:700; letter-spacing:-.3px; }
  .shopm { font-size:11px; color:#666; line-height:1.7; padding-top:6px; }
  .inv h1 { font-size:23px; font-weight:700; letter-spacing:1px; text-transform:uppercase; text-align:right; }
  .invm { font-size:11px; color:#666; line-height:1.7; text-align:right; padding-top:8px; }
  .invm b { color:#111; }
  /* parties */
  .parties td { padding:22px 0 24px; vertical-align:top; }
  .pnm { font-size:13px; font-weight:600; padding-top:6px; }
  .pdt { font-size:11.5px; color:#666; line-height:1.6; padding-top:2px; }
  /* asset group */
  .asset-h td { border-bottom:1.5px solid #111; padding:14px 0 6px; }
  .an { font-size:12.5px; font-weight:700; }
  .as { font-size:10px; text-transform:uppercase; letter-spacing:.6px; color:#8a8a8a; font-weight:700; text-align:right; }
  .li td { border-bottom:1px solid #f0f0ee; padding:8px 0; font-size:13px; }
  .li .amt { text-align:right; white-space:nowrap; font-weight:500; }
  .li .nm { font-weight:500; }
  .li.add .nm { font-weight:400; color:#444; padding-left:16px; font-size:12.5px; }
  .li.add .amt { font-weight:400; color:#444; }
  .asub td { text-align:right; font-size:11px; color:#8a8a8a; font-weight:600; padding:7px 0 18px; }
  .asub b { color:#444; }
  /* note */
  .note { background:#f8f8f6; border:1px solid #e8e8e4; border-left:3px solid {{ $acc }}; padding:13px 15px; margin-top:18px; }
  .note .nl { font-size:10px; text-transform:uppercase; letter-spacing:.8px; color:#8a8a8a; font-weight:700; padding-bottom:5px; }
  .note .nt { font-size:12.5px; color:#444; line-height:1.6; }
  /* totals */
  .foot td { vertical-align:top; padding-top:24px; }
  .pay .lbl { padding-bottom:8px; }
  .pay .pln { font-size:11.5px; color:#666; line-height:1.7; }
  .pay .pln b { color:#111; }
  .tot { width:280px; }
  .tr td { font-size:13px; padding:6px 0; color:#444; }
  .tr td.v { text-align:right; color:#111; }
  .tr.first td { border-top:1px solid #f0f0ee; }
  .grand td { border-top:2px solid #111; padding-top:13px; font-size:15px; font-weight:700; }
  .grand td.v { text-align:right; font-size:20px; }
  .terms { margin-top:42px; padding-top:16px; border-top:1px solid #f0f0ee; font-size:10.5px; color:#8a8a8a; line-height:1.7; }
</style>
</head>
<body>
<div class="acc"></div>
<div class="wrap">

  <table class="hd">
    <tr>
      <td>
        <div class="shop">{{ $tenant->name }}</div>
        <div class="shopm">{!! nl2br(e($tenant->address)) !!}@if($tenant->phone)<br>{{ $tenant->phone }}@endif @if($tenant->email)· {{ $tenant->email }}@endif</div>
      </td>
      <td class="inv">
        <h1>Invoice</h1>
        <div class="invm">
          Work order <b>{{ $number }}</b><br>
          Issued <b>{{ now()->format('M j, Y') }}</b><br>
          @if($isPaid) Status <b>Paid in full</b>
          @else Due <b>{{ $terms === 'due_now' ? 'now' : 'on completion' }}</b>
          @endif
        </div>
      </td>
    </tr>
  </table>

  <table class="parties">
    <tr>
      <td style="width:50%">
        <div class="lbl">Bill to</div>
        <div class="pnm">{{ $customer['name'] }}</div>
        <div class="pdt">@if($customer['email']){{ $customer['email'] }}@endif @if($customer['phone'])· {{ $customer['phone'] }}@endif</div>
      </td>
      <td style="width:50%">
        <div class="lbl">Service</div>
        <div class="pnm">{{ count($assets) }} {{ \Illuminate\Support\Str::plural('asset', count($assets)) }}</div>
        <div class="pdt">Completed {{ now()->format('M j, Y') }}</div>
      </td>
    </tr>
  </table>

  {{-- asset groups --}}
  @foreach($assets as $a)
    <table class="asset-h">
      <tr><td><span class="an">{{ $a['name'] }}</span></td><td class="as">Asset · {{ format_money($a['subtotal']) }}</td></tr>
    </table>
    <table>
      @foreach($a['lines'] as $l)
        <tr class="li {{ $l['add'] ? 'add' : '' }}"><td class="nm">{{ $l['name'] }}</td><td class="amt">{{ format_money($l['cents']) }}</td></tr>
      @endforeach
    </table>
    <table><tr class="asub"><td>Asset subtotal&nbsp;&nbsp;<b>{{ format_money($a['subtotal']) }}</b></td></tr></table>
  @endforeach

  {{-- loose / shop items --}}
  @if(count($loose))
    <table class="asset-h"><tr><td><span class="an">Shop &amp; parts</span></td><td class="as"></td></tr></table>
    <table>
      @foreach($loose as $l)
        <tr class="li {{ $l['add'] ? 'add' : '' }}"><td class="nm">{{ $l['name'] }}</td><td class="amt">{{ format_money($l['cents']) }}</td></tr>
      @endforeach
    </table>
  @endif

  {{-- shop note --}}
  @if(trim((string) $note) !== '')
    <div class="note"><div class="nl">Note from the shop</div><div class="nt">{!! nl2br(e($note)) !!}</div></div>
  @endif

  {{-- payment + totals --}}
  <table class="foot">
    <tr>
      <td class="pay" style="width:55%; padding-right:30px">
        <div class="lbl">Payment</div>
        @if($isPaid)
          <div class="pln">Paid in full. Thank you — no balance remaining.</div>
        @else
          @if($paid > 0)<div class="pln">Deposit paid — <b>{{ format_money($paid) }}</b>.</div>@endif
          <div class="pln">{{ $terms === 'due_now' ? 'Balance due now.' : 'Balance due at pickup, or pay online — link in your emailed receipt.' }}@if($tenant->phone)<br>Questions? {{ $tenant->phone }}@endif</div>
        @endif
      </td>
      <td>
        <table class="tot">
          <tr class="tr first"><td>Subtotal</td><td class="v">{{ format_money($subtotal) }}</td></tr>
          <tr class="tr"><td>Tax</td><td class="v">{{ format_money($tax) }}</td></tr>
          @if(!$isPaid && $paid > 0)<tr class="tr"><td>Deposit paid</td><td class="v">&minus;{{ format_money($paid) }}</td></tr>@endif
          <tr class="grand">
            <td>{{ $isPaid ? 'Total paid' : 'Balance due' }}</td>
            <td class="v">{{ format_money($isPaid ? $total : $balance) }}</td>
          </tr>
        </table>
      </td>
    </tr>
  </table>

  @if(trim((string) ($tenant->invoice_footer_terms ?? '')) !== '')
    <div class="terms">{!! nl2br(e($tenant->invoice_footer_terms)) !!}</div>
  @endif
</div>
</body>
</html>
