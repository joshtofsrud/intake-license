{{-- MARKER-PATCH-158-G19 — Hero public renderer (Phase 2 v2 fields) --}}
@php
  // Height presets (Layout tab)
  $heights = ['small'=>'380px','medium'=>'520px','large'=>'680px','fullscreen'=>'100vh'];
  $height  = $heights[$c['height'] ?? 'large'] ?? '680px';

  // Padding presets (Layout tab)
  $padTokens = ['none'=>'0','compact'=>'40px','normal'=>'80px','spacious'=>'120px'];
  $padTop    = $padTokens[$c['padding_top']    ?? 'normal'] ?? '80px';
  $padBot    = $padTokens[$c['padding_bottom'] ?? 'normal'] ?? '80px';

  // Alignment
  $hAlign = $c['text_align']     ?? 'left';      // left | center | right
  $vAlign = $c['vertical_align'] ?? 'center';    // top | center | bottom
  $vAlignCss = ['top'=>'flex-start','center'=>'center','bottom'=>'flex-end'][$vAlign] ?? 'center';

  // Content sizing
  $maxW = (int)($c['content_max_width'] ?? 680);
  if ($maxW < 320 || $maxW > 1600) $maxW = 680;

  // Background
  $bgMode  = $c['bg_mode'] ?? 'color';
  $bgColor = $c['bg_color'] ?? '#1a1a1a';
  $gradFrom= $c['bg_gradient_from'] ?? '#1a1a1a';
  $gradTo  = $c['bg_gradient_to']   ?? '#0a0a0a';
  $gradDeg = (int)($c['bg_gradient_angle'] ?? 135);
  $imgUrl  = $c['bg_image_url'] ?? '';
  $imgPos  = $c['bg_image_position'] ?? 'center';
  $imgSize = $c['bg_image_size'] ?? 'cover';

  // Overlay
  $overlayOpacity = max(0, min(100, (int)($c['bg_overlay_opacity'] ?? 45)));
  $overlayColor   = $c['bg_overlay_color'] ?? '#000000';

  // MARKER-PATCH-249 — parallax + blur (image mode only; ?? everywhere,
  // pre-249 rows lack these keys).
  $hasImage   = $bgMode === 'image' && $imgUrl;
  $parallaxOn = $hasImage && (($c['bg_parallax'] ?? '0') === '1');
  $pDepth     = max(0, min(70, (int)($c['bg_parallax_depth'] ?? 35))) / 100;
  $blurPx     = max(0, min(14, (int)($c['bg_blur'] ?? 0)));
  // Veil replaces the ::before overlay whenever a separate layer is needed.
  $useVeil    = $hasImage && ($parallaxOn || $blurPx > 0);

  // Colors
  $textColor     = $c['text_color']      ?? '#ffffff';
  // MARKER-PATCH-158-G19B — Use ?? (null-coalesce) not ?: (truthy-or). Older
  // hero rows seeded before G19 don't have these keys, so ?: blows up on
  // "undefined array key". ?? checks for existence first.
  $textColorBody = ($c['text_color_body'] ?? '') ?: null;
  $accentColor   = ($c['accent_color']    ?? '') ?: null;

  // Buttons — prefer buttons[] array, fall back to legacy cta_primary/secondary
  $buttons = $c['buttons'] ?? [];
  if (is_string($buttons)) { $d = json_decode($buttons, true); $buttons = is_array($d) ? $d : []; }
  if (!is_array($buttons)) $buttons = [];
  if (empty($buttons) && !empty($c['cta_primary_label'] ?? '')) {
      $buttons[] = ['label' => $c['cta_primary_label'], 'url' => $c['cta_primary_url'] ?? '/', 'style' => 'primary'];
      if (!empty($c['cta_secondary_label'] ?? '')) {
          $buttons[] = ['label' => $c['cta_secondary_label'], 'url' => $c['cta_secondary_url'] ?? '#', 'style' => 'outline'];
      }
  }

  // Headline rendering — preserve \n, wrap accent_words in a span if present
  $headlineHtml = e($c['headline'] ?? '');
  $accentWords  = trim($c['accent_words'] ?? '');
  if ($accentWords !== '' && stripos($headlineHtml, e($accentWords)) !== false) {
      $headlineHtml = str_ireplace(
          e($accentWords),
          '<span class="p-hero-accent">' . e($accentWords) . '</span>',
          $headlineHtml
      );
  }
  $headlineHtml = nl2br($headlineHtml);

  // Advanced
  $anchorId    = trim($c['anchor_id'] ?? '');
  $customClass = trim($c['custom_classes'] ?? '');
  $hideMobile  = !empty($c['hide_on_mobile']);
  $hideDesktop = !empty($c['hide_on_desktop']);

  // MARKER-PATCH-158-G21 — typography size overrides. Auto preserves the
  // responsive clamp() default; named presets use fixed sizes.
  $headlineSizeMap = [
      'auto'   => 'clamp(32px, 6vw, 64px)',
      'small'  => '32px',
      'medium' => '44px',
      'large'  => '56px',
      'xl'     => '72px',
  ];
  $subheadingSizeMap = [
      'xs'     => '14px',
      'small'  => '16px',
      'medium' => 'clamp(16px, 2vw, 20px)',
      'large'  => '22px',
      'xl'     => '26px',
  ];
  $headlineSize   = $headlineSizeMap[$c['headline_size']   ?? 'auto']   ?? $headlineSizeMap['auto'];
  $subheadingSize = $subheadingSizeMap[$c['subheading_size'] ?? 'medium'] ?? $subheadingSizeMap['medium'];

  // Stable per-section instance class so styles scope cleanly
  $instId = 'p-hero-' . ($section->id ?? uniqid());
