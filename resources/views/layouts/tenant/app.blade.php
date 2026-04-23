<!DOCTYPE html>
<html lang="en" class="ia-theme-{{ $adminTheme }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>{{ $pageTitle ?? 'Dashboard' }} — {{ $currentTenant->name }}</title>

  {{-- Fonts --}}
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

  {{-- Favicon --}}
  @if($currentTenant->favicon_url)
    <link rel="icon" href="{{ $currentTenant->favicon_url }}">
  @endif

  {{-- Base + theme CSS --}}
  <link rel="stylesheet" href="{{ asset('css/tenant/base.css') }}">
  <link rel="stylesheet" href="{{ asset('css/tenant/theme-' . $adminTheme . '.css') }}">
  <link rel="stylesheet" href="{{ asset('css/tenant/mobile-nav.css') }}">
  <link rel="stylesheet" href="{{ asset('css/tenant/dashboard.css') }}">

  {{-- Tenant accent color injected at runtime --}}
  <style>
    body {
      --ia-accent: {{ $currentTenant->accent_color ?? '#BEF264' }};
      --ia-accent-text: {{ \App\Support\ColorHelper::accentTextColor($currentTenant->accent_color ?? '#BEF264') }};
      --ia-accent-soft: {{ \App\Support\ColorHelper::accentSoft($currentTenant->accent_color ?? '#BEF264') }};
    }
  </style>

  @stack('styles')
</head>

<body class="ia-theme-{{ $adminTheme }}">

@include('layouts.tenant._mobile-header')

<div class="ia-shell">

  {{-- ================================================================
       Sidebar (themes A + C) — desktop only via CSS media query
       ================================================================ --}}
  @if($adminTheme !== 'b')
    @include('layouts.tenant._sidebar')
  @endif

  {{-- ================================================================
       Main area
       ================================================================ --}}
  <div class="ia-main">

    {{-- Top bar (theme B only) — desktop only via CSS media query --}}
    @if($adminTheme === 'b')
      @include('layouts.tenant._topnav')
    @endif

    {{-- Impersonation banner --}}
    @if(session('impersonating_from'))
      <div style="background:#854F0B;color:#fff;padding:8px 20px;font-size:13px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:200">
        <span>⚠ You are impersonating this tenant as an admin.</span>
        <a href="{{ url('/admin/impersonate/stop') }}" style="color:#FCD34D;font-weight:600">Stop impersonating →</a>
      </div>
    @endif

    {{-- Page content --}}
    <main class="ia-content">

      {{-- Impersonation banner --}}
      @if(session('impersonating_tenant_name') || session()->has('impersonating_from'))
        <div style="background:#854F0B;color:#FAEEDA;padding:10px 16px;border-radius:var(--ia-r-md);margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;font-size:13px">
          <span>
            👤 You are impersonating <strong>{{ session('impersonating_tenant_name', 'this tenant') }}</strong>.
            All actions you take are real.
          </span>
          <a href="{{ url('/admin/impersonate/stop') }}"
             style="background:rgba(0,0,0,.2);color:#FAEEDA;padding:5px 14px;border-radius:6px;font-weight:600;font-size:12px">
            Stop impersonating →
          </a>
        </div>
      @endif

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
  };
</script>

<script src="{{ asset('js/tenant/admin.js') }}" defer></script>
<script src="{{ asset('js/tenant/mobile-nav.js') }}" defer></script>

@stack('scripts')

@include('tenant._onboarding_modal')

</body>
</html>
