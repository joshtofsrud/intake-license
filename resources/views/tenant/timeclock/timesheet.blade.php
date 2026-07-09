{{-- MARKER-PATCH-613 — timesheet (print + email). Self-contained HTML so it
     renders identically in the browser print view and in the emailed body. --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Timesheet — {{ $staffName }} — {{ $rangeLabel }}</title>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; color: #111; margin: 0; padding: 32px; background: #fff; }
  .sheet { max-width: 720px; margin: 0 auto; }
  .head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #111; padding-bottom: 14px; margin-bottom: 18px; }
  .head .biz { font-size: 18px; font-weight: 800; letter-spacing: -0.01em; }
  .head .who { font-size: 13px; color: #555; margin-top: 3px; }
  .head .rng { text-align: right; font-size: 12px; color: #555; }
  .head .rng .lbl { font-weight: 700; color: #111; font-size: 14px; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .05em; color: #888; padding: 8px 10px; border-bottom: 1px solid #ddd; }
  td { padding: 9px 10px; border-bottom: 1px solid #f0f0f0; font-variant-numeric: tabular-nums; }
  td.dur { text-align: right; font-weight: 600; }
  .flag { font-size: 10px; color: #b45309; text-transform: uppercase; letter-spacing: .04em; }
  .total { margin-top: 16px; display: flex; justify-content: flex-end; gap: 16px; align-items: baseline; }
  .total .l { font-size: 12px; color: #555; text-transform: uppercase; letter-spacing: .05em; }
  .total .v { font-size: 22px; font-weight: 800; font-variant-numeric: tabular-nums; }
  .foot { margin-top: 22px; font-size: 10px; color: #999; border-top: 1px solid #eee; padding-top: 10px; }
  .empty { padding: 40px; text-align: center; color: #999; }
  @media print { body { padding: 0; } .noprint { display: none; } }
  .noprint { text-align: center; margin-bottom: 18px; }
  .btn { background: #111; color: #fff; border: none; padding: 9px 18px; border-radius: 7px; font-size: 13px; cursor: pointer; }
</style>
</head>
<body>
<div class="sheet">
  @if($print)
    <div class="noprint"><button class="btn" onclick="window.print()">Print / Save as PDF</button></div>
  @endif

  <div class="head">
    <div>
      <div class="biz">{{ $tenantName }}</div>
      <div class="who">Timesheet · {{ $staffName }}</div>
    </div>
    <div class="rng">
      <div class="lbl">{{ $rangeLabel }}</div>
      <div>Generated {{ tlocal_datetime(now()) }}</div>
    </div>
  </div>

  @if($punches->isEmpty())
    <div class="empty">No punches in this range.</div>
  @else
    <table>
      <thead>
        <tr><th>Date</th><th>Clock in</th><th>Clock out</th><th>Break</th><th style="text-align:right">Hours</th></tr>
      </thead>
      <tbody>
        @foreach($punches as $p)
          @php $mins = $p->minutes(); @endphp
          <tr>
            <td>{{ tlocal_date($p->clock_in_at) }}</td>
            <td>{{ tlocal($p->clock_in_at) }}</td>
            <td>
              @if($p->clock_out_at){{ tlocal($p->clock_out_at) }}@else <span class="flag">open</span> @endif
              @if($p->auto_closed) <span class="flag">auto-closed</span> @endif
            </td>
            <td>{{ $p->break_minutes ? $p->break_minutes . 'm' : '—' }}</td>
            <td class="dur">{{ intdiv($mins, 60) }}h {{ $mins % 60 }}m</td>
          </tr>
        @endforeach
      </tbody>
    </table>

    <div class="total">
      <span class="l">Total</span>
      <span class="v">{{ intdiv($totalMinutes, 60) }}h {{ $totalMinutes % 60 }}m</span>
    </div>
  @endif

  <div class="foot">Hours are net of recorded breaks. Times shown in {{ tenant()->timezone() }}. This timesheet is a record of clock punches, not an approved payroll document.</div>
</div>
</body>
</html>

