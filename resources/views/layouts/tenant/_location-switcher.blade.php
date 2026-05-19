{{-- ================================================================
     Location switcher (desktop + mobile).
     Renders only when the signed-in user has 2+ active locations.
     Posts to tenant.select-location with chosen location_id and a
     return_url so the page reloads at the same URL with new context.
     ================================================================ --}}
@if(isset($userLocations) && $userLocations->count() >= 2 && isset($currentLocation) && $currentLocation)
<div class="ia-loc-switcher" data-loc-switcher="root">
  <details class="ia-loc-switcher-details">
    <summary class="ia-loc-switcher-trigger" aria-haspopup="menu" aria-label="Switch location">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
        <circle cx="12" cy="10" r="3"/>
      </svg>
      <span class="ia-loc-switcher-label">{{ $currentLocation->name }}</span>
      <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <polyline points="6 9 12 15 18 9"/>
      </svg>
    </summary>

    <div class="ia-loc-switcher-menu" role="menu">
      <div class="ia-loc-switcher-menu-label">Switch location</div>
      <form method="POST" action="{{ route('tenant.select-location.store') }}"
            data-loc-switcher="form">
        @csrf
        <input type="hidden" name="return_url" value="{{ request()->fullUrl() }}">
        @foreach($userLocations as $loc)
          <button type="submit" name="location_id" value="{{ $loc->id }}"
                  class="ia-loc-switcher-item @if($loc->id === $currentLocation->id) is-current @endif"
                  role="menuitem"
                  @if($loc->id === $currentLocation->id) aria-current="true" @endif>
            <span class="ia-loc-switcher-item-name">{{ $loc->name }}</span>
            @if($loc->id === $currentLocation->id)
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                   stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <polyline points="20 6 9 17 4 12"/>
              </svg>
            @endif
          </button>
        @endforeach
      </form>
    </div>
  </details>
</div>
@endif
