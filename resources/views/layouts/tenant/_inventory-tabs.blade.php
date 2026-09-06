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
  $invTabs[] = ['route' => 'tenant.inventory.categories.index', 'label' => 'Categories',        'match' => 'tenant.inventory.categories'];
  $invTabs[] = ['route' => 'tenant.inventory.category-mappings.index', 'label' => 'Category mappings', 'match' => 'tenant.inventory.category-mappings']; // MARKER-CAT-MAP
  $invTabs[] = ['route' => 'tenant.inventory.receiving.index',  'label' => 'Receiving',         'match' => 'tenant.inventory.receiving'];
  $invTabs[] = ['route' => 'tenant.inventory.reports',          'label' => 'Reports',           'match' => 'tenant.inventory.reports']; // MARKER-INV-REPORTS-TABS
  if ($distOn) {
      $invTabs[] = ['route' => 'tenant.distributors.import',     'label' => 'Import',            'match' => 'tenant.distributors.import'];
      $invTabs[] = ['route' => 'tenant.distributors.attention',  'label' => 'Catalog attention', 'match' => 'tenant.distributors.attention'];
      $invTabs[] = ['route' => 'tenant.distributors.connection', 'label' => 'Connection & sync', 'match' => 'tenant.distributors.connection'];
  }
@endphp
<div style="display:flex;gap:4px;border-bottom:1px solid var(--ia-border);margin-bottom:22px;overflow-x:auto;white-space:nowrap">
  @foreach($invTabs as $t)
    @continue(! Route::has($t['route']))
    @php $active = str_starts_with($cur, $t['match']); @endphp
    <a href="{{ route($t['route']) }}"
       style="padding:9px 15px;font-size:13px;font-weight:600;text-decoration:none;flex-shrink:0;border-bottom:2px solid {{ $active ? 'var(--ia-accent)' : 'transparent' }};color:{{ $active ? 'var(--ia-text)' : 'var(--ia-text-dim)' }}">{{ $t['label'] }}</a>
  @endforeach
</div>
