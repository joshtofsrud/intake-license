{{-- ================================================================
     Location-switch welcome overlay (patch 108).
     Renders only when session has 'location_switched' flash.
     Animates a checkmark draw-in, then fades out after 1.5s.
     ================================================================ --}}
@if($switched = session('location_switched'))
<div class="ia-loc-welcome" id="ia-loc-welcome" role="status" aria-live="polite">
  <div class="ia-loc-welcome-card">
    <div class="ia-loc-welcome-check" aria-hidden="true">
      <svg viewBox="0 0 56 56" width="56" height="56" fill="none">
        <circle cx="28" cy="28" r="26" stroke="currentColor" stroke-width="2"
                stroke-dasharray="164" stroke-dashoffset="164"
                class="ia-loc-welcome-circle"/>
        <polyline points="16,29 25,38 41,20"
                  stroke="currentColor" stroke-width="3" stroke-linecap="round"
                  stroke-linejoin="round" fill="none"
                  stroke-dasharray="44" stroke-dashoffset="44"
                  class="ia-loc-welcome-tick"/>
      </svg>
    </div>
    <div class="ia-loc-welcome-title">Welcome to</div>
    <div class="ia-loc-welcome-name">{{ $switched['name'] ?? 'this location' }}</div>
  </div>
</div>
<script>
(function(){
  var el = document.getElementById('ia-loc-welcome');
  if (!el) return;
  // Trigger the animation immediately on render.
  requestAnimationFrame(function(){ el.classList.add('is-active'); });
  // Auto-dismiss after 1500ms.
  setTimeout(function(){
    el.classList.remove('is-active');
    el.classList.add('is-leaving');
    setTimeout(function(){ el.parentNode && el.parentNode.removeChild(el); }, 280);
  }, 1500);
})();
</script>
@endif
