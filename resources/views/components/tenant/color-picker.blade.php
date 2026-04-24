@props([
  'swatches' => [],
  'selected' => null,
  'reserved' => '#BEF264',
  'name'     => 'color_hex',
  'resourceId' => null,  // when set, change emits to onResourceColorChange(id, color)
  'compact'  => false,
])

@php
  $swatchSize = $compact ? 22 : 26;
  $gap        = $compact ? 4  : 5;
  $pickerId   = 'cp-' . uniqid();
@endphp

<div class="color-picker" data-picker-id="{{ $pickerId }}" data-resource-id="{{ $resourceId ?? '' }}">
  <input type="hidden" name="{{ $name }}" value="{{ $selected }}" class="cp-hidden">

  <div class="cp-swatches" style="display:flex;flex-wrap:wrap;gap:{{ $gap }}px">
    @foreach($swatches as $hex)
      <button type="button"
              class="cp-swatch {{ $selected === $hex ? 'is-selected' : '' }}"
              data-hex="{{ $hex }}"
              style="width:{{ $swatchSize }}px;height:{{ $swatchSize }}px;background:{{ $hex }}"
              title="{{ $hex }}"></button>
    @endforeach
    <button type="button"
            class="cp-swatch is-locked"
            disabled
            style="width:{{ $swatchSize }}px;height:{{ $swatchSize }}px;background:{{ $reserved }}"
            title="Reserved for system signals (now-line, walk-in holds, hot days)">
      <span class="cp-locked-label">!</span>
    </button>
  </div>
</div>

<script>
  (function () {
    var picker = document.querySelector('[data-picker-id="{{ $pickerId }}"]');
    if (!picker) return;
    var hidden = picker.querySelector('.cp-hidden');
    var swatches = picker.querySelectorAll('.cp-swatch:not(.is-locked)');
    var resourceId = picker.getAttribute('data-resource-id');

    swatches.forEach(function (sw) {
      sw.addEventListener('click', function () {
        var hex = sw.getAttribute('data-hex');
        hidden.value = hex;
        swatches.forEach(function (s) { s.classList.remove('is-selected'); });
        sw.classList.add('is-selected');
        if (resourceId && typeof window.onResourceColorChange === 'function') {
          window.onResourceColorChange(resourceId, hex);
        }
      });
    });
  })();
</script>
