<header class="ia-topbar">

  {{-- Logo --}}
  <div class="ia-topbar-logo">
    @if($currentTenant->logo_url)
      <img src="{{ $currentTenant->logo_url }}" alt="{{ $currentTenant->name }}" style="height:24px;width:auto;border-radius:3px">
    @else
      <div class="ia-topbar-logo-mark">{{ strtoupper(substr($currentTenant->name, 0, 1)) }}</div>
    @endif
    <span class="ia-topbar-logo-name">{{ $currentTenant->name }}</span>
  </div>

  {{-- Nav items --}}
  <nav class="ia-topbar-nav">
    @include('layouts.tenant._nav-items')
  </nav>

  {{-- End: user avatar + logout --}}
  <div class="ia-topbar-end">
    <a href="{{ route('tenant.settings.index') }}" class="ia-btn ia-btn--ghost ia-btn--sm">Settings</a>
    <div class="ia-user-avatar" title="{{ $authUser->name }} · {{ ucfirst($authUser->role) }}"
         onclick="document.getElementById('logout-form-b').submit()">
      {{ strtoupper(substr($authUser->name, 0, 2)) }}
    </div>
    <form id="logout-form-b" method="POST" action="{{ route('tenant.logout') }}" style="display:none">
      @csrf
    </form>
  </div>

</header>
