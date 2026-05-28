{{-- MARKER-PATCH-158-G31 — feature_grid public renderer (v2) --}}
@php
  $c = $c ?? [];

  // Features normalize
  $features = $c['features'] ?? [];
  if (is_string($features)) { $d = json_decode($features, true); $features = is_array($d) ? $d : []; }
  if (!is_array($features)) $features = [];

  // Layout
  $layoutMode = $c['layout'] ?? 'grid';      // grid | intro_split
  $cols       = max(1, min(4, (int)($c['columns'] ?? 3)));
  $cardStyle  = $c['card_style'] ?? 'card';   // card | minimal
  $showIcons  = (bool)($c['show_icons'] ?? true);
  $hAlign     = $c['text_align'] ?? 'center';

  $padTokens = ['none'=>'0','compact'=>'40px','normal'=>'80px','spacious'=>'120px'];
  $padTop = $padTokens[$c['padding_top']    ?? 'normal'] ?? '80px';
  $padBot = $padTokens[$c['padding_bottom'] ?? 'normal'] ?? '80px';

  // Background
  $bgMode  = $c['bg_mode']  ?? 'none';
  $bgColor = $c['bg_color'] ?? '#0a0f1a';
  $gradF   = $c['bg_gradient_from'] ?? '#0a0f1a';
  $gradT   = $c['bg_gradient_to']   ?? '#0f1828';

  // Colors
  $textColor     = ($c['text_color']      ?? '') ?: '#ffffff';
  $textColorBody = ($c['text_color_body'] ?? '') ?: 'rgba(255,255,255,0.7)';
  $accentColor   = ($c['accent_color']    ?? '') ?: '#BEF264';
  $cardBg        = ($c['card_bg']         ?? '') ?: '#111a2b';
  $cardBorder    = ($c['card_border']     ?? '') ?: 'rgba(255,255,255,0.08)';

  // Heading with accent phrase
  $headingHtml = e($c['heading'] ?? '');
  $accentWords = trim($c['accent_words'] ?? '');
  if ($accentWords !== '' && stripos($headingHtml, e($accentWords)) !== false) {
      $headingHtml = str_ireplace(
          e($accentWords),
          '<span class="p-fg-accent-phrase">' . e($accentWords) . '</span>',
          $headingHtml
      );
  }
  $headingHtml = nl2br($headingHtml);

  // Advanced
  $anchorId    = trim($c['anchor_id'] ?? '');
  $customClass = trim($c['custom_classes'] ?? '');
  $hideMobile  = !empty($c['hide_on_mobile']);
  $hideDesktop = !empty($c['hide_on_desktop']);

  $instId = 'p-fg-' . ($section->id ?? uniqid());

  // For intro_split layout: how many card columns?
  $cardCount = count($features);
  $splitCols = max(1, min(3, $cardCount));
@endphp

<style>
.{{ $instId }} {
  padding-top: {{ $padTop }};
  padding-bottom: {{ $padBot }};
  @if($bgMode === 'color') background: {{ $bgColor }};
  @elseif($bgMode === 'gradient') background: linear-gradient(135deg, {{ $gradF }} 0%, {{ $gradT }} 100%);
  @endif
}
.{{ $instId }} .p-fg-wrap {
  max-width: 1200px;
  margin: 0 auto;
  padding: 0 clamp(20px, 5vw, 48px);
}

/* Shared intro text */
.{{ $instId }} .p-fg-eyebrow {
  font-family: ui-monospace, monospace;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  color: {{ $accentColor }};
  margin-bottom: 14px;
  opacity: .95;
  display: flex;
  align-items: center;
  gap: 10px;
}
.{{ $instId }} .p-fg-eyebrow::before {
  content: '';
  display: inline-block;
  width: 18px; height: 1px;
  background: {{ $accentColor }};
}
.{{ $instId }} .p-fg-heading {
  font-size: clamp(28px, 3.4vw, 44px);
  font-weight: 500;
  letter-spacing: -.02em;
  margin: 0 0 18px;
  line-height: 1.1;
  color: {{ $textColor }};
}
.{{ $instId }} .p-fg-accent-phrase { color: {{ $textColorBody }}; }
.{{ $instId }} .p-fg-sub {
  font-size: 15.5px;
  line-height: 1.55;
  color: {{ $textColorBody }};
  margin: 0;
  max-width: 540px;
}

