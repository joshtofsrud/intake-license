{{-- text_image public renderer (v2) --}}
@php
  $c = $c ?? [];

  // Layout
  $imgPos     = $c['image_position'] ?? 'right'; // left | right
  $ratio      = $c['image_ratio']    ?? 'equal'; // equal | wide_text | wide_image
  $imgAspect  = $c['image_aspect']   ?? '4/3';
  $imgRadius  = $c['image_radius']   ?? 'medium';
  $hAlign     = $c['text_align']     ?? 'left';

  $ratioCols = [
      'equal'      => '1fr 1fr',
      'wide_text'  => '6fr 4fr',
      'wide_image' => '4fr 6fr',
  ][$ratio] ?? '1fr 1fr';

  $radiusPx = [
      'none' => '0', 'small' => '4px', 'medium' => '8px',
      'large' => '16px', 'full' => '24px',
  ][$imgRadius] ?? '8px';

  // Padding
  $padTokens = ['none'=>'0','compact'=>'40px','normal'=>'80px','spacious'=>'120px'];
  $padTop    = $padTokens[$c['padding_top']    ?? 'normal'] ?? '80px';
  $padBot    = $padTokens[$c['padding_bottom'] ?? 'normal'] ?? '80px';

  // Background
  $bgMode  = $c['bg_mode'] ?? 'none';
  $bgColor = $c['bg_color'] ?? '#ffffff';
  $gradF   = $c['bg_gradient_from'] ?? '#ffffff';
  $gradT   = $c['bg_gradient_to']   ?? '#fafafa';

  // Colors
  $textColor     = ($c['text_color']      ?? '') ?: '#0a0a0a';
  $textColorBody = ($c['text_color_body'] ?? '') ?: 'rgba(0,0,0,0.65)';
  $accentColor   = ($c['accent_color']    ?? '') ?: null;

  // Buttons
  $buttons = $c['buttons'] ?? [];
  if (is_string($buttons)) { $d = json_decode($buttons, true); $buttons = is_array($d) ? $d : []; }
  if (!is_array($buttons)) $buttons = [];
  if (empty($buttons) && !empty($c['cta_label'] ?? '')) {
      $buttons[] = ['label' => $c['cta_label'], 'url' => $c['cta_url'] ?? '#', 'style' => 'primary'];
  }

  // Heading with accent phrase
  $headingHtml = e($c['heading'] ?? '');
  $accentWords = trim($c['accent_words'] ?? '');
  if ($accentWords !== '' && stripos($headingHtml, e($accentWords)) !== false) {
      $headingHtml = str_ireplace(
          e($accentWords),
          '<span class="p-ti-accent">' . e($accentWords) . '</span>',
          $headingHtml
      );
  }

  // Body — preserve line breaks
  $bodyHtml = nl2br(e($c['body'] ?? ''));

  // Advanced
  $anchorId    = trim($c['anchor_id'] ?? '');
  $customClass = trim($c['custom_classes'] ?? '');
  $hideMobile  = !empty($c['hide_on_mobile']);
  $hideDesktop = !empty($c['hide_on_desktop']);

  $instId = 'p-ti-' . ($section->id ?? uniqid());
  $imgRight = $imgPos === 'right';

  // MARKER-SHOP-TI-ACCORDION: style, image frame and lightbox, as on intake.works
  $tiStyle    = ($c['ti_style'] ?? 'classic') === 'accordion' ? 'accordion' : 'classic';
  $tiLightbox = ! empty($c['img_lightbox']) && ! in_array((string) $c['img_lightbox'], ['0', 'false'], true);
  $tiFrame    = in_array($c['img_frame'] ?? 'none', ['panel', 'browser'], true) ? $c['img_frame'] : 'none';
  $tiFrameOpen = match ($tiFrame) {
      'panel'   => '<div class="ti-frame ti-frame-panel">',
      'browser' => '<div class="ti-frame ti-frame-browser"><div class="ti-bar" aria-hidden="true"><i></i><i></i><i></i><span>' . e(trim((string) ($c['img_frame_url'] ?? '')) ?: request()->getHost()) . '</span></div>',
      default   => '',
  };
  $tiFrameClose = $tiFrame === 'none' ? '' : '</div>';
@endphp

