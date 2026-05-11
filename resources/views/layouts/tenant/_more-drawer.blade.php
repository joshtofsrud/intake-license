{{-- DRAWER-NAV-SYNC v1 — drawer items derived from shared $navItems.
     Excludes routes that already appear in the bottom nav bar.
     Anything added to _nav-items-data.blade.php shows up here automatically. --}}
@include('layouts.tenant._nav-items-data')
@php
  $current = request()->route()?->getName() ?? '';

  // Routes already shown in the bottom nav bar. Don't duplicate them in the drawer.
  $bottomNavRoutes = [
    'tenant.dashboard',
    'tenant.calendar.index',
    'tenant.customers.index',
  ];

  // Filter $navItems: drop bottom-nav primaries, drop gated items the tenant
  // doesn't have enabled, drop any with routes that don't exist.
  $drawerItems = [];
  foreach ($navItems as $item) {
    if (in_array($item['route'], $bottomNavRoutes, true)) continue;
    if (!empty($item['gate']) && !$currentTenant->{$item['gate']}) continue;
    if (!\Illuminate\Support\Facades\Route::has($item['route'])) continue;
    $drawerItems[] = $item;
  }
@endphp

<div class="ia-drawer-overlay" id="ia-more-drawer" aria-hidden="true" onclick="IntakeMobileNav.closeDrawerFromOverlay(event)">
  <div class="ia-drawer" role="dialog" aria-modal="true" aria-labelledby="ia-drawer-title">
    <div class="ia-drawer-handle" aria-hidden="true"></div>
    <div class="ia-drawer-header">
      <h2 id="ia-drawer-title" class="ia-drawer-title">More</h2>
      <button type="button" class="ia-drawer-close" onclick="IntakeMobileNav.closeDrawer()" aria-label="Close">
        <svg width="18" height="18" viewBox="0 0 18 18" fill="none">
          <path d="M4 4l10 10M14 4L4 14" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
        </svg>
      </button>
    </div>

    <div class="ia-drawer-items">
      @foreach($drawerItems as $item)
        @php
          $primaryMatch = str_replace('.index', '', $item['route']);
          $isActive = str_starts_with($current, $primaryMatch);
          if (!$isActive && !empty($item['match_alt'])) {
            $isActive = str_starts_with($current, $item['match_alt']);
          }
        @endphp
        <a href="{{ route($item['route']) }}" class="ia-drawer-item {{ $isActive ? 'active' : '' }}">
          {{ $item['label'] }}
        </a>
      @endforeach
    </div>

    {{-- DRAWER-USER v2 — split user info from sign-out to prevent accidental logouts --}}
    <div class="ia-drawer-user ia-drawer-user--readonly">
      <div class="ia-user-avatar">{{ strtoupper(substr($authUser->name, 0, 2)) }}</div>
      <div>
        <div class="ia-user-name">{{ $authUser->name }}</div>
        <div class="ia-user-role">{{ ucfirst($authUser->role ?? 'Member') }}</div>
      </div>
    </div>
    <button type="button"
            class="ia-drawer-signout"
            onclick="if(confirm('Sign out of {{ addslashes($currentTenant->name) }}?')) document.getElementById('logout-form-mobile').submit()">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
        <polyline points="16 17 21 12 16 7"/>
        <line x1="21" y1="12" x2="9" y2="12"/>
      </svg>
      Sign out
    </button>

    @include('layouts.tenant._brand-footer')

    <form id="logout-form-mobile" method="POST" action="{{ route('tenant.logout') }}" style="display:none">
      @csrf
    </form>
  </div>
</div>
