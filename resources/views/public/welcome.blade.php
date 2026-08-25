{{-- MARKER-WELCOME — the holding page. Uses the tenant's own logo,
     accent and contact details, so there is nothing to design. --}}
@php
  $t      = $currentTenant ?? tenant();
  $accent = $t->accent_color ?: '#BEF264';
  $loc    = $t->locations()->orderBy('is_default', 'desc')->first();
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>{{ $t->name }}</title>
  @if($t->favicon_url)<link rel="icon" href="{{ $t->favicon_url }}">@endif
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:-apple-system,BlinkMacSystemFont,'Inter',sans-serif;
         background:#0d0d0d;color:#f2f2f2;min-height:100vh;display:flex;
         align-items:center;justify-content:center;text-align:center;padding:32px;
         background-image:radial-gradient(circle at 50% 0%,
           color-mix(in srgb, {{ $accent }} 10%, transparent), transparent 60%)}
    .w{max-width:460px}
    .logo{height:52px;margin:0 auto 26px;display:block}
    .mark{width:56px;height:56px;border-radius:14px;margin:0 auto 26px;
          display:flex;align-items:center;justify-content:center;
          background:color-mix(in srgb, {{ $accent }} 16%, transparent);
          color:{{ $accent }};font-size:20px;font-weight:800}
    h1{font-size:clamp(26px,5vw,40px);font-weight:700;letter-spacing:-.02em;line-height:1.15}
    p{font-size:16px;color:rgba(255,255,255,.55);margin:12px auto 0;line-height:1.65}
    .cta{display:inline-block;margin-top:26px;padding:12px 26px;border-radius:9px;
         background:{{ $accent }};color:#0a0a0a;font-size:15px;font-weight:700;text-decoration:none}
    .meta{margin-top:26px;font-size:13px;color:rgba(255,255,255,.35);line-height:1.7}
    .meta a{color:inherit}
  </style>
</head>
<body>
  <div class="w">
    {{-- MARKER-WELCOME-LOGO — resolved from the welcome setting, not the
         main logo alone: this page is always dark. --}}
    @php $wLogo = \App\Support\WelcomePage::logoUrl($t); @endphp
    @if($wLogo)
      <img class="logo" src="{{ $wLogo }}" alt="{{ $t->name }}">
    @else
      <div class="mark">{{ brand_initials($t->name) }}</div>
    @endif

    <h1>{{ $w['headline'] }}</h1>
    @if($w['message'])<p>{{ $w['message'] }}</p>@endif

    @if($w['cta_label'] && $w['cta_url'])
      <a class="cta" href="{{ $w['cta_url'] }}">{{ $w['cta_label'] }}</a>
    @endif

    <div class="meta">
      {{ $t->name }}
      @if($loc && $loc->phone) · <a href="tel:{{ preg_replace('/[^0-9+]/', '', $loc->phone) }}">{{ $loc->phone }}</a> @endif
      @if($loc && $loc->city) · {{ $loc->city }}@if($loc->state), {{ $loc->state }}@endif @endif
    </div>
  </div>
</body>
</html>
