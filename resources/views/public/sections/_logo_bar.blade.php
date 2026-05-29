{{-- MARKER-PATCH-158-G32 — logo_bar public renderer (v2) --}}
@php
  $c = $c ?? [];

  // Logos normalize — handle v1 (parallel arrays) AND v2 (unified objects)
  $rawLogos = $c['logos'] ?? [];
  if (is_string($rawLogos)) { $d = json_decode($rawLogos, true); $rawLogos = is_array($d) ? $d : []; }
  if (!is_array($rawLogos)) $rawLogos = [];

  $logos = [];
  if (!empty($rawLogos) && is_string($rawLogos[0] ?? null)) {
      // v1 shape: logos is a flat array of URL strings; merge with shop_names
      $shopNames = $c['shop_names'] ?? [];
      if (is_string($shopNames)) { $d = json_decode($shopNames, true); $shopNames = is_array($d) ? $d : []; }
      if (!is_array($shopNames)) $shopNames = [];
      foreach ($shopNames as $name) {
          if (trim((string)$name) !== '') $logos[] = ['name' => $name, 'logo_url' => '', 'link_url' => ''];
      }
      foreach ($rawLogos as $url) {
          if (is_string($url) && trim($url) !== '') $logos[] = ['name' => '', 'logo_url' => $url, 'link_url' => ''];
      }
  } else {
      // v2 shape: array of {name, logo_url, link_url} objects
      foreach ($rawLogos as $lg) {
          if (!is_array($lg)) continue;
          $name    = trim($lg['name'] ?? '');
          $logoUrl = trim($lg['logo_url'] ?? '');
          if ($name === '' && $logoUrl === '') continue;
          $logos[] = ['name' => $name, 'logo_url' => $logoUrl, 'link_url' => trim($lg['link_url'] ?? '')];
      }
  }

  // Layout
  $layout      = $c['layout']      ?? 'grid';
  $colsSetting = $c['cols']        ?? 'auto';
  $logoSize    = $c['logo_size']   ?? 'medium';
  $treatment   = $c['logo_treatment'] ?? 'grayscale_hover';
  $marqueeSpd  = $c['marquee_speed'] ?? 'normal';
  $hAlign      = $c['text_align']  ?? 'center';

  // Logo height map
  $sizeMap = ['small'=>'28px','medium'=>'40px','large'=>'56px'];
  $logoH   = $sizeMap[$logoSize] ?? '40px';

  // Marquee duration (seconds per loop)
  $speedMap = ['slow'=>'60s','normal'=>'40s','fast'=>'24s'];
  $marqueeDuration = $speedMap[$marqueeSpd] ?? '40s';

  $padTokens = ['none'=>'0','compact'=>'40px','normal'=>'64px','spacious'=>'96px'];
  $padTop = $padTokens[$c['padding_top']    ?? 'compact'] ?? '40px';
  $padBot = $padTokens[$c['padding_bottom'] ?? 'compact'] ?? '40px';

  // Grid columns
  $count = max(1, count($logos));
  $cols  = $colsSetting === 'auto' ? min($count, 6) : (int)$colsSetting;
  if ($cols < 1) $cols = 1;

  // Background
  $bgMode  = $c['bg_mode']  ?? 'none';
  $bgColor = $c['bg_color'] ?? '#ffffff';
  $gradF   = $c['bg_gradient_from'] ?? '#ffffff';
  $gradT   = $c['bg_gradient_to']   ?? '#fafafa';

  // Colors
  $textColor     = ($c['text_color']      ?? '') ?: '#0a0a0a';
  $textColorBody = ($c['text_color_body'] ?? '') ?: 'rgba(0,0,0,0.6)';
  $accentColor   = ($c['accent_color']    ?? '') ?: '#BEF264';

  // Heading with accent
  $headingHtml = e($c['heading'] ?? '');
  $accentWords = trim($c['accent_words'] ?? '');
  if ($accentWords !== '' && stripos($headingHtml, e($accentWords)) !== false) {
      $headingHtml = str_ireplace(
          e($accentWords),
          '<span class="p-lb-accent">' . e($accentWords) . '</span>',
          $headingHtml
      );
  }

  // Advanced
  $anchorId    = trim($c['anchor_id'] ?? '');
  $customClass = trim($c['custom_classes'] ?? '');
  $hideMobile  = !empty($c['hide_on_mobile']);
  $hideDesktop = !empty($c['hide_on_desktop']);

  $instId = 'p-lb-' . ($section->id ?? uniqid());
