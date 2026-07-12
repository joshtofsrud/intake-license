<!DOCTYPE html>
{{-- MARKER-PATCH-633 — printable end-of-day sheet. --}}
<html lang="en">
<head>
<meta charset="utf-8">
<title>End of day — {{ $day->format('M j, Y') }} — {{ $tenant->name }}</title>
<style>
  body { font-family: -apple-system, 'Segoe UI', sans-serif; color:#111; max-width:640px; margin:32px auto; font-size:13px; }
  h1 { font-size:18px; margin:0 0 2px; } .sub { color:#666; font-size:12px; margin-bottom:20px; }
  table { width:100%; border-collapse:collapse; margin-bottom:18px; }
  th { text-align:left; font-size:10px; text-transform:uppercase; letter-spacing:.05em; color:#888; padding:6px 8px; border-bottom:1.5px solid #ddd; }
  td { padding:7px 8px; border-bottom:1px solid #eee; font-variant-numeric:tabular-nums; }
  td.r, th.r { text-align:right; }
  .tot td { font-weight:800; border-top:1.5px solid #999; }
  .sig { margin-top:36px; display:flex; gap:40px; }
  .sig div { flex:1; border-top:1px solid #999; padding-top:5px; font-size:11px; color:#666; }
  @media print { body { margin:0; } }
</style>
</head>
<body onload="window.print()">
  @php $money = fn ($c) => '$' . number_format($c / 100, 2); @endphp
  <h1>{{ $tenant->name }} — End of day</h1>
  <div class="sub">{{ $day->format('l, F j, Y') }}
    @if($drawer?->closed_at) · closed {{ tlocal($drawer->closed_at, 'g:ia') }}@endif
  </div>

  <table>
    <tr><td>Gross sales ({{ $n['sale_count'] }})</td><td class="r">{{ $money($n['gross']) }}</td></tr>
    <tr><td>Collected</td><td class="r">{{ $money($n['collected']) }}</td></tr>
    <tr><td>Refunds</td><td class="r">{{ $n['refunds'] > 0 ? '−' . $money($n['refunds']) : '—' }}</td></tr>
    <tr><td>Tax collected</td><td class="r">{{ $money($n['tax']) }}</td></tr>
    <tr><td>Deposits taken</td><td class="r">{{ $money($n['deposits']) }}</td></tr>
    <tr><td>Tips</td><td class="r">{{ $money($n['tips']) }}</td></tr>
  </table>

  <table>
    <tr><th>Method</th><th class="r">Count</th><th class="r">Collected</th><th class="r">Refunded</th></tr>
    @foreach($n['by_method'] as $bm)
      <tr><td>{{ $bm['label'] }}</td><td class="r">{{ $bm['count'] }}</td><td class="r">{{ $money($bm['collected']) }}</td><td class="r">{{ $bm['refunded'] > 0 ? '−' . $money($bm['refunded']) : '—' }}</td></tr>
    @endforeach
    <tr class="tot"><td>Total</td><td></td><td class="r">{{ $money($n['collected']) }}</td><td class="r">{{ $n['refunds'] > 0 ? '−' . $money($n['refunds']) : '—' }}</td></tr>
  </table>

  @if($drawer)
    <table>
      <tr><td>Opening float</td><td class="r">{{ $money($drawer->opening_float_cents) }}</td></tr>
      <tr><td>+ Cash collected</td><td class="r">{{ $money($n['cash_collected']) }}</td></tr>
      <tr><td>− Cash refunds</td><td class="r">{{ $money($n['cash_refunds']) }}</td></tr>
      <tr><td>− Paid out @if($drawer->paid_out_note)({{ $drawer->paid_out_note }})@endif</td><td class="r">{{ $money($drawer->paid_out_cents) }}</td></tr>
      <tr><td><b>Expected</b></td><td class="r"><b>{{ $money($drawer->expected_cents ?? ($drawer->opening_float_cents + $n['cash_collected'] - $n['cash_refunds'] - $drawer->paid_out_cents)) }}</b></td></tr>
      <tr><td><b>Counted</b></td><td class="r"><b>{{ $drawer->counted_cents !== null ? $money($drawer->counted_cents) : '________' }}</b></td></tr>
      <tr class="tot"><td>Over / short</td><td class="r">{{ $drawer->over_short_cents !== null ? $money($drawer->over_short_cents) : '________' }}</td></tr>
    </table>
  @endif

  <div class="sig"><div>Counted by</div><div>Verified by</div></div>
</body>
</html>

