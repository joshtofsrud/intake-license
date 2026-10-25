{{-- MARKER-DASH-AUDIENCE: opens the Customize panel. Only drawn when this
     person can change something on this template. --}}
@if (! empty($customize))
  <button type="button" class="ia-btn ia-btn--sm ia-dash-cust" data-dash-customize title="Choose which blocks you see{{ $customize['mode'] === 'both' ? ' and their order' : '' }}" aria-label="Customize this dashboard">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h9M17 6h3M4 12h3M11 12h9M4 18h11M19 18h1"/><circle cx="15" cy="6" r="2"/><circle cx="9" cy="12" r="2"/><circle cx="17" cy="18" r="2"/></svg>
    <span class="ia-dash-cust-lb">Customize{{ $customize['hiddenCount'] ? ' · ' . $customize['hiddenCount'] . ' hidden' : '' }}</span>
  </button>
@endif
