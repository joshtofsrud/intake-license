@php
  // the More drawer renders the SAME nav item set as the
  // desktop sidebar (_nav-items.blade.php), grouped into sections. Sharing the
  // full list guarantees nothing is dropped and that plan tiers (Starter vs
  // Scale) surface exactly the items their feature gates allow.
  $current = request()->route()?->getName() ?? '';

  // one list for the sidebar and the phone drawer; gates and role checks are done in TenantNav::visible.
  // MARKER-NAV-PINS: nothing is left out of the drawer any more (it used to skip the four bottom-bar
  // items), so every item can be reached and pinned from a phone.
  $navItems = \App\Support\TenantNav::visible($currentTenant, $authUser ?? null);
  $navPins = \App\Support\TenantNav::pins($authUser ?? null, $navItems);
  $navByRoute = collect($navItems)->keyBy('route');
  $navRoutes = array_column($navItems, 'route');
  $drawerOpenSects = array_flip(array_map(fn ($i) => $i['group'] ?? 'workspace', array_filter($navItems, fn ($i) => ! in_array($i['route'], $navPins, true))));

  $drawerSections = ['workspace' => 'Workspace', 'manage' => 'Manage', 'website' => 'Website', 'marketing' => 'Marketing', 'messages' => 'Messages', 'settings' => 'Settings'];
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
      {{-- MARKER-NAV-PINS: pinned first, in this person's order (drag the handle); tap a pin to add or remove one --}}
      <div class="ia-drawer-pins" data-nav-pins @if(! $navPins) hidden @endif>
        <div class="ia-drawer-sect">Pinned <span class="ia-drawer-sect-note">first 4 show in the bottom bar · drag to reorder</span></div>
        @foreach($navPins as $r)
          @php $navItem = $navByRoute[$r]; $idx = array_search($r, $navRoutes, true); @endphp
        <a href="{{ route($navItem['route']) }}" class="ia-drawer-link {{ \App\Support\TenantNav::isActive($navItem, $current) ? 'active' : '' }}"
           data-nav-row data-route="{{ $navItem['route'] }}" data-idx="{{ $idx }}" data-group="{{ $navItem['group'] ?? 'workspace' }}" draggable="false">
          <span class="ia-drawer-grip ia-nav-grip" aria-hidden="true"><svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M3 4.5h8M3 7h8M3 9.5h8" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg></span>
          <span class="ia-drawer-link-ic">{!! $navItem['icon'] !!}</span>
          <span class="ia-drawer-link-lb">{{ $navItem['label'] }}</span>
          <span class="ia-drawer-pin" role="button" tabindex="0" data-nav-pin aria-label="Pin or unpin {{ $navItem['label'] }}"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 17v5M9 3h6l-1 6 4 4H6l4-4-1-6z"/></svg></span>
        </a>
        @endforeach
        <div class="ia-drawer-rule"></div>
      </div>
      <div data-nav-rest>
      @foreach($navItems as $idx => $navItem)
        @php $sect = $navItem['group'] ?? 'workspace'; @endphp
        @if($sect !== $lastSect)
          <div class="ia-drawer-sect" data-nav-head="{{ $sect }}" @if(! isset($drawerOpenSects[$sect])) hidden @endif>{{ $drawerSections[$sect] ?? \Illuminate\Support\Str::title($sect) }}</div>
          @php $lastSect = $sect; @endphp
        @endif
        @continue(in_array($navItem['route'], $navPins, true))
        <a href="{{ route($navItem['route']) }}" class="ia-drawer-link {{ \App\Support\TenantNav::isActive($navItem, $current) ? 'active' : '' }}"
           data-nav-row data-route="{{ $navItem['route'] }}" data-idx="{{ $idx }}" data-group="{{ $navItem['group'] ?? 'workspace' }}" draggable="false">
          <span class="ia-drawer-grip ia-nav-grip" aria-hidden="true"><svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M3 4.5h8M3 7h8M3 9.5h8" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg></span>
          <span class="ia-drawer-link-ic">{!! $navItem['icon'] !!}</span>
          <span class="ia-drawer-link-lb">{{ $navItem['label'] }}</span>
          <span class="ia-drawer-pin" role="button" tabindex="0" data-nav-pin aria-label="Pin or unpin {{ $navItem['label'] }}"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 17v5M9 3h6l-1 6 4 4H6l4-4-1-6z"/></svg></span>
        </a>
      @endforeach
      </div>
    </nav>

    {{-- DRAWER-USER v2 — split user info from sign-out to prevent accidental logouts --}}
    <div class="ia-drawer-user ia-drawer-user--readonly">
      <div class="ia-user-avatar">{{ strtoupper(substr($authUser->name, 0, 2)) }}</div>
      <div>
        <div class="ia-user-name">{{ $authUser->name }}</div>
        <div class="ia-user-role">{{ ucfirst($authUser->role ?? 'Member') }}</div>
      </div>
    </div>
    {{-- switch user (PIN tier only) --}}
    {{-- see _sidebar: no switch user on a demo tenant --}}
    {{-- see _sidebar --}}
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
            onclick="iaConfirm('Sign out of {{ addslashes($currentTenant->name) }}?').then(function (ok) { if (ok) { document.getElementById('logout-form-mobile').submit(); } })"{{-- in-app dialog, not the browser's --}}>
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
