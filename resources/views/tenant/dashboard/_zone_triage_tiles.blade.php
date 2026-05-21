{{-- MARKER-PATCH-110-STEP-5 - Dashboard triage tiles.
     Renders cards from zoneAttention as tile grid. Shows top N tiles by
     priority (tone weight: red > amber > violet > blue), with a "show all"
     disclosure for the rest. Clear-state tile renders when zero cards. --}}

@php
  $allCards = $attention['cards'] ?? [];
  $totalCards = count($allCards);

  // Sort by tone priority — red first, then amber, violet, blue. Stable
  // within a tone (preserves service ordering for cards with the same tone).
  $toneWeight = ['red' => 0, 'amber' => 1, 'violet' => 2, 'blue' => 3];
  $sortedCards = collect($allCards)
      ->sortBy(fn($c) => $toneWeight[$c['tone'] ?? 'blue'] ?? 99)
      ->values()
      ->all();

  $visibleLimit = 6;
  $visibleCards = array_slice($sortedCards, 0, $visibleLimit);
  $hiddenCards  = array_slice($sortedCards, $visibleLimit);
  $hiddenCount  = count($hiddenCards);
@endphp

<div class="ia-dash-tiles-block">
  <div class="ia-dash-tiles-zone-label">
    <span>Needs you today</span>
    <span class="hint">
      @if($totalCards === 0)
        0 open · enjoy the calm
      @else
        {{ $totalCards }} open · tap a tile to act
      @endif
    </span>
  </div>

  @if($totalCards === 0)
    <div class="ia-dash-tiles-grid">
      <div class="ia-dash-tile-clear">
        <div class="clear-icon">✨</div>
        <div class="clear-msg">You're caught up.</div>
        <div class="clear-sub">No pending bookings · no overdue jobs · no parts on the bench. Enjoy the calm.</div>
      </div>
    </div>
  @else
    <div class="ia-dash-tiles-grid">
      @foreach($visibleCards as $card)
        <a href="{{ $card['link'] }}"
           class="ia-dash-tile ia-dash-tile--tone-{{ $card['tone'] }}">
          <div class="ia-dash-tile-head">
            <div class="ia-dash-tile-label">{{ $card['title'] }}</div>
          </div>
          <div class="ia-dash-tile-count ia-dash-tile-count--{{ $card['tone'] }}">{{ $card['count'] }}</div>
          <div class="ia-dash-tile-desc">{{ $card['desc'] }}</div>
          <div class="ia-dash-tile-cta">
            <span>Open</span>
            <span class="arrow">→</span>
          </div>
        </a>
      @endforeach
    </div>

    @if($hiddenCount > 0)
      <details class="ia-dash-tiles-disclosure">
        <summary>Show {{ $hiddenCount }} more
          @if($hiddenCount > 0)
            ({{ collect($hiddenCards)->pluck('title')->take(3)->implode(' · ') }}@if($hiddenCount > 3) · and {{ $hiddenCount - 3 }} more @endif)
          @endif
        </summary>
        <div class="ia-dash-tiles-grid ia-dash-tiles-grid--secondary">
          @foreach($hiddenCards as $card)
            <a href="{{ $card['link'] }}"
               class="ia-dash-tile ia-dash-tile--tone-{{ $card['tone'] }}">
              <div class="ia-dash-tile-head">
                <div class="ia-dash-tile-label">{{ $card['title'] }}</div>
              </div>
              <div class="ia-dash-tile-count ia-dash-tile-count--{{ $card['tone'] }}">{{ $card['count'] }}</div>
              <div class="ia-dash-tile-desc">{{ $card['desc'] }}</div>
              <div class="ia-dash-tile-cta">
                <span>Open</span>
                <span class="arrow">→</span>
              </div>
            </a>
          @endforeach
        </div>
      </details>
    @endif
  @endif
</div>
