<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">

  <title>{{ $page->meta_title ?? $page->title }} — {{ $currentTenant->name }}</title>

  @if($page->meta_description)
    <meta name="description" content="{{ $page->meta_description }}">
  @endif

  @if($currentTenant->favicon_url)
    <link rel="icon" href="{{ $currentTenant->favicon_url }}">
  @endif

  {{-- Fonts --}}
  @php
    $headingFont = $currentTenant->font_heading ?? 'Inter';
    $bodyFont    = $currentTenant->font_body    ?? 'Inter';
    $fontFamilies = array_unique([$headingFont, $bodyFont]);
    $fontQuery = implode('&family=', array_map(fn($f) => str_replace(' ', '+', $f) . ':wght@400;500;600;700', $fontFamilies));
  @endphp
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family={{ $fontQuery }}&display=swap" rel="stylesheet">

  <style>
    /* ================================================================
       Public site CSS — completely separate from admin themes
       ================================================================ */
    :root {
      --p-accent:       {{ $currentTenant->accent_color  ?? '#BEF264' }};
      --p-text:         {{ $currentTenant->text_color    ?? '#111111' }};
      --p-bg:           {{ $currentTenant->bg_color      ?? '#ffffff' }};
      --p-font-heading: '{{ $headingFont }}', -apple-system, sans-serif;
      --p-font-body:    '{{ $bodyFont }}',    -apple-system, sans-serif;
      --p-accent-text:  {{ \App\Support\ColorHelper::accentTextColor($currentTenant->accent_color ?? '#BEF264') }};
      --p-r:            8px;
      --p-r-lg:         12px;
      --p-max:          1160px;
      --p-gutter:       clamp(16px, 5vw, 64px);
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html { -webkit-text-size-adjust: 100%; scroll-behavior: smooth; }

    body {
      font-family: var(--p-font-body);
      background: var(--p-bg);
      color: var(--p-text);
      font-size: 16px;
      line-height: 1.65;
      -webkit-font-smoothing: antialiased;
    }

    img { max-width: 100%; display: block; }
    a   { color: inherit; text-decoration: none; }
    button { font-family: inherit; cursor: pointer; }

    /* Container */
    .p-container {
      max-width: var(--p-max);
      margin: 0 auto;
      padding: 0 var(--p-gutter);
    }

    /* Headings */
    h1,h2,h3,h4 {
      font-family: var(--p-font-heading);
      line-height: 1.2;
      font-weight: 700;
    }

    /* Buttons */
    .p-btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 12px 24px;
      border-radius: var(--p-r);
      font-size: 15px;
      font-weight: 600;
      border: 2px solid transparent;
      cursor: pointer;
      transition: all .15s;
      white-space: nowrap;
    }
    .p-btn--primary {
      background: var(--p-accent);
      color: var(--p-accent-text);
      border-color: var(--p-accent);
    }
    .p-btn--primary:hover { filter: brightness(.93); }
    .p-btn--outline {
      background: transparent;
      color: currentColor;
      border-color: currentColor;
      opacity: .8;
    }
    .p-btn--outline:hover { opacity: 1; }
    .p-btn--sm { padding: 8px 18px; font-size: 14px; }

    /* Section padding */
    .p-section { padding: clamp(40px, 7vw, 96px) 0; }
    .p-section--tight { padding: clamp(24px, 4vw, 48px) 0; }
    .p-section--none  { padding: 0; }

    /* Section heading */
    .p-section-heading {
      font-size: clamp(24px, 3.5vw, 40px);
      font-weight: 700;
      margin-bottom: 12px;
    }
    .p-section-sub {
      font-size: 17px;
      opacity: .6;
      max-width: 560px;
      line-height: 1.6;
    }
    .p-section-head-wrap { margin-bottom: clamp(28px, 4vw, 48px); }

    /* Forms */
    .p-input {
      width: 100%;
      padding: 12px 16px;
      border: 1.5px solid rgba(0,0,0,.15);
      border-radius: var(--p-r);
      font-family: var(--p-font-body);
      font-size: 15px;
      background: transparent;
      color: var(--p-text);
      transition: border-color .15s;
    }
    .p-input:focus { outline: none; border-color: var(--p-accent); }
    .p-label { display: block; font-size: 13px; font-weight: 500; margin-bottom: 6px; opacity: .7; }
    .p-form-group { margin-bottom: 16px; }

    /* Flash */
    .p-flash {
      padding: 14px 20px;
      border-radius: var(--p-r);
      font-size: 14px;
      margin-bottom: 20px;
    }
    .p-flash--success { background: #EAF3DE; color: #3B6D11; }
    .p-flash--error   { background: #FCEBEB; color: #A32D2D; }

    /* Mobile nav */
    .p-mobile-nav {
      display: none;
      position: fixed;
      inset: 0;
      background: var(--p-bg);
      z-index: 200;
      padding: 80px 32px 40px;
      flex-direction: column;
      gap: 8px;
    }
    .p-mobile-nav.open { display: flex; }
    .p-mobile-nav a {
      font-size: 22px;
      font-weight: 600;
      padding: 10px 0;
      border-bottom: 1px solid rgba(0,0,0,.07);
    }

    /* Responsive grid utility */
    .p-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
    .p-grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px; }
    .p-grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; }

    @media (max-width: 768px) {
      .p-grid-2, .p-grid-3, .p-grid-4 { grid-template-columns: 1fr; }
      .p-grid-2.p-2-col-mobile { grid-template-columns: 1fr 1fr; }
    }

    /* Powered by intake — conditional branded footer */
    .p-intake-footer {
      text-align: center;
      padding: 20px 16px;
      border-top: 1px solid rgba(0, 0, 0, 0.06);
      font-size: 12px;
      color: rgba(0, 0, 0, 0.4);
      font-family: var(--p-font-body);
      margin-top: 40px;
    }
    .p-intake-footer a {
      color: rgba(0, 0, 0, 0.55);
      font-weight: 500;
      text-decoration: none;
      border-bottom: 1px solid rgba(0, 0, 0, 0.15);
    }
    .p-intake-footer a:hover {
      color: var(--p-accent);
      border-bottom-color: var(--p-accent);
    }

    @media (max-width: 480px) {
      .p-grid-2.p-2-col-mobile { grid-template-columns: 1fr; }
    }
  </style>

  @stack('styles')
