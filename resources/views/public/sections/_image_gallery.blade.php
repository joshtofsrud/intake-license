@php
  $cols   = (int)($c['columns'] ?? 3);
  $images = $c['images'] ?? [];
@endphp

<style>
.p-gallery-grid {
  display: grid;
  grid-template-columns: repeat({{ $cols }}, 1fr);
  gap: 12px;
}
.p-gallery-item {
  aspect-ratio: 1;
  overflow: hidden;
  border-radius: var(--p-r);
  background: rgba(0,0,0,.06);
}
.p-gallery-item img {
  width: 100%; height: 100%;
  object-fit: cover;
  transition: transform .3s;
}
.p-gallery-item:hover img { transform: scale(1.04); }
@media (max-width: 768px) { .p-gallery-grid { grid-template-columns: 1fr 1fr; } }
@media (max-width: 480px) { .p-gallery-grid { grid-template-columns: 1fr; } }
</style>

<section class="p-section--tight">
  <div class="p-container">
    @if(!empty($images))
      <div class="p-gallery-grid">
        @foreach($images as $img)
          <div class="p-gallery-item">
            <img src="{{ $img['url'] ?? $img }}" alt="{{ $img['alt'] ?? '' }}" loading="lazy">
          </div>
        @endforeach
      </div>
    @else
      <div class="p-gallery-grid">
        @for($i = 0; $i < $cols; $i++)
          <div class="p-gallery-item" style="display:flex;align-items:center;justify-content:center;font-size:13px;opacity:.25">
            Image
          </div>
        @endfor
      </div>
    @endif
  </div>
</section>
