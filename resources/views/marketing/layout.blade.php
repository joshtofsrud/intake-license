{{-- DEPRECATED — patch #45 (marketing-system-unification.sh) made this file unused.
     The CMS layout (marketing/page.blade.php) is the only shell.
     Kept temporarily for one release in case a route still references it.
     SAFE TO DELETE in patch #46+ --}}
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  @include('partials.mobile-input-zoom') {{-- MARKER-MOBILE-INPUT-ZOOM --}}
  <title>@yield('title', 'intake — Retail, booking, and classes — built for communication and efficiency.')</title>
  <meta name="description" content="@yield('meta_description', 'Retail, booking, and classes — built for communication and efficiency. For service, retail, fitness, and appointment-based businesses.')">

  <!-- Logo system v1 (patch #44) — favicons + OG/Twitter meta -->
  <link rel="icon" type="image/svg+xml" href="{{ \App\Support\Brand::url('favicon') }}">
  <link rel="icon" type="image/png" sizes="32x32" href="{{ \App\Support\Brand::url('favicon_32') }}">
  <link rel="icon" type="image/png" sizes="16x16" href="{{ \App\Support\Brand::url('favicon_16') }}">
  <link rel="apple-touch-icon" href="{{ \App\Support\Brand::url('apple') }}">
  <meta name="theme-color" content="#0c0c0c">

  <meta property="og:type" content="website">
  <meta property="og:site_name" content="intake">
  <meta property="og:title" content="@yield('og_title', 'intake — Retail, booking, and classes — built for communication and efficiency.')">
  <meta property="og:description" content="@yield('og_description', 'For service, retail, fitness, and appointment-based businesses.')">
  <meta property="og:image" content="{{ \App\Support\Brand::url('og') }}">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta property="og:url" content="{{ url()->current() }}">

  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="@yield('og_title', 'intake — Retail, booking, and classes — built for communication and efficiency.')">
  <meta name="twitter:description" content="@yield('og_description', 'For service, retail, fitness, and appointment-based businesses.')">
  <meta name="twitter:image" content="{{ \App\Support\Brand::url('og') }}">
  <link rel="stylesheet" href="{{ asset('css/fonts.css') }}">{{-- MARKER-SELFHOST-FONTS-2 --}}
  <style>
    /* ================================================================
       Intake Marketing Site
       Dark (#0c0c0c) + lime accent (#BEF264)
       ================================================================ */
    :root {
      --mk-accent:      #BEF264;
      --mk-accent-dim:  rgba(190,242,100,.12);
      --mk-accent-text: #0a0a0a;
      --mk-bg:          #0c0c0c;
      --mk-bg2:         #141414;
      --mk-bg3:         #1a1a1a;
      --mk-text:        #f0f0f0;
      --mk-muted:       rgba(255,255,255,.45);
      --mk-dim:         rgba(255,255,255,.2);
      --mk-border:      rgba(255,255,255,.08);
      --mk-border2:     rgba(255,255,255,.14);
      --mk-r:           8px;
      --mk-r-lg:        12px;
      --mk-max:         1080px;
      --mk-gutter:      clamp(20px, 5vw, 64px);
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html { scroll-behavior: smooth; }
    body {
      font-family: 'Inter', -apple-system, sans-serif;
      background: var(--mk-bg);
      color: var(--mk-text);
      font-size: 16px;
      line-height: 1.6;
      -webkit-font-smoothing: antialiased;
    }
    a { text-decoration: none; color: inherit; }
    button { font-family: inherit; cursor: pointer; }
    img { max-width: 100%; display: block; }

    /* Container */
    .mk-container {
      max-width: var(--mk-max);
      margin: 0 auto;
      padding: 0 var(--mk-gutter);
    }

    /* Buttons */
    .mk-btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 12px 24px;
      border-radius: var(--mk-r);
      font-size: 14px;
      font-weight: 600;
      border: none;
      cursor: pointer;
      transition: filter .15s, opacity .15s;
      white-space: nowrap;
    }
    .mk-btn--primary {
      background: var(--mk-accent);
      color: var(--mk-accent-text);
    }
    .mk-btn--primary:hover { filter: brightness(.92); }
    .mk-btn--ghost {
      background: transparent;
      border: 0.5px solid var(--mk-border2);
      color: var(--mk-muted);
    }
    .mk-btn--ghost:hover { border-color: rgba(255,255,255,.3); color: var(--mk-text); }
    .mk-btn--sm { padding: 8px 18px; font-size: 13px; }

    /* Section spacing */
    .mk-section { padding: clamp(48px, 7vw, 96px) 0; border-bottom: 0.5px solid var(--mk-border); }
    .mk-section:last-of-type { border-bottom: none; }

    /* Section labels */
    .mk-eyebrow {
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: .1em;
      color: var(--mk-accent);
      font-weight: 600;
      margin-bottom: 10px;
    }
    .mk-section-title {
      font-size: clamp(22px, 3.5vw, 36px);
      font-weight: 700;
      letter-spacing: -.02em;
      line-height: 1.15;
      margin-bottom: 12px;
    }
    .mk-section-sub {
      font-size: 16px;
      color: var(--mk-muted);
      max-width: 520px;
      line-height: 1.65;
      margin-bottom: 40px;
    }

    /* Nav */
    .mk-nav {
      position: sticky;
      top: 0;
      z-index: 100;
      background: rgba(12,12,12,.92);
      backdrop-filter: blur(12px);
      border-bottom: 0.5px solid var(--mk-border);
    }
    .mk-nav-inner {
      max-width: var(--mk-max);
      margin: 0 auto;
      padding: 0 var(--mk-gutter);
      height: 60px;
      display: flex;
      align-items: center;
      gap: 32px;
    }
    .mk-logo {
      display: flex;
      align-items: center;
      gap: 9px;
      font-size: 16px;
      font-weight: 700;
      letter-spacing: -.01em;
      flex-shrink: 0;
    }
    .mk-logo-mark {
      width: 26px;
      height: 26px;
      background: var(--mk-accent);
      border-radius: 6px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 12px;
      font-weight: 800;
      color: var(--mk-accent-text);
    }
    .mk-logo-mark svg { width: 18px; height: 18px; display: block; }
    .mk-nav-links {
      display: flex;
      align-items: center;
      gap: 2px;
      flex: 1;
    }
    .mk-nav-link {
      padding: 6px 14px;
      font-size: 14px;
      color: var(--mk-muted);
      border-radius: 6px;
      transition: color .12s, background .12s;
    }
    .mk-nav-link:hover { color: var(--mk-text); }
    .mk-nav-link.active { color: var(--mk-text); }
    .mk-nav-end { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
    .mk-nav-signin { font-size: 14px; color: var(--mk-muted); padding: 6px 14px; transition: color .12s; }
    .mk-nav-signin:hover { color: var(--mk-text); }

    /* Mobile nav toggle */
    .mk-hamburger {
      display: none;
      background: none;
      border: none;
      padding: 4px;
      flex-direction: column;
      gap: 5px;
      margin-left: auto;
    }
    .mk-hamburger span { display: block; width: 20px; height: 1.5px; background: var(--mk-text); border-radius: 2px; }
    .mk-mobile-nav {
      display: none;
      flex-direction: column;
      gap: 2px;
      padding: 12px var(--mk-gutter) 16px;
      border-top: 0.5px solid var(--mk-border);
      background: rgba(12,12,12,.96);
    }
    .mk-mobile-nav.open { display: flex; }
    .mk-mobile-nav a { padding: 10px 0; font-size: 15px; color: var(--mk-muted); border-bottom: 0.5px solid var(--mk-border); }

    /* Footer */
    .mk-footer { padding: clamp(32px, 5vw, 64px) 0 clamp(24px, 3vw, 40px); }
    .mk-footer-inner {
      display: grid;
      grid-template-columns: 1.5fr 1fr 1fr 1fr;
      gap: 40px;
      padding-bottom: 40px;
      border-bottom: 0.5px solid var(--mk-border);
      margin-bottom: 28px;
    }
    .mk-footer-brand-name { font-size: 15px; font-weight: 700; margin-bottom: 8px; display: flex; align-items: center; gap: 8px; }
    .mk-footer-tagline { font-size: 13px; color: var(--mk-muted); line-height: 1.6; max-width: 260px; }
    .mk-footer-col-title { font-size: 11px; text-transform: uppercase; letter-spacing: .08em; font-weight: 600; color: var(--mk-dim); margin-bottom: 12px; }
    .mk-footer-link { display: block; font-size: 13px; color: var(--mk-muted); margin-bottom: 8px; transition: color .12s; }
    .mk-footer-link:hover { color: var(--mk-text); }
    .mk-footer-bottom { display: flex; align-items: center; justify-content: space-between; }
    .mk-footer-copy { font-size: 12px; color: var(--mk-dim); }
    .mk-footer-legal { display: flex; gap: 20px; }
    .mk-footer-legal a { font-size: 12px; color: var(--mk-dim); transition: color .12s; }
    .mk-footer-legal a:hover { color: var(--mk-muted); }

    @media (max-width: 860px) {
      .mk-footer-inner { grid-template-columns: 1fr 1fr; }
      .mk-nav-links, .mk-nav-end .mk-nav-signin { display: none; }
      .mk-hamburger { display: flex; }
    }
    @media (max-width: 520px) {
      .mk-footer-inner { grid-template-columns: 1fr; }
      .mk-footer-bottom { flex-direction: column; gap: 10px; text-align: center; }
    }
  </style>
  @stack('styles')
</head>
<body>

{{-- MARKER-MKT-NAV — the one header, from Navigation --}}
@include('marketing.sections._shell_nav')

{{-- Page content --}}
@yield('content')

{{-- Footer --}}
<footer class="mk-footer">
  <div class="mk-container">
    <div class="mk-footer-inner">
      <div>
        <div class="mk-footer-brand-name">
          {{-- MARKER-BRAND-CANON --}}
          <img src="{{ \App\Support\Brand::url('logo') }}" alt="Intake" style="display:block;height:22px;width:auto">
        </div>
        <p class="mk-footer-tagline">Online booking, work orders, and customer management for service shops.</p>
      </div>
      <div>
        <div class="mk-footer-col-title">Product</div>
        <a href="{{ route('marketing.why-intake') }}" class="mk-footer-link">Why Intake</a>
        <a href="{{ route('marketing.features') }}"   class="mk-footer-link">Features</a>
        <a href="{{ route('marketing.pricing') }}"    class="mk-footer-link">Pricing</a>
        <a href="{{ route('marketing.docs') }}"       class="mk-footer-link">Docs</a>
      </div>
      <div>
        <div class="mk-footer-col-title">Company</div>
        <a href="{{ route('marketing.contact') }}"  class="mk-footer-link">Contact</a>
        <a href="#"                                 class="mk-footer-link">Blog</a>
        <a href="#"                                 class="mk-footer-link">Status</a>
      </div>
      <div>
        <div class="mk-footer-col-title">Get started</div>
        <a href="{{ route('platform.signup') }}"    class="mk-footer-link">Free trial</a>
        <a href="{{ route('platform.login') }}"     class="mk-footer-link">Sign in</a>
        <a href="#" data-open-quiz                  class="mk-footer-link">Which plan is right for me?</a>
      </div>
    </div>
    <div class="mk-footer-bottom">
      <div class="mk-footer-copy">© {{ date('Y') }} Intake. All rights reserved.</div>
      <div class="mk-footer-legal">
        <a href="#">Privacy</a>
        <a href="#">Terms</a>
      </div>
    </div>
  </div>
</footer>

<script>
function toggleMobileNav() {
  document.getElementById('mk-mobile-nav').classList.toggle('open');
}
</script>
@include('marketing._plan-quiz')

@stack('scripts')
{{-- MARKER-MKTCONV — one include for the whole marketing site: any page that
     extends this layout is tracked, including pages added later. Builder
     previews still skip it (MARKER-MKT-PARITY). --}}
@if(empty($builderPreview))
@include('marketing._funnel_tracker')
@endif
</body>
</html>
