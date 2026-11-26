<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  {!! \App\Support\SiteLook::schemeScript($currentTenant ?? null) !!}
  @include('partials.mobile-input-zoom')
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>{{ $pageTitle ?? 'My Account' }} — {{ $currentTenant->name }}</title>
  @if($currentTenant->favicon_url)<link rel="icon" href="{{ $currentTenant->favicon_url }}">@endif
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>{{-- the font FILES come from gstatic; without this the browser pays a second DNS+TLS handshake --}}
  <link href="https://fonts.googleapis.com/css2?family={{ \App\Support\SiteLook::fontParam($currentTenant->font_heading ?? 'Inter') }}&family={{ \App\Support\SiteLook::fontParam($currentTenant->font_body ?? 'Inter') }}&display=swap" rel="stylesheet">
  <style>
    :root {
{!! \App\Support\DesignTokens::cssVars(\App\Support\DesignTokens::resolve($currentTenant)) !!}
      /* MARKER-PORTAL-RENTALS: follow the shop's own button / card radius settings
         (were hard-coded 8px / 12px, overriding them) */
      --p-r: var(--p-btn-r, 8px); --p-r-lg: var(--p-card-r, 12px); --p-max: 680px;
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: var(--p-font-body); background: var(--p-bg); color: var(--p-text); font-size: 15px; line-height: 1.6; -webkit-font-smoothing: antialiased; }
    a { color: inherit; text-decoration: none; }
    button { font-family: inherit; cursor: pointer; }
    img { max-width: 100%; display: block; }
    .ac-top { border-bottom: 1px solid var(--p-border); padding: 14px 24px; display: flex; align-items: center; justify-content: space-between; }
    .ac-logo { font-family: var(--p-font-heading); font-size: 17px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
    .ac-logo img { height: 26px; width: auto; border-radius: 4px; }
    .ac-top-right { display: flex; align-items: center; gap: 16px; font-size: 13px; }
    .ac-top-link { opacity: .6; transition: opacity .12s; }
    .ac-top-link:hover { opacity: 1; }
    .ac-body { max-width: var(--p-max); margin: 0 auto; padding: 40px 24px 80px; }
    .ac-card { background: var(--p-bg); border: 1px solid var(--p-border); border-radius: var(--p-r-lg); padding: 28px; }
    .ac-title { font-family: var(--p-font-heading); font-size: 22px; font-weight: 700; margin-bottom: 6px; }
    .ac-subtitle { font-size: 14px; opacity: .55; margin-bottom: 24px; }
    .ac-field { margin-bottom: 16px; }
    .ac-label { display: block; font-size: 12px; font-weight: 500; opacity: .6; margin-bottom: 5px; text-transform: uppercase; letter-spacing: .05em; }
    .ac-input { width: 100%; padding: 11px 14px; border: 1.5px solid var(--p-border); border-radius: var(--p-r); font-family: var(--p-font-body); font-size: 15px; background: transparent; color: var(--p-text); transition: border-color .15s; }
    .ac-input:focus { outline: none; border-color: var(--p-accent); }
    .ac-btn { width: 100%; padding: 13px; border-radius: var(--p-r); font-size: 15px; font-weight: 600; cursor: pointer; border: none; transition: all .15s; }
    .ac-btn--primary { background: var(--p-accent); color: var(--p-accent-text); }
    .ac-btn--primary:hover { filter: brightness(.93); }
    .ac-btn--ghost { background: transparent; color: var(--p-text); border: 1.5px solid var(--p-border); }
    .ac-btn--ghost:hover { border-color: var(--p-text); }
    .ac-divider { display: flex; align-items: center; gap: 12px; margin: 20px 0; font-size: 13px; opacity: .4; }
    .ac-divider::before, .ac-divider::after { content: ''; flex: 1; height: 1px; background: var(--p-border); }
    .ac-link { color: var(--p-accent); font-weight: 500; }
    .ac-link:hover { text-decoration: underline; }
    .ac-flash { padding: 12px 16px; border-radius: var(--p-r); font-size: 14px; margin-bottom: 20px; }
    /* tinted against the shop's own colours so they read on dark sites too */
    .ac-flash--success { background: color-mix(in srgb, #3f8f2a 15%, var(--p-bg)); color: color-mix(in srgb, #3f8f2a 75%, var(--p-text)); }
    .ac-flash--error { background: color-mix(in srgb, #c2362b 15%, var(--p-bg)); color: color-mix(in srgb, #c2362b 75%, var(--p-text)); }
    .ac-error { font-size: 13px; color: color-mix(in srgb, #c2362b 80%, var(--p-text)); margin-top: 5px; }
    .ac-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .ac-check-row { display: flex; align-items: center; gap: 8px; font-size: 14px; }
    .ac-intake-footer { text-align: center; padding: 20px 16px; font-size: 12px; opacity: .35; margin-top: 20px; }
    .ac-intake-footer a { border-bottom: 1px solid currentColor; }
  </style>
  @stack('styles')
  {{-- analytics + funnel tracking --}}
  @include('public._funnel_tracker')
  @include('public._site-scripts', ['ssPart' => 'head']) {{-- MARKER-SITE-SCRIPTS --}}
</head>
<body>
@include('public._chrome-inline', ['chromePos' => 'top'])
@php
  $logoUrl = \App\Support\ColorHelper::pickLogo($currentTenant, $currentTenant->bg_color ?? '#ffffff');
  $customer = Auth::guard('customer')->user();
@endphp
{{-- the builder nav above already shows the logo and the
     signed-in customer, so this bar would be a second copy of both. It still
     renders for a tenant with no site chrome. --}}
@php $acHasChrome = (bool) \App\Services\Tenant\SiteChromeService::parts($currentTenant)['nav']; @endphp
@unless($acHasChrome)
<div class="ac-top">
  <a href="{{ route('tenant.home') }}" class="ac-logo">
    @if($logoUrl)
      <img src="{{ $logoUrl }}" alt="{{ $currentTenant->name }}">
    @else
      {{ $currentTenant->name }}
    @endif
  </a>
  <div class="ac-top-right">
    @if($customer)
      <span style="opacity:.6">{{ $customer->first_name }}</span>
      <form method="POST" action="{{ route('tenant.customer.logout') }}" style="display:inline">
        @csrf
        <button type="submit" class="ac-top-link" style="background:none;border:none;font-size:13px;cursor:pointer">Sign out</button>
      </form>
    @else
      <a href="{{ route('tenant.customer.login') }}" class="ac-top-link">Sign in</a>
      <a href="{{ route('tenant.customer.register') }}" class="ac-top-link">Create account</a>
    @endif
  </div>
</div>
@endunless

<div class="ac-body">
  @yield('content')
</div>

@if($currentTenant->show_intake_branding ?? true)
  <div class="ac-intake-footer">Powered by <a href="https://intake.works" target="_blank">intake</a></div>
@endif

@stack('scripts')
@include('public._chrome-inline', ['chromePos' => 'bottom'])
@include('public._site-scripts', ['ssPart' => 'body']) {{-- MARKER-SITE-SCRIPTS --}}
</body>
</html>
