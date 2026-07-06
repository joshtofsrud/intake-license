@php
  $bk = $bk ?? [];
  $bkTheme = $bk['theme'] ?? 'light';
  $isDark = $bkTheme === 'dark';
  $bkAccent = ($bk['accent'] ?? null) ?: ($currentTenant->accent_color ?? '#BEF264');
  $bkText = $isDark
    ? (($bk['body_text'] ?? null) ?: '#f0f0f0')
    : (($bk['body_text'] ?? null) ?: ($currentTenant->text_color ?? '#111111'));
  $bkBg = $isDark ? '#111111' : ($currentTenant->bg_color ?? '#ffffff');
  $bkTint = $isDark
    ? (($bk['bg_tint'] ?? null) ?: '#1a1a1a')
    : (($bk['bg_tint'] ?? null) ?: '#FFFFFF');
  $bookingBg = $isDark ? '#111111' : ($currentTenant->bg_color ?? '#ffffff');
  $logoUrl = \App\Support\ColorHelper::pickLogo($currentTenant, $bookingBg);
  $bookingLogoHeight = (int) ($currentTenant->logo_size_booking ?? 28);
  $bookingLogoHeight = max(16, min(120, $bookingLogoHeight));
  $muted = $isDark ? 'rgba(255,255,255,.6)' : 'rgba(0,0,0,.55)';
  $border = $isDark ? 'rgba(255,255,255,.14)' : 'rgba(0,0,0,.12)';
  $cardBg = $isDark ? 'rgba(255,255,255,.03)' : '#ffffff';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  @include('public._funnel_tracker')
  <title>Book online — {{ $currentTenant->name }}</title>
  @if($currentTenant->favicon_url)<link rel="icon" href="{{ $currentTenant->favicon_url }}">@endif
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family={{ str_replace(' ', '+', $currentTenant->font_heading ?? 'Inter') }}:wght@400;500;600;700&family={{ str_replace(' ', '+', $currentTenant->font_body ?? 'Inter') }}:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
    :root{
      --p-accent: {{ $bkAccent }};
      --p-accent-text: {{ \App\Support\ColorHelper::accentTextColor($bkAccent) }};
      --p-text: {{ $bkText }};
      --p-bg: {{ $bkBg }};
      --p-muted: {{ $muted }};
      --p-border: {{ $border }};
      --p-card: {{ $cardBg }};
      --p-font-heading:'{{ $currentTenant->font_heading ?? 'Inter' }}', -apple-system, sans-serif;
      --p-font-body:'{{ $currentTenant->font_body ?? 'Inter' }}', -apple-system, sans-serif;
      --p-r: 10px; --p-r-lg: 16px;
    }
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    body{ font-family:var(--p-font-body); color:var(--p-text); background:{{ $bkTint }}; min-height:100vh; -webkit-font-smoothing:antialiased; }
    .wrap{ max-width:760px; margin:0 auto; padding:32px 20px 60px; }
    .bk-head{ display:flex; align-items:center; justify-content:space-between; margin-bottom:48px; }
    .bk-logo img{ height:{{ $bookingLogoHeight }}px; width:auto; display:block; }
    .bk-logo .name{ font-family:var(--p-font-heading); font-weight:700; font-size:18px; }
    .fork-intro{ text-align:center; margin-bottom:34px; }
    .fork-intro h1{ font-family:var(--p-font-heading); font-size:clamp(24px,5vw,32px); font-weight:700; letter-spacing:-.02em; margin-bottom:10px; }
    .fork-intro p{ font-size:15px; color:var(--p-muted); max-width:440px; margin:0 auto; line-height:1.55; }
    .fork{ display:flex; gap:16px; flex-wrap:wrap; }
    .fcard{ flex:1 1 280px; background:var(--p-card); border:1px solid var(--p-border); border-radius:var(--p-r-lg); padding:26px 24px; text-decoration:none; color:inherit; display:flex; flex-direction:column; transition:transform .12s, border-color .12s, box-shadow .12s; }
    .fcard:hover{ transform:translateY(-2px); border-color:var(--p-accent); box-shadow:0 10px 30px rgba(0,0,0,{{ $isDark ? '.4' : '.08' }}); }
    .fcard.lead{ border-color:var(--p-accent); }
    .ficon{ width:46px; height:46px; border-radius:12px; background:color-mix(in srgb, var(--p-accent) 16%, transparent); display:flex; align-items:center; justify-content:center; color:var(--p-accent); margin-bottom:18px; }
    .ficon svg{ width:24px; height:24px; }
    .fcard h2{ font-family:var(--p-font-heading); font-size:19px; font-weight:600; margin-bottom:7px; }
    .fcard p{ font-size:13.5px; color:var(--p-muted); line-height:1.5; flex:1; }
    .fmeta{ margin-top:16px; font-size:12px; font-weight:600; color:var(--p-muted); display:flex; align-items:center; gap:8px; }
    .fgo{ margin-top:18px; display:inline-flex; align-items:center; gap:7px; font-size:13px; font-weight:600; color:var(--p-accent); }
    .fnote{ text-align:center; margin-top:26px; font-size:12.5px; color:var(--p-muted); }
    @media (max-width:560px){ .fork{ flex-direction:column; } }
  </style>
