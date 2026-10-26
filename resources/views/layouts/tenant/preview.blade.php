{{-- MARKER-DASH-PREVIEW: master admin's dashboard preview. The same head as
     layouts.tenant.app (theme, fonts, accent), then the page's own content
     with no sidebar, navigation or banners around it. Links and buttons are
     switched off: nothing here acts on the shop. --}}
<!DOCTYPE html>
<html lang="en" class="ia-theme-{{ $adminTheme }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  @include('partials.mobile-input-zoom')
  <meta name="robots" content="noindex">
  <title>{{ $pageTitle ?? 'Dashboard' }} — {{ $currentTenant->name }}</title>

  {{-- Fonts — Self-hosted from public/fonts; the
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

  {{-- Favicon: the shop's own, else Intake's (the one master admin uses),
       never the browser's blank globe. --}}
  @if($currentTenant->favicon_url)
    <link rel="icon" href="{{ $currentTenant->favicon_url }}">
  @else
    <link rel="icon" href="{{ \App\Support\Brand::url('favicon') }}">
  @endif

  <link rel="stylesheet" href="{{ asset('css/fonts.css') }}">

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
<style>
  .ia-main.dash-pv { margin-left: 0 !important; width: 100% !important; }
  .dash-pv a, .dash-pv button, .dash-pv form, .dash-pv input, .dash-pv select { pointer-events: none !important; }
  .dash-pv [hidden] { display: none !important; }
</style>
<div class="ia-shell" style="display:block">
  <div class="ia-main dash-pv">
    <main class="ia-content">
      @yield('content')
    </main>
  </div>
</div>
<script>
  window.IntakeAdmin = { tenantId: '{{ $currentTenant->id }}', csrfToken: '', theme: '{{ $adminTheme }}', currency: '{{ $currentTenant->currency_symbol ?? "$" }}', ajaxUrl: '' };
</script>
<script src="{{ asset('js/tenant/toast.js') }}?v={{ filemtime(public_path('js/tenant/toast.js')) }}"></script>
<script src="{{ asset('js/tenant/confirm.js') }}?v={{ filemtime(public_path('js/tenant/confirm.js')) }}"></script>
@stack('scripts')
</body>
</html>
