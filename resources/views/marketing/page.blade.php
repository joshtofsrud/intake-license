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
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"> {{-- MARKER-MKT-NAV-EDGE --}}
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
    <link rel="icon" type="image/svg+xml" href="{{ \App\Support\Brand::url('favicon') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ \App\Support\Brand::url('favicon_32') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ \App\Support\Brand::url('favicon_16') }}">
    <link rel="apple-touch-icon" href="{{ \App\Support\Brand::url('apple') }}">
    @php
        // MARKER-MKT-NAV-EDGE — the browser's top colour matches the first section.
        $mkTop = '#0c0c0c';
        $mkFirst = collect($sections ?? [])->first(fn ($s) => ! in_array($s->section_type, ['nav', 'footer'], true));
        if ($mkFirst) {
            $mkFc = $mkFirst->content ?? [];
            $mkCand = ($mkFc['bg_mode'] ?? '') === 'gradient' ? ($mkFc['bg_gradient_from'] ?? null) : ($mkFirst->bg_color ?? null);
            if (is_string($mkCand) && preg_match('/^#[0-9a-fA-F]{6}$/', $mkCand)) $mkTop = $mkCand;
        }
    @endphp
    <meta name="theme-color" content="{{ $mkTop }}">

    {{-- OG/Twitter card --}}
    <meta property="og:image" content="{{ \App\Support\Brand::shareImageFor($page) }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:image" content="{{ \App\Support\Brand::shareImageFor($page) }}">

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
        /* MARKER-MKT-SECTION-LAYOUT — each section sits in a .mkw wrapper, so the
           last one is marked by the loop; :last-of-type would match them all. */
        .mkw-last > .mk-section { border-bottom: none; }
        /* MARKER-MKT-DIVIDER — no divider lines unless a section asks for one */
        /* MARKER-MKT-HAIRLINE-ROOT — no border at all (a transparent one shows the gradient's first row repeated) */
        .mkw > section, .mkw > footer { border-bottom-width: 0 !important; border-top-width: 0 !important; }
        .mkw.mkw-divider > section { border-bottom: 0.5px solid var(--mk-border) !important; background-origin: border-box !important; }
        /* MARKER-MKT-APPEAR */
        /* MARKER-MKT-APPEAR-CONTENT — the content appears; the background is always there */
        html.mk-appear-on .mk-appear > section > *, html.mk-appear-on .mk-appear > footer > * { opacity: 0; transition: opacity .7s ease, transform .7s ease; transition-delay: var(--mk-appear-delay, 0ms); }
        html.mk-appear-on .mk-appear-up > section > *, html.mk-appear-on .mk-appear-up > footer > * { transform: translateY(28px); }
        html.mk-appear-on .mk-appear.is-in > section > *, html.mk-appear-on .mk-appear.is-in > footer > * { opacity: 1; transform: none; }
        /* MARKER-MKT-BG-CHAIN — sections sharing a gradient show no divider between them. */
        .mkw[data-bg-chain] > section { border-bottom-width: 0 !important; }
        @media (max-width: 768px) { .mkw-hide-m { display: none !important; } }
        /* MARKER-MKT-HIDE-TABLET — phone ≤768, tablet 769–1024, desktop ≥1025 */
        @media (min-width: 769px) and (max-width: 1024px) { .mkw-hide-t { display: none !important; } }
        @media (min-width: 1025px) { .mkw-hide-d { display: none !important; } }

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
    <script>/* MARKER-MKT-APPEAR — hide appearing sections only when they can be shown again */
      if (!(window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches) && 'IntersectionObserver' in window) document.documentElement.classList.add('mk-appear-on');
    </script>
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
  /* MARKER-PB-HIDDEN-TAGS — a section hidden on the previewed screen takes no space (no stray outline) */
  @media (max-width: 768px) { [data-pb-section]:has(.mkw-hide-m) { display: none; } }
  @media (min-width: 769px) and (max-width: 1024px) { [data-pb-section]:has(.mkw-hide-t) { display: none; } }
  @media (min-width: 1025px) { [data-pb-section]:has(.mkw-hide-d) { display: none; } }
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
@php
    // MARKER-MKT-BG-CHAIN — each Gradient section's gradient, handed to the
    // painter below, which joins it with the visible sections continuing it.
    $mkHex     = fn ($v, $d) => is_string($v) && preg_match('/^#[0-9a-fA-F]{3,8}$/', trim($v)) ? trim($v) : $d;
    $mkGradCss = [];
    foreach ($sections as $mkS) {
        $mkC = $mkS->content ?? [];
        if (($mkC['bg_mode'] ?? '') !== 'gradient') continue;
        $mkOp = max(0, min(100, (int) ($mkC['bg_opacity'] ?? 100)));
        $mkF  = fn ($col) => $mkOp >= 100 ? $col : 'color-mix(in srgb, ' . $col . ' ' . $mkOp . '%, transparent)';
        $mkGradCss[(string) $mkS->id] = 'linear-gradient(' . (int) ($mkC['bg_gradient_angle'] ?? 135) . 'deg, '
            . $mkF($mkHex($mkC['bg_gradient_from'] ?? null, '#1a1a1a')) . ' 0%, '
            . (! empty($mkC['bg_fade_out'])
                ? 'color-mix(in srgb, ' . $mkHex($mkC['bg_gradient_to'] ?? null, '#0a0a0a') . ' 0%, transparent)'
                : $mkF($mkHex($mkC['bg_gradient_to'] ?? null, '#0a0a0a')))
            . ' ' . max(20, min(100, (int) ($mkC['bg_grad_end'] ?? 100))) . '%)';
    }
