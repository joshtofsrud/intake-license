{{--
  MARKER-PATCH-581 — inline site chrome for standalone documents.
  Usage: @include('public._chrome-inline', ['chromePos' => 'top'])   (after <body>)
         @include('public._chrome-inline', ['chromePos' => 'bottom']) (before </body>)
  Renders the tenant's builder nav/footer sections inside pages that keep
  their own <html> shell. The shim CSS is the minimal layout subset the
  nav partial expects (vars, container, mobile nav); nav/footer partials
  carry the rest of their styles themselves, instance-scoped.
--}}
@php
  $ciTenant = $currentTenant ?? $tenant ?? tenant();
  $ci = \App\Services\Tenant\SiteChromeService::parts($ciTenant);
@endphp

@if(($chromePos ?? 'top') === 'top' && $ci['nav'])
  <style>
    :root {
      --p-accent:       {{ $ciTenant->accent_color ?? '#BEF264' }};
      --p-text:         {{ $ciTenant->text_color   ?? '#111111' }};
      --p-bg:           {{ $ciTenant->bg_color     ?? '#ffffff' }};
      --p-font-heading: '{{ $ciTenant->font_heading ?? 'Inter' }}', -apple-system, sans-serif;
      --p-font-body:    '{{ $ciTenant->font_body    ?? 'Inter' }}', -apple-system, sans-serif;
      --p-accent-text:  {{ \App\Support\ColorHelper::accentTextColor($ciTenant->accent_color ?? '#BEF264') }};
      --p-r: 8px; --p-r-lg: 12px; --p-max: 1160px;
      --p-gutter: clamp(16px, 5vw, 64px);
    }
    .p-container { max-width: var(--p-max); margin: 0 auto; padding: 0 var(--p-gutter); }
    .p-mobile-nav { display: none; position: fixed; inset: 0; background: var(--p-bg); z-index: 200; padding: 80px 32px 40px; flex-direction: column; gap: 8px; }
    .p-mobile-nav.open { display: flex; }
    .p-mobile-nav a { font-size: 22px; font-weight: 600; padding: 10px 0; border-bottom: 1px solid rgba(0,0,0,.07); color: var(--p-text); text-decoration: none; }
  </style>
  @include('public.sections._nav', [
      'c' => $ci['nav']->content ?? [], 'section' => $ci['nav'],
      'navItems' => $ci['navItems'], 'tenant' => $ciTenant, 'catalog' => collect(),
  ])
@endif

@if(($chromePos ?? 'top') === 'bottom' && $ci['footer'])
  @include('public.sections._footer', [
      'c' => $ci['footer']->content ?? [], 'section' => $ci['footer'],
      'navItems' => $ci['navItems'], 'tenant' => $ciTenant, 'catalog' => collect(),
  ])
@endif
