<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  @include('partials.mobile-input-zoom') {{-- MARKER-MOBILE-INPUT-ZOOM --}}
  {{-- MARKER-PATCH-173 — customer-facing landing after Stripe Checkout success. --}}
  <title>Payment received — {{ $currentTenant->name }}</title>
  @if($currentTenant->favicon_url)<link rel="icon" href="{{ $currentTenant->favicon_url }}">@endif
  <link rel="stylesheet" href="{{ asset('css/fonts.css') }}">{{-- MARKER-SELFHOST-FONTS-2 --}}
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
    .icon{width:64px;height:64px;background:var(--accent);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 24px;font-size:28px;color:var(--accent-text)}
    h1{font-size:28px;font-weight:700;letter-spacing:-.01em;margin-bottom:8px}
    .sub{font-size:16px;opacity:.55;margin-bottom:28px;line-height:1.55}
    .amt-box{background:rgba(0,0,0,.04);border-radius:12px;padding:16px 24px;margin-bottom:28px}
    .amt-label{font-size:11px;text-transform:uppercase;letter-spacing:.08em;opacity:.4;margin-bottom:4px}
    .amt-number{font-size:26px;font-weight:700;letter-spacing:.01em}
    .actions{display:flex;gap:12px;justify-content:center;flex-wrap:wrap}
    .btn{padding:12px 24px;border-radius:8px;font-size:14px;font-weight:600;text-decoration:none;transition:filter .12s}
    .btn-outline{border:1.5px solid rgba(0,0,0,.15);color:var(--text)}
    .btn-outline:hover{border-color:rgba(0,0,0,.3)}
    .note{font-size:13px;opacity:.4;margin-top:24px;line-height:1.5}
  </style>
</head>
<body>
<div class="card">
  <div class="icon">✓</div>

  <h1>Payment received</h1>
  <p class="sub">
    Thanks — your payment to {{ $currentTenant->name }} went through.
  </p>

  @if(!is_null($amountCents))
    <div class="amt-box">
      <div class="amt-label">Amount paid</div>
      <div class="amt-number">${{ number_format($amountCents / 100, 2) }}</div>
    </div>
  @endif

  <p class="note">
    A receipt is on its way to your email.<br>
    {{ $currentTenant->name }} will be in touch about your service — you can close this window.
  </p>

  <div class="actions" style="margin-top:24px">
    <a href="/" class="btn btn-outline">Back to {{ $currentTenant->name }}</a>
  </div>
</div>
</body>
</html>
