{{-- ================================================================
     Mobile admin header (≤1023px) — MOBILE-BACK v1 + MOBILE-HEADER-LOGO-PICK v1
     Shows ‹ Back chevron when @section('mobile-back', 'Label|/url') is set,
     otherwise shows tenant logo or wordmark.
     Uses ColorHelper::pickLogo so dark themes get the light logo variant
     (matching the desktop sidebar's behavior).
     Desktop hides this entirely via CSS.
     ================================================================ --}}
@php
  $mobileBackRaw = trim(View::yieldContent('mobile-back', ''));
  $mobileBackLabel = null;
  $mobileBackUrl = null;
  if ($mobileBackRaw !== '') {
    $parts = explode('|', $mobileBackRaw, 2);
    if (count($parts) === 2) {
      $mobileBackLabel = trim($parts[0]);
      $mobileBackUrl   = trim($parts[1]);
    }
  }

  // Pick the right logo variant for the mobile header surface.
  // Background matches the CSS rule in mobile-nav.css: dark themes #0c0c0c,
  // light theme (b) #ffffff. Match those exact values so pickLogo's
  // dark-detection lines up with the painted surface.
  $mhdrBg = ($adminTheme === 'b') ? '#ffffff' : '#0c0c0c';
  $mhdrLogo = \App\Support\ColorHelper::pickLogo($currentTenant, $mhdrBg);
  $mhdrLogoHeight = (int) ($currentTenant->logo_size_admin ?? 26);
  $mhdrLogoHeight = max(16, min(40, $mhdrLogoHeight)); // clamp to mobile-friendly size

  // MARKER-PATCH-363 — unread alerts count for the mobile header bell
  // (per-user, mirrors StaffAlertController::feed). Server-rendered; refreshes
  // on each page load, like the inbox badge in the attention row.
  $mhdrAlertsUnread = 0;
  if (auth('tenant')->check()) {
      $mhdrAlertsUnread = (int) \App\Models\Tenant\TenantStaffAlert::where('tenant_id', tenant()->id)
          ->where('user_id', auth('tenant')->id())
          ->whereNull('read_at')
          ->count();
  }
@endphp
<header class="ia-mobile-header" role="banner">
  <div class="ia-mobile-header-inner">
    @if($mobileBackLabel && $mobileBackUrl)
      <a href="{{ $mobileBackUrl }}" class="ia-mobile-header-back" aria-label="Back to {{ $mobileBackLabel }}">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <polyline points="15 18 9 12 15 6"/>
        </svg>
        <span>{{ $mobileBackLabel }}</span>
      </a>
    @elseif($mhdrLogo)
      <a href="{{ route('tenant.dashboard') }}" class="ia-mobile-header-brand" aria-label="{{ $currentTenant->name }} — Dashboard">
        <img src="{{ $mhdrLogo }}" alt="{{ $currentTenant->name }}" class="ia-mobile-header-logo" style="height:{{ $mhdrLogoHeight }}px">
      </a>
    @else
      <a href="{{ route('tenant.dashboard') }}" class="ia-mobile-header-brand ia-mobile-header-brand-text" aria-label="{{ $currentTenant->name }} — Dashboard">
        <span class="ia-mobile-header-mark">{{ strtoupper(substr($currentTenant->name, 0, 1)) }}</span>
        <span class="ia-mobile-header-name">{{ $currentTenant->name }}</span>
      </a>
    @endif
    @include('layouts.tenant._location-switcher')

    {{-- MARKER-OFFLINE-SYNC stage 4 — status pill mount, in the header flow --}}
    <span id="ioMountMobile" style="display:inline-flex;align-items:center;margin-left:auto;margin-right:8px"></span>
    {{-- MARKER-PATCH-363 — alerts bell -> full notifications page, with unread badge --}}
    <a href="{{ route('tenant.notifications') }}" class="ia-mobile-header-bell"
       aria-label="Notifications{{ $mhdrAlertsUnread > 0 ? ' — '.$mhdrAlertsUnread.' unread' : '' }}">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>
      </svg>
      @if($mhdrAlertsUnread > 0)<span class="ia-mobile-header-bell-badge">{{ $mhdrAlertsUnread > 99 ? '99+' : $mhdrAlertsUnread }}</span>@endif
    </a>
  </div>
</header>
