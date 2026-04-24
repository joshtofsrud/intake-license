@php
  $current = request()->route()?->getName() ?? '';

  // The 5 primary tabs for mobile. "More" always last.
  $mobilePrimary = [
    [
      'route'  => 'tenant.dashboard',
      'label'  => 'Home',
      'match'  => 'tenant.dashboard',
      'icon'   => '<svg width="20" height="20" viewBox="0 0 20 20" fill="none"><rect x="2" y="2" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.4"/><rect x="11" y="2" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.4"/><rect x="2" y="11" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.4"/><rect x="11" y="11" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.4"/></svg>',
    ],
    [
      'route'  => 'tenant.calendar.index',
      'label'  => 'Schedule',
      'match'  => 'tenant.calendar|tenant.appointments',
      'icon'   => '<svg width="20" height="20" viewBox="0 0 20 20" fill="none"><rect x="2" y="4" width="16" height="14" rx="1.5" stroke="currentColor" stroke-width="1.4"/><path d="M7 4V2M13 4V2M2 8h16" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/><circle cx="6" cy="12" r="1" fill="currentColor"/><circle cx="10" cy="12" r="1" fill="currentColor"/><circle cx="14" cy="12" r="1" fill="currentColor"/></svg>',
    ],
    [
      'route'  => 'tenant.customers.index',
      'label'  => 'Customers',
      'match'  => 'tenant.customers',
      'icon'   => '<svg width="20" height="20" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="7" r="3.5" stroke="currentColor" stroke-width="1.4"/><path d="M3 17c0-3 3-5 7-5s7 2 7 5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>',
    ],
  ];

  // Conditionally show Inbox if messaging is shipped (route exists).
  if (\Illuminate\Support\Facades\Route::has('tenant.inbox.index')) {
    $mobilePrimary[] = [
      'route'  => 'tenant.inbox.index',
      'label'  => 'Inbox',
      'match'  => 'tenant.inbox',
      'icon'   => '<svg width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M3 5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H6l-3 2V5z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>',
    ];
  }
@endphp

<nav class="ia-mobile-nav" aria-label="Primary">
  @foreach($mobilePrimary as $item)
    @php
      // 'match' may be a single prefix or a |-separated list of prefixes.
      // Schedule tab uses this to stay active for both calendar.* and appointments.* routes.
      $matchPrefixes = explode('|', $item['match']);
      $isActive = false;
      foreach ($matchPrefixes as $prefix) {
        if (str_starts_with($current, $prefix)) { $isActive = true; break; }
      }
      $url = route($item['route']);
    @endphp
    <a href="{{ $url }}" class="ia-mobile-nav-item {{ $isActive ? 'active' : '' }}">
      <span class="ia-mobile-nav-icon">{!! $item['icon'] !!}</span>
      <span class="ia-mobile-nav-label">{{ $item['label'] }}</span>
    </a>
  @endforeach

  <button type="button" class="ia-mobile-nav-item" onclick="IntakeMobileNav.openDrawer()" aria-label="More">
    <span class="ia-mobile-nav-icon">
      <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
        <circle cx="5" cy="10" r="1.5" fill="currentColor"/>
        <circle cx="10" cy="10" r="1.5" fill="currentColor"/>
        <circle cx="15" cy="10" r="1.5" fill="currentColor"/>
      </svg>
    </span>
    <span class="ia-mobile-nav-label">More</span>
  </button>
</nav>
