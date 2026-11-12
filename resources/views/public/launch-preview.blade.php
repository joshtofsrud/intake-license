{{-- MARKER-LAUNCH-CHECKLIST: what a visitor sees while the store or booking page is in preview. --}}
@php
  $lpT = $currentTenant ?? tenant();
  $lpWhat = ($module ?? 'ecom') === 'rent' ? 'Online rentals are' : 'Our online store is';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Coming soon — {{ $lpT->name }}</title>
  @if($lpT->favicon_url)<link rel="icon" href="{{ $lpT->favicon_url }}">@endif
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:-apple-system,BlinkMacSystemFont,'Inter',sans-serif;background:#0f0f0f;color:#f0f0f0;min-height:100vh;display:flex;align-items:center;justify-content:center;text-align:center;padding:24px}
    h1{font-size:clamp(28px,5vw,48px);font-weight:700;letter-spacing:-.02em;margin-bottom:12px}
    p{font-size:17px;opacity:.55;max-width:440px;margin:0 auto 28px;line-height:1.6}
    a{display:inline-flex;align-items:center;padding:12px 26px;background:{{ $lpT->accent_color ?? '#BEF264' }};color:#0a0a0a;border-radius:8px;font-size:15px;font-weight:600;text-decoration:none}
  </style>
</head>
<body>
  <div>
    @if($lpT->logo_url)
      <img src="{{ $lpT->logo_url }}" alt="{{ $lpT->name }}" style="height:48px;margin:0 auto 28px">
    @else
      <div style="font-size:22px;font-weight:700;margin-bottom:28px">{{ $lpT->name }}</div>
    @endif
    <h1>Coming soon</h1>
    <p>{{ $lpWhat }} almost ready. Check back soon.</p>
    <a href="/">Back to {{ $lpT->name }}</a>
  </div>
</body>
</html>
