{{-- ================================================================
     Mobile admin header (≤1023px) — MOBILE-BACK v1
     Shows ‹ Back chevron when @section('mobile-back', 'Label|/url') is set,
     otherwise shows tenant logo or wordmark.
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
    @elseif($currentTenant->logo_url)
      <a href="{{ route('tenant.dashboard') }}" class="ia-mobile-header-brand" aria-label="{{ $currentTenant->name }} — Dashboard">
        <img src="{{ $currentTenant->logo_url }}" alt="{{ $currentTenant->name }}" class="ia-mobile-header-logo">
      </a>
    @else
      <a href="{{ route('tenant.dashboard') }}" class="ia-mobile-header-brand ia-mobile-header-brand-text" aria-label="{{ $currentTenant->name }} — Dashboard">
        <span class="ia-mobile-header-mark">{{ strtoupper(substr($currentTenant->name, 0, 1)) }}</span>
        <span class="ia-mobile-header-name">{{ $currentTenant->name }}</span>
      </a>
    @endif
  </div>
</header>
