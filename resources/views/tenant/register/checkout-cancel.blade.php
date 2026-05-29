<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  {{-- MARKER-PATCH-173 — customer-facing landing after Stripe Checkout cancel. --}}
  <title>Payment cancelled — {{ $currentTenant->name }}</title>
  @if($currentTenant->favicon_url)<link rel="icon" href="{{ $currentTenant->favicon_url }}">@endif
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root{
      --accent:      {{ $currentTenant->accent_color ?? '#BEF264' }};
      --accent-text: {{ \App\Support\ColorHelper::accentTextColor($currentTenant->accent_color ?? '#BEF264') }};
      --text:  {{ $currentTenant->text_color ?? '#111' }};
      --bg:    {{ $currentTenant->bg_color ?? '#fff' }};
    }
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'Inter',-apple-system,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:32px 16px;-webkit-font-smoothing:antialiased}
    .card{max-width:480px;width:100%;text-align:center}
    .icon{width:64px;height:64px;background:rgba(0,0,0,.06);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 24px;font-size:30px;opacity:.5}
    h1{font-size:28px;font-weight:700;letter-spacing:-.01em;margin-bottom:8px}
    .sub{font-size:16px;opacity:.55;margin-bottom:28px;line-height:1.55}
    .actions{display:flex;gap:12px;justify-content:center;flex-wrap:wrap}
    .btn{padding:12px 24px;border-radius:8px;font-size:14px;font-weight:600;text-decoration:none;transition:filter .12s}
    .btn-outline{border:1.5px solid rgba(0,0,0,.15);color:var(--text)}
    .btn-outline:hover{border-color:rgba(0,0,0,.3)}
    .note{font-size:13px;opacity:.4;margin-top:24px;line-height:1.5}
  </style>
</head>
<body>
<div class="card">
  <div class="icon">&times;</div>

  <h1>Payment cancelled</h1>
  <p class="sub">
    No charge was made.<br>
    If you'd still like to pay, contact {{ $currentTenant->name }} and they can resend your payment link.
  </p>

  <div class="actions">
    <a href="/" class="btn btn-outline">Back to {{ $currentTenant->name }}</a>
  </div>

  <p class="note">You can safely close this window.</p>
</div>
</body>
</html>
