{{--
  inline site chrome for standalone documents.
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
  // MARKER-WEB-TEMPLATES: the menu bar and footer follow the template here too
  $ciLook = \App\Support\SiteLook::for($ciTenant);
  $ciNavC = $ci['nav'] ? \App\Support\SiteLook::applyDesign('nav', $ci['nav']->content ?? [], $ciLook) : [];
  $ciFootC = $ci['footer'] ? \App\Support\SiteLook::applyDesign('footer', $ci['footer']->content ?? [], $ciLook) : [];
@endphp
@if(($chromePos ?? 'top') === 'top'){!! \App\Support\SiteLook::schemeScript($ciTenant) !!}@endif

@if(($chromePos ?? 'top') === 'top' && $ci['nav'])
  <style>
    /* scoped, NOT :root: booking pages own their vars */
    .p-chrome-scope {
{!! \App\Support\DesignTokens::cssVars(\App\Support\DesignTokens::resolve($ciTenant)) !!}
      --p-r: 8px; --p-r-lg: 12px; --p-max: 1160px;
      --p-gutter: clamp(16px, 5vw, 64px);
    }
    .p-container { max-width: var(--p-max); margin: 0 auto; padding: 0 var(--p-gutter); }
    .p-mobile-nav { display: none; position: fixed; inset: 0; background: var(--p-bg); z-index: 200; padding: 80px 32px 40px; flex-direction: column; gap: 8px; }
    .p-mobile-nav.open { display: flex; }
    .p-mobile-nav a { font-size: 22px; font-weight: 600; padding: 10px 0; border-bottom: 1px solid rgba(0,0,0,.07); color: var(--p-text); text-decoration: none; }
  </style>
  {{-- MARKER-SHOP-FLOAT-NAV: display:contents so the sticky nav sticks to the page, not to this wrapper --}}
  <div class="p-chrome-scope" style="display:contents">
  @include('public.sections._nav', [
      'c' => $ciNavC, 'section' => $ci['nav'],
      'navItems' => $ci['navItems'], 'tenant' => $ciTenant, 'catalog' => collect(),
  ])
  </div>
@endif

@if(($chromePos ?? 'top') === 'bottom' && $ci['footer'])
  <div class="p-chrome-scope">
  @include('public.sections._footer', [
      'c' => (($hideBookingCta ?? false) ? array_merge($ciFootC, ['cta_band' => false]) : $ciFootC), 'section' => $ci['footer'],
      'navItems' => $ci['navItems'], 'tenant' => $ciTenant, 'catalog' => collect(),
  ])
  </div>
@endif
