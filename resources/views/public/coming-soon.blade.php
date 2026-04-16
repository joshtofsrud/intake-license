<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Coming soon — {{ $currentTenant->name }}</title>
  @if($currentTenant->favicon_url)
    <link rel="icon" href="{{ $currentTenant->favicon_url }}">
  @endif
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:-apple-system,BlinkMacSystemFont,'Inter',sans-serif;background:#0f0f0f;color:#f0f0f0;min-height:100vh;display:flex;align-items:center;justify-content:center;text-align:center;padding:32px}
    h1{font-size:clamp(28px,5vw,52px);font-weight:700;letter-spacing:-.02em;margin-bottom:12px}
    p{font-size:17px;opacity:.5;max-width:420px;margin:0 auto 28px;line-height:1.6}
    a{display:inline-flex;align-items:center;padding:12px 28px;background:{{ $currentTenant->accent_color ?? '#BEF264' }};color:#0a0a0a;border-radius:8px;font-size:15px;font-weight:600;text-decoration:none}
  </style>
</head>
<body>
  <div>
    @if($currentTenant->logo_url)
      <img src="{{ $currentTenant->logo_url }}" alt="{{ $currentTenant->name }}" style="height:48px;margin:0 auto 28px">
    @else
      <div style="font-size:22px;font-weight:700;margin-bottom:28px">{{ $currentTenant->name }}</div>
    @endif
    <h1>Coming soon</h1>
    <p>We're getting everything ready. Check back soon.</p>
    <a href="/book">Book an appointment</a>
  </div>
</body>
</html>
