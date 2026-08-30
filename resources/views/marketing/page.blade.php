{{--
    Marketing page — base layout + section loop.

    Dark theme (#0c0c0c bg + lime accent #BEF264) ported from the old
    marketing/layout.blade.php. All pages served under the platform tenant
    inherit this shell. The sticky nav and footer are built into the shell
    rather than added as sections, so every marketing page has consistent
    navigation without the editor having to think about it.

    Variables available:
      $page      — TenantPage (platform tenant)
      $sections  — Collection<TenantPageSection> (visible, ordered)
      $navItems  — Collection<TenantNavItem> (platform nav)
      $tenant    — Tenant (the platform tenant)
      $industry  — array|null (set on /for/{slug} pages, see MarketingController)
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @include('partials.mobile-input-zoom') {{-- MARKER-MOBILE-INPUT-ZOOM --}}

    <title>{{ $page->meta_title ?? ($page->title . ' — Intake') }}</title>

    @if($page->meta_description)
        <meta name="description" content="{{ $page->meta_description }}">
    @endif

    <meta property="og:title" content="{{ $page->meta_title ?? $page->title }}">
    @if($page->meta_description)
        <meta property="og:description" content="{{ $page->meta_description }}">
    @endif
    <meta property="og:site_name" content="Intake">


    {{-- Patch #44 favicon links + OG meta — match the static-layout shell --}}
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16.png">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <meta name="theme-color" content="#0c0c0c">

    {{-- OG/Twitter card --}}
    <meta property="og:image" content="{{ url('/og-image.png') }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:image" content="{{ url('/og-image.png') }}">

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

        .mk-container {
            max-width: var(--mk-max);
            margin: 0 auto;
            padding: 0 var(--mk-gutter);
        }

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
        .mk-btn--primary { background: var(--mk-accent); color: var(--mk-accent-text); }
        .mk-btn--primary:hover { filter: brightness(.92); }
        .mk-btn--ghost {
            background: transparent;
            border: 0.5px solid var(--mk-border2);
            color: var(--mk-muted);
        }
        .mk-btn--ghost:hover { border-color: rgba(255,255,255,.3); color: var(--mk-text); }
        .mk-btn--sm { padding: 8px 18px; font-size: 13px; }

        .mk-section {
            padding: clamp(48px, 7vw, 96px) 0;
            border-bottom: 0.5px solid var(--mk-border);
        }
        .mk-section:last-of-type { border-bottom: none; }

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

        .mk-logo { display: flex; align-items: center; gap: 9px; font-size: 16px; font-weight: 700; letter-spacing: -.01em; flex-shrink: 0; }
        .mk-logo-mark {
            width: 26px; height: 26px;
            background: var(--mk-accent);
            border-radius: 6px;
            display: flex; align-items: center; justify-content: center;
            font-size: 12px; font-weight: 800;
            color: var(--mk-accent-text);
        }
    </style>
</head>
<body>

{{-- Nav (shell — always present) --}}
@include('marketing.sections._shell_nav', ['navItems' => $navItems])

{{-- MARKER-MKT-PARITY — hover highlight + click-to-select, and the scroll-to
     handler the builder calls. Builder preview only; port of the tenant
     MARKER-BUILDER-SYNC block in public/layout.blade.php. --}}
@if(!empty($builderPreview))
<style>
  [data-pb-section] { position: relative; }
  [data-pb-section]::after {
    content: ''; position: absolute; inset: 0; z-index: 2147483000;
    pointer-events: none; opacity: 0;
    outline: 2px solid #BEF264; outline-offset: -2px;
    background: rgba(190,242,100,.06);
    transition: opacity .12s;
  }
  [data-pb-section].pb-hover::after,
  [data-pb-section].pb-flash::after { opacity: 1; }
  [data-pb-section].pb-flash::after { transition: opacity .35s; }
  [data-pb-section] { cursor: pointer; }
</style>
<script>
(function () {
  function boot() {
  var wraps = Array.prototype.slice.call(document.querySelectorAll('[data-pb-section]'));
  if (!wraps.length) return;

  function post(msg) {
    try { parent.postMessage(msg, window.location.origin); } catch (e) {}
  }

  wraps.forEach(function (w) {
    w.addEventListener('mouseenter', function () {
      wraps.forEach(function (o) { o.classList.remove('pb-hover'); });
      w.classList.add('pb-hover');
    });
    w.addEventListener('mouseleave', function () { w.classList.remove('pb-hover'); });

    w.addEventListener('click', function (e) {
      if (e.target.closest('a, button, input, select, textarea, label')) return;
      e.preventDefault();
      post({ source: 'pb-preview', type: 'select', id: w.dataset.pbSection, sectionType: w.dataset.pbType });
    }, true);
  });

  window.addEventListener('message', function (e) {
    if (e.origin !== window.location.origin) return;
    var d = e.data || {};
    if (d.source !== 'pb-builder' || d.type !== 'scrollTo') return;
    var el = document.querySelector('[data-pb-section="' + d.id + '"]');
    if (!el) return;
    el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    el.classList.add('pb-flash');
    setTimeout(function () { el.classList.remove('pb-flash'); }, 900);
  });

  post({ source: 'pb-preview', type: 'ready' });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
</script>
@endif

{{-- Page content --}}
@foreach($sections as $section)
    @php
        $c = $section->content ?? [];
        $type = $section->section_type;
        // Shell-only sections (nav, footer) are skipped — they're rendered
        // by the layout itself, not as editable blocks. Editing nav/footer
        // sections in the builder is a no-op; they stay in the DB but don't
        // render twice. Keep them filterable so older pages don't regress.
        if (in_array($type, ['nav', 'footer'])) continue;

        $partial = 'marketing.sections.' . $type;

        // Padding: content override > section column > default ('normal')
        $paddingValue = $c['padding_override'] ?? $section->padding ?? 'normal';
        $padding = 'mk-section--' . $paddingValue;

        // Margin override — only applied if explicitly set.
        $marginMap = [
            'none'   => '0',
            'small'  => 'clamp(12px, 2vw, 24px)',
            'normal' => 'clamp(24px, 4vw, 48px)',
            'large'  => 'clamp(48px, 6vw, 80px)',
        ];
        $marginValue = $c['margin_override'] ?? null;
        $marginCss = $marginValue && isset($marginMap[$marginValue])
            ? "margin-top:{$marginMap[$marginValue]};margin-bottom:{$marginMap[$marginValue]};"
            : '';

        // Inline section-level style assembly (bg, text color, margin).
        $inlineStyle = '';
        if (! empty($section->bg_color)) {
            $inlineStyle .= "background:{$section->bg_color};";
        }
        if (! empty($c['text_color'])) {
            $inlineStyle .= "color:{$c['text_color']};";
        }
        $inlineStyle .= $marginCss;

        // Border radius map (for per-block use inside partials).
        $radiusMap = ['none' => '0', 'sm' => '4px', 'md' => '8px', 'lg' => '14px', 'xl' => '20px'];
        $borderRadiusValue = $c['border_radius'] ?? null;
        $borderRadius = $borderRadiusValue && isset($radiusMap[$borderRadiusValue])
            ? $radiusMap[$borderRadiusValue]
            : null;
    @endphp

    @if(view()->exists($partial))
        @if(!empty($builderPreview))<div data-pb-section="{{ $section->id }}" data-pb-type="{{ $section->section_type }}">@endif
        @include($partial, [
            'c' => $c,
            'section' => $section,
            'padding' => $padding,
            'inlineStyle' => $inlineStyle,
            'borderRadius' => $borderRadius,
            'navItems' => $navItems,
            'tenant' => $tenant,
            'industry' => $industry,
        ])
        @if(!empty($builderPreview))</div>@endif
    @else
        <div style="background:#3b1d0b;color:#ffcc80;padding:12px 24px;font-size:13px;text-align:center;border-top:0.5px solid rgba(255,255,255,.08)">
            No renderer for section type: <code>{{ $type }}</code>
        </div>
    @endif
@endforeach

{{-- Footer (shell — always present) --}}
@include('marketing.sections._shell_footer', ['navItems' => $navItems])

<script>
    function toggleMobileNav() {
        document.getElementById('mk-mobile-nav').classList.toggle('open');
    }
</script>
@include('marketing._plan-quiz')
@if(empty($builderPreview))
@include('marketing._funnel_tracker') {{-- MARKER-MKTTRAFFIC — skipped in builder previews (MARKER-MKT-PARITY) --}}
@endif
</body>
</html>
