{{-- ================================================================
     Mobile admin header (≤1023px)
     Shows tenant logo if uploaded, else tenant name as wordmark.
     Desktop hides this entirely via CSS.
     ================================================================ --}}
<header class="ia-mobile-header" role="banner">
  <div class="ia-mobile-header-inner">
    @if($currentTenant->logo_url)
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
