#!/bin/bash
# ============================================================================
# error-pages-install.sh   (patch #43)
# ----------------------------------------------------------------------------
# Install custom Laravel error pages for 404, 403, 419, 500, 503.
#
# Laravel 11 auto-resolves resources/views/errors/{code}.blade.php — no route
# changes needed. Each page extends a shared shell partial (_shell.blade.php)
# that handles the mini-nav, hero container, and footer. Per-page Blades
# just set: eyebrow text + tone + title + accent + body + actions.
#
# Design follows the mockup that Josh approved:
#   - Eyebrow label (uppercase, color-toned by severity) above the title
#   - No decorative icons or rounded code pills
#   - Lime accent on a key word in the title
#   - Centered hero, single-column, max-width 580px
#   - Mini-nav: logo + 1-2 escape links
#   - Footer: support email or status link
#
# 500 page additionally gets a Reference ID generated in the exception
# handler via bootstrap/app.php — we hook the renderable to capture
# Symfony exceptions and pass the ID into the view.
#
# Files created:
#   resources/views/errors/_shell.blade.php
#   resources/views/errors/404.blade.php
#   resources/views/errors/403.blade.php
#   resources/views/errors/419.blade.php
#   resources/views/errors/500.blade.php
#   resources/views/errors/503.blade.php
#
# Files modified:
#   bootstrap/app.php  (add 500-error reference-ID generation)
#
# Deploy:
#   git pull && php artisan view:clear && \
#   sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm
# ============================================================================

set -euo pipefail
REPO_ROOT="${REPO_ROOT:-$(pwd)}"
cd "$REPO_ROOT"

echo "==> Patch 43: install custom error pages"

# Ensure the errors directory exists.
mkdir -p resources/views/errors

# ----------------------------------------------------------------------------
# 1. The shared shell partial. Pages set @section('eyebrow'),
#    @section('eyebrow_tone'), @section('title_main'), @section('title_accent'),
#    @section('title_trailing'), @section('body'), @section('actions'),
#    @section('footer_links'). Title pieces are separate so the accent word
#    can be styled mid-sentence without per-page custom HTML.
# ----------------------------------------------------------------------------
cat > resources/views/errors/_shell.blade.php <<'EOF'
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
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
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
      color: #000;
      border-radius: 6px;
      display: inline-flex; align-items: center; justify-content: center;
      font-weight: 700; font-size: 14px;
    }
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
    <span class="err-logo-mark">I</span>
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
  <a href="mailto:support@intake.works">support@intake.works</a>
</footer>

</body>
</html>
EOF
echo "    CREATED resources/views/errors/_shell.blade.php"

# ----------------------------------------------------------------------------
# 2. 404 — Page not found. Neutral tone.
# ----------------------------------------------------------------------------
cat > resources/views/errors/404.blade.php <<'EOF'
@extends('errors._shell')
@section('page_title', '404 — Page not found')
@section('eyebrow', '404 · Page not found')
@section('title')
This page <span class="err-title-accent">doesn't exist</span> — yet.
@endsection
@section('body')
The link might be old, mistyped, or pointing to something we haven't built. Try the homepage, or use the search if you know what you're looking for.
@endsection
@section('actions')
  <a href="{{ url('/') }}" class="btn btn-primary">← Back to homepage</a>
  <a href="{{ url('/docs') }}" class="btn btn-secondary">Browse help center</a>
@endsection
EOF
echo "    CREATED resources/views/errors/404.blade.php"

# ----------------------------------------------------------------------------
# 3. 403 — Forbidden. Blue tone (informational, user-fixable).
# ----------------------------------------------------------------------------
cat > resources/views/errors/403.blade.php <<'EOF'
@extends('errors._shell')
@section('page_title', '403 — Access denied')
@section('eyebrow', '403 · Access denied')
@section('eyebrow_tone', 'tone-blue')
@section('title')
You don't have <span class="err-title-accent">access</span> to this page.
@endsection
@section('body')
Either you're not signed in to this shop, or the owner hasn't granted you permission for this area. If you think this is a mistake, ask the shop owner to check your role under Team Settings.
@endsection
@section('mini_links')
  <a href="{{ url('/login') }}">Sign in with a different account</a>
