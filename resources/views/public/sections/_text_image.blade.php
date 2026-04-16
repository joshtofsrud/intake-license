@php
  $imgRight = ($c['image_position'] ?? 'right') === 'right';
@endphp

@push('styles')
<style>
.p-text-image-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: clamp(32px, 6vw, 80px);
  align-items: center;
}
.p-text-image-grid.img-left { direction: rtl; }
.p-text-image-grid.img-left > * { direction: ltr; }
.p-text-image-img {
  border-radius: var(--p-r-lg);
  overflow: hidden;
  aspect-ratio: 4/3;
  background: rgba(0,0,0,.06);
}
.p-text-image-img img { width: 100%; height: 100%; object-fit: cover; }
.p-text-image-heading {
  font-size: clamp(22px, 3vw, 36px);
  font-weight: 700;
  margin-bottom: 16px;
  line-height: 1.2;
}
.p-text-image-body {
  font-size: 16px;
  line-height: 1.7;
  opacity: .7;
  margin-bottom: 24px;
  white-space: pre-line;
}
@media (max-width: 768px) {
  .p-text-image-grid { grid-template-columns: 1fr; direction: ltr !important; }
  .p-text-image-grid.img-left .p-text-image-img { order: -1; }
}
</style>
@endpush

<section class="p-section">
  <div class="p-container">
    <div class="p-text-image-grid {{ $imgRight ? '' : 'img-left' }}">
      <div class="p-text-image-text">
        @if(!empty($c['heading']))
          <h2 class="p-text-image-heading">{{ $c['heading'] }}</h2>
        @endif
        @if(!empty($c['body']))
          <p class="p-text-image-body">{{ $c['body'] }}</p>
        @endif
        @if(!empty($c['cta_label']))
          <a href="{{ $c['cta_url'] ?? '#' }}" class="p-btn p-btn--primary">
            {{ $c['cta_label'] }}
          </a>
        @endif
      </div>
      <div class="p-text-image-img">
        @if(!empty($c['image_url']))
          <img src="{{ $c['image_url'] }}" alt="{{ $c['heading'] ?? '' }}" loading="lazy">
        @else
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:13px;opacity:.3">
            No image set
          </div>
        @endif
      </div>
    </div>
  </div>
</section>
