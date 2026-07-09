{{-- MARKER-PATCH-615 — team hours report (print + email). Self-contained HTML. --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Hours report — {{ $tenantName }} — {{ $rangeLabel }}</title>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; color:#111; margin:0; padding:32px; background:#fff; }
  .sheet { max-width:720px; margin:0 auto; }
  .head { display:flex; justify-content:space-between; align-items:flex-start; border-bottom:2px solid #111; padding-bottom:14px; margin-bottom:18px; }
  .head .biz { font-size:18px; font-weight:800; }
  .head .who { font-size:13px; color:#555; margin-top:3px; }
  .head .rng { text-align:right; font-size:12px; color:#555; }
  .head .rng .lbl { font-weight:700; color:#111; font-size:14px; }
  table { width:100%; border-collapse:collapse; font-size:13px; }
  th { text-align:left; font-size:10px; text-transform:uppercase; letter-spacing:.05em; color:#888; padding:8px 10px; border-bottom:1px solid #ddd; }
  th.n, td.n { text-align:right; font-variant-numeric:tabular-nums; }
  td { padding:9px 10px; border-bottom:1px solid #f0f0f0; }
  tr.tot td { font-weight:800; border-top:2px solid #111; border-bottom:none; }
  td.ot { color:#b45309; }
  .foot { margin-top:22px; font-size:10px; color:#999; border-top:1px solid #eee; padding-top:10px; }
  .empty { padding:40px; text-align:center; color:#999; }
  @media print { body { padding:0; } .noprint { display:none; } }
  .noprint { text-align:center; margin-bottom:18px; }
  .btn { background:#111; color:#fff; border:none; padding:9px 18px; border-radius:7px; font-size:13px; cursor:pointer; }
</style>
</head>
<body>
<div class="sheet">
  @if($print)<div class="noprint"><button class="btn" onclick="window.print()">Print / Save as PDF</button></div>@endif

  <div class="head">
    <div><div class="biz">{{ $tenantName }}</div><div class="who">Hours report</div></div>
    <div class="rng"><div class="lbl">{{ $rangeLabel }}</div><div>Generated {{ tlocal_datetime(now()) }}</div></div>
  </div>

  @if(empty($rows))
    <div class="empty">No punches in this range.</div>
  @else
    @php $tReg=0;$tOt=0;$tSh=0; @endphp
    <table>
      <thead><tr><th>Staff</th><th class="n">Regular</th><th class="n">OT 1.5×</th><th class="n">DT 2×</th><th class="n">Total</th><th class="n">Shifts</th></tr></thead>
      <tbody>
        @foreach($rows as $r)
          @php $dt=$r['dt']??0; $tot=$r['regular']+$r['ot']+$dt; $tReg+=$r['regular'];$tOt+=$r['ot'];$tDt=($tDt??0)+$dt;$tSh+=$r['shifts']; @endphp
          <tr>
            <td>{{ $r['name'] }}</td>
            <td class="n">{{ intdiv($r['regular'],60) }}h {{ $r['regular']%60 }}m</td>
            <td class="n ot">{{ $r['ot'] ? intdiv($r['ot'],60).'h '.($r['ot']%60).'m' : '—' }}</td>
            <td class="n ot">{{ $dt ? intdiv($dt,60).'h '.($dt%60).'m' : '—' }}</td>
            <td class="n">{{ intdiv($tot,60) }}h {{ $tot%60 }}m</td>
            <td class="n">{{ $r['shifts'] }}</td>
          </tr>
        @endforeach
        @php $tDt = $tDt ?? 0; $grand = $tReg+$tOt+$tDt; @endphp
        <tr class="tot">
          <td>Total</td>
          <td class="n">{{ intdiv($tReg,60) }}h {{ $tReg%60 }}m</td>
          <td class="n">{{ intdiv($tOt,60) }}h {{ $tOt%60 }}m</td>
          <td class="n">{{ intdiv($tDt,60) }}h {{ $tDt%60 }}m</td>
          <td class="n">{{ intdiv($grand,60) }}h {{ $grand%60 }}m</td>
          <td class="n">{{ $tSh }}</td>
        </tr>
      </tbody>
    </table>
  @endif

  <div class="foot">Hours net of recorded breaks. Overtime per tenant policy (daily and/or weekly). DT = double-time. Times in {{ tenant()->timezone() }}. Record of punches, not an approved payroll document.</div>
</div>
</body>
</html>

