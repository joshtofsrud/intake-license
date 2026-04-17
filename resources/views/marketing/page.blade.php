{{--
    Marketing page — base layout + section loop.

    Serves pages from the platform tenant (intake.works, /pricing, /for/*, etc.).
    Renders each visible section via its partial. Section partials live in
    resources/views/marketing/sections/*.blade.php — one per section type.

    Variables available:
      $page      — TenantPage (platform tenant)
      $sections  — Collection<TenantPageSection> (visible only, ordered)
      $navItems  — Collection<TenantNavItem> (platform nav)
      $tenant    — Tenant (the platform tenant — used for logo/colors)
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

    {{-- Open Graph — matters for social sharing of industry pages --}}
    <meta property="og:title" content="{{ $page->meta_title ?? $page->title }}">
    @if($page->meta_description)
        <meta property="og:description" content="{{ $page->meta_description }}">
    @endif
    <meta property="og:site_name" content="Intake">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --mk-accent:     {{ $tenant->accent_color ?? '#7C3AED' }};
            --mk-text:       {{ $tenant->text_color ?? '#111111' }};
            --mk-text-muted: #4B5563;
            --mk-bg:         {{ $tenant->bg_color ?? '#FFFFFF' }};
            --mk-border:     #E5E7EB;
            --mk-radius:     12px;
        }
        * { box-sizing: border-box; }
        html, body {
            margin: 0; padding: 0;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            color: var(--mk-text);
            background: var(--mk-bg);
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }
        a { color: inherit; text-decoration: none; }
        img { max-width: 100%; display: block; }

        .mk-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 24px;
        }

        .mk-section { padding: 80px 0; }
        .mk-section--tight  { padding: 48px 0; }
        .mk-section--none   { padding: 0; }
        .mk-section--wide   { padding: 120px 0; }

        .mk-btn {
            display: inline-block;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 15px;
            transition: all .15s ease;
            cursor: pointer;
            border: none;
        }
        .mk-btn--primary {
            background: var(--mk-accent);
            color: white;
        }
        .mk-btn--primary:hover { opacity: .9; transform: translateY(-1px); }
        .mk-btn--ghost {
            background: transparent;
            border: 1px solid rgba(255,255,255,.3);
            color: white;
        }
        .mk-btn--ghost:hover { background: rgba(255,255,255,.1); }
        .mk-btn--secondary {
            background: transparent;
            border: 1px solid var(--mk-border);
            color: var(--mk-text);
        }
        .mk-btn--secondary:hover { border-color: var(--mk-accent); color: var(--mk-accent); }

        h1, h2, h3, h4 { margin: 0 0 16px; font-weight: 700; letter-spacing: -.02em; }
        h1 { font-size: clamp(32px, 5vw, 56px); line-height: 1.1; }
        h2 { font-size: clamp(24px, 3.5vw, 36px); line-height: 1.2; }
        h3 { font-size: 20px; }
        p  { margin: 0 0 16px; color: var(--mk-text-muted); }
    </style>
</head>
<body>

@foreach($sections as $section)
    @php
        $c = $section->content ?? [];
        $type = $section->section_type;
        $partial = 'marketing.sections.' . $type;
        $padding = 'mk-section--' . ($section->padding ?? 'normal');
    @endphp

    @if(view()->exists($partial))
        @include($partial, [
            'c' => $c,
            'section' => $section,
            'padding' => $padding,
            'navItems' => $navItems,
            'tenant' => $tenant,
            'industry' => $industry,
        ])
    @else
        <div style="background:#fef3c7;color:#92400e;padding:12px 24px;font-size:13px;text-align:center">
            No renderer for section type: <code>{{ $type }}</code>
        </div>
    @endif
@endforeach

</body>
</html>
