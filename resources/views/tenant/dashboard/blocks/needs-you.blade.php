{{-- Block: needs you (Tiles). --}}
@php
  // same sort as _zone_triage_tiles: red first,
  // then amber, violet, blue; stable within a tone.
  $toneWeight = ['red' => 0, 'amber' => 1, 'violet' => 2, 'blue' => 3];
  $cards    = collect($attention['cards'] ?? [])
      ->filter(fn ($c) => ($c['count'] ?? 0) > 0)
      ->sortBy(fn ($c) => $toneWeight[$c['tone'] ?? 'blue'] ?? 99)
      ->values();
@endphp
{{-- Needs you today: kept deliberately. Simple shouldn't mean blind to a
     booking that's waiting. --}}
@if($cards->count())
  @php $worstTone = $cards->contains(fn ($c) => ($c['tone'] ?? '') === 'red') ? 'red' : 'amber'; @endphp
  <button type="button" id="ia-needsyou-bar" class="ia-needsyou-bar ia-needsyou-bar--{{ $worstTone }}" hidden>
    {{-- the same count as the greeting (things that
         need you), not every card's inner count added up. --}}
    <span class="ia-needsyou-count">{{ $attention['total_items'] ?? $cards->count() }}</span>
    <span class="ia-needsyou-label">need you</span>
    <span class="ia-needsyou-titles">{{ $cards->pluck('title')->take(3)->implode(' · ') }}@if($cards->count() > 3) · +{{ $cards->count() - 3 }} more @endif</span>
    <span class="ia-needsyou-chev">▾</span>
  </button>
  <div class="ia-tiles-zone ia-needsyou-zone">Needs you
    <button type="button" id="ia-needsyou-collapse" class="ia-needsyou-min" title="Collapse">— collapse</button>
  </div>
  <div class="ia-tiles-grid" id="ia-needsyou-grid">
    @foreach($cards as $card)
      @php $tileTone = match ($card['tone'] ?? null) { 'red' => 'red', 'amber' => 'rust', 'violet' => 'plum', 'blue' => 'blue', default => 'rust' }; @endphp
      <a class="ia-tile ia-tile--{{ $tileTone }} ia-tile--wide" href="{{ $card['link'] ?? '#' }}">
        <span class="badge alert">{{ $card['count'] }}</span>
        <div style="font-size:26px;font-weight:300;margin-top:auto;line-height:1">{{ $card['count'] }}</div>
        <div class="nm" style="margin-top:8px">{{ $card['title'] ?? '' }}</div>
        <div class="mt">{{ $card['desc'] ?? '' }}</div>
      </a>
    @endforeach
  </div>
@endif

@push('scripts')
<script>
/* own scope, deliberately separate from the
   edit-tiles script below (shared blocks die whole on one duplicate const). */
(function () {
  var KEY  = 'ia-needsyou-collapsed';
  var bar  = document.getElementById('ia-needsyou-bar');
  var zone = document.querySelector('.ia-needsyou-zone');
  var grid = document.getElementById('ia-needsyou-grid');
  var min  = document.getElementById('ia-needsyou-collapse');
  if (!bar || !zone || !grid) return;
  function apply(collapsed) {
    bar.hidden  = !collapsed;
    zone.hidden = collapsed;
    grid.hidden = collapsed;
    try { localStorage.setItem(KEY, collapsed ? '1' : '0'); } catch (e) {}
  }
  bar.addEventListener('click', function () { apply(false); });
  if (min) min.addEventListener('click', function () { apply(true); });
  var saved = null;
  try { saved = localStorage.getItem(KEY); } catch (e) {}
  if (saved === '1') apply(true);
})();
</script>
@endpush
