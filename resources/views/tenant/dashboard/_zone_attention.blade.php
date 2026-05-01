@if($attention['total_items'] > 0)
<div class="ia-dash-attention-block">
  <div class="ia-dash-zone-head">
    <span class="ia-card-title">Needs your attention</span>
    <span class="ia-dash-zone-count">· {{ $attention['total_items'] }} {{ Str::plural('item', $attention['total_items']) }}</span>
  </div>

  @include('tenant.dashboard._attention_cards', ['cards' => $attention['cards']])
</div>
@endif