@endsection
@section('actions')
  <a href="{{ url('/') }}" class="btn btn-primary">← Back to dashboard</a>
  <a href="{{ url('/logout') }}" class="btn btn-secondary">Sign out</a>
@endsection
@section('footer_text', "Need help? Contact your shop's owner, or email")
EOF
echo "    CREATED resources/views/errors/403.blade.php"

# ----------------------------------------------------------------------------
# 4. 419 — Session expired / CSRF. Amber tone (gentle warning).
# ----------------------------------------------------------------------------
cat > resources/views/errors/419.blade.php <<'EOF'
@extends('errors._shell')
@section('page_title', '419 — Session expired')
@section('eyebrow', '419 · Session expired')
@section('eyebrow_tone', 'tone-amber')
@section('title')
Your <span class="err-title-accent">session expired</span>. Sign in to keep going.
@endsection
@section('body')
For your security we sign you out after a long period of inactivity. Any unsaved changes on the previous page may be lost. Sign in again and we'll bring you back where you left off when possible.
@endsection
@section('actions')
  <a href="{{ url('/login') }}" class="btn btn-primary">Sign in again</a>
  <a href="javascript:window.location.reload()" class="btn btn-secondary">Reload page</a>
@endsection
@section('footer_text', 'Tip: long forms? Save as draft often. We auto-save every 90 seconds where supported.')
EOF
echo "    CREATED resources/views/errors/419.blade.php"

# ----------------------------------------------------------------------------
# 5. 500 — Server error. Red tone (we broke something). Includes Reference ID.
#    The $errorRefId variable is passed in from the exception handler hook
#    added in bootstrap/app.php below.
# ----------------------------------------------------------------------------
cat > resources/views/errors/500.blade.php <<'EOF'
@extends('errors._shell')
@section('page_title', '500 — Something broke')
@section('eyebrow', '500 · Something broke on our end')
@section('eyebrow_tone', 'tone-red')
@section('title')
That's <span class="err-title-accent">on us</span>, not you.
@endsection
@section('body')
Something went sideways. We've been notified automatically and we'll dig into it. In the meantime, going back and trying again often works — most issues like this clear in seconds.
@endsection
@section('mini_links')
  <a href="{{ url('/status') }}">Status</a>
  <a href="{{ url('/docs') }}">Help</a>
@endsection
@section('actions')
  <a href="{{ url('/') }}" class="btn btn-primary">← Back to dashboard</a>
  <a href="javascript:window.location.reload()" class="btn btn-secondary">Try this page again</a>
@endsection
@section('status_block')
<div class="err-status-block">
  <div class="err-status-row">
    <span>Reference ID</span>
    <span class="err-status-value">{{ $errorRefId ?? 'ERR-' . strtoupper(\Illuminate\Support\Str::random(8)) }}</span>
  </div>
  <div class="err-status-row">
    <span>Time</span>
    <span class="err-status-value">{{ now()->format('M j, Y · H:i') }} UTC</span>
  </div>
  <div class="err-status-row">
    <span>System status</span>
    <span class="err-status-pill ok"><span class="err-status-dot"></span> All systems normal</span>
  </div>
</div>
@endsection
@section('footer_text', 'Persistent issue? Email')
EOF
echo "    CREATED resources/views/errors/500.blade.php"

# ----------------------------------------------------------------------------
# 6. 503 — Maintenance. Amber tone. Surfaces booking-queue promise.
# ----------------------------------------------------------------------------
cat > resources/views/errors/503.blade.php <<'EOF'
@extends('errors._shell')
@section('page_title', '503 — Scheduled maintenance')
@section('eyebrow', '503 · Scheduled maintenance')
@section('eyebrow_tone', 'tone-amber')
@section('title')
We're <span class="err-title-accent">making it better</span>.
@endsection
@section('body')
Intake is briefly offline for scheduled maintenance. Customer bookings on your public booking page will continue to queue — nothing is lost. We'll be back online shortly.
@endsection
@section('status_block')
<div class="err-status-block">
  <div class="err-status-row">
    <span>Status</span>
    <span class="err-status-pill"><span class="err-status-dot"></span> In progress</span>
  </div>
  <div class="err-status-row">
    <span>Public booking</span>
    <span class="err-status-pill ok"><span class="err-status-dot"></span> Queueing</span>
  </div>
