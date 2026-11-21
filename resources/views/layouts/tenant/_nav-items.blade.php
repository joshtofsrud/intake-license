@php
  $current = request()->route()?->getName() ?? '';
  // one list for the sidebar and the phone drawer; gates and role checks are done in TenantNav::visible.
  // MARKER-NAV-PINS: each person can pin items to the top (and drag them into order); the rest folds under More.
  $navItems = \App\Support\TenantNav::visible($currentTenant, $authUser ?? null);
  $navPins = \App\Support\TenantNav::pins($authUser ?? null, $navItems);
  $navByRoute = collect($navItems)->keyBy('route');
  $navRest = array_values(array_filter($navItems, fn ($i) => ! in_array($i['route'], $navPins, true)));
  $navOpenGroups = array_flip(array_map(fn ($i) => $i['group'] ?? '', $navRest));

  // Engage split into the three jobs it was doing.
  // identical to _attention-row, so the sidebar count
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

{{-- MARKER-NAV-PINS: pinned items, in this person's order. Drag to reorder. --}}
<div class="ia-nav-pins" id="ia-nav-pins" data-nav-pins @if(! $navPins) hidden @endif>
  <div class="ia-nav-section">Pinned</div>
  @foreach($navPins as $r)
    @php $item = $navByRoute[$r]; $idx = array_search($r, array_column($navItems, 'route'), true); @endphp
  <a href="{{ route($item['route']) }}" class="ia-nav-item {{ \App\Support\TenantNav::isActive($item, $current) ? 'active' : '' }}" title="{{ $item['label'] }}"
     data-nav-row data-route="{{ $item['route'] }}" data-idx="{{ $idx }}" data-group="{{ $item['group'] ?? '' }}" draggable="false">
    {!! $item['icon'] !!}
    <span class="ia-nav-label">{{ $item['label'] }}</span>
    {{-- inbox count is server-rendered; alerts is filled
         by the same feed the bell polls, so the two never disagree. --}}
    @if(($item['badge'] ?? null) === 'inbox' && $navInboxUnread > 0)
      <span class="ia-nav-badge">{{ $navInboxUnread > 99 ? '99+' : $navInboxUnread }}</span>
    @elseif(($item['badge'] ?? null) === 'alerts')
      <span class="ia-nav-badge" data-nav-alert-badge hidden></span>
    @endif
    <span class="ia-nav-pin" role="button" tabindex="0" data-nav-pin aria-label="Pin or unpin {{ $item['label'] }}" title="Pin to the top, or unpin. Pinned items can be dragged into order."><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 17v5M9 3h6l-1 6 4 4H6l4-4-1-6z"/></svg></span>
  </a>
  @endforeach
</div>

<button type="button" class="ia-nav-item ia-nav-more" id="ia-nav-more" aria-expanded="false" title="More" @if(! $navPins) hidden @endif>
  <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><circle cx="3" cy="7" r="1.1" fill="currentColor"/><circle cx="7" cy="7" r="1.1" fill="currentColor"/><circle cx="11" cy="7" r="1.1" fill="currentColor"/></svg>
  <span class="ia-nav-label">More</span>
  <span class="ia-nav-more-n" data-nav-rest-n>{{ count($navRest) }}</span>
</button>

{{-- everything not pinned, grouped as always. Folded while there are pins, except the page you are on. --}}
<div class="ia-nav-fold {{ $navPins ? 'folded' : '' }}" id="ia-nav-fold" data-nav-rest>
@foreach($navItems as $idx => $item)
  {{-- the uppercase header already separates groups;
       a divider on top of it stacked ~36px of dead space per boundary, and
       with five groups that reads as gappy rather than organized. --}}
  @if($item['group'] !== $lastGroup && $item['group'])
    <div class="ia-nav-section" data-nav-head="{{ $item['group'] }}" @if(! isset($navOpenGroups[$item['group']])) hidden @endif>{{ $groups[$item['group']] }}</div>
    @php $lastGroup = $item['group']; @endphp
  @endif
  @continue(in_array($item['route'], $navPins, true))

  {{-- title carries the label when collapsed; the
       text itself stays in the DOM for screen readers rather than being
       display:none'd away. --}}
  <a href="{{ route($item['route']) }}" class="ia-nav-item {{ \App\Support\TenantNav::isActive($item, $current) ? 'active' : '' }}" title="{{ $item['label'] }}"
     data-nav-row data-route="{{ $item['route'] }}" data-idx="{{ $idx }}" data-group="{{ $item['group'] ?? '' }}" draggable="false">
    {!! $item['icon'] !!}
    <span class="ia-nav-label">{{ $item['label'] }}</span>
    {{-- inbox count is server-rendered; alerts is filled
         by the same feed the bell polls, so the two never disagree. --}}
    @if(($item['badge'] ?? null) === 'inbox' && $navInboxUnread > 0)
      <span class="ia-nav-badge">{{ $navInboxUnread > 99 ? '99+' : $navInboxUnread }}</span>
    @elseif(($item['badge'] ?? null) === 'alerts')
      <span class="ia-nav-badge" data-nav-alert-badge hidden></span>
    @endif
    <span class="ia-nav-pin" role="button" tabindex="0" data-nav-pin aria-label="Pin or unpin {{ $item['label'] }}" title="Pin to the top, or unpin. Pinned items can be dragged into order."><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 17v5M9 3h6l-1 6 4 4H6l4-4-1-6z"/></svg></span>
  </a>
@endforeach
</div>
<script>try { if (localStorage.getItem('ia-nav-more') === '1') { document.getElementById('ia-nav-fold').classList.remove('folded'); document.getElementById('ia-nav-more').setAttribute('aria-expanded', 'true'); } } catch (e) {}</script>



<style>
  .ia-nav-item { position: relative; }
  /* MARKER-NAV-PINS */
  .ia-nav-item[data-nav-row] { -webkit-user-drag: none; user-select: none; }
  .ia-nav-pin { margin-left: auto; display: none; width: 20px; height: 20px; border-radius: 5px; place-content: center; color: inherit; opacity: .45; cursor: pointer; flex: none; }
  .ia-nav-badge + .ia-nav-pin { margin-left: 6px; }
  .ia-nav-item:hover .ia-nav-pin, .ia-nav-pin:focus-visible { display: grid; }
  .ia-nav-pin:hover, .ia-nav-pin:focus-visible { opacity: 1; background: rgba(127,127,127,.18); outline: none; }
  .ia-nav-pins .ia-nav-pin svg { fill: currentColor; }
  .ia-nav-pins .ia-nav-item { cursor: grab; }
  .ia-nav-pins .ia-nav-item.dragging { cursor: grabbing; opacity: .6; background: var(--ia-side-hover, rgba(127,127,127,.15)); }
  @media (hover: none) { .ia-nav-pin { display: grid; opacity: .3; } }
  button.ia-nav-more { width: calc(100% - 16px); background: none; border: 0; border-left: 2px solid transparent; outline: none; font: inherit; font-size: 13px; text-align: left; }
  button.ia-nav-more:focus-visible { background: var(--ia-side-hover, rgba(127,127,127,.15)); }
  .ia-nav-more-n { margin-left: auto; font-size: 10.5px; opacity: .5; }
  .ia-nav-more-n:after { content: " ▾"; }
  .ia-nav-more[aria-expanded="true"] .ia-nav-more-n:after { content: " ▴"; }
  .ia-nav-fold.folded > *:not(.active) { display: none; }
  html.ia-sb-collapsed .ia-nav-pin, html.ia-sb-collapsed .ia-nav-more-n { display: none !important; }
  html.ia-sb-collapsed button.ia-nav-more { width: auto; }
  @media (max-width: 900px) { .ia-nav-pin { display: none !important; } }
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