@if($layoutMode === 'grid')
.{{ $instId }} .p-fg-head {
  text-align: {{ $hAlign }};
  margin-bottom: 48px;
}
.{{ $instId }} .p-fg-head .p-fg-eyebrow { justify-content: {{ $hAlign === 'center' ? 'center' : 'flex-start' }}; }
.{{ $instId }} .p-fg-head .p-fg-sub {
  @if($hAlign === 'center') margin-left: auto; margin-right: auto; @endif
}

.{{ $instId }} .p-fg-grid {
  display: grid;
  grid-template-columns: repeat({{ $cols }}, minmax(0, 1fr));
  gap: 18px;
}
@media (max-width: 900px) { .{{ $instId }} .p-fg-grid { grid-template-columns: repeat({{ min($cols, 2) }}, 1fr); } }
@media (max-width: 600px) { .{{ $instId }} .p-fg-grid { grid-template-columns: 1fr; } }

@elseif($layoutMode === 'intro_split')
.{{ $instId }} .p-fg-split {
  background: {{ $cardBg }};
  border: 1px solid {{ $cardBorder }};
  border-radius: 18px;
  padding: 40px;
  display: grid;
  grid-template-columns: minmax(260px, 1.05fr) repeat({{ $splitCols }}, minmax(0, 1fr));
  gap: 28px;
  align-items: start;
}
.{{ $instId }} .p-fg-split-intro { padding-right: 8px; }

@media (max-width: 1000px) {
  .{{ $instId }} .p-fg-split { grid-template-columns: 1fr 1fr; }
  .{{ $instId }} .p-fg-split-intro { grid-column: 1 / -1; padding-right: 0; margin-bottom: 8px; }
}
@media (max-width: 600px) {
  .{{ $instId }} .p-fg-split { grid-template-columns: 1fr; padding: 28px; }
  .{{ $instId }} .p-fg-split-intro { grid-column: 1 / -1; }
}
@endif

/* Cards (shared between layouts) */
.{{ $instId }} .p-fg-card {
  @if($cardStyle === 'card')
  background: {{ $cardBg }};
  border: 1px solid {{ $cardBorder }};
  border-radius: 12px;
  padding: 24px;
  @else
  padding: 4px 0;
  @endif
  display: flex;
  flex-direction: column;
  color: {{ $textColor }};
}
@if($layoutMode === 'intro_split')
.{{ $instId }} .p-fg-split .p-fg-card {
  background: rgba(255,255,255,0.025);
  border: 1px solid rgba(255,255,255,0.05);
  border-radius: 10px;
  padding: 22px;
}
@endif
.{{ $instId }} .p-fg-card-icon {
  font-size: 22px;
  line-height: 1;
  color: {{ $accentColor }};
  margin: 0 0 14px;
}
.{{ $instId }} .p-fg-card-title {
  font-size: 17px;
  font-weight: 600;
  letter-spacing: -.01em;
  line-height: 1.25;
  color: {{ $textColor }};
  margin: 0 0 10px;
}
.{{ $instId }} .p-fg-card-price {
  font-family: ui-monospace, monospace;
  font-size: 15px;
  font-weight: 600;
  color: {{ $accentColor }};
  letter-spacing: -.01em;
  margin: 0 0 12px;
}
.{{ $instId }} .p-fg-card-body {
  font-size: 14px;
  line-height: 1.55;
  color: {{ $textColorBody }};
  margin: 0;
  flex: 1;
}
.{{ $instId }} .p-fg-card-cta {
  display: inline-flex;
  align-items: center;
  margin-top: 14px;
  font-size: 13px;
  font-weight: 500;
  color: {{ $accentColor }};
  text-decoration: none;
  border: 0;
  background: none;
  cursor: pointer;
  gap: 4px;
}
.{{ $instId }} .p-fg-card-cta:hover { text-decoration: underline; }

