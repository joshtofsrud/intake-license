{{-- MARKER-PATCH-HLC7B — distributor area tabs --}}
@php $cur = Route::currentRouteName(); @endphp
<div style="display:flex;gap:4px;border-bottom:1px solid var(--ia-border);margin-bottom:22px">
  @php
    $tabs = [
      'tenant.distributors.import'             => 'Import',
      'tenant.distributors.attention'          => 'Pricing Attention',
      'tenant.distributors.connection'         => 'Connection & Sync',
    ];
  @endphp
  @foreach($tabs as $route => $label)
    @continue(! Route::has($route))
    @php $active = $cur === $route; @endphp
    <a href="{{ route($route) }}"
       style="padding:9px 15px;font-size:13px;font-weight:600;text-decoration:none;border-bottom:2px solid {{ $active ? 'var(--ia-accent)' : 'transparent' }};color:{{ $active ? 'var(--ia-text)' : 'var(--ia-text-dim)' }}">{{ $label }}</a>
  @endforeach
</div>
