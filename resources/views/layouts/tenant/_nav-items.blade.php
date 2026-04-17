@php
  $current = request()->route()?->getName() ?? '';
  $navItems = [
    [
      'route'  => 'tenant.dashboard',
      'label'  => 'Dashboard',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><rect x="1" y="1" width="5" height="5" rx="1" fill="currentColor"/><rect x="8" y="1" width="5" height="5" rx="1" fill="currentColor"/><rect x="1" y="8" width="5" height="5" rx="1" fill="currentColor"/><rect x="8" y="8" width="5" height="5" rx="1" fill="currentColor"/></svg>',
      'group'  => null,
    ],
    [
      'route'  => 'tenant.appointments.index',
      'label'  => 'Appointments',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><rect x="1" y="3" width="12" height="8" rx="1.5" stroke="currentColor" stroke-width="1.2"/><path d="M5 3V2M9 3V2" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>',
      'group'  => null,
    ],
    [
      'route'  => 'tenant.customers.index',
      'label'  => 'Customers',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><circle cx="7" cy="5" r="3" stroke="currentColor" stroke-width="1.2"/><path d="M1.5 12.5c0-2.5 2.5-4 5.5-4s5.5 1.5 5.5 4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>',
      'group'  => null,
    ],
    [
      'route'  => 'tenant.services.index',
      'label'  => 'Services',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M2 4h10M2 7h7M2 10h5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>',
      'group'  => 'manage',
    ],
    [
      'route'  => 'tenant.booking-editor.index',
      'label'  => 'Intake Form Editor',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><rect x="1" y="1" width="12" height="12" rx="1.5" stroke="currentColor" stroke-width="1.2"/><path d="M4 5h6M4 7.5h4M4 10h2" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>',
      'group'  => 'manage',
    ],
    [
      'route'  => 'tenant.capacity.index',
      'label'  => 'Capacity',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><rect x="1" y="1" width="12" height="12" rx="1.5" stroke="currentColor" stroke-width="1.2"/><path d="M1 5h12" stroke="currentColor" stroke-width="1.2"/><path d="M5 1v4M9 1v4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>',
      'group'  => 'manage',
    ],
    [
      'route'  => 'tenant.pages.index',
      'label'  => 'Pages',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><rect x="1" y="2" width="12" height="10" rx="1.5" stroke="currentColor" stroke-width="1.2"/><path d="M4 6h6M4 8.5h4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>',
      'group'  => 'manage',
    ],
    [
      'route'  => 'tenant.emails.index',
      'label'  => 'Email',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><rect x="1" y="3" width="12" height="8" rx="1.5" stroke="currentColor" stroke-width="1.2"/><path d="M1.5 4l5.5 4 5.5-4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>',
      'group'  => 'manage',
    ],
    [
      'route'  => 'tenant.campaigns.index',
      'label'  => 'Campaigns',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M2 7h10M9 4l3 3-3 3" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
      'group'  => 'manage',
    ],
    [
      'route'  => 'tenant.branding.index',
      'label'  => 'Branding',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><circle cx="7" cy="7" r="5" stroke="currentColor" stroke-width="1.2"/><circle cx="7" cy="7" r="2" fill="currentColor"/></svg>',
      'group'  => 'settings',
    ],
    [
      'route'  => 'tenant.settings.index',
      'label'  => 'Settings',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><circle cx="7" cy="7" r="2" stroke="currentColor" stroke-width="1.2"/><path d="M7 1v1.5M7 11.5V13M1 7h1.5M11.5 7H13M2.9 2.9l1.1 1.1M10 10l1.1 1.1M2.9 11.1l1.1-1.1M10 4l1.1-1.1" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>',
      'group'  => 'settings',
    ],
    [
      'route'  => 'tenant.team.index',
      'label'  => 'Team',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><circle cx="5" cy="5" r="2.5" stroke="currentColor" stroke-width="1.2"/><circle cx="10" cy="5" r="2" stroke="currentColor" stroke-width="1.2"/><path d="M1 12c0-2 1.8-3.5 4-3.5s4 1.5 4 3.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/><path d="M10.5 8.5c1.5.3 2.5 1.3 2.5 2.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>',
      'group'  => 'settings',
    ],
  ];

  $groups = ['manage' => 'Manage', 'settings' => 'Settings'];
  $lastGroup = null;
@endphp

@foreach($navItems as $item)
  @php
    $isActive = str_starts_with($current, str_replace('.index', '', $item['route']));
    $url      = route($item['route']);
  @endphp

  @if($item['group'] !== $lastGroup && $item['group'])
    @if($lastGroup !== null)
      <div class="ia-sidebar-divider"></div>
    @endif
    <div class="ia-nav-section">{{ $groups[$item['group']] }}</div>
    @php $lastGroup = $item['group']; @endphp
  @endif

  <a href="{{ $url }}" class="ia-nav-item {{ $isActive ? 'active' : '' }}">
    {!! $item['icon'] !!}
    {{ $item['label'] }}
  </a>

@endforeach
