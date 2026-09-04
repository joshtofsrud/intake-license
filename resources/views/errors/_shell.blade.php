{{-- Shared error-page shell. Used by all of errors/{404,403,419,500,503}.blade.php
     Variation lives in the @section blocks — chrome is identical across all.
     The mini-nav + footer match the marketing site brand language and use the
     same --mk-* tokens, but with no full nav/footer (less decision paralysis). --}}
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>@yield('page_title', 'Something went wrong') · Intake</title>
  <meta name="robots" content="noindex,nofollow">

  <!-- Logo system v1 (patch #44) — favicons -->
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16.png">
  <link rel="apple-touch-icon" href="/apple-touch-icon.png">
  <meta name="theme-color" content="#0c0c0c">
  <link rel="stylesheet" href="{{ asset('css/fonts.css') }}">{{-- MARKER-SELFHOST-FONTS-2 --}}
  <style>
    :root {
      --mk-accent:      #BEF264;
      --mk-bg:          #0c0c0c;
      --mk-bg2:         #141414;
      --mk-text:        #f0f0f0;
      --mk-muted:       rgba(255,255,255,.45);
      --mk-border:      rgba(255,255,255,.08);
      --mk-border-strong: rgba(255,255,255,.18);
      --mk-amber:       #FAB46A;
      --mk-red:         #F47373;
      --mk-blue:        #75A8E0;
    }
    * { box-sizing: border-box; }
    html, body { height: 100%; }
    body {
      margin: 0;
      background: var(--mk-bg);
      color: var(--mk-text);
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif;
      display: flex;
      flex-direction: column;
      min-height: 100vh;
    }
    a { color: inherit; text-decoration: none; }

    .err-mini-nav {
      padding: 22px 32px;
      display: flex; align-items: center; justify-content: space-between;
      border-bottom: 0.5px solid var(--mk-border);
    }
    .err-logo { display: flex; align-items: center; gap: 10px; }
    .err-logo-mark {
      width: 28px; height: 28px;
      background: var(--mk-accent);
      border-radius: 6px;
      display: inline-flex; align-items: center; justify-content: center;
      flex-shrink: 0;
    }
    .err-logo-mark svg { width: 18px; height: 18px; display: block; }
    .err-logo-text { font-size: 16px; font-weight: 600; letter-spacing: -.01em; }
    .err-mini-links {
      display: flex; gap: 24px;
      font-size: 13.5px;
      color: var(--mk-muted);
    }
    .err-mini-links a:hover { color: var(--mk-text); }

    .err-body {
      flex: 1;
      display: flex; align-items: center; justify-content: center;
      padding: 60px 24px;
      background: radial-gradient(ellipse at center top,
        rgba(190,242,100,.04) 0%, transparent 60%);
    }
    .err-content { max-width: 580px; text-align: center; }

    .err-eyebrow {
      display: inline-block;
      font-size: 11px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: .14em;
      color: var(--mk-muted);
      margin-bottom: 14px;
    }
    .err-eyebrow.tone-amber { color: var(--mk-amber); }
    .err-eyebrow.tone-red   { color: var(--mk-red); }
    .err-eyebrow.tone-blue  { color: var(--mk-blue); }

    .err-title {
      font-size: 38px;
      line-height: 1.15;
      font-weight: 600;
      letter-spacing: -.025em;
      margin: 0 0 16px;
    }
    .err-title-accent { color: var(--mk-accent); }

    .err-body-text {
      font-size: 16px;
      color: var(--mk-muted);
      line-height: 1.55;
      margin: 0 0 32px;
      max-width: 480px;
      margin-left: auto; margin-right: auto;
    }

    .err-actions {
      display: flex; gap: 10px;
      justify-content: center;
      flex-wrap: wrap;
    }
    .btn {
      display: inline-flex; align-items: center;
      padding: 12px 22px;
      border-radius: 8px;
      font-size: 14px;
      font-weight: 600;
      border: 1px solid transparent;
      transition: all .15s;
      cursor: pointer;
      font-family: inherit;
      background: none;
    }
    .btn-primary {
      background: var(--mk-accent);
      color: #0a0a0a;
    }
    .btn-primary:hover { filter: brightness(1.05); }
    .btn-secondary {
      color: var(--mk-text);
      border-color: var(--mk-border-strong);
    }
    .btn-secondary:hover { border-color: var(--mk-muted); }

    .err-status-block {
      background: var(--mk-bg2);
      border: 0.5px solid var(--mk-border);
      border-radius: 10px;
      padding: 18px 22px;
      margin: 28px auto 0;
      max-width: 440px;
      text-align: left;
      font-size: 13px;
      color: var(--mk-muted);
    }
    .err-status-row {
      display: flex; justify-content: space-between;
      align-items: center;
      padding: 6px 0;
    }
    .err-status-row + .err-status-row {
      border-top: 0.5px solid var(--mk-border);
    }
    .err-status-value {
      color: var(--mk-text);
      font-family: ui-monospace, monospace;
      font-size: 12px;
    }
    .err-status-pill {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 2px 8px; border-radius: 99px;
      background: rgba(250,180,106,.12);
      color: var(--mk-amber);
      font-size: 11px;
      font-weight: 600;
    }
    .err-status-pill.ok {
      background: rgba(190,242,100,.12);
      color: var(--mk-accent);
    }
    .err-status-dot {
      width: 6px; height: 6px; border-radius: 50%;
      background: currentColor;
    }

    .err-foot {
      padding: 18px 32px;
      border-top: 0.5px solid var(--mk-border);
      font-size: 12.5px;
      color: var(--mk-muted);
      text-align: center;
    }
    .err-foot a { color: var(--mk-accent); }

    @media (max-width: 600px) {
      .err-title { font-size: 28px; }
      .err-body-text { font-size: 14.5px; }
      .err-mini-nav { padding: 18px 20px; }
      .err-mini-links { display: none; }
      .err-body { padding: 48px 20px; }
    }
  </style>
</head>
<body>

<nav class="err-mini-nav">
  <a href="{{ url('/') }}" class="err-logo">
    <span class="err-logo-mark">
      <svg viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" style="width:18px;height:18px;display:block">
        <rect x="6" y="6"    width="16" height="3.5" rx="1" fill="#0a0a0a"/>
        <rect x="6" y="12.25" width="13" height="3.5" rx="1" fill="#0a0a0a"/>
        <rect x="6" y="18.5"  width="10" height="3.5" rx="1" fill="#0a0a0a"/>
      </svg>
    </span>
    <span class="err-logo-text">intake</span>
  </a>
  <div class="err-mini-links">
    @yield('mini_links')
  </div>
</nav>

<main class="err-body">
  <div class="err-content">
    <div class="err-eyebrow @yield('eyebrow_tone')">@yield('eyebrow')</div>
    <h1 class="err-title">@yield('title')</h1>
    <p class="err-body-text">@yield('body')</p>
    <div class="err-actions">
      @yield('actions')
    </div>
    @yield('status_block')
  </div>
</main>

<footer class="err-foot">
  @yield('footer_text', 'Need help? Email')
  <a href="mailto:{{ \App\Models\PlatformSettings::supportEmail() }}">{{ \App\Models\PlatformSettings::supportEmail() }}</a>
</footer>

</body>
</html>