@endphp

<style>
.{{ $instId }} {
  padding-top: {{ $padTop }};
  padding-bottom: {{ $padBot }};
  @if($bgMode === 'color') background: {{ $bgColor }};
  @elseif($bgMode === 'gradient') background: linear-gradient(135deg, {{ $gradF }} 0%, {{ $gradT }} 100%);
  @endif
}
.{{ $instId }} .p-lb-wrap {
  max-width: 1200px;
  margin: 0 auto;
  padding: 0 clamp(20px, 5vw, 48px);
}
.{{ $instId }} .p-lb-head {
  text-align: {{ $hAlign }};
  margin-bottom: 32px;
  color: {{ $textColor }};
}
.{{ $instId }} .p-lb-eyebrow {
  font-family: ui-monospace, monospace;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  color: {{ $accentColor }};
  margin-bottom: 10px;
  opacity: .95;
}
.{{ $instId }} .p-lb-heading {
  font-size: clamp(15px, 1.6vw, 18px);
  font-weight: 500;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  margin: 0;
  line-height: 1.3;
  color: {{ $textColorBody }};
}
.{{ $instId }} .p-lb-accent { color: {{ $accentColor }}; }
.{{ $instId }} .p-lb-sub {
  font-size: 14px;
  line-height: 1.55;
  color: {{ $textColorBody }};
  margin: 8px 0 0;
  max-width: 540px;
  @if($hAlign === 'center') margin-left: auto; margin-right: auto; @endif
}

