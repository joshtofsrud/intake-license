{{-- ================================================================
     Mobile FAB — floating action button shown bottom-right on certain
     pages. Page declares @section('mobile-fab') => 'walk-in' to enable.

     v1: only the 'walk-in' variant exists, stubbed to a v1.1 toast.
     Future: more FAB types as features ship.
     ================================================================ --}}
@hasSection('mobile-fab')
  @php $fabType = trim(View::yieldContent('mobile-fab')); @endphp
  @if($fabType === 'walk-in')
    <button type="button"
            class="ia-mobile-fab ia-mobile-fab--walkin"
            aria-label="Start walk-in"
            onclick="window.IntakeToast && window.IntakeToast.info('Walk-in flow ships in v1.1. For now, use New appointment.')">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
        <line x1="12" y1="5" x2="12" y2="19"/>
        <line x1="5" y1="12" x2="19" y2="12"/>
      </svg>
    </button>
  @endif
@endif
