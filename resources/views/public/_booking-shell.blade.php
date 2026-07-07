{{--
  MARKER-PATCH-594 — shared booking shell.
  The three booking front-ends (advanced/full, simple, choice) @extends this
  layout so the themed chrome — head, :root vars, background + tint overlay,
  logo header, nav/footer site chrome — lives in ONE place. Each view supplies
  only its unique body via @section('content'), plus @push('styles')/
  @push('scripts') for view-specific CSS/JS.

  Canonical background model (from the old booking.blade / advanced view):
    body painted --p-bg ($bkBg); the editor's "background tint" is layered as a
    semi-transparent ::before overlay honoring the opacity slider. Simple + choice
    previously hardwired body = tint at full strength and ignored opacity — this
    shell makes all three honor the editor's two background controls identically.

  Contract (all optional, sensible defaults):
    $bk              array of booking settings (theme, accent, tint, opacity, show_* …)
    $currentTenant   tenant model
    $pageTitle       <title> text        (default: "Book online")
    $showBackLink    bool — render "← Back to site" beside the logo (full sets true)
    @section('content')   the view's body
    @push('styles')       extra <style>/<link> (before </head>)
    @push('scripts')      extra <script> (before the bottom chrome)
--}}
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
  $bkOpacity = ($bk['bg_opacity'] ?? 100) / 100;
  $bkProgressBg = ($bk['progress_bg'] ?? null) ?: ($isDark ? '#333333' : '#ABA6A6');
  $bkProgressText = ($bk['progress_text'] ?? null) ?: ($isDark ? '#f0f0f0' : '#000000');
  $muted  = $isDark ? 'rgba(255,255,255,.6)'  : 'rgba(0,0,0,.55)';
  $border = $isDark ? 'rgba(255,255,255,.14)' : 'rgba(0,0,0,.12)';
  $cardBg = $isDark ? 'rgba(255,255,255,.03)' : '#ffffff';
  $field  = $isDark ? 'rgba(255,255,255,.05)' : '#ffffff';
  $bookingBg = $bkBg;
  $logoUrl = \App\Support\ColorHelper::pickLogo($currentTenant, $bookingBg);
  $bookingLogoHeight = (int) ($currentTenant->logo_size_booking ?? 28);
  $bookingLogoHeight = max(16, min(120, $bookingLogoHeight));
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  {{-- MARKER-PATCH-150 — analytics + funnel tracking --}}
  @include('public._funnel_tracker')
  <title>{{ $pageTitle ?? 'Book online' }} — {{ $currentTenant->name }}</title>
  @if($currentTenant->favicon_url)<link rel="icon" href="{{ $currentTenant->favicon_url }}">@endif
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family={{ str_replace(' ', '+', $currentTenant->font_heading ?? 'Inter') }}:wght@400;500;600;700&family={{ str_replace(' ', '+', $currentTenant->font_body ?? 'Inter') }}:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
    :root {
      --p-accent:      {{ $bkAccent }};
      --p-accent-text: {{ \App\Support\ColorHelper::accentTextColor($bkAccent) }};
      --p-text:        {{ $bkText }};
      --p-bg:          {{ $bkBg }};
      --p-muted:       {{ $muted }};
      --p-border:      {{ $border }};
      --p-card:        {{ $cardBg }};
      --p-field:       {{ $field }};
      --p-font-heading:'{{ $currentTenant->font_heading ?? 'Inter' }}', -apple-system, sans-serif;
      --p-font-body:   '{{ $currentTenant->font_body ?? 'Inter' }}', -apple-system, sans-serif;
      --p-r: 8px; --p-r-lg: 12px; --p-max: 1100px;
      --p-gutter: clamp(16px, 4vw, 48px);
      --bk-progress-bg: {{ $bkProgressBg }};
      --bk-progress-text: {{ $bkProgressText }};
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: var(--p-font-body);
      background: var(--p-bg);
      color: var(--p-text);
      -webkit-font-smoothing: antialiased;
    }
    @if($bkOpacity < 1)
    body::before {
      content: '';
      position: fixed;
      inset: 0;
      background: {{ $bkTint }};
      opacity: {{ $bkOpacity }};
      z-index: -1;
      pointer-events: none;
    }
    @endif
    @if($isDark)
    .bk-top-bar { border-bottom-color: rgba(255,255,255,.08) !important; }
    @endif
    a { color: inherit; text-decoration: none; }
    button { font-family: inherit; }
    /* Shared logo header — both the centered form and the full-view top bar. */
    .bk-top-bar {
      border-bottom: 1px solid rgba(0,0,0,.08);
      padding: 14px var(--p-gutter);
      display: flex; align-items: center; justify-content: {{ ($showBackLink ?? false) ? 'space-between' : 'center' }};
      max-width: var(--p-max); margin: 0 auto;
    }
    .bk-top-logo { font-family: var(--p-font-heading); font-size: 17px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
    .bk-top-logo img { height: {{ $bookingLogoHeight }}px; width: auto; border-radius: 4px; }
    .bk-top-back { font-size: 13px; opacity: .5; transition: opacity .12s; }
    .bk-top-back:hover { opacity: 1; }

    /* MARKER-PATCH-601 — booking marketing sections (before/after slots) */
    .bx-section { width: 100%; }
    .bx-inner { max-width: var(--p-max, 1100px); margin: 0 auto; padding-left: var(--p-gutter, 24px); padding-right: var(--p-gutter, 24px); }
    .bx-eyebrow { font-size: 12px; letter-spacing: .14em; text-transform: uppercase; font-weight: 600; opacity: .7; margin-bottom: 10px; }
    .bx-h { font-family: var(--p-font-heading); font-weight: 700; letter-spacing: -0.01em; font-size: clamp(22px, 3.2vw, 30px); line-height: 1.12; margin: 0 0 10px; }
    .bx-h--lg { font-size: clamp(28px, 4.6vw, 44px); }
    .bx-sub { font-size: 15px; line-height: 1.6; opacity: .85; max-width: 640px; margin: 0 auto 8px; }
    .bx-section[style*="text-align:left"] .bx-sub { margin-left: 0; }
    .bx-section[style*="text-align:right"] .bx-sub { margin-right: 0; }
    .bx-actions { display: flex; gap: 12px; margin-top: 22px; flex-wrap: wrap; }
    .bx-section[style*="text-align:center"] .bx-actions { justify-content: center; }
    .bx-section[style*="text-align:right"] .bx-actions { justify-content: flex-end; }
    .bx-btn { display: inline-flex; align-items: center; font-weight: 600; font-size: 14px; padding: 12px 22px; border-radius: var(--p-r, 8px); text-decoration: none; transition: filter .15s, transform .15s; }
    .bx-btn--primary { background: var(--p-accent); color: var(--p-accent-text); }
    .bx-btn--primary:hover { filter: brightness(1.06); transform: translateY(-1px); }
    .bx-btn--ghost { border: 1px solid currentColor; opacity: .85; }
    .bx-btn--ghost:hover { opacity: 1; }
    .bx-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 18px; margin-top: 26px; text-align: left; }
    .bx-card { background: var(--p-card, rgba(0,0,0,.02)); border: 1px solid var(--p-border, rgba(0,0,0,.1)); border-radius: var(--p-r-lg, 12px); padding: 20px; }
    .bx-card-icon { font-size: 24px; margin-bottom: 10px; }
    .bx-card-title { font-family: var(--p-font-heading); font-weight: 600; font-size: 16px; margin-bottom: 6px; }
    .bx-card-text { font-size: 13.5px; line-height: 1.5; opacity: .8; }
  </style>
  @stack('styles')
</head>
<body>
@if(($bk['show_nav'] ?? '1') === '1')@include('public._chrome-inline', ['chromePos' => 'top'])@endif {{-- MARKER-PATCH-589 --}}

@if(($bk['show_logo'] ?? '1') === '1') {{-- MARKER-PATCH-594 --}}
<div class="bk-top-bar">
  <div class="bk-top-logo">
    @if($logoUrl)
      <img src="{{ $logoUrl }}" alt="{{ $currentTenant->name }}">
    @else
      {{ $currentTenant->name }}
    @endif
  </div>
  @if($showBackLink ?? false)<a href="/" class="bk-top-back">← Back to site</a>@endif
</div>
@endif {{-- MARKER-PATCH-594 --}}

@include('public.sections._booking_extras', ['slot' => 'before']) {{-- MARKER-PATCH-601 --}}

@yield('content')

@include('public.sections._booking_extras', ['slot' => 'after']) {{-- MARKER-PATCH-601 --}}

@stack('scripts')
@if(($bk['show_footer'] ?? '1') === '1')@include('public._chrome-inline', ['chromePos' => 'bottom', 'hideBookingCta' => ($bk['hide_cta'] ?? false)])@endif {{-- MARKER-PATCH-594 --}}
</body>
</html>
