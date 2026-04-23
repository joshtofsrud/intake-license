@if($attention['total_items'] > 0)
<div class="ia-dash-attention-block">
  <div class="ia-dash-zone-head">
    <span class="ia-card-title">Needs your attention</span>
    <span class="ia-dash-zone-count">· {{ $attention['total_items'] }} {{ Str::plural('item', $attention['total_items']) }}</span>
  </div>

  <div class="ia-dash-attention-grid">
    @foreach($attention['cards'] as $card)
      <a href="{{ $card['link'] }}" class="ia-dash-attention-card ia-dash-attention-card--{{ $card['tone'] }}">
        <div class="ia-dash-attention-count">{{ $card['count'] }}</div>
        <div class="ia-dash-attention-title">{{ $card['title'] }}</div>
        <div class="ia-dash-attention-desc">{{ $card['desc'] }}</div>
      </a>
    @endforeach
  </div>
</div>
@endif
