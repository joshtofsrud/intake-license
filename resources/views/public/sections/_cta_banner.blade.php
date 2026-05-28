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
  @if($bgMode === 'image' && $imgUrl)
  background-color: {{ $bgColor }};
  background-image: url('{{ $imgUrl }}');
  background-size: cover;
  background-position: center;
  @elseif($bgMode === 'gradient')
  background: linear-gradient(135deg, {{ $gradF }} 0%, {{ $gradT }} 100%);
  @else
  background: {{ $bgColor }};
  @endif
}
@if($bgMode === 'image' && $imgUrl && $overlayOpacity > 0)
.{{ $instId }}::before {
  content: '';
  position: absolute; inset: 0;
  background: {{ $overlayColor }};
  opacity: {{ $overlayOpacity / 100 }};
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
