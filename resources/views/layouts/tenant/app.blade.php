<!DOCTYPE html>
<html lang="en" class="ia-theme-{{ $adminTheme }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  @include('partials.mobile-input-zoom') {{-- MARKER-MOBILE-INPUT-ZOOM --}}
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>{{ $pageTitle ?? 'Dashboard' }} — {{ $currentTenant->name }}</title>

  {{-- Fonts — MARKER-SELFHOST-FONTS. Self-hosted from public/fonts; the
       @font-face rules live in base.css. Preloading the two weights that
       carry almost all of the UI means they are normally decoded before
       first paint, so there is no metric swap to watch. crossorigin is
       required on font preloads even same-origin, or the browser fetches
       the file twice. 600 and the mono faces are not preloaded — they are
       used sparsely enough that a late swap is not noticeable. --}}
  <link rel="preload" as="font" type="font/woff2" crossorigin
        href="{{ asset('fonts/inter-latin-400-normal.woff2') }}">
  <link rel="preload" as="font" type="font/woff2" crossorigin
        href="{{ asset('fonts/inter-latin-500-normal.woff2') }}">

  {{-- Favicon --}}
  @if($currentTenant->favicon_url)
    <link rel="icon" href="{{ $currentTenant->favicon_url }}">
  @endif

  <link rel="stylesheet" href="{{ asset('css/fonts.css') }}">{{-- MARKER-SELFHOST-FONTS-2 --}}

  {{-- Base + theme CSS --}}
  <link rel="stylesheet" href="{{ asset('css/tenant/base.css') }}?v={{ filemtime(public_path('css/tenant/base.css')) }}">
  <link rel="stylesheet" href="{{ asset('css/tenant/theme-' . $adminTheme . '.css') }}?v={{ filemtime(public_path('css/tenant/theme-' . $adminTheme . '.css')) }}">
  <link rel="stylesheet" href="{{ asset('css/tenant/mobile-nav.css') }}?v={{ filemtime(public_path('css/tenant/mobile-nav.css')) }}">
  <link rel="stylesheet" href="{{ asset('css/tenant/mobile-schedule.css') }}?v={{ filemtime(public_path('css/tenant/mobile-schedule.css')) }}">
  <link rel="stylesheet" href="{{ asset('css/tenant/mobile-forms.css') }}?v={{ filemtime(public_path('css/tenant/mobile-forms.css')) }}">
  <link rel="stylesheet" href="{{ asset('css/tenant/dashboard.css') }}?v={{ filemtime(public_path('css/tenant/dashboard.css')) }}">
  <link rel="stylesheet" href="{{ asset('css/tenant/toast.css') }}?v={{ filemtime(public_path('css/tenant/toast.css')) }}">
  <link rel="stylesheet" href="{{ asset('css/tenant/confirm.css') }}?v={{ filemtime(public_path('css/tenant/confirm.css')) }}">
  <link rel="stylesheet" href="{{ asset('css/tenant/toggle.css') }}?v={{ filemtime(public_path('css/tenant/toggle.css')) }}">

  {{-- Master-admin theme overrides (theme_settings table) --}}
  {!! \App\Support\ThemeOverrideHelper::styleTag() !!}

  {{-- Tenant accent color injected at runtime --}}
  <style>
    body {
      --ia-accent: {{ $currentTenant->accent_color ?? '#3B5A78' }};
      --ia-accent-text: {{ \App\Support\ColorHelper::accentTextColor($currentTenant->accent_color ?? '#3B5A78') }};
      --ia-accent-soft: {{ \App\Support\ColorHelper::accentSoft($currentTenant->accent_color ?? '#3B5A78') }};
    }
  </style>

  @stack('styles')
</head>

<body class="ia-theme-{{ $adminTheme }}">
{{-- MARKER-PATCH-498 — invite setup success: check draws, circle wraps it, dashboard fades in --}}
@if(session('setup_complete'))
<div id="ia-setup-success" aria-hidden="true">
  <svg viewBox="0 0 72 72" width="88" height="88">
    <circle cx="36" cy="36" r="32" fill="none" stroke="var(--ia-accent)" stroke-width="3"
            stroke-linecap="round" stroke-dasharray="202" stroke-dashoffset="202"
            transform="rotate(-90 36 36)" class="iss-circle"/>
    <path d="M23 37l9 9 17-19" fill="none" stroke="var(--ia-accent)" stroke-width="4"
          stroke-linecap="round" stroke-linejoin="round"
          stroke-dasharray="40" stroke-dashoffset="40" class="iss-check"/>
  </svg>
  <div class="iss-label">You're in</div>
