{{--
  Attention cards row. Used in two places:
    1. Dashboard "Needs your attention" zone
    2. Appointments page header (when accessed via dashboard card link)
  
  Inputs:
    $cards         - the cards array from DashboardDataService::zoneAttention()
    $activeFilter  - optional, the currently-active filter slug (highlights the matching card)
--}}
@if(!empty($cards))
<div class="ia-dash-attention-grid">
  @foreach($cards as $card)
    @php
      $isActive = isset($activeFilter)
        && !empty($card['link'])
        && str_contains($card['link'], 'filter=' . $activeFilter);
    @endphp
    <a href="{{ $card['link'] }}"
       class="ia-dash-attention-card ia-dash-attention-card--{{ $card['tone'] }} {{ $isActive ? 'ia-dash-attention-card--active' : '' }}">
      <div class="ia-dash-attention-count">{{ $card['count'] }}</div>
      <div class="ia-dash-attention-title">{{ $card['title'] }}</div>
      <div class="ia-dash-attention-desc">{{ $card['desc'] }}</div>
    </a>
  @endforeach
</div>
@endif
