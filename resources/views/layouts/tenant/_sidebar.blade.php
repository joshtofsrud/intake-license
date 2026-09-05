@php
  $sidebarBg = ($adminTheme === 'c') ? '#0c0c0c' : (($adminTheme === 'a') ? '#0f0f0f' : '#1E2A3A');
  $sidebarLogo = \App\Support\ColorHelper::pickLogo($currentTenant, $sidebarBg);

  // Logo height in pixels. Clamp defensively in case bad data sneaks in.
  $adminLogoHeight = (int) ($currentTenant->logo_size_admin ?? 26);
  $adminLogoHeight = max(16, min(80, $adminLogoHeight));
@endphp

<aside class="ia-sidebar {{ is_impersonating() ? 'is-impersonating' : '' }}">

  {{-- MARKER-SIDEBAR-COLLAPSE --}}
  <button type="button" class="ia-sb-collapse-btn" id="ia-sb-collapse"
          aria-label="Collapse sidebar" aria-expanded="true" title="Collapse sidebar  [">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <polyline points="15 18 9 12 15 6"/>
    </svg>
  </button>

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
    @include('layouts.tenant._attention-row')
    {{-- User row: click opens a menu with Sign out (and later Switch staff). --}}
    <details class="ia-sb-user-details" data-loc-switcher="root">
      <summary class="ia-sb-user-row {{ is_impersonating() ? 'is-impersonating' : '' }}" aria-haspopup="menu" aria-label="Account menu">
        <div class="ia-sb-user-avatar">{{ strtoupper(substr($authUser->name, 0, 2)) }}</div>
        <div class="ia-sb-user-text">
          <div class="ia-sb-user-name">{{ $authUser->name }}</div>
          <div class="ia-sb-user-role">{{ ucfirst($authUser->role) }}</div>
          {{-- MARKER-IMPERSONATION-PIN — persistent chrome, so the state is
               always visible without a bar sitting on top of the page. --}}
          @if(is_impersonating())
            <div class="ia-sb-imp-badge">Impersonating</div>
          @endif
        </div>
        <svg class="ia-sb-user-caret" width="12" height="12" viewBox="0 0 24 24"
             fill="none" stroke="currentColor" stroke-width="2.5"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <polyline points="6 9 12 15 18 9"/>
        </svg>
      </summary>
      <div class="ia-sb-user-menu" role="menu">
        @if(is_impersonating())
          <a href="{{ config('app.url') }}/admin/impersonate/stop" class="ia-sb-imp-stop" role="menuitem">
            <span class="ia-sb-imp-dot" aria-hidden="true"></span>
            <span>
              <span class="ia-sb-imp-title">Stop impersonating</span>
              <span class="ia-sb-imp-sub">Their PIN lock is bypassed</span>
            </span>
          </a>
        @endif

        {{-- MARKER-SIDEBAR-CLOCK — punch without leaving the page. Hidden for
             anyone exempt from the clock; shows elapsed time when on shift. --}}
        @if(!$authUser->exempt_from_timeclock)
          @php
            $sbPunch = \App\Models\Tenant\TenantTimePunch::openFor($currentTenant->id, $authUser->id);
            $sbMins  = $sbPunch ? $sbPunch->minutes() : 0;
          @endphp
          <form method="POST" action="{{ $sbPunch ? route('tenant.timeclock.out') : route('tenant.timeclock.in') }}" style="margin:0">
            @csrf
            <button type="submit" class="ia-sb-user-menu-item" role="menuitem">
              @if($sbPunch)
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/>
                </svg>
                <span>Clock out<span style="opacity:.5"> · {{ intdiv($sbMins, 60) }}h {{ $sbMins % 60 }}m</span></span>
              @else
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <circle cx="12" cy="12" r="9"/><polyline points="12 8 12 12 14 15"/>
                </svg>
                <span>Clock in</span>
              @endif
            </button>
          </form>
        @endif

        {{-- MARKER-USER-THEME-PREF — theme toggle writes THIS person's
             preference only. It used to POST to Settings->appearance, which
             stored the theme on the tenant and flipped it for the whole shop. --}}
        <form method="POST" action="{{ route('tenant.theme.set') }}" id="theme-toggle-form" style="margin:0">
          @csrf
          <input type="hidden" name="admin_theme" id="theme-toggle-value" value="{{ $adminTheme === 'c' ? 'b' : 'c' }}">
          <button type="submit" class="ia-sb-user-menu-item" role="menuitem">
            @if($adminTheme === 'c')
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="4"/>
                <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/>
              </svg>
              <span>Switch to light theme</span>
            @else
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
              </svg>
              <span>Switch to dark theme</span>
            @endif
          </button>
        </form>

        {{-- MARKER-PATCH-496 — switch user (PIN tier only) --}}
        {{-- MARKER-DEMO-FIXES — never on the demo: it asks for a PIN and a
             password no visitor can know, and drops them at a login they
             cannot pass. --}}
        {{-- MARKER-IMPERSONATE-SWITCH — hidden while impersonating: the
             page refuses, and offering a dead end is worse than omitting it. --}}
        @if($currentTenant->pin_tier_active && ! $currentTenant->is_demo && ! is_impersonating())
        <a href="{{ route('tenant.switch') }}" class="ia-sb-user-menu-item" role="menuitem">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2" stroke-linecap="round"
               stroke-linejoin="round" aria-hidden="true">
            <path d="M16 3h5v5"/><path d="M21 3l-7 7"/>
            <path d="M8 21H3v-5"/><path d="M3 21l7-7"/>
          </svg>
          <span>Switch user</span>
        </a>
        @endif

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

    {{-- MARKER-OFFLINE-SYNC stage 6 — status row just below the user block --}}
    {{-- MARKER-IOFLASH — rendered server-side in the online state, identical
         to renderSidebarBlock()'s output, so the pill is present at first
         paint instead of appearing once the script runs. offline-sync.js
         replaces this with the same markup on init; if the connection is
         actually down, navigator.onLine corrects it in milliseconds.
         Gated on the same addon check renderMounts() uses. --}}
    <div id="ioMountSidebar">
      @php
          // MARKER-IOFLASH — resolved here rather than inherited: these
          // partials render before the layout's $ioEnabled block runs.
          $ioStatusEnabled = app()->bound('tenant')
              && app(\App\Services\FeatureAccessService::class)->hasAddon(app('tenant'), 'offline_sync');
        @endphp
        @if($ioStatusEnabled)
        <div class="io-status io-srow" role="button" tabindex="0" aria-label="Offline sync status and settings">
          <span class="io-dot"></span>
          <span>Online</span>
          <span class="io-chev">&#9662;</span>
        </div>
      @endif
    </div>

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
