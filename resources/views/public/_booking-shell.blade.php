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

