@php
  $current = request()->route()?->getName() ?? '';

  // Items that go in the More drawer. Primary tabs are excluded above.
  $moreItems = [
    ['route' => 'tenant.services.index',        'label' => 'Services'],
    ['route' => 'tenant.resources.index',       'label' => 'Resources'],
    ['route' => 'tenant.capacity.index',        'label' => 'Capacity'],
    ['route' => 'tenant.pages.index',           'label' => 'Pages'],
    ['route' => 'tenant.booking-editor.index',  'label' => 'Intake Form Editor'],
    ['route' => 'tenant.emails.index',          'label' => 'Email'],
    ['route' => 'tenant.campaigns.index',       'label' => 'Campaigns'],
    ['route' => 'tenant.team.index',            'label' => 'Team'],
    ['route' => 'tenant.settings.index',        'label' => 'Settings'],
    ['route' => 'tenant.help.index',            'label' => 'Help & Guides'],
    ['route' => 'tenant.whats_new.changelog',   'label' => "What's New"],
    ['route' => 'tenant.whats_new.roadmap',     'label' => "What's Coming"],
  ];
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
      @foreach($moreItems as $item)
        @if(\Illuminate\Support\Facades\Route::has($item['route']))
          @php
            $isActive = str_starts_with($current, str_replace('.index', '', $item['route']));
          @endphp
          <a href="{{ route($item['route']) }}" class="ia-drawer-item {{ $isActive ? 'active' : '' }}">
            {{ $item['label'] }}
          </a>
        @endif
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