@endphp
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

        // MARKER-MKT-SECTION-LAYOUT — the editor's shared Layout settings,
        // applied around every section so none of them silently does nothing.
        // "Normal" (the editor default) keeps the section's own spacing; only
        // None / Compact / Spacious override it. Sections that already handle
        // their own anchor or classes are left to do so.
        $mkwId     = 'mkw-' . substr(md5((string) $section->id), 0, 8);
        $mkwPad    = ['none' => '0', 'compact' => 'clamp(24px, 4vw, 48px)', 'spacious' => 'clamp(80px, 10vw, 140px)'];
        // MARKER-MKT-LEGACY-PAD — sections on the older setting have no spacing of their own.
        $mkwLegacy = $c['padding_override'] ?? (in_array($type, ['book_call', 'try_demo'], true) ? 'normal' : null);
        $mkwPadL   = $mkwPad + ['normal' => 'clamp(48px, 7vw, 96px)'];
        // MARKER-MKT-HERO-PAD — the hero applies its own padding presets.
        $mkwTop    = $type === 'hero' ? null : (isset($c['padding_top'])    ? ($mkwPad[$c['padding_top']] ?? null)    : ($mkwLegacy !== null ? ($mkwPadL[$mkwLegacy] ?? null) : null));
        $mkwBot    = $type === 'hero' ? null : (isset($c['padding_bottom']) ? ($mkwPad[$c['padding_bottom']] ?? null) : ($mkwLegacy !== null ? ($mkwPadL[$mkwLegacy] ?? null) : null));
        $mkwAnchor = in_array($type, ['custom_html', 'image_carousel', 'book_call', 'feature_groups', 'try_demo'], true)
            ? '' : preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($c['anchor_id'] ?? ''));
        $mkwExtra  = in_array($type, ['custom_html', 'image_carousel'], true)
            ? '' : trim(preg_replace('/[^A-Za-z0-9_ -]/', '', (string) ($c['custom_classes'] ?? '')));
        $mkwColor  = fn ($v) => is_string($v) && preg_match('/^(#[0-9a-fA-F]{3,8}|rgba?\([0-9.,\s%]+\)|[a-zA-Z]+)$/', trim($v)) ? trim($v) : null;
        $mkwHead   = $type === 'hero' ? null : $mkwColor($c['text_color'] ?? null);
        $mkwBody   = $type === 'hero' ? null : $mkwColor($c['text_color_body'] ?? null);
        $mkwLast   = collect($sections)->slice($loop->index + 1)->every(fn ($s) => in_array($s->section_type, ['nav', 'footer'], true));
        // MARKER-MKT-FLOAT-OVERLAP — the first drawn section can sit behind a Floating header.
        $mkwFirst  = collect($sections)->slice(0, $loop->index)->every(fn ($s) => in_array($s->section_type, ['nav', 'footer'], true));
        // MARKER-MKT-BG-BLEND — $mkBgPrev is the colour the section above ended on.
        $mkBgPrev  = $mkBgCarry ?? null;
        $mkBgOp    = max(0, min(100, (int) ($c['bg_opacity'] ?? 100)));
        $mkBgFade  = fn ($col) => $mkBgOp >= 100 ? $col : 'color-mix(in srgb, ' . $col . ' ' . $mkBgOp . '%, transparent)';
        $mkBgMode  = $c['bg_mode'] ?? 'color';
        $mkBgHex   = fn ($v) => is_string($v) && preg_match('/^#[0-9a-fA-F]{3,8}$/', trim($v)) ? trim($v) : null;
        if ($mkBgMode === 'gradient') {
            $mkBgCarry = ! empty($c['bg_fade_out']) ? null : $mkBgFade($mkBgHex($c['bg_gradient_to'] ?? null) ?: '#0a0a0a'); // faded out = page background
        } elseif ($mkBgMode === 'color' && $mkBgHex($section->bg_color ?? null)) {
            $mkBgCarry = $mkBgFade($mkBgHex($section->bg_color));
        } else {
            $mkBgCarry = null;
        }
        // MARKER-MKT-NO-LINE — a section with its own background needs no divider line.
        $mkwNoLine = ($c['bg_mode'] ?? 'none') !== 'none' && ($c['bg_mode'] ?? '') !== 'color' || (($c['bg_mode'] ?? '') === 'color' && ! empty($section->bg_color));
        $mkwClass  = trim('mkw ' . $mkwId . ($mkwNoLine ? ' mkw-noline' : '') . ($mkwFirst ? ' mkw-first' : '')
            . (! empty($c['hide_on_mobile'])  ? ' mkw-hide-m' : '')
            . (! empty($c['hide_on_desktop']) ? ' mkw-hide-d' : '')
            . (! empty($c['hide_on_tablet'])  && ! in_array((string) $c['hide_on_tablet'], ['0', 'false'], true) ? ' mkw-hide-t' : '')
            . ($mkwLast ? ' mkw-last' : '')
            . (! empty($c['divider_below']) && ! in_array((string) $c['divider_below'], ['0', 'false'], true) ? ' mkw-divider' : '') // MARKER-MKT-DIVIDER
            . (in_array($c['appear'] ?? '', ['fade', 'up'], true) ? ' mk-appear' . (($c['appear'] ?? '') === 'up' ? ' mk-appear-up' : '') : '') // MARKER-MKT-APPEAR
            . ($mkwExtra !== '' ? ' ' . $mkwExtra : ''));
    @endphp

    @if(view()->exists($partial))
        {{-- MARKER-SECTION-OVERLAP — same treatment as the tenant renderer, so
             intake.works and a shop's own site behave identically. --}}
        @php
          $pull   = max(0, min(240, (int) ($c['overlap_top'] ?? 0)));
          $pullP  = max(0, min(240, (int) ($c['overlap_top_phone'] ?? 0))); // MARKER-OVERLAP-PHONE
          $pullId = 'pbpull-' . substr(md5((string) $section->id), 0, 10); // no short-id collisions
        @endphp
        {{-- MARKER-MKT-OVERLAP-LIVE — the preview's redrawn wrapper now holds the overlap too. --}}
        @if(!empty($builderPreview))<div data-pb-section="{{ $section->id }}" data-pb-type="{{ $section->section_type }}">@endif
        @if($pull > 0 || $pullP > 0)
          <style>
            .{{ $pullId }} { margin-top: -{{ $pull }}px; position: relative; z-index: 2; }
            @media (max-width: 768px) { .{{ $pullId }} { margin-top: -{{ $pullP }}px; } }
          </style>
          <div class="{{ $pullId }}">
        @endif
        <div class="{{ $mkwClass }}" @if(in_array($c['appear'] ?? '', ['fade', 'up'], true)) style="--mk-appear-delay: {{ max(0, min(1000, (int) ($c['appear_delay'] ?? 0))) }}ms" @endif @if($mkwAnchor !== '') id="{{ $mkwAnchor }}" @endif @isset($mkGradCss[(string) $section->id]) data-bg-grad="{{ $mkGradCss[(string) $section->id] }}" @endisset @if(! empty($c['bg_continue']) && ! in_array((string) $c['bg_continue'], ['0', 'false'], true)) data-bg-cont="1" @endif>
        @if($mkwTop !== null || $mkwBot !== null || $mkwHead || $mkwBody)
          <style>
            @if($mkwTop !== null) .{{ $mkwId }} > section, .{{ $mkwId }} > footer, .{{ $mkwId }} > div { padding-top: {{ $mkwTop }} !important; } @endif
            @if($mkwBot !== null) .{{ $mkwId }} > section, .{{ $mkwId }} > footer, .{{ $mkwId }} > div { padding-bottom: {{ $mkwBot }} !important; } @endif
            @if($mkwHead) .{{ $mkwId }} h1, .{{ $mkwId }} h2, .{{ $mkwId }} h3, .{{ $mkwId }} h4, .{{ $mkwId }} .mk-section-title { color: {{ $mkwHead }}; } @endif
            @if($mkwBody) .{{ $mkwId }} p, .{{ $mkwId }} li, .{{ $mkwId }} .mk-section-sub { color: {{ $mkwBody }}; } @endif
          </style>
        @endif
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
        </div>
        @if($pull > 0 || $pullP > 0)</div>@endif
        @if(!empty($builderPreview))</div>@endif
    @else
        <div style="background:#3b1d0b;color:#ffcc80;padding:12px 24px;font-size:13px;text-align:center;border-top:0.5px solid rgba(255,255,255,.08)">
            No renderer for section type: <code>{{ $type }}</code>
        </div>
    @endif
@endforeach

<script>
/* MARKER-MKT-APPEAR-VIEW — reveal each section when its top reaches 85% of the window. */
(function () {
  if (!document.documentElement.classList.contains('mk-appear-on')) return;
  var pending = Array.prototype.slice.call(document.querySelectorAll('.mk-appear'));
  var raf = 0;
  function check() {
    raf = 0;
    var line = window.innerHeight * 0.85;
    pending = pending.filter(function (el) {
      if (el.getClientRects().length === 0) return true;          // hidden on this screen size
      if (el.getBoundingClientRect().top < line) { el.classList.add('is-in'); return false; }
      return true;
    });
    if (!pending.length) { window.removeEventListener('scroll', onScroll); window.removeEventListener('resize', onScroll); }
  }
  function onScroll() { if (!raf) raf = requestAnimationFrame(check); }
  window.addEventListener('scroll', onScroll, { passive: true });
  window.addEventListener('resize', onScroll);
  window.addEventListener('load', check);
  check();
})();
</script>
<script>
/* MARKER-MKT-BG-CHAIN — join each gradient with the VISIBLE sections that
   continue it (sections hidden on this screen size are skipped), and paint
   one gradient across them. Re-measured whenever sizes change. */
(function () {
  var PROPS = ['background', 'background-size', 'background-position', 'background-repeat'];
  function sec(el) { return el.querySelector(':scope > section') || el.querySelector(':scope > footer'); }
  // MARKER-MKT-BG-CHAIN-PRECISE — exact (sub-pixel) page position
  function docTop(el) { return el.getBoundingClientRect().top + window.scrollY; }
  function paint() {
    document.querySelectorAll('.mkw[data-bg-chain]').forEach(function (el) {
      el.removeAttribute('data-bg-chain');
      var s = sec(el); if (s) PROPS.forEach(function (p) { s.style.removeProperty(p); });
    });
    var all = Array.prototype.filter.call(document.querySelectorAll('.mkw'), function (el) { return el.getClientRects().length > 0; });
    for (var i = 0; i < all.length; i++) {
      var head = all[i];
      if (!head.dataset.bgGrad || head.dataset.bgCont) continue;
      var j = i + 1;
      while (j < all.length && all[j].dataset.bgCont) j++;
      if (j === i + 1) continue;
      var chain = all.slice(i, j);
      var top = docTop(chain[0]);
      var lastEl = chain[chain.length - 1];
      var total = lastEl.getBoundingClientRect().bottom + window.scrollY - top;
      chain.forEach(function (el) {
        var s = sec(el); if (!s) return;
        el.setAttribute('data-bg-chain', '1');
        s.style.setProperty('background', head.dataset.bgGrad, 'important');
        s.style.setProperty('background-size', '100% ' + total + 'px', 'important');
        s.style.setProperty('background-position', '0 ' + (top - docTop(s)) + 'px', 'important');
        s.style.setProperty('background-repeat', 'no-repeat', 'important');
      });
      i = j - 1;
    }
  }
  var t;
  function soon() { clearTimeout(t); t = setTimeout(paint, 60); }
  paint();
  window.addEventListener('load', paint);
  window.addEventListener('resize', soon);
  if (window.ResizeObserver) new ResizeObserver(soon).observe(document.body);
})();
</script>

{{-- Footer (shell — always present) --}}
@if(empty($hideFooter)) {{-- MARKER-MKT-NAV-POLISH --}}
@include('marketing.sections._shell_footer', ['navItems' => $navItems])
@endif

<script>
    function toggleMobileNav() {
        document.getElementById('mk-mobile-nav').classList.toggle('open');
    }
</script>
@include('marketing._plan-quiz')
{{-- MARKER-MKTREPAIR — the tracker has to be HERE. MARKER-MKTCONV moved it to
     marketing/layout.blade.php, but every routed marketing page is rendered by
     MarketingController::renderPage into THIS file, which carries its own
     <html> and extends no layout. The include went somewhere nothing renders,
     and the marketing site recorded nothing from that deploy onward. The
     layout keeps its copy for the old static views; the script's own
     __intakeMktFunnel guard makes a double include harmless. --}}
@if(empty($builderPreview))
@include('marketing._funnel_tracker')
@endif
</body>
</html>
