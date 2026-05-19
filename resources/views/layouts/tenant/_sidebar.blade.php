@php
  $sidebarBg = ($adminTheme === 'c') ? '#0c0c0c' : (($adminTheme === 'a') ? '#0f0f0f' : '#1E2A3A');
  $sidebarLogo = \App\Support\ColorHelper::pickLogo($currentTenant, $sidebarBg);

  // Logo height in pixels. Clamp defensively in case bad data sneaks in.
  $adminLogoHeight = (int) ($currentTenant->logo_size_admin ?? 26);
  $adminLogoHeight = max(16, min(80, $adminLogoHeight));
@endphp

<aside class="ia-sidebar">

  {{-- Logo (image only when uploaded, fallback to letter + name when not) --}}
  <div class="ia-sidebar-logo">
    @if($sidebarLogo)
      <img src="{{ $sidebarLogo }}" alt="{{ $currentTenant->name }}" style="height:{{ $adminLogoHeight }}px;width:auto;border-radius:4px;max-width:160px;object-fit:contain">
    @else
      <div class="ia-sidebar-logo-mark">{{ strtoupper(substr($currentTenant->name, 0, 1)) }}</div>
      <span class="ia-sidebar-logo-name">{{ $currentTenant->name }}</span>
    @endif
  </div>

  {{-- ================================================================
       Identity block — top of sidebar, below logo, above nav.
       User row (always visible) + location row (only when 2+ locations).
       ================================================================ --}}
  <div class="ia-sidebar-identity" data-identity-block>
    {{-- User row: click opens a menu with Sign out (and later Switch staff). --}}
    <details class="ia-sb-user-details" data-loc-switcher="root">
      <summary class="ia-sb-user-row" aria-haspopup="menu" aria-label="Account menu">
        <div class="ia-sb-user-avatar">{{ strtoupper(substr($authUser->name, 0, 2)) }}</div>
        <div class="ia-sb-user-text">
          <div class="ia-sb-user-name">{{ $authUser->name }}</div>
          <div class="ia-sb-user-role">{{ ucfirst($authUser->role) }}</div>
        </div>
        <svg class="ia-sb-user-caret" width="12" height="12" viewBox="0 0 24 24"
             fill="none" stroke="currentColor" stroke-width="2.5"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <polyline points="6 9 12 15 18 9"/>
        </svg>
      </summary>
      <div class="ia-sb-user-menu" role="menu">
        <button type="button" class="ia-sb-user-menu-item"
                onclick="document.getElementById('logout-form').submit()"
                role="menuitem">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2" stroke-linecap="round"
               stroke-linejoin="round" aria-hidden="true">
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
            <polyline points="16 17 21 12 16 7"/>
            <line x1="21" y1="12" x2="9" y2="12"/>
          </svg>
          <span>Sign out</span>
        </button>
      </div>
    </details>

    {{-- Location row: rendered as the same partial used elsewhere, but
         styled as a sidebar row instead of a floating pill. The partial
         checks $userLocations->count() >= 2 before rendering anything. --}}
    <div class="ia-sb-location-wrap">
      @include('layouts.tenant._location-switcher')
    </div>
  </div>

  {{-- Primary nav --}}
  @include('layouts.tenant._nav-items')

  {{-- Bottom: brand footer (respects show_intake_branding) --}}
  <div class="ia-sidebar-bottom">
    @include('layouts.tenant._brand-footer')
  </div>

  {{-- Logout form (referenced by the user menu) --}}
  <form id="logout-form" method="POST" action="{{ route('tenant.logout') }}" style="display:none">
    @csrf
  </form>

</aside>
