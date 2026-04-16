@php
  $heights = ['small'=>'380px','medium'=>'520px','large'=>'680px','fullscreen'=>'100vh'];
  $height  = $heights[$c['height'] ?? 'large'] ?? '680px';
  $hasBgImage = !empty($c['bg_image_url']);
  $bgColor    = $c['bg_color'] ?? '#1a1a1a';
  $textColor  = $c['text_color'] ?? '#ffffff';
@endphp

@push('styles')
<style>
.p-hero {
  min-height: {{ $height }};
  display: flex;
  align-items: center;
  position: relative;
  overflow: hidden;
  background-color: {{ $bgColor }};
  @if($hasBgImage)
  background-image: url('{{ $c['bg_image_url'] }}');
  background-size: cover;
  background-position: center;
  @endif
}
.p-hero::after {
  @if($hasBgImage)
  content: '';
  position: absolute;
  inset: 0;
  background: rgba(0,0,0,.45);
  @endif
}
.p-hero-content {
  position: relative;
  z-index: 1;
  color: {{ $textColor }};
  padding: clamp(40px, 8vw, 96px) 0;
  max-width: 680px;
}
.p-hero-headline {
  font-size: clamp(32px, 6vw, 72px);
  font-weight: 800;
  line-height: 1.08;
  letter-spacing: -.02em;
  margin-bottom: 20px;
}
.p-hero-sub {
  font-size: clamp(16px, 2.2vw, 22px);
  line-height: 1.55;
  opacity: .8;
  margin-bottom: 32px;
  max-width: 520px;
}
.p-hero-actions { display: flex; gap: 12px; flex-wrap: wrap; }
</style>
@endpush

<section class="p-hero">
  <div class="p-container">
    <div class="p-hero-content">
      @if(!empty($c['headline']))
        <h1 class="p-hero-headline">{{ $c['headline'] }}</h1>
      @endif
      @if(!empty($c['subheading']))
        <p class="p-hero-sub">{{ $c['subheading'] }}</p>
      @endif
      <div class="p-hero-actions">
        @if(!empty($c['cta_primary_label']))
          <a href="{{ $c['cta_primary_url'] ?? '/book' }}" class="p-btn p-btn--primary">
            {{ $c['cta_primary_label'] }}
          </a>
        @endif
        @if(!empty($c['cta_secondary_label']))
          <a href="{{ $c['cta_secondary_url'] ?? '#' }}" class="p-btn p-btn--outline"
             style="color:{{ $textColor }};border-color:{{ $textColor }}">
            {{ $c['cta_secondary_label'] }}
          </a>
        @endif
      </div>
    </div>
  </div>
</section>