<style>
.{{ $instId }} {
  padding-top: {{ $padTop }};
  padding-bottom: {{ $padBot }};
  @if($bgMode === 'color') background: {{ $bgColor }};
  @elseif($bgMode === 'gradient') background: linear-gradient({{ (int)($c['bg_gradient_angle'] ?? 135) }}deg, {{ $gradF }} 0%, {{ $gradT }} 100%);
  @endif
}
.{{ $instId }} .p-ti-wrap {
  max-width: min({{ (int)($c['content_max_width'] ?? 1200) }}px, var(--p-page-max, {{ (int)($c['content_max_width'] ?? 1200) }}px));
  margin: 0 auto;
  padding: 0 clamp(20px, 5vw, 48px);
}
.{{ $instId }} .p-ti-grid {
  display: grid;
  grid-template-columns: {{ $ratioCols }};
  gap: clamp(32px, 6vw, 80px);
  align-items: center;
}
@if(!$imgRight)
.{{ $instId }} .p-ti-grid { direction: rtl; }
.{{ $instId }} .p-ti-grid > * { direction: ltr; }
@endif
.{{ $instId }} .p-ti-text {
  color: {{ $textColor }};
  text-align: {{ $hAlign }};
}
.{{ $instId }} .p-ti-eyebrow {
  font-family: ui-monospace, monospace;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  color: {{ $accentColor ?? $textColor }};
  margin-bottom: 14px;
  opacity: .9;
}
.{{ $instId }} .p-ti-heading {
  font-size: clamp(24px, 3.5vw, 40px);
  font-weight: 500;
  line-height: 1.15;
  letter-spacing: -.02em;
  margin: 0 0 18px;
  color: {{ $textColor }};
}
.{{ $instId }} .p-ti-accent {
  color: {{ $accentColor ?? '#BEF264' }};
  font-style: {{ ($c['accent_italic'] ?? true) ? 'italic' : 'normal' }};
  font-weight: 500;
}
.{{ $instId }} .p-ti-body {
  font-size: 16px;
  line-height: 1.65;
  color: {{ $textColorBody }};
  margin: 0 0 24px;
}
.{{ $instId }} .p-ti-actions {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  @if($hAlign === 'center') justify-content: center;
  @elseif($hAlign === 'right') justify-content: flex-end;
  @endif
}
.{{ $instId }} .p-ti-btn {
  display: inline-flex; align-items: center;
  padding: 11px 20px;
  border-radius: 6px;
  font-size: 14px;
  font-weight: 500;
  text-decoration: none;
  border: 1px solid transparent;
  transition: all 0.15s;
}
.{{ $instId }} .p-ti-btn--primary { background: {{ $accentColor ?? '#0a0a0a' }}; color: {{ $accentColor ? '#0a1a00' : '#ffffff' }}; }
.{{ $instId }} .p-ti-btn--primary:hover { filter: brightness(1.05); }
.{{ $instId }} .p-ti-btn--outline { background: transparent; color: {{ $textColor }}; border-color: {{ $textColor }}; opacity: .8; }
.{{ $instId }} .p-ti-btn--outline:hover { opacity: 1; }
.{{ $instId }} .p-ti-btn--ghost { background: rgba(0,0,0,0.05); color: {{ $textColor }}; }
.{{ $instId }} .p-ti-btn--ghost:hover { background: rgba(0,0,0,0.1); }
.{{ $instId }} .p-ti-btn--link { background: transparent; color: {{ $accentColor ?? $textColor }}; padding: 11px 0; text-decoration: underline; text-underline-offset: 4px; }

.{{ $instId }} .p-ti-image {
  overflow: hidden;
  border-radius: {{ $radiusPx }};
  @if($imgAspect !== 'auto')
  aspect-ratio: {{ $imgAspect }};
  @endif
  background: rgba(0,0,0,0.06);
}
.{{ $instId }} .p-ti-image img {
  width: 100%; height: 100%;
  object-fit: cover;
  display: block;
}
.{{ $instId }} .p-ti-image-empty {
  width: 100%; height: 100%;
  display: flex; align-items: center; justify-content: center;
  font-size: 12px;
  font-family: ui-monospace, monospace;
  color: rgba(0,0,0,0.3);
}

