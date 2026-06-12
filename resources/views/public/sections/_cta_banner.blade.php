{{-- MARKER-PATCH-158-G20 — cta_banner public renderer (v2) --}}
@php
  $c = $c ?? [];

  $hAlign = $c['text_align'] ?? 'center';
  $maxW = (int)($c['content_max_width'] ?? 640);
  if ($maxW < 320 || $maxW > 1200) $maxW = 640;

  $padTokens = ['compact'=>'40px','normal'=>'72px','spacious'=>'112px'];
  $padTop    = $padTokens[$c['padding_top']    ?? 'normal'] ?? '72px';
  $padBot    = $padTokens[$c['padding_bottom'] ?? 'normal'] ?? '72px';

  // Background
  $bgMode  = $c['bg_mode'] ?? 'color';
  $bgColor = ($c['bg_color'] ?? '') ?: '#0a0a0a';
  $gradF   = $c['bg_gradient_from'] ?? '#0a0a0a';
  $gradT   = $c['bg_gradient_to']   ?? '#1a1a1a';
  $imgUrl  = $c['bg_image_url'] ?? '';
  $overlayOpacity = max(0, min(100, (int)($c['bg_overlay_opacity'] ?? 50)));
  $overlayColor   = $c['bg_overlay_color'] ?? '#000000';

  // MARKER-PATCH-250 — parallax + blur (image mode only, ?? everywhere).
  $hasImage   = $bgMode === 'image' && $imgUrl;
  $parallaxOn = $hasImage && (($c['bg_parallax'] ?? '0') === '1');
  $pDepth     = max(0, min(70, (int)($c['bg_parallax_depth'] ?? 35))) / 100;
  $blurPx     = max(0, min(14, (int)($c['bg_blur'] ?? 0)));
  $useVeil    = $hasImage && ($parallaxOn || $blurPx > 0);

  // Colors
  $textColor     = ($c['text_color']      ?? '') ?: '#ffffff';
  $textColorBody = ($c['text_color_body'] ?? '') ?: 'rgba(255,255,255,0.7)';
  $accentColor   = ($c['accent_color']    ?? '') ?: null;

  // Buttons
  $buttons = $c['buttons'] ?? [];
  if (is_string($buttons)) { $d = json_decode($buttons, true); $buttons = is_array($d) ? $d : []; }
  if (!is_array($buttons)) $buttons = [];
  if (empty($buttons) && !empty($c['cta_label'] ?? '')) {
      $buttons[] = ['label' => $c['cta_label'], 'url' => $c['cta_url'] ?? '/book', 'style' => 'primary'];
  }

  // Headline with accent words
  $headlineHtml = e($c['headline'] ?? '');
  $accentWords  = trim($c['accent_words'] ?? '');
  if ($accentWords !== '' && stripos($headlineHtml, e($accentWords)) !== false) {
      $headlineHtml = str_ireplace(
          e($accentWords),
          '<span class="p-cta-accent">' . e($accentWords) . '</span>',
          $headlineHtml
      );
  }
  $headlineHtml = nl2br($headlineHtml);

  // Advanced
  $anchorId    = trim($c['anchor_id'] ?? '');
  $customClass = trim($c['custom_classes'] ?? '');
  $hideMobile  = !empty($c['hide_on_mobile']);
  $hideDesktop = !empty($c['hide_on_desktop']);

  $instId = 'p-cta-' . ($section->id ?? uniqid());
@endphp

