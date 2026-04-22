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

    <title>{{ $page->meta_title ?? ($page->title . ' — Intake') }}</title>

    @if($page->meta_description)
        <meta name="description" content="{{ $page->meta_description }}">
    @endif

    <meta property="og:title" content="{{ $page->meta_title ?? $page->title }}">
    @if($page->meta_description)
        <meta property="og:description" content="{{ $page->meta_description }}">
    @endif
    <meta property="og:site_name" content="Intake">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

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
</body>
</html>