@media (max-width: 768px) {
  .{{ $instId }} .p-ti-grid {
    grid-template-columns: 1fr;
    direction: ltr;
  }
  @if(!$imgRight)
  .{{ $instId }} .p-ti-image { order: -1; }
  @endif
}

@if($hideMobile)
@media (max-width: 768px) { .{{ $instId }} { display: none; } }
@endif
@if($hideDesktop)
@media (min-width: 769px) { .{{ $instId }} { display: none; } }
@endif
@if($tiFrame !== 'none')
/* MARKER-SHOP-TI-ACCORDION: image frame */
.{{ $instId }} .ti-frame { padding: 12px; border-radius: calc({{ $radiusPx }} + 8px); background: color-mix(in srgb, {{ $textColor }} 5%, transparent); border: 1px solid color-mix(in srgb, {{ $textColor }} 12%, transparent); box-shadow: 0 30px 60px -28px rgba(0,0,0,.35), 0 0 90px -30px color-mix(in srgb, {{ $accentColor ?: 'var(--p-accent, #0a0a0a)' }} 35%, transparent); }
.{{ $instId }} .ti-frame-browser { padding: 0 10px 10px; }
.{{ $instId }} .ti-bar { display: flex; align-items: center; gap: 6px; height: 34px; padding: 0 4px; }
.{{ $instId }} .ti-bar i { width: 9px; height: 9px; border-radius: 50%; background: color-mix(in srgb, {{ $textColor }} 18%, transparent); flex: none; }
.{{ $instId }} .ti-bar span { margin: 0 auto; transform: translateX(-16px); font-size: 11px; color: {{ $textColorBody }}; background: color-mix(in srgb, {{ $textColor }} 6%, transparent); border-radius: 6px; padding: 3px 14px; max-width: 60%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.{{ $instId }} .ti-frame-browser .p-ti-image { border-radius: 0 0 {{ $radiusPx }} {{ $radiusPx }}; }
@media (max-width: 768px) {
  .{{ $instId }} .ti-frame { padding: 8px; }
  .{{ $instId }} .ti-frame-browser { padding: 0 6px 6px; }
  @if(!$imgRight)
  .{{ $instId }} .p-ti-grid > .ti-frame { order: -1; }
  @endif
}
@endif
@if($tiLightbox)
.{{ $instId }} .p-ti-image img { cursor: zoom-in; }
@endif
</style>

<section class="{{ $instId }} p-text-image {{ $customClass }}" @if($anchorId) id="{{ $anchorId }}" @endif @if($tiLightbox) data-ti-lb @endif>
  <div class="p-ti-wrap">
    @if($tiStyle === 'accordion')
      @include('public._ti-acc')
    @else
    <div class="p-ti-grid">
      <div class="p-ti-text">
        @if(!empty($c['eyebrow']))
          <div class="p-ti-eyebrow">{{ $c['eyebrow'] }}</div>
        @endif
        @if(!empty($c['heading']))
          <h2 class="p-ti-heading">{!! $headingHtml !!}</h2>
        @endif
        @if(!empty($c['body']))
          <div class="p-ti-body">{!! $bodyHtml !!}</div>
        @endif
        @if(count($buttons) > 0)
          <div class="p-ti-actions">
            @foreach($buttons as $btn)
              @php $style = $btn['style'] ?? 'primary'; @endphp
              @if(!empty($btn['label']))
                <a href="{{ $btn['url'] ?? '#' }}" class="p-ti-btn p-ti-btn--{{ $style }}">{{ $btn['label'] }}</a>
              @endif
            @endforeach
          </div>
        @endif
      </div>
      {!! $tiFrameOpen !!}<div class="p-ti-image">
        @if(!empty($c['image_url']))
          <img src="{{ $c['image_url'] }}" alt="{{ $c['image_alt'] ?? $c['heading'] ?? '' }}" loading="lazy">
        @else
          <div class="p-ti-image-empty">No image</div>
        @endif
      </div>{!! $tiFrameClose !!}
    </div>
    @endif
  </div>
</section>
@if($tiStyle === 'accordion' || $tiLightbox)
  @include('public._ti-js', ['tiJsAcc' => $tiStyle === 'accordion', 'tiJsLb' => $tiLightbox])
@endif
