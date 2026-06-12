<!DOCTYPE html>
{{-- MARKER-PATCH-240 — reservation confirmation. --}}
@php
  $accent = $currentTenant->accent_color ?? '#BEF264';
  $tname  = $currentTenant->name ?? 'Rentals';
  $paid   = (int) $rental->paid_cents >= (int) $rental->total_cents && (int) $rental->total_cents > 0;
@endphp
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reserved — {{ $tname }}</title>
<style>
  :root { --acc: {{ $accent }}; }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Inter', sans-serif; color: #161616; background: #fafafa; line-height: 1.6; -webkit-font-smoothing: antialiased; }
  .wrap { max-width: 520px; margin: 0 auto; padding: 48px 20px 80px; text-align: center; }
  .tick { width: 56px; height: 56px; border-radius: 50%; background: var(--acc); display: flex; align-items: center; justify-content: center; margin: 0 auto 18px; font-size: 26px; }
  h1 { font-size: 24px; font-weight: 650; letter-spacing: -.02em; }
  .num { font-family: ui-monospace, monospace; font-size: 15px; opacity: .55; margin-top: 4px; }
  .card { background: #fff; border: 1.5px solid rgba(0,0,0,.09); border-radius: 14px; padding: 20px 22px; margin-top: 24px; text-align: left; }
  .kv { display: flex; justify-content: space-between; font-size: 14px; padding: 4px 0; }
  .kv span:first-child { opacity: .55; }
  a.btn { display: inline-block; font-size: 14px; font-weight: 650; padding: 11px 26px; border-radius: 10px; background: var(--acc); color: #111; text-decoration: none; margin-top: 26px; }
</style>
</head>
<body>
<div class="wrap">
  <div class="tick">✓</div>
  <h1>You're reserved</h1>
  <div class="num">{{ $rental->rental_number }}</div>

  <div class="card">
    @foreach($rental->lines->where('kind', 'unit') as $line)
      <div class="kv"><span>Bike</span><span>{{ $line->name_snapshot }}</span></div>
    @endforeach
    <div class="kv"><span>Pickup</span><span>{{ tlocal_datetime($rental->starts_at, 'D M j, g:i A') }}</span></div>
    <div class="kv"><span>Return by</span><span>{{ tlocal_datetime($rental->due_at, 'D M j, g:i A') }}</span></div>
    <div class="kv"><span>{{ $paid ? 'Paid' : 'Due at pickup' }}</span><span>{{ format_money($paid ? $rental->paid_cents : $rental->total_cents) }}</span></div>
  </div>

  <p style="font-size:13px;opacity:.55;margin-top:18px">Bring photo ID at pickup. Need to change anything? Just get in touch — your reservation number is above.</p>
  <a class="btn" href="/">Back to {{ $tname }}</a>
</div>
</body>
</html>