@endphp

<style>
.{{ $instId }} {
  min-height: {{ $height }};
  display: flex;
  align-items: {{ $vAlignCss }};
  position: relative;
  overflow: hidden;
  padding-top: {{ $padTop }};
  padding-bottom: {{ $padBot }};
  @if($bgMode === 'image' && $imgUrl && !$parallaxOn)
  background-color: {{ $bgColor }};
  background-image: url('{{ $imgUrl }}');
  background-size: {{ $imgSize }};
  background-position: {{ $imgPos }};
  @elseif($parallaxOn)
  background-color: {{ $bgColor }}; {{-- MARKER-PATCH-249 — image moves to .p-hero-bg --}}
  @elseif($bgMode === 'gradient')
  background: linear-gradient({{ $gradDeg }}deg, {{ $gradFrom }} 0%, {{ $gradTo }} 100%);
  @else
  background-color: {{ $bgColor }};
  @endif
}
@if($bgMode === 'image' && $imgUrl && $overlayOpacity > 0 && !$useVeil)
.{{ $instId }}::before {
  content: '';
  position: absolute; inset: 0;
  background: {{ $overlayColor }};
  opacity: {{ $overlayOpacity / 100 }};
  pointer-events: none;
}
@endif
@if($parallaxOn)
.{{ $instId }} .p-hero-bg { {{-- MARKER-PATCH-249 --}}
  position: absolute;
  left: 0; right: 0; top: -18%; bottom: -18%;
  background-color: {{ $bgColor }};
  background-image: url('{{ $imgUrl }}');
  background-size: {{ $imgSize }};
  background-position: {{ $imgPos }};
  will-change: transform;
  z-index: 0;
  pointer-events: none;
}
@endif
@if($useVeil)
.{{ $instId }} .p-hero-veil { {{-- MARKER-PATCH-249 --}}
  position: absolute; inset: 0;
  @if($overlayOpacity > 0)
  background: {{ $overlayColor }};
  opacity: {{ $overlayOpacity / 100 }};
  @endif
  @if($blurPx > 0)
  backdrop-filter: blur({{ $blurPx }}px);
  -webkit-backdrop-filter: blur({{ $blurPx }}px);
  @endif
  z-index: 0;
  pointer-events: none;
}
@endif
.{{ $instId }} .p-hero-content {
  position: relative;
  z-index: 1;
  color: {{ $textColor }};
  width: 100%;
  max-width: {{ $maxW }}px;
  padding: 0 clamp(20px, 5vw, 48px);
  text-align: {{ $hAlign }};
  @if($hAlign === 'center')
  margin-left: auto; margin-right: auto;
  @elseif($hAlign === 'right')
  margin-left: auto;
  @endif
}
.{{ $instId }} .p-hero-eyebrow {
  display: inline-block;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  color: {{ $accentColor ?? $textColor }};
  margin-bottom: 18px;
  opacity: .9;
}
.{{ $instId }} .p-hero-headline {
  font-size: {{ $headlineSize }};
  font-weight: 600;
  line-height: 1.08;
  letter-spacing: -.025em;
  margin: 0 0 20px;
  color: {{ $textColor }};
}
.{{ $instId }} .p-hero-accent {
  /* MARKER-PATCH-158-G21A — color only; weight inherits from headline */
  color: {{ $accentColor ?? '#BEF264' }};
}
.{{ $instId }} .p-hero-sub {
  font-size: {{ $subheadingSize }};
  line-height: 1.55;
  color: {{ $textColorBody ?? 'rgba(255,255,255,0.7)' }};
  margin: 0 0 28px;
  max-width: 540px;
  @if($hAlign === 'center')
  margin-left: auto; margin-right: auto;
  @elseif($hAlign === 'right')
  margin-left: auto;
  @endif
}
.{{ $instId }} .p-hero-actions {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  @if($hAlign === 'center')
  justify-content: center;
  @elseif($hAlign === 'right')
  justify-content: flex-end;
  @endif
}
.{{ $instId }} .p-hero-btn {
  display: inline-flex;
  align-items: center;
  padding: 12px 22px;
  border-radius: 6px;
  font-size: 14px;
  font-weight: 500;
  text-decoration: none;
  transition: all 0.15s;
  border: 1px solid transparent;
}
.{{ $instId }} .p-hero-btn--primary {
  background: {{ $accentColor ?? '#BEF264' }};
  color: #0a1a00;
}
.{{ $instId }} .p-hero-btn--primary:hover { filter: brightness(1.05); }
.{{ $instId }} .p-hero-btn--outline {
  background: transparent;
  color: {{ $textColor }};
  border-color: {{ $textColor }};
  opacity: .85;
}
.{{ $instId }} .p-hero-btn--outline:hover { opacity: 1; }
.{{ $instId }} .p-hero-btn--ghost {
  background: rgba(255,255,255,0.06);
  color: {{ $textColor }};
}
.{{ $instId }} .p-hero-btn--ghost:hover { background: rgba(255,255,255,0.12); }
.{{ $instId }} .p-hero-btn--link {
  background: transparent;
  color: {{ $textColor }};
  padding: 12px 0;
  text-decoration: underline;
  text-underline-offset: 4px;
}
.{{ $instId }} .p-hero-footnote {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 12px;
  color: {{ $textColorBody ?? 'rgba(255,255,255,0.5)' }};
  margin-top: 24px;
}
@if($hideMobile)
@media (max-width: 768px) { .{{ $instId }} { display: none; } }
@endif
@if($hideDesktop)
@media (min-width: 769px) { .{{ $instId }} { display: none; } }
@endif
</style>