<style>
.{{ $instId }} {
  position: relative;
  padding-top: {{ $padTop }};
  padding-bottom: {{ $padBot }};
  overflow: hidden;
  @if($bgMode === 'image' && $imgUrl && !$parallaxOn)
  background-color: {{ $bgColor }};
  background-image: url('{{ $imgUrl }}');
  background-size: cover;
  background-position: center;
  @elseif($parallaxOn)
  background-color: {{ $bgColor }}; {{-- MARKER-PATCH-250 — image moves to .p-cta-bg --}}
  @elseif($bgMode === 'gradient')
  background: linear-gradient(135deg, {{ $gradF }} 0%, {{ $gradT }} 100%);
  @else
  background: {{ $bgColor }};
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
.{{ $instId }} .p-cta-bg { {{-- MARKER-PATCH-250 --}}
  position: absolute;
  left: 0; right: 0; top: -18%; bottom: -18%;
  background-color: {{ $bgColor }};
  background-image: url('{{ $imgUrl }}');
  background-size: cover;
  background-position: center;
  will-change: transform;
  z-index: 0;
  pointer-events: none;
}
@endif
@if($useVeil)
.{{ $instId }} .p-cta-veil { {{-- MARKER-PATCH-250 --}}
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
.{{ $instId }} .p-cta-inner {
  position: relative;
  z-index: 1;
  max-width: {{ $maxW }}px;
  margin: 0 auto;
  padding: 0 clamp(20px, 5vw, 48px);
  text-align: {{ $hAlign }};
  color: {{ $textColor }};
}
.{{ $instId }} .p-cta-eyebrow {
  font-family: ui-monospace, monospace;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  color: {{ $accentColor ?? $textColor }};
  margin-bottom: 14px;
  opacity: .9;
}
.{{ $instId }} .p-cta-headline {
  font-size: clamp(26px, 4vw, 44px);
  font-weight: 500;
  line-height: 1.12;
  letter-spacing: -.02em;
  margin: 0 0 14px;
  color: {{ $textColor }};
}
.{{ $instId }} .p-cta-accent {
  color: {{ $accentColor ?? '#BEF264' }};
  font-style: italic;
  font-weight: 500;
}
.{{ $instId }} .p-cta-sub {
  font-size: 17px;
  line-height: 1.55;
  color: {{ $textColorBody }};
  margin: 0 0 26px;
  @if($hAlign === 'center')
  max-width: 520px;
  margin-left: auto; margin-right: auto;
  @endif
}
.{{ $instId }} .p-cta-actions {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  @if($hAlign === 'center') justify-content: center;
  @elseif($hAlign === 'right') justify-content: flex-end;
  @endif
}
.{{ $instId }} .p-cta-btn {
  display: inline-flex; align-items: center;
  padding: 13px 26px;
  border-radius: 6px;
  font-size: 15px;
  font-weight: 500;
  text-decoration: none;
  border: 1px solid transparent;
  transition: all 0.15s;
}
.{{ $instId }} .p-cta-btn--primary { background: {{ $accentColor ?? '#BEF264' }}; color: #0a1a00; }
.{{ $instId }} .p-cta-btn--primary:hover { filter: brightness(1.05); }
.{{ $instId }} .p-cta-btn--outline { background: transparent; color: {{ $textColor }}; border-color: {{ $textColor }}; opacity: .85; }
.{{ $instId }} .p-cta-btn--outline:hover { opacity: 1; }
.{{ $instId }} .p-cta-btn--ghost { background: rgba(255,255,255,0.1); color: {{ $textColor }}; }
.{{ $instId }} .p-cta-btn--ghost:hover { background: rgba(255,255,255,0.18); }
.{{ $instId }} .p-cta-btn--link { background: transparent; color: {{ $textColor }}; padding: 13px 0; text-decoration: underline; text-underline-offset: 4px; }
.{{ $instId }} .p-cta-footnote {
  font-family: ui-monospace, monospace;
  font-size: 12px;
  color: {{ $textColorBody }};
  margin-top: 20px;
  opacity: .8;
}

@if($hideMobile)
@media (max-width: 768px) { .{{ $instId }} { display: none; } }
@endif
@if($hideDesktop)
@media (min-width: 769px) { .{{ $instId }} { display: none; } }
@endif
</style>

<section class="{{ $instId }} p-cta-banner {{ $customClass }}" @if($anchorId) id="{{ $anchorId }}" @endif>
  {{-- MARKER-PATCH-250 — layered background: bg (moves) under veil under content. --}}
  @if($parallaxOn)<div class="p-cta-bg" data-ia-parallax="{{ $pDepth }}"></div>@endif
  @if($useVeil)<div class="p-cta-veil"></div>@endif
  <div class="p-cta-inner">
    @if(!empty($c['eyebrow']))
      <div class="p-cta-eyebrow">{{ $c['eyebrow'] }}</div>
    @endif
    @if(!empty($c['headline']))
      <h2 class="p-cta-headline">{!! $headlineHtml !!}</h2>
    @endif
    @if(!empty($c['subheading']))
      <p class="p-cta-sub">{{ $c['subheading'] }}</p>
    @endif
    @if(count($buttons) > 0)
      <div class="p-cta-actions">
        @foreach($buttons as $btn)
          @php $style = $btn['style'] ?? 'primary'; @endphp
          @if(!empty($btn['label']))
            <a href="{{ $btn['url'] ?? '#' }}" class="p-cta-btn p-cta-btn--{{ $style }}">{{ $btn['label'] }}</a>
          @endif
        @endforeach
      </div>
    @endif
    @if(!empty($c['note']))
      <div class="p-cta-footnote">{{ $c['note'] }}</div>
    @endif
  </div>
</section>

@if($parallaxOn)
{{-- MARKER-PATCH-250 — same shared driver as the hero; the window guard
     ensures exactly one scroll listener no matter which partial loads first. --}}
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
