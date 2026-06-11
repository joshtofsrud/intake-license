{{-- MARKER-PATCH-228B — shared rental sub-nav. Pass $active with one of:
     desk | fleet | bookings | availability | leases | settings.
     Consistent across every rental page so you can reach any page from
     any page. Page-specific actions live in the page, not here. --}}
@php
  $rnTabs = [
    ['key' => 'desk',         'label' => 'Desk',         'route' => 'tenant.rentals.desk'],
    ['key' => 'fleet',        'label' => 'Fleet',        'route' => 'tenant.rentals.fleet'],
    ['key' => 'bookings',     'label' => 'Bookings',     'route' => 'tenant.rentals.bookings.index'],
    ['key' => 'availability', 'label' => 'Availability', 'route' => 'tenant.rentals.availability.timeline'],
  ];
  if (tenant()->leases_enabled) {
    $rnTabs[] = ['key' => 'leases', 'label' => 'Leases', 'route' => 'tenant.rentals.leases.index']; // MARKER-PATCH-230
  }
  $rnTabs[] = ['key' => 'settings', 'label' => 'Settings', 'route' => 'tenant.rentals.settings'];
@endphp
<div class="rn-tabs">
  @foreach($rnTabs as $tab)
    <a href="{{ route($tab['route']) }}" class="rn-tab {{ ($active ?? '') === $tab['key'] ? 'is-active' : '' }}">{{ $tab['label'] }}</a>
  @endforeach
</div>
<style>
  .rn-tabs{display:flex;gap:2px;margin:0 0 22px;border-bottom:.5px solid var(--ia-border);overflow-x:auto}
  .rn-tab{padding:9px 16px;font-size:13px;font-weight:550;color:var(--ia-text-dim,rgba(255,255,255,.55));text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-.5px;white-space:nowrap;transition:color .12s ease,border-color .12s ease}
  .rn-tab:hover{color:var(--ia-text,#f0f0f0)}
  .rn-tab.is-active{color:var(--ia-text,#f0f0f0);border-bottom-color:var(--ia-accent,#BEF264)}
</style>
