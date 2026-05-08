@php
  $sidebarBg = ($adminTheme === 'c') ? '#0c0c0c' : (($adminTheme === 'a') ? '#0f0f0f' : '#ffffff');
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

  {{-- Primary nav --}}
  @include('layouts.tenant._nav-items')

  {{-- Bottom: user + logout --}}
  <div class="ia-sidebar-bottom">
    <div class="ia-sidebar-user" onclick="document.getElementById('logout-form').submit()">
      <div class="ia-user-avatar">{{ strtoupper(substr($authUser->name, 0, 2)) }}</div>
      <div>
        <div class="ia-user-name">{{ $authUser->name }}</div>
        <div class="ia-user-role">{{ ucfirst($authUser->role) }}</div>
      </div>
    </div>
    <form id="logout-form" method="POST" action="{{ route('tenant.logout') }}" style="display:none">
      @csrf
    </form>
  </div>

</aside>
