<header class="ia-topbar">

  {{-- Logo (image only when uploaded, fallback to letter + name when not) --}}
  <div class="ia-topbar-logo">
    @if($currentTenant->logo_url)
      <img src="{{ $currentTenant->logo_url }}" alt="{{ $currentTenant->name }}" style="height:24px;width:auto;border-radius:3px">
    @else
      <div class="ia-topbar-logo-mark">{{ strtoupper(substr($currentTenant->name, 0, 1)) }}</div>
      <span class="ia-topbar-logo-name">{{ $currentTenant->name }}</span>
    @endif
  </div>

  {{-- Nav items --}}
  <nav class="ia-topbar-nav">
    @include('layouts.tenant._nav-items')
  </nav>

  {{-- End: user avatar + logout --}}
  {{-- Note: Settings link is in _nav-items; no need to duplicate here. --}}
  <div class="ia-topbar-end">
    <div class="ia-user-avatar" title="{{ $authUser->name }} · {{ ucfirst($authUser->role) }}"
         onclick="document.getElementById('logout-form-b').submit()">
      {{ strtoupper(substr($authUser->name, 0, 2)) }}
    </div>
    <form id="logout-form-b" method="POST" action="{{ route('tenant.logout') }}" style="display:none">
      @csrf
    </form>
  </div>

</header>
