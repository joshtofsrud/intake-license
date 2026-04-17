{{--
    Master admin page editor layout — minimal shell for the three-column
    page builder. Used only by marketing page editing; deliberately avoids
    the tenant admin sidebar/nav so we don't have to satisfy tenant-route
    parameters.

    Required view vars:
      $pageTitle (optional) — title bar text
--}}
<!DOCTYPE html>
<html lang="en" class="ia-theme-dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $pageTitle ?? 'Page editor' }} — Intake admin</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        /* ================================================================
           Minimal admin chrome for the page editor.
           Reuses the --ia-* token names the tenant editor expects so the
           editor's own CSS works unchanged.
           ================================================================ */
        :root {
            --ia-bg:           #0c0c0c;
            --ia-surface:      #141414;
            --ia-surface-2:    #1a1a1a;
            --ia-input-bg:     #0a0a0a;
            --ia-text:         #f0f0f0;
            --ia-muted:        rgba(255,255,255,.5);
            --ia-dim:          rgba(255,255,255,.25);
            --ia-border:       rgba(255,255,255,.08);
            --ia-border2:      rgba(255,255,255,.14);
            --ia-accent:       #7C3AED;
            --ia-accent-soft:  rgba(124,58,237,.12);
            --ia-r-md:         6px;
            --ia-r-lg:         10px;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html, body {
            font-family: 'Inter', -apple-system, sans-serif;
            background: var(--ia-bg);
            color: var(--ia-text);
            font-size: 14px;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
            min-height: 100vh;
        }
        a { text-decoration: none; color: inherit; }
        button { font-family: inherit; cursor: pointer; border: none; background: none; color: inherit; }
        img { max-width: 100%; display: block; }

        /* Top bar */
        .ia-topbar {
            height: 56px;
            background: var(--ia-surface);
            border-bottom: 0.5px solid var(--ia-border);
            display: flex;
            align-items: center;
            padding: 0 20px;
            gap: 20px;
            position: sticky;
            top: 0;
            z-index: 50;
        }
        .ia-topbar-logo {
            display: flex; align-items: center; gap: 10px;
            font-weight: 700; font-size: 15px;
        }
        .ia-topbar-logo-mark {
            width: 26px; height: 26px;
            background: var(--ia-accent);
            border-radius: 6px;
            display: flex; align-items: center; justify-content: center;
            color: white; font-size: 13px; font-weight: 800;
        }
        .ia-topbar-breadcrumb {
            font-size: 13px;
            color: var(--ia-muted);
            flex: 1;
        }
        .ia-topbar-breadcrumb a { color: var(--ia-muted); transition: color .12s; }
        .ia-topbar-breadcrumb a:hover { color: var(--ia-text); }
        .ia-topbar-breadcrumb .sep { margin: 0 8px; opacity: .4; }
        .ia-topbar-breadcrumb .current { color: var(--ia-text); }

        /* Page head (reused by editor) */
        .ia-page-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 24px;
            border-bottom: 0.5px solid var(--ia-border);
        }
        .ia-page-head-left h1 { font-size: 16px; font-weight: 600; margin: 0 0 2px; }
        .ia-page-subtitle { font-size: 12px; color: var(--ia-muted); margin: 0; }
        .ia-page-actions { display: flex; gap: 8px; align-items: center; }

        /* Buttons */
        .ia-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: var(--ia-r-md);
            font-size: 13px;
            font-weight: 500;
            border: 0.5px solid var(--ia-border2);
            background: var(--ia-surface);
            color: var(--ia-text);
            cursor: pointer;
            transition: all .12s;
        }
        .ia-btn:hover { border-color: rgba(255,255,255,.25); }
        .ia-btn--sm { padding: 5px 11px; font-size: 12px; }
        .ia-btn--ghost { background: transparent; color: var(--ia-muted); }
        .ia-btn--ghost:hover { color: var(--ia-text); }
        .ia-btn--secondary { background: var(--ia-surface-2); }
        .ia-btn--primary {
            background: var(--ia-accent);
            color: white;
            border-color: var(--ia-accent);
        }
        .ia-btn--primary:hover { filter: brightness(.92); }
        .ia-btn--danger { color: #EF4444; }
        .ia-btn--danger:hover { border-color: rgba(239,68,68,.4); color: #F87171; }

        /* Editor content container */
        .ia-editor-content { padding: 24px; }
    </style>

    <script>
        // Global the editor script expects — CSRF token for auto-save calls.
        window.IntakeAdmin = { csrfToken: '{{ csrf_token() }}' };
    </script>

    @stack('styles')
</head>
<body>

<div class="ia-topbar">
    <a href="/admin" class="ia-topbar-logo">
        <div class="ia-topbar-logo-mark">I</div>
        Intake
    </a>
    <div class="ia-topbar-breadcrumb">
        <a href="/admin">Admin</a>
        <span class="sep">/</span>
        <a href="/admin/marketing-pages">Marketing pages</a>
        <span class="sep">/</span>
        <span class="current">{{ $page->title ?? 'Editor' }}</span>
    </div>
</div>

<div class="ia-editor-content">
    @yield('content')
</div>

@stack('scripts')
</body>
</html>