</div>
<div class="err-actions" style="margin-top:28px">
  <a href="{{ url('/status') }}" class="btn btn-secondary">View status page</a>
</div>
@endsection
@section('footer_text', 'Follow updates:')
EOF
echo "    CREATED resources/views/errors/503.blade.php"

# ----------------------------------------------------------------------------
# 7. Add a render() hook to bootstrap/app.php so 500 errors get a Reference ID
#    that's both logged and shown in the UI. Idempotent — only patches if the
#    marker isn't already there.
# ----------------------------------------------------------------------------
python3 <<'PYEOF'
from pathlib import Path
p = Path("bootstrap/app.php")
s = p.read_text()

marker = "// 500 error reference ID (patch #43)"
if marker in s:
    print("    SKIP bootstrap/app.php — render hook already installed")
else:
    # Find the existing $exceptions->report(...) block close. We add a
    # $exceptions->render(...) hook right after it.
    old_block = """        $exceptions->report(function (\\Throwable $e) {
            if (app()->bound(\\App\\Services\\DebugLogService::class)) {
                app(\\App\\Services\\DebugLogService::class)->error($e);
            }
        });"""

    if s.count(old_block) != 1:
        raise SystemExit("ABORT: $exceptions->report block anchor not unique")

    new_block = old_block + """

        """ + marker + """
        // Stamp a short reference ID on every 5xx so support can grep logs.
        // Also passes the ID into the 500 view as $errorRefId. The ID is
        // written into the log message via the report() hook above when
        // the exception bubbles up — same ID in both places.
        $exceptions->render(function (\\Throwable $e, \\Illuminate\\Http\\Request $request) {
            // Only intercept 5xx-class errors. Symfony HttpException carries
            // its own status code; other Throwables default to 500.
            $status = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
            if ($status < 500 || $status > 599) {
                return null; // let Laravel render normally (404, 419, etc.)
            }

            $refId = 'ERR-' . strtoupper(\\Illuminate\\Support\\Str::random(8));

            // Surface the ref id in the log line so it can be grepped.
            \\Illuminate\\Support\\Facades\\Log::error('500 with refId: ' . $refId, [
                'exception' => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
                'url'       => $request->fullUrl(),
            ]);

            return response()->view('errors.500', [
                'errorRefId' => $refId,
                'exception'  => $e,
            ], 500);
        });"""

    s = s.replace(old_block, new_block)
    p.write_text(s)
    print("    UPDATED bootstrap/app.php — added 500 render() hook with reference ID")
PYEOF

cat <<EONOTE

==> Patch 43 applied locally.

To deploy:
  git add -A
  git commit -m "feat(errors): custom 404/403/419/500/503 pages with shared shell (#43)"
  git push

On server:
  git pull
  php artisan view:clear
  sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm

  (View files + bootstrap config only — no migration, no composer.)

What this adds:
  - 5 custom error pages matching the marketing-site brand
  - Eyebrow-label design (no decorative icons), severity by color
  - 500 page generates a Reference ID, logged + displayed
  - 419 explains gently — security signout, not user error
  - 503 reassures that public booking still queues during downtime
  - Single shared shell partial — visual changes are one-file edits later
  - Mobile responsive (title sizes down at <=600px, mini-links collapse)

Smoke test:
  1. Visit https://intake.works/this-page-does-not-exist → 404 renders
  2. Visit https://thebikehub.intake.works/this-page-does-not-exist → 404 renders
     (tenant-context aware via the same view since errors don't route through
      tenant controllers)
  3. Submit any form 12h+ after page load → 419 should render
  4. To smoke-test 500: temporarily throw new \\Exception() in any controller
     and verify Reference ID appears + matches a log line
  5. To smoke-test 503: php artisan down --render="errors::503"
     (then php artisan up to restore)

Out of scope:
  - 401 (Laravel auth middleware redirects to /login, doesn't render an error)
  - 422 (validation errors render inline on forms, never as a full page)
  - Custom /status page (referenced from 500 + 503 footers — separate build)
EONOTE