<section class="{{ $instId }} p-hero {{ $customClass }}" @if($anchorId) id="{{ $anchorId }}" @endif>
  {{-- MARKER-PATCH-249 — layered background: bg (moves) under veil (overlay+blur) under content. --}}
  @if($parallaxOn)<div class="p-hero-bg" data-ia-parallax="{{ $pDepth }}"></div>@endif
  @if($useVeil)<div class="p-hero-veil"></div>@endif
  <div class="p-hero-content">
    @if(!empty($c['eyebrow']))
      <div class="p-hero-eyebrow">{{ $c['eyebrow'] }}</div>
    @endif

    @if(!empty($c['headline']))
      <h1 class="p-hero-headline">{!! $headlineHtml !!}</h1>
    @endif

    @if(!empty($c['subheading']))
      <p class="p-hero-sub">{{ $c['subheading'] }}</p>
    @endif

    @if(count($buttons) > 0)
      <div class="p-hero-actions">
        @foreach($buttons as $btn)
          @php $style = $btn['style'] ?? 'primary'; @endphp
          @if(!empty($btn['label']))
            <a href="{{ $btn['url'] ?? '#' }}" class="p-hero-btn p-hero-btn--{{ $style }}">
              {{ $btn['label'] }}
            </a>
          @endif
        @endforeach
      </div>
    @endif

    @if(!empty($c['note']))
      <div class="p-hero-footnote">{{ $c['note'] }}</div>
    @endif
  </div>
</section>

@if($parallaxOn)
{{-- MARKER-PATCH-249 — one shared rAF-throttled driver per page, bound once
     no matter how many parallax heroes render. transform-based (never
     background-attachment:fixed). prefers-reduced-motion disables entirely. --}}
<script>
(function () {
  if (window.__iaParallaxBound) return;
  window.__iaParallaxBound = true;
  if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  var ticking = false;
  function frame() {
    var els = document.querySelectorAll('[data-ia-parallax]');
    for (var i = 0; i < els.length; i++) {
      var el = els[i];
      var host = el.parentElement;
      if (!host) continue;
      var r = host.getBoundingClientRect();
      if (r.bottom < -200 || r.top > window.innerHeight + 200) continue;
      var d = parseFloat(el.getAttribute('data-ia-parallax')) || 0.35;
      el.style.transform = 'translate3d(0,' + (-r.top * d).toFixed(1) + 'px,0)';
    }
    ticking = false;
  }
  function onScroll() { if (!ticking) { ticking = true; requestAnimationFrame(frame); } }
  window.addEventListener('scroll', onScroll, { passive: true });
  window.addEventListener('resize', onScroll, { passive: true });
  frame();
})();
</script>
@endif
