<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  @include('partials.mobile-input-zoom') {{-- MARKER-MOBILE-INPUT-ZOOM --}}
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>{{ $pageTitle ?? 'My Account' }} — {{ $currentTenant->name }}</title>
  @if($currentTenant->favicon_url)<link rel="icon" href="{{ $currentTenant->favicon_url }}">@endif
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family={{ str_replace(' ', '+', $currentTenant->font_heading ?? 'Inter') }}:wght@400;500;600;700&family={{ str_replace(' ', '+', $currentTenant->font_body ?? 'Inter') }}:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
    :root {
{!! \App\Support\DesignTokens::cssVars(\App\Support\DesignTokens::resolve($currentTenant)) !!} {{-- MARKER-TOKENS --}}
      --p-r: 8px; --p-r-lg: 12px; --p-max: 680px;
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
    .ac-flash--success { background: #EAF3DE; color: #3B6D11; }
    .ac-flash--error { background: #FCEBEB; color: #A32D2D; }
    .ac-error { font-size: 13px; color: #A32D2D; margin-top: 5px; }
    .ac-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .ac-check-row { display: flex; align-items: center; gap: 8px; font-size: 14px; }
    .ac-intake-footer { text-align: center; padding: 20px 16px; font-size: 12px; opacity: .35; margin-top: 20px; }
    .ac-intake-footer a { border-bottom: 1px solid currentColor; }
  </style>
  @stack('styles')
  {{-- MARKER-PATCH-150 — analytics + funnel tracking --}}
  @include('public._funnel_tracker')
</head>
<body>
@include('public._chrome-inline', ['chromePos' => 'top']) {{-- MARKER-PATCH-581 --}}
@php
  $logoUrl = \App\Support\ColorHelper::pickLogo($currentTenant, $currentTenant->bg_color ?? '#ffffff');
  $customer = Auth::guard('customer')->user();
@endphp
{{-- MARKER-PORTAL-CSS — the builder nav above already shows the logo and the
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
@include('public._chrome-inline', ['chromePos' => 'bottom']) {{-- MARKER-PATCH-581 --}}
</body>
</html>