</head>
<body>
@if(($bk['show_nav'] ?? '1') === '1')@include('public._chrome-inline', ['chromePos' => 'top'])@endif {{-- MARKER-PATCH-589 --}}
  <div class="wrap">
    <div class="bk-head">
      <div class="bk-logo">
        @if($logoUrl)<img src="{{ $logoUrl }}" alt="{{ $currentTenant->name }}">@else<span class="name">{{ $currentTenant->name }}</span>@endif
      </div>
    </div>

    <div class="fork-intro">
      <h1>How would you like to book?</h1>
      <p>Pick what fits your visit — you can switch anytime.</p>
    </div>

    <div class="fork">
      <a class="fcard lead" href="{{ url('/book?flow=quick') }}" data-flow="quick">
        <div class="ficon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
        </div>
        <h2>Quick booking</h2>
        <p>Pick from our service menu and grab a time. Best for a single, standard job.</p>
        <div class="fmeta">3 steps · about a minute</div>
        <span class="fgo">Start quick
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </span>
      </a>

      <a class="fcard" href="{{ url('/book?flow=full') }}" data-flow="full">
        <div class="ficon" style="color:var(--p-muted);background:color-mix(in srgb, var(--p-text) 8%, transparent);">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18v3h3l6.3-6.3a4 4 0 0 0 5.4-5.4l-2.6 2.6-2-2 2.6-2.6z"/></svg>
        </div>
        <h2>Full setup</h2>
        <p>Add each item, choose services per item, and review everything before you book.</p>
        <div class="fmeta">6 steps · full control</div>
        <span class="fgo" style="color:var(--p-muted);">Start full
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </span>
      </a>
    </div>

    <div class="fnote">Not sure? Quick booking covers most visits — you can always start over.</div>
  </div>

  <script>
    // Record which path the customer chose (anonymous funnel signal).
    document.querySelectorAll('.fcard').forEach(function(el){
      el.addEventListener('click', function(){
        try {
          if (navigator.sendBeacon) {
            navigator.sendBeacon('/funnel/track', new Blob([JSON.stringify({event_type:'booking_step', step:'00 Chose ' + (el.dataset.flow === 'quick' ? 'Quick' : 'Full')})], {type:'application/json'}));
          }
        } catch(e){}
      });
    });
  </script>
@if(($bk['show_footer'] ?? '1') === '1')@include('public._chrome-inline', ['chromePos' => 'bottom'])@endif {{-- MARKER-PATCH-589 --}}
</body>
</html>
