@php
  // MARKER-PATCH-360 — the More drawer renders the SAME nav item set as the
  // desktop sidebar (_nav-items.blade.php), grouped into sections. Sharing the
  // full list guarantees nothing is dropped and that plan tiers (Starter vs
  // Scale) surface exactly the items their feature gates allow.
  $current = request()->route()?->getName() ?? '';

  // MARKER-NAV-ONE-LIST — one list for the sidebar and the phone drawer.
  $navItems = \App\Support\TenantNav::items();

  // MARKER-NAV-REGROUP
  $drawerSections = ['workspace' => 'Workspace', 'manage' => 'Manage', 'website' => 'Website', 'marketing' => 'Marketing', 'messages' => 'Messages', 'settings' => 'Settings'];
  // Already in the bottom tab bar — don't repeat them in the drawer:
  $drawerSkip = ['tenant.dashboard', 'tenant.calendar.index', 'tenant.customers.index', 'tenant.inbox.index'];
  $lastSect = null;
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

    <nav class="ia-drawer-nav" aria-label="All sections">
      @foreach($navItems as $navItem)
        @continue(in_array($navItem['route'], $drawerSkip, true))
        @continue(!empty($navItem['gate']) && !$currentTenant->{$navItem['gate']})
        @continue(!\Illuminate\Support\Facades\Route::has($navItem['route']))
        {{-- MARKER-PATCH-493 — role section visibility --}}
        @php $dSec = \App\Support\SectionRegistry::sectionForRoute($navItem['route']); @endphp
        @continue($dSec && !empty($authUser) && !$authUser->canAccessSection($dSec))
        @php $sect = $navItem['group'] ?? 'workspace'; @endphp
        @if($sect !== $lastSect)
          @if($lastSect !== null)<div class="ia-drawer-rule"></div>@endif
          <div class="ia-drawer-sect">{{ $drawerSections[$sect] ?? \Illuminate\Support\Str::title($sect) }}</div>
          @php $lastSect = $sect; @endphp
        @endif
        @php $isActive = str_starts_with($current, str_replace('.index', '', $navItem['route'])); @endphp
        <a href="{{ route($navItem['route']) }}" class="ia-drawer-link {{ $isActive ? 'active' : '' }}">
          <span class="ia-drawer-link-ic">{!! $navItem['icon'] !!}</span>
          <span class="ia-drawer-link-lb">{{ $navItem['label'] }}</span>
          <span class="ia-drawer-link-ch" aria-hidden="true">&rsaquo;</span>
        </a>
      @endforeach
    </nav>

    {{-- DRAWER-USER v2 — split user info from sign-out to prevent accidental logouts --}}
    <div class="ia-drawer-user ia-drawer-user--readonly">
      <div class="ia-user-avatar">{{ strtoupper(substr($authUser->name, 0, 2)) }}</div>
      <div>
        <div class="ia-user-name">{{ $authUser->name }}</div>
        <div class="ia-user-role">{{ ucfirst($authUser->role ?? 'Member') }}</div>
      </div>
    </div>
    {{-- MARKER-PATCH-496 — switch user (PIN tier only) --}}
    {{-- MARKER-DEMO-FIXES — see _sidebar: no switch user on a demo tenant --}}
    {{-- MARKER-IMPERSONATE-SWITCH — see _sidebar --}}
    @if($currentTenant->pin_tier_active && ! $currentTenant->is_demo && ! is_impersonating())
    <a href="{{ route('tenant.switch') }}" class="ia-drawer-signout" style="text-decoration:none">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M16 3h5v5"/><path d="M21 3l-7 7"/>
        <path d="M8 21H3v-5"/><path d="M3 21l7-7"/>
      </svg>
      Switch user
    </a>
    @endif
    <button type="button"
            class="ia-drawer-signout"
            onclick="iaConfirm('Sign out of {{ addslashes($currentTenant->name) }}?').then(function (ok) { if (ok) { document.getElementById('logout-form-mobile').submit(); } })"{{-- MARKER-NAV-ONE-LIST: in-app dialog, not the browser's --}}>
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
