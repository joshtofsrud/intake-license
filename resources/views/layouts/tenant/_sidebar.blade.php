@php
  $sidebarBg = ($adminTheme === 'c') ? '#0c0c0c' : (($adminTheme === 'a') ? '#0f0f0f' : '#ffffff');
  $sidebarLogo = \App\Support\ColorHelper::pickLogo($currentTenant, $sidebarBg);
@endphp

<aside class="ia-sidebar">

  {{-- Logo (tenant only — no co-branding) --}}
  <div class="ia-sidebar-logo">
    @if($sidebarLogo)
      <img src="{{ $sidebarLogo }}" alt="{{ $currentTenant->name }}" style="height:26px;width:auto;border-radius:4px">
    @else
      <div class="ia-sidebar-logo-mark">{{ strtoupper(substr($currentTenant->name, 0, 1)) }}</div>
    @endif
    <span class="ia-sidebar-logo-name">{{ $currentTenant->name }}</span>
  </div>

  {{-- Primary nav --}}
  @include('layouts.tenant._nav-items')

  {{-- Bottom: user + logout + branding credit --}}
  <div class="ia-sidebar-bottom">
    <div class="ia-sidebar-user" onclick="document.getElementById('logout-form').submit()">
      <div class="ia-user-avatar">{{ strtoupper(substr($authUser->name, 0, 2)) }}</div>
      <div>
        <div class="ia-user-name">{{ $authUser->name }}</div>
        <div class="ia-user-role">{{ ucfirst($authUser->role) }}</div>
      </div>
    </div>

    @include('layouts.tenant._brand-footer')

    <form id="logout-form" method="POST" action="{{ route('tenant.logout') }}" style="display:none">
      @csrf
    </form>
  </div>

</aside>
