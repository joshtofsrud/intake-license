{{-- MARKER-PATCH-HLC22 — unified Inventory tab bar.
     Items is always present; distributor tabs appear only when the
     bike_distributor_sync addon is enabled. Mobile: scrolls horizontally. --}}
@php
  // MARKER-PATCH-HLC27 — section-level inventory nav. Each tab carries an
  // explicit match prefix so exactly one highlights (Items no longer lights up
  // on categories/receiving/uncategorized, which all start with tenant.inventory).
  $cur = Route::currentRouteName() ?? '';
  $distOn = $currentTenant->distributor_sync_enabled ?? false;
  $uncatCount = \App\Models\Tenant\TenantInventoryItem::where('tenant_id', $currentTenant->id)->whereNull('category_id')->count();

  $invTabs = [];
  $invTabs[] = ['route' => 'tenant.inventory.index',          'label' => 'Items',             'match' => 'tenant.inventory.index'];
  if ($uncatCount > 0) {
      $invTabs[] = ['route' => 'tenant.inventory.uncategorized', 'label' => 'Uncategorized (' . $uncatCount . ')', 'match' => 'tenant.inventory.uncategorized'];
  }
  // MARKER-DUP-MERGE — for every shop, not only ones with a distributor.
  $dupCount = \App\Models\Tenant\TenantDuplicateGroup::openCount($currentTenant->id);
  if ($dupCount > 0) {
      $invTabs[] = ['route' => 'tenant.inventory.duplicates', 'label' => 'Duplicates (' . number_format($dupCount) . ')', 'match' => 'tenant.inventory.duplicates'];
  }
  $invTabs[] = ['route' => 'tenant.inventory.categories.index', 'label' => 'Categories',        'match' => 'tenant.inventory.categories'];
  $invTabs[] = ['route' => 'tenant.inventory.receiving.index',  'label' => 'Receiving',         'match' => 'tenant.inventory.receiving'];
  $invTabs[] = ['route' => 'tenant.inventory.reports',          'label' => 'Reports',           'match' => 'tenant.inventory.reports']; // MARKER-INV-REPORTS-TABS
  if ($distOn) {
      $invTabs[] = ['route' => 'tenant.distributors.import',     'label' => 'Import',            'match' => 'tenant.distributors.import'];
      $invTabs[] = ['route' => 'tenant.distributors.attention',  'label' => 'Catalog attention', 'match' => 'tenant.distributors.attention'];
      $invTabs[] = ['route' => 'tenant.distributors.connection', 'label' => 'Connection & sync', 'match' => 'tenant.distributors.connection'];
  }
@endphp
{{-- MARKER-SECTION-WIDTH — the bar scrolls, and the current tab is scrolled
     into view: on Catalog attention the first tab was cut off, and on Import
     and Connection the last one sat off-screen. --}}
<div class="ia-tabs">
<div class="ia-tabs-bar" style="display:flex;gap:4px;border-bottom:1px solid var(--ia-border);margin-bottom:22px;overflow-x:auto;white-space:nowrap">
  @foreach($invTabs as $t)
    @continue(! Route::has($t['route']))
    @php $active = str_starts_with($cur, $t['match']); @endphp
    <a href="{{ route($t['route']) }}" @if($active) data-tab-active @endif
       style="padding:9px 15px;font-size:13px;font-weight:600;text-decoration:none;flex-shrink:0;border-bottom:2px solid {{ $active ? 'var(--ia-accent)' : 'transparent' }};color:{{ $active ? 'var(--ia-text)' : 'var(--ia-text-dim)' }}">{{ $t['label'] }}</a>
  @endforeach
</div>
</div>
<script>
// MARKER-SECTION-WIDTH — keep the current tab visible, and drop the right-hand
// fade once the bar is scrolled to its end.
(function () {
  var wrap = document.currentScript.previousElementSibling;
  if (!wrap || !wrap.classList.contains('ia-tabs')) { return; }
  var bar = wrap.querySelector('.ia-tabs-bar');
  var active = bar && bar.querySelector('[data-tab-active]');
  if (active && bar.scrollWidth > bar.clientWidth) {
    bar.scrollLeft = Math.max(0, active.offsetLeft - (bar.clientWidth - active.offsetWidth) / 2);
  }
  var edge = function () {
    wrap.classList.toggle('at-end', bar.scrollLeft + bar.clientWidth >= bar.scrollWidth - 2);
  };
  if (bar) { bar.addEventListener('scroll', edge, { passive: true }); edge(); }
})();
</script>