@if($hideMobile)
@media (max-width: 768px) { .{{ $instId }} { display: none; } }
@endif
@if($hideDesktop)
@media (min-width: 769px) { .{{ $instId }} { display: none; } }
@endif
</style>

<section class="{{ $instId }} p-feature-grid {{ $customClass }}" @if($anchorId) id="{{ $anchorId }}" @endif>
  <div class="p-fg-wrap">

    @if($layoutMode === 'grid')
      {{-- Standard grid layout: intro text above, even cards below --}}
      @if(!empty($c['eyebrow']) || !empty($c['heading']) || !empty($c['subheading']))
        <div class="p-fg-head">
          @if(!empty($c['eyebrow']))
            <div class="p-fg-eyebrow">{{ $c['eyebrow'] }}</div>
          @endif
          @if(!empty($c['heading']))
            <h2 class="p-fg-heading">{!! $headingHtml !!}</h2>
          @endif
          @if(!empty($c['subheading']))
            <p class="p-fg-sub">{{ $c['subheading'] }}</p>
          @endif
        </div>
      @endif

      @if(!empty($features))
        <div class="p-fg-grid">
          @foreach($features as $f)
            <div class="p-fg-card">
              @if($showIcons && !empty($f['icon']))
                <div class="p-fg-card-icon">{{ $f['icon'] }}</div>
              @endif
              @if(!empty($f['title']))
                <h3 class="p-fg-card-title">{{ $f['title'] }}</h3>
              @endif
              @if(!empty($f['price']))
                <div class="p-fg-card-price">{{ $f['price'] }}</div>
              @endif
              @if(!empty($f['body']))
                <p class="p-fg-card-body">{{ $f['body'] }}</p>
              @endif
              @if(!empty($f['cta_label']))
                <a href="{{ $f['cta_url'] ?? '#' }}" class="p-fg-card-cta">{{ $f['cta_label'] }} →</a>
              @endif
            </div>
          @endforeach
        </div>
      @endif

    @elseif($layoutMode === 'intro_split')
      {{-- Intro split: large intro left + cards right inside one container --}}
      <div class="p-fg-split">
        <div class="p-fg-split-intro">
          @if(!empty($c['eyebrow']))
            <div class="p-fg-eyebrow">{{ $c['eyebrow'] }}</div>
          @endif
          @if(!empty($c['heading']))
            <h2 class="p-fg-heading">{!! $headingHtml !!}</h2>
          @endif
          @if(!empty($c['subheading']))
            <p class="p-fg-sub">{{ $c['subheading'] }}</p>
          @endif
        </div>

        @foreach($features as $f)
          <div class="p-fg-card">
            @if($showIcons && !empty($f['icon']))
              <div class="p-fg-card-icon">{{ $f['icon'] }}</div>
            @endif
            @if(!empty($f['title']))
              <h3 class="p-fg-card-title">{{ $f['title'] }}</h3>
            @endif
            @if(!empty($f['price']))
              <div class="p-fg-card-price">{{ $f['price'] }}</div>
            @endif
            @if(!empty($f['body']))
              <p class="p-fg-card-body">{{ $f['body'] }}</p>
            @endif
            @if(!empty($f['cta_label']))
              <a href="{{ $f['cta_url'] ?? '#' }}" class="p-fg-card-cta">{{ $f['cta_label'] }} →</a>
            @endif
          </div>
        @endforeach
      </div>
    @endif

  </div>
</section>
