{{-- NAV-ITEMS-EXTRACTED v1 — array data now lives in _nav-items-data.blade.php --}}
@include('layouts.tenant._nav-items-data')


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
