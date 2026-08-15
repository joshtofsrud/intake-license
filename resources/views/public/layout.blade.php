<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  @include('partials.mobile-input-zoom') {{-- MARKER-MOBILE-INPUT-ZOOM --}}
  <meta name="csrf-token" content="{{ csrf_token() }}">
  {{-- MARKER-PATCH-150 — analytics + funnel tracking --}}
  @include('public._funnel_tracker')

  <title>{{ $page->meta_title ?? $page->title }} — {{ $currentTenant->name }}</title>

  @if($page->meta_description)
    <meta name="description" content="{{ $page->meta_description }}">
  @endif

  @if($currentTenant->favicon_url)
    <link rel="icon" href="{{ $currentTenant->favicon_url }}">
  @endif

  {{-- Fonts --}}
  @php
    // MARKER-TOKENS — one resolve for the whole page.
    $dt = \App\Support\DesignTokens::resolve($currentTenant);
    $headingFont = $dt['font_heading'];
    $bodyFont    = $dt['font_body'];
    $fontFamilies = array_unique([$headingFont, $bodyFont]);
    $fontQuery = implode('&family=', array_map(fn($f) => str_replace(' ', '+', $f) . ':wght@400;500;600;700', $fontFamilies));
  @endphp
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>{{-- MARKER-SELFHOST-FONTS-2 — the font FILES come from gstatic; without this the browser pays a second DNS+TLS handshake --}}
  <link href="https://fonts.googleapis.com/css2?family={{ $fontQuery }}&display=swap" rel="stylesheet">

  <style>
    /* ================================================================
       Public site CSS — completely separate from admin themes
       ================================================================ */
    :root {
{!! \App\Support\DesignTokens::cssVars($dt) !!} {{-- MARKER-TOKENS --}}
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
      font-weight: var(--p-heading-weight, 700);      /* MARKER-TOKENS */
      text-transform: var(--p-heading-transform, none);
    }

    /* Buttons */
    .p-btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 12px 24px;
      border-radius: var(--p-btn-r, var(--p-r));      /* MARKER-TOKENS */
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
    /* MARKER-TOKENS — template button styles. Outline and ghost keep the
       accent as the visible edge or fill hint rather than a solid slab. */
    body.p-btn-outline .p-btn--primary {
      background: transparent;
      color: var(--p-text);
      border-color: var(--p-accent);
    }
    body.p-btn-outline .p-btn--primary:hover {
      background: var(--p-accent);
      color: var(--p-accent-text);
      filter: none;
    }
    body.p-btn-ghost .p-btn--primary {
      background: var(--p-surface);
      color: var(--p-text);
      border-color: transparent;
    }
    body.p-btn-ghost .p-btn--primary:hover { filter: brightness(.96); }
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
    /* MARKER-NAV-ACCOUNT */
    .p-mobile-account { display: flex; align-items: center; gap: 10px; font-size: 17px; font-weight: 600; }
    .p-mobile-account small { display: block; font-size: 12.5px; font-weight: 400; opacity: .5; margin-top: 1px; }
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

<body class="p-btn-{{ $dt['button_style'] }}">

{{-- Mobile nav overlay --}}
<div class="p-mobile-nav" id="p-mobile-nav">
  <button onclick="closeMobileNav()"
    style="position:absolute;top:20px;right:20px;background:none;border:none;font-size:28px;cursor:pointer;color:var(--p-text)">
    ×
  </button>
  {{-- MARKER-NAV-ACCOUNT — first row, so the portal is reachable on a phone --}}
  @php
    $mnNav          = $sections->firstWhere('section_type', 'nav');
    $mnShowAccount  = (bool) (($mnNav?->content['show_account']) ?? true);
    $mnCustomer     = \Illuminate\Support\Facades\Auth::guard('customer')->user();
  @endphp
  @if($mnShowAccount)
    <a href="{{ $mnCustomer ? route('tenant.customer.portal') : route('tenant.customer.login') }}"
       onclick="closeMobileNav()" class="p-mobile-account">
      <svg width="22" height="22" viewBox="0 0 16 16" fill="none" aria-hidden="true"><circle cx="8" cy="5.2" r="3" stroke="currentColor" stroke-width="1.6"/><path d="M2.6 14c.8-2.6 2.9-4 5.4-4s4.6 1.4 5.4 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
      <span>{{ $mnCustomer ? $mnCustomer->first_name : 'Sign in' }}
        <small>{{ $mnCustomer ? 'My account' : 'Bookings, orders, rentals & messages' }}</small></span>
    </a>
  @endif
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

{{-- MARKER-SPLASH — drawn OVER the homepage, which is fully rendered below.
     Included before the sections so it exists even if a section throws. --}}
@if(!empty($splashPage) && !empty($splashSections) && count($splashSections))
  @include('public._splash-overlay')
@endif

{{-- Page sections --}}
@foreach($sections as $section)
  @if($section->is_visible)
    {{-- MARKER-PATCH-158-G14 — Guard against section types that exist in the
         admin builder's DEFAULTS but have no matching public partial. Without
         this, an unknown type causes a ViewException → 500 for the whole page. --}}
    @php $partial = 'public.sections._' . $section->section_type; @endphp
    @if(view()->exists($partial))
      {{-- MARKER-TOKENS — resolve an inheriting background here, once, rather
           than in 19 section partials. An explicit hex passes through
           untouched, so nothing a tenant chose in the builder changes. --}}
      @php
        $sc = $section->content ?? [];
        $sc['bg_color'] = \App\Support\DesignTokens::sectionBg(
            $sc['bg_color'] ?? null, $section->section_type, $dt
        );
      @endphp
      @include($partial, [
        'c'        => $sc,
        'section'  => $section,
        'navItems' => $navItems,
        'catalog'  => $catalog,
        'tenant'   => $currentTenant,
      ])
    @elseif(config('app.debug'))
      <div style="padding:24px;margin:20px auto;max-width:800px;background:#fef3c7;border:1px solid #f59e0b;border-radius:8px;color:#78350f;font-family:monospace;font-size:13px;">
        <strong>⚠ Page builder section unsupported on public renderer:</strong> <code>{{ $section->section_type }}</code><br>
        <span style="opacity:.7">This section type exists in the admin builder but has no public partial at <code>{{ $partial }}.blade.php</code>. Edit the page and switch to a supported type, or build the missing partial. (Visible in debug mode only.)</span>
      </div>
    @endif
  @endif
@endforeach

{{-- Powered by intake — shown only when:
     (a) tenant plan tier permits the badge (show_intake_branding flag), AND
     (b) the page doesn't have its own footer section
         (MARKER-PATCH-158-G26 — when a footer section is present, the
         credit lives there; this layout-level badge is the fallback for
         pages without a footer section.) --}}
@php
  $hasFooterSection = $sections->contains(fn($s) => $s->is_visible && $s->section_type === 'footer');
@endphp
@if(($currentTenant->show_intake_branding ?? true) && !$hasFooterSection)
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