</div>
<style>
#ia-setup-success{position:fixed;inset:0;z-index:9999;background:var(--ia-bg);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:16px;animation:iss-fade .5s ease 1.6s forwards}
#ia-setup-success .iss-check{animation:iss-draw .35s ease-out .15s forwards}
#ia-setup-success .iss-circle{animation:iss-draw .55s ease-in-out .45s forwards}
#ia-setup-success .iss-label{font-size:14px;font-weight:600;color:var(--ia-text);opacity:0;animation:iss-in .3s ease .9s forwards}
@keyframes iss-draw{to{stroke-dashoffset:0}}
@keyframes iss-in{to{opacity:1}}
@keyframes iss-fade{to{opacity:0;visibility:hidden}}
</style>
<script>setTimeout(function(){var el=document.getElementById('ia-setup-success');if(el)el.remove();},2300);</script>
@endif

@include('layouts.tenant._mobile-header')

{{-- MARKER-SIDEBAR-COLLAPSE — applied to <html> before first paint. A
     deferred script would let the expanded sidebar flash on every load. --}}
<script>
  try {
    if (localStorage.getItem('ia-sidebar-collapsed') === '1') {
      document.documentElement.classList.add('ia-sb-collapsed');
    }
  } catch (e) {}
</script>

<div class="ia-shell">

  {{-- ================================================================
       Sidebar — rendered on both themes (B = Light Premium, C = Dark Premium).
       Both share the same sidebar layout; theme CSS handles the palette.
       ================================================================ --}}
  @include('layouts.tenant._sidebar')

  {{-- ================================================================
       Main area
       ================================================================ --}}
  <div class="ia-main">

    {{-- MARKER-IMPERSONATION-PIN — the sticky bar sat at top:0 over page
         headers and primary actions, and a second copy rendered inside the
         content area below. Both are gone; the state now lives in the
         sidebar user block, with a fixed chip on mobile. --}}
    @if(is_impersonating())
      <a href="{{ config('app.url') }}/admin/impersonate/stop" class="ia-imp-chip">
        Impersonating · stop
      </a>
    @endif

    {{-- MARKER-IMPORT-PROGRESS — background import/reverse banner. Sits on
         every page because the work outlives the page that started it. --}}
    <div id="ia-jobbar" hidden>
      <div class="ia-jobbar-in">
        <span class="ia-jobbar-dot"></span>
        <span class="ia-jobbar-txt"></span>
        <span class="ia-jobbar-track"><span class="ia-jobbar-fill"></span></span>
        <a class="ia-jobbar-link" href="#">View</a>
        <button type="button" class="ia-jobbar-x" title="Dismiss">&times;</button>
      </div>
    </div>

    {{-- Page content --}}
    <main class="ia-content">


      {{-- Flash messages.
           Success → inline green banner (non-blocking, just confirms an action).
           Error   → IntakeConfirm.alert() modal (blocks until acknowledged so
           it can't be missed when the page is long, e.g. class session list). --}}
      {{-- MARKER-FLASH-MODAL — the inline success bar pushed the page down and
           stacked with the clock-in nudge. Both success and error now render
           through one animated modal. --}}
      @include('layouts.tenant._flash-modal')

      {{-- MARKER-PATCH-613 — clock-in prompt. Off-the-clock staff get a gentle,
           dismissible nudge (dismissal is per page-load, not persisted — it
           reappears next visit so a forgotten clock-in gets caught). --}}
      @if(!empty($authUser) && empty($pinLockPending) && !$authUser->exempt_from_timeclock)
        @php
          $tcOpen = \App\Models\Tenant\TenantTimePunch::openFor($currentTenant->id, $authUser->id);
        @endphp
        @if(!$tcOpen)
          <div class="ia-flash" id="tc-clockin-nudge"
               style="display:flex;align-items:center;gap:12px;background:color-mix(in srgb,var(--ia-accent) 10%,transparent);border:0.5px solid var(--ia-accent);color:var(--ia-text)">
            <span style="flex:1">You're not clocked in.</span>
            <form method="POST" action="{{ route('tenant.timeclock.in') }}" style="margin:0">
              @csrf<input type="hidden" name="source" value="lock_screen">
              <button type="submit" style="background:var(--ia-accent);color:var(--ia-accent-text);border:none;border-radius:6px;padding:6px 14px;font-size:12.5px;font-weight:600;cursor:pointer">Clock in</button>
            </form>
            <button type="button" onclick="sessionStorage.setItem('tc_nudge_dismissed','1');document.getElementById('tc-clockin-nudge').remove()"
                    style="background:none;border:none;color:var(--ia-text-muted);cursor:pointer;font-size:16px;line-height:1">×</button>
          </div>
          <script>if(sessionStorage.getItem('tc_nudge_dismissed')){var n=document.getElementById('tc-clockin-nudge');if(n)n.remove();}</script>
        @endif
      @endif
      {{-- MARKER-PATCH-445 — single global flash; per-page success/error banners removed across tenant views --}}
      {{-- MARKER-FLASH-MODAL — errors used a blocking IntakeConfirm.alert so they
           couldn't be missed on a long page. The modal keeps that: it waits for
           acknowledgement, while a success dismisses itself. --}}

      @include('layouts.tenant._staff-broadcast-banner')
      @include('layouts.tenant._standing-banner') {{-- MARKER-TENANT-STANDING --}}
      @yield('content')

    </main>
  </div>
</div>

{{-- ================================================================
     Mobile-only nav (bottom tab bar + drawer)
     Hidden on desktop via CSS; always rendered in markup.
     ================================================================ --}}
@include('layouts.tenant._mobile-nav')
@include('layouts.tenant._more-drawer')
@include('layouts.tenant._mobile-fab')

{{-- Detail modal (appointments, customers) --}}
@include('tenant._detail_modal')

{{-- Global JS --}}
<script>
  window.IntakeAdmin = {
    tenantId:   '{{ $currentTenant->id }}',
    csrfToken:  '{{ csrf_token() }}',
    theme:      '{{ $adminTheme }}',
    currency:   '{{ $currentTenant->currency_symbol ?? "$" }}',
    ajaxUrl:    '{{ url("/admin/ajax") }}',
    pinIdleThresholdSec:    {{ (int) config('intake.auth.pin_idle_threshold_sec', 120) }},
    pinHeartbeatIntervalSec:{{ (int) config('intake.auth.pin_heartbeat_interval_sec', 60) }},
  };
</script>

<script src="{{ asset('js/tenant/toast.js') }}?v={{ filemtime(public_path('js/tenant/toast.js')) }}" defer></script>
<script src="{{ asset('js/tenant/confirm.js') }}?v={{ filemtime(public_path('js/tenant/confirm.js')) }}" defer></script>
<script src="{{ asset('js/tenant/admin.js') }}?v={{ filemtime(public_path('js/tenant/admin.js')) }}" defer></script>
<script src="{{ asset('js/tenant/sidebar-collapse.js') }}?v={{ filemtime(public_path('js/tenant/sidebar-collapse.js')) }}" defer></script>
<script src="{{ asset('js/tenant/mobile-nav.js') }}?v={{ filemtime(public_path('js/tenant/mobile-nav.js')) }}" defer></script>
<script src="{{ asset('js/tenant/location-switcher.js') }}?v={{ filemtime(public_path('js/tenant/location-switcher.js')) }}" defer></script>
@unless($currentTenant->is_demo ?? false) {{-- MARKER-DEMO-IDLELOCK --}}
<script src="{{ asset('js/tenant/idle-lock.js') }}?v={{ filemtime(public_path('js/tenant/idle-lock.js')) }}" defer></script>
@endunless

{{-- MARKER-IMPERSONATION-PIN — omitted while impersonating so the client
     idle timer has nothing to open. --}}
{{-- MARKER-DEMO-IDLELOCK — and on a demo tenant, where a visitor has no PIN
     and creating one would lock the demo behind a number only they know. --}}
@unless(session()->has('impersonating_from') || ($currentTenant->is_demo ?? false))
  @include('layouts.tenant._lock-overlay')
@endunless
@include('layouts.tenant._action-gate-modal')
@include('layouts.tenant._location-welcome')

{{-- MARKER-OFFLINE-SYNC stage 3 — global module: SW install, background
     snapshot refresh, queue replay, and the status pill live on EVERY admin
     page. No arming ritual. --}}
@php
  $ioEnabled = app()->bound('tenant')
      ? app(\App\Services\FeatureAccessService::class)->hasAddon(app('tenant'), 'offline_sync')
      : false;
@endphp
<script>
window.IntakeOfflineConfig = {
  enabled: {{ $ioEnabled ? 'true' : 'false' }},
  catalogUrl: @json(route('tenant.register.offline_catalog')),
  storeSaleUrl: @json(route('tenant.register.sales.store')),
  csrf: document.querySelector('meta[name=csrf-token]')?.content || '',
};
</script>
<script src="{{ asset('js/offline-sync.js') }}?v=nogear2"></script>

{{-- MARKER-IMPORT-PROGRESS --}}
<style>
  #ia-jobbar{position:sticky;top:0;z-index:60;background:var(--ia-surface-2,rgba(255,255,255,.05));
    border-bottom:.5px solid var(--ia-border)}
  .ia-jobbar-in{display:flex;align-items:center;gap:12px;padding:8px 16px;font-size:12.5px;color:var(--ia-text)}
  .ia-jobbar-dot{width:8px;height:8px;border-radius:50%;background:var(--ia-accent);flex:none;
    animation:ia-jobpulse 1.4s ease-in-out infinite}
  #ia-jobbar.is-done .ia-jobbar-dot{animation:none}
  #ia-jobbar.is-failed .ia-jobbar-dot{background:#F08A8A;animation:none}
  @keyframes ia-jobpulse{0%,100%{opacity:1}50%{opacity:.25}}
  @media(prefers-reduced-motion:reduce){.ia-jobbar-dot{animation:none}}
  .ia-jobbar-txt{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .ia-jobbar-track{flex:1;min-width:60px;max-width:280px;height:5px;border-radius:3px;
    background:rgba(127,127,127,.25);overflow:hidden}
  #ia-jobbar.is-done .ia-jobbar-track,#ia-jobbar.is-failed .ia-jobbar-track{display:none}
  .ia-jobbar-fill{display:block;height:100%;width:0;border-radius:3px;background:var(--ia-accent);transition:width .4s}
  .ia-jobbar-link{margin-left:auto;color:var(--ia-accent);font-weight:600;text-decoration:none;white-space:nowrap}
  .ia-jobbar-x{background:none;border:0;color:var(--ia-text-dim);font-size:17px;line-height:1;cursor:pointer;padding:0 2px}
</style>
<script>
(function () {
  var bar = document.getElementById('ia-jobbar');
  if (!bar) return;
  var txt = bar.querySelector('.ia-jobbar-txt'),
      fill = bar.querySelector('.ia-jobbar-fill'),
      link = bar.querySelector('.ia-jobbar-link'),
      xBtn = bar.querySelector('.ia-jobbar-x');
  var URL_P = @json(route('tenant.imports.progress'));
  var SEEN  = @json(url('/admin/imports')) + '/';
  var TOKEN = document.querySelector('meta[name="csrf-token"]');
  var current = null, timer = null;

  function hide() { bar.hidden = true; }

  function paint(d) {
    if (!d || !d.active) { hide(); return; }
    current = d.id;
    bar.hidden = false;
    bar.classList.toggle('is-done', !d.running && d.stage !== 'failed');
    bar.classList.toggle('is-failed', d.stage === 'failed');
    if (d.running) {
      txt.textContent = d.label + ' · ' + d.done.toLocaleString() + ' of ' + d.total.toLocaleString() + ' · ' + d.pct + '%';
      fill.style.width = d.pct + '%';
    } else {
      txt.textContent = d.result || 'Finished';
    }
    link.href = d.href || '#';
    // Only a finished banner is dismissible; a running one is information.
    xBtn.style.display = d.running ? 'none' : '';
  }

  function poll() {
    fetch(URL_P, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        paint(d);
        var wait = (d && d.active && d.running) ? 3000 : 15000;
        timer = setTimeout(poll, wait);
      })
      .catch(function () { timer = setTimeout(poll, 30000); });
  }

  xBtn.addEventListener('click', function () {
    if (!current) { hide(); return; }
    fetch(SEEN + current + '/progress-seen', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'X-CSRF-TOKEN': TOKEN ? TOKEN.content : '', 'Accept': 'application/json' }
    }).catch(function () {});
    hide();
  });

  poll();
})();
</script>

@stack('scripts')

@include('tenant._onboarding_modal')

  <script defer src="{{ asset('js/tenant/cl-subnav-hint.js') }}"></script>
@include('tenant.print._composer') {{-- MARKER-PATCH-337 --}}
</body>
</html>

