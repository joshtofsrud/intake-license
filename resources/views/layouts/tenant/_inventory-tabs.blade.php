{{-- MARKER-PATCH-HLC22 — unified Inventory tab bar.
     Items is always present; distributor tabs appear only when the
     bike_distributor_sync addon is enabled. Mobile: scrolls horizontally. --}}
@php
  $cur = Route::currentRouteName() ?? '';
  $distOn = $currentTenant->distributor_sync_enabled ?? false;
  $invTabs = ['tenant.inventory.index' => 'Items'];
  if ($distOn) {
      $invTabs['tenant.distributors.import']     = 'Import';
      $invTabs['tenant.distributors.attention']  = 'Catalog attention';
      $invTabs['tenant.distributors.connection'] = 'Connection & sync';
  }
@endphp
<div style="display:flex;gap:4px;border-bottom:1px solid var(--ia-border);margin-bottom:22px;overflow-x:auto;white-space:nowrap">
  @foreach($invTabs as $route => $label)
    @continue(! Route::has($route))
    @php $active = str_starts_with($cur, str_replace('.index', '', $route)); @endphp
    <a href="{{ route($route) }}"
       style="padding:9px 15px;font-size:13px;font-weight:600;text-decoration:none;flex-shrink:0;border-bottom:2px solid {{ $active ? 'var(--ia-accent)' : 'transparent' }};color:{{ $active ? 'var(--ia-text)' : 'var(--ia-text-dim)' }}">{{ $label }}</a>
  @endforeach
</div>