</head>

<body>

{{-- Mobile nav overlay --}}
<div class="p-mobile-nav" id="p-mobile-nav">
  <button onclick="closeMobileNav()"
    style="position:absolute;top:20px;right:20px;background:none;border:none;font-size:28px;cursor:pointer;color:var(--p-text)">
    ×
  </button>
  @foreach($navItems as $item)
    <a href="{{ $item->url }}" onclick="closeMobileNav()">{{ $item->label }}</a>
  @endforeach
  @php $navSection = $sections->firstWhere('section_type', 'nav'); $nc = $navSection?->content ?? []; @endphp
  @if(!empty($nc['cta_label']))
    <a href="{{ $nc['cta_url'] ?? '/book' }}" class="p-btn p-btn--primary" style="margin-top:16px;justify-content:center">
      {{ $nc['cta_label'] }}
    </a>
  @endif
</div>

{{-- Page sections --}}
@foreach($sections as $section)
  @if($section->is_visible)
    @include('public.sections._' . $section->section_type, [
      'c'        => $section->content ?? [],
      'section'  => $section,
      'navItems' => $navItems,
      'catalog'  => $catalog,
      'tenant'   => $currentTenant,
    ])
  @endif
@endforeach

{{-- Powered by intake — hidden when tenant is on Branded / Scale plan --}}
@if($currentTenant->show_intake_branding ?? true)
  <div class="p-intake-footer">
    Powered by <a href="https://intake.works" target="_blank" rel="noopener">intake</a>
  </div>
@endif

<script>
function openMobileNav()  { document.getElementById('p-mobile-nav').classList.add('open'); document.body.style.overflow='hidden'; }
function closeMobileNav() { document.getElementById('p-mobile-nav').classList.remove('open'); document.body.style.overflow=''; }
</script>

@stack('scripts')
</body>
</html>
