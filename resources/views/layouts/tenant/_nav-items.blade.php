@php
  $current = request()->route()?->getName() ?? '';
  // MARKER-NAV-ONE-LIST — one list for the sidebar and the phone drawer.
  $navItems = \App\Support\TenantNav::items();

  // MARKER-NAV-REGROUP — Engage split into the three jobs it was doing.
  // MARKER-NAV-MESSAGES — identical to _attention-row, so the sidebar count
  // and the top-row count are the same number, not two guesses.
  $navInboxUnread = 0;
  if (tenant()->unified_inbox_enabled) {
      $navInboxUnread = (int) \App\Models\Tenant\TenantThread::where('tenant_id', tenant()->id)
          ->where('status', '!=', 'closed')->sum('unread_count');
  }

  $groups = [
    'manage'    => 'Manage',
    'website'   => 'Website',
    'marketing' => 'Marketing',
    'messages'  => 'Messages',
    'settings'  => 'Settings',
  ];
  $lastGroup = null;
@endphp

@foreach($navItems as $item)
  @php
    $primaryMatch = str_replace('.index', '', $item['route']);
    $isActive = str_starts_with($current, $primaryMatch);
    if (!$isActive && !empty($item['match_alt'])) {
      $isActive = str_starts_with($current, $item['match_alt']);
    }
    $url      = route($item['route']);
  @endphp

  @if(!empty($item['gate']) && !$currentTenant->{$item['gate']})
    @continue
  @endif

  {{-- MARKER-PATCH-493 — Roles & access: hide sections outside the user's role --}}
  @php $navSec = \App\Support\SectionRegistry::sectionForRoute($item['route']); @endphp
  @if($navSec && !empty($authUser) && !$authUser->canAccessSection($navSec))
    @continue
  @endif

  {{-- MARKER-NAV-SPACING — the uppercase header already separates groups;
       a divider on top of it stacked ~36px of dead space per boundary, and
       with five groups that reads as gappy rather than organised. --}}
  @if($item['group'] !== $lastGroup && $item['group'])
    <div class="ia-nav-section">{{ $groups[$item['group']] }}</div>
    @php $lastGroup = $item['group']; @endphp
  @endif

  {{-- MARKER-SIDEBAR-COLLAPSE — title carries the label when collapsed; the
       text itself stays in the DOM for screen readers rather than being
       display:none'd away. --}}
  <a href="{{ $url }}" class="ia-nav-item {{ $isActive ? 'active' : '' }}" title="{{ $item['label'] }}">
    {!! $item['icon'] !!}
    <span class="ia-nav-label">{{ $item['label'] }}</span>
    {{-- MARKER-NAV-MESSAGES — inbox count is server-rendered; alerts is filled
         by the same feed the bell polls, so the two never disagree. --}}
    @if(($item['badge'] ?? null) === 'inbox' && $navInboxUnread > 0)
      <span class="ia-nav-badge">{{ $navInboxUnread > 99 ? '99+' : $navInboxUnread }}</span>
    @elseif(($item['badge'] ?? null) === 'alerts')
      <span class="ia-nav-badge" data-nav-alert-badge hidden></span>
    @endif
  </a>

@endforeach



{{-- MARKER-NAV-MESSAGES --}}
<style>
  .ia-nav-item { position: relative; }
  .ia-nav-badge {
    margin-left: auto;
    min-width: 16px; height: 16px; padding: 0 4px;
    border-radius: 999px; background: #5BA3D0; color: #fff;
    font-size: 10px; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
  }
  html.ia-sb-collapsed .ia-nav-badge {
    position: absolute; top: 4px; right: 6px; margin-left: 0;
  }
</style>
<script>
(function () {
  var el = document.querySelector('[data-nav-alert-badge]');
  if (!el) return;
  function paint(n) {
    if (n > 0) { el.hidden = false; el.textContent = n > 99 ? '99+' : n; }
    else { el.hidden = true; }
  }
  function load() {
    fetch('{{ route('tenant.alerts.feed') }}', { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) { paint((d && d.unread) || 0); })
      .catch(function () {});
  }
  load();
  setInterval(load, 60000);
})();
</script>