/* === Logo treatment ============================================ */
.{{ $instId }} .p-lb-logo img {
  height: {{ $logoH }};
  width: auto;
  max-width: 100%;
  display: block;
  @if($treatment === 'grayscale')
  filter: grayscale(100%);
  opacity: .7;
  @elseif($treatment === 'grayscale_hover')
  filter: grayscale(100%);
  opacity: .65;
  transition: filter 0.2s, opacity 0.2s;
  @elseif($treatment === 'muted')
  opacity: .55;
  transition: opacity 0.2s;
  @endif
}
.{{ $instId }} .p-lb-logo:hover img {
  @if($treatment === 'grayscale_hover')
  filter: grayscale(0%);
  opacity: 1;
  @elseif($treatment === 'muted')
  opacity: 1;
  @endif
}
.{{ $instId }} .p-lb-logo-text {
  font-family: ui-monospace, monospace;
  font-size: 13px;
  font-weight: 500;
  letter-spacing: 0.04em;
  color: {{ $textColorBody }};
  padding: 8px 16px;
  border: 1px solid rgba(0,0,0,0.1);
  border-radius: 999px;
  white-space: nowrap;
  transition: color 0.2s, border-color 0.2s;
}
.{{ $instId }} .p-lb-logo:hover .p-lb-logo-text {
  color: {{ $textColor }};
  border-color: rgba(0,0,0,0.25);
}
.{{ $instId }} a.p-lb-logo { text-decoration: none; cursor: pointer; }
.{{ $instId }} .p-lb-logo {
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

@if($layout === 'grid')
.{{ $instId }} .p-lb-grid {
  display: grid;
  grid-template-columns: repeat({{ $cols }}, minmax(0, 1fr));
  gap: 32px 28px;
  align-items: center;
  justify-items: center;
}
@media (max-width: 900px) {
  .{{ $instId }} .p-lb-grid { grid-template-columns: repeat({{ min($cols, 3) }}, 1fr); gap: 24px; }
}
@media (max-width: 480px) {
  .{{ $instId }} .p-lb-grid { grid-template-columns: repeat(2, 1fr); gap: 18px; }
}

@elseif($layout === 'marquee')
.{{ $instId }} .p-lb-marquee {
  overflow: hidden;
  position: relative;
  mask-image: linear-gradient(90deg, transparent, #000 8%, #000 92%, transparent);
  -webkit-mask-image: linear-gradient(90deg, transparent, #000 8%, #000 92%, transparent);
}
.{{ $instId }} .p-lb-marquee-track {
  display: flex;
  gap: 48px;
  width: max-content;
  animation: pb2-lb-marq-{{ $section->id ?? 'x' }} {{ $marqueeDuration }} linear infinite;
}
.{{ $instId }} .p-lb-marquee-track .p-lb-logo {
  flex-shrink: 0;
}
@keyframes pb2-lb-marq-{{ $section->id ?? 'x' }} {
  from { transform: translateX(0); }
  to   { transform: translateX(-50%); }
}
.{{ $instId }} .p-lb-marquee:hover .p-lb-marquee-track {
  animation-play-state: paused;
}
@media (prefers-reduced-motion: reduce) {
  .{{ $instId }} .p-lb-marquee-track {
    animation: none;
    flex-wrap: wrap;
    justify-content: center;
    width: 100%;
  }
}
@endif

@if($hideMobile)
@media (max-width: 768px) { .{{ $instId }} { display: none; } }
@endif
@if($hideDesktop)
@media (min-width: 769px) { .{{ $instId }} { display: none; } }
@endif
</style>

<section class="{{ $instId }} p-logo-bar {{ $customClass }}" @if($anchorId) id="{{ $anchorId }}" @endif>
  <div class="p-lb-wrap">

    @if(!empty($c['eyebrow']) || !empty($c['heading']) || !empty($c['subheading']))
      <div class="p-lb-head">
        @if(!empty($c['eyebrow']))
          <div class="p-lb-eyebrow">{{ $c['eyebrow'] }}</div>
        @endif
        @if(!empty($c['heading']))
          <h2 class="p-lb-heading">{!! $headingHtml !!}</h2>
        @endif
        @if(!empty($c['subheading']))
          <p class="p-lb-sub">{{ $c['subheading'] }}</p>
        @endif
      </div>
    @endif

    @if(!empty($logos))
      @if($layout === 'grid')
        <div class="p-lb-grid">
          @foreach($logos as $lg)
            @if(!empty($lg['link_url']))
              <a href="{{ $lg['link_url'] }}" class="p-lb-logo" target="_blank" rel="noopener noreferrer" aria-label="{{ $lg['name'] ?: 'Partner' }}">
                @if(!empty($lg['logo_url']))
                  <img src="{{ $lg['logo_url'] }}" alt="{{ $lg['name'] ?: 'Logo' }}">
                @else
                  <span class="p-lb-logo-text">{{ $lg['name'] }}</span>
                @endif
              </a>
            @else
              <div class="p-lb-logo">
                @if(!empty($lg['logo_url']))
                  <img src="{{ $lg['logo_url'] }}" alt="{{ $lg['name'] ?: 'Logo' }}">
                @else
                  <span class="p-lb-logo-text">{{ $lg['name'] }}</span>
                @endif
              </div>
            @endif
          @endforeach
        </div>
      @elseif($layout === 'marquee')
        <div class="p-lb-marquee">
          <div class="p-lb-marquee-track">
            {{-- Render the list TWICE for seamless looping --}}
            @for($pass = 0; $pass < 2; $pass++)
              @foreach($logos as $lg)
                @if(!empty($lg['link_url']))
                  <a href="{{ $lg['link_url'] }}" class="p-lb-logo" target="_blank" rel="noopener noreferrer" aria-label="{{ $lg['name'] ?: 'Partner' }}" @if($pass === 1) aria-hidden="true" @endif>
                    @if(!empty($lg['logo_url']))
                      <img src="{{ $lg['logo_url'] }}" alt="{{ $lg['name'] ?: 'Logo' }}">
                    @else
                      <span class="p-lb-logo-text">{{ $lg['name'] }}</span>
                    @endif
                  </a>
                @else
                  <div class="p-lb-logo" @if($pass === 1) aria-hidden="true" @endif>
                    @if(!empty($lg['logo_url']))
                      <img src="{{ $lg['logo_url'] }}" alt="{{ $lg['name'] ?: 'Logo' }}">
                    @else
                      <span class="p-lb-logo-text">{{ $lg['name'] }}</span>
                    @endif
                  </div>
                @endif
              @endforeach
            @endfor
          </div>
        </div>
      @endif
    @endif

  </div>
</section>
