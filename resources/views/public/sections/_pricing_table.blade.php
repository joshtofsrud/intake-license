{{-- MARKER-PATCH-158-G30 — pricing_table public renderer (v2) --}}
@php
  $c = $c ?? [];

  // Plans normalize
  $plans = $c['plans'] ?? [];
  if (is_string($plans)) { $d = json_decode($plans, true); $plans = is_array($d) ? $d : []; }
  if (!is_array($plans)) $plans = [];

  // Enforce "only one featured" rule — first one wins if multiple
  $featuredSeen = false;
  $plans = array_map(function($p) use (&$featuredSeen) {
      $isF = !empty($p['featured']);
      if ($isF && $featuredSeen) $p['featured'] = false;
      elseif ($isF) $featuredSeen = true;
      return $p;
  }, $plans);

  // Layout
  $count = max(1, count($plans));
  $colsSetting = $c['columns'] ?? 'auto';
  $cols = $colsSetting === 'auto' ? min($count, 3) : (int)$colsSetting;
  if ($cols < 1) $cols = 1;
  if ($cols > 4) $cols = 4;

  $featStyle = $c['featured_style']  ?? 'border';     // border | elevated | scale
  $featDiv   = $c['feature_divider'] ?? 'dashed';      // none | solid | dashed
  $hAlign    = $c['text_align']      ?? 'center';

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
          '<span class="p-pt-accent">' . e($accentWords) . '</span>',
          $headingHtml
      );
  }

  // Advanced
  $anchorId    = trim($c['anchor_id'] ?? '');
  $customClass = trim($c['custom_classes'] ?? '');
  $hideMobile  = !empty($c['hide_on_mobile']);
  $hideDesktop = !empty($c['hide_on_desktop']);

  $instId = 'p-pt-' . ($section->id ?? uniqid());

  // Divider CSS for inside cards
  $featDivCss = match($featDiv) {
      'solid'  => '1px solid '.$cardBorder,
      'dashed' => '1px dashed '.$cardBorder,
      default  => 'none',
  };
@endphp

<style>
.{{ $instId }} {
  padding-top: {{ $padTop }};
  padding-bottom: {{ $padBot }};
  @if($bgMode === 'color') background: {{ $bgColor }};
  @elseif($bgMode === 'gradient') background: linear-gradient({{ (int)($c['bg_gradient_angle'] ?? 135) }}deg, {{ $gradF }} 0%, {{ $gradT }} 100%);
  @endif
}
.{{ $instId }} .p-pt-wrap {
  max-width: {{ (int)($c['content_max_width'] ?? 1200) }}px;
  margin: 0 auto;
  padding: 0 clamp(20px, 5vw, 48px);
}
.{{ $instId }} .p-pt-head {
  text-align: {{ $hAlign }};
  margin-bottom: 48px;
  color: {{ $textColor }};
}
.{{ $instId }} .p-pt-eyebrow {
  font-family: ui-monospace, monospace;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  color: {{ $accentColor }};
  margin-bottom: 12px;
  opacity: .95;
}
.{{ $instId }} .p-pt-heading {
  font-size: clamp(28px, 3.4vw, 44px);
  font-weight: 500;
  letter-spacing: -.02em;
  margin: 0 0 14px;
  line-height: 1.1;
  color: {{ $textColor }};
}
.{{ $instId }} .p-pt-accent { color: {{ $accentColor }}; }
.{{ $instId }} .p-pt-sub {
  font-size: 16px;
  line-height: 1.55;
  color: {{ $textColorBody }};
  margin: 0;
  max-width: 640px;
  @if($hAlign === 'center') margin-left: auto; margin-right: auto; @endif
}

.{{ $instId }} .p-pt-grid {
  display: grid;
  grid-template-columns: repeat({{ $cols }}, minmax(0, 1fr));
  gap: 20px;
  align-items: stretch;
}
@media (max-width: 900px) {
  .{{ $instId }} .p-pt-grid { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 600px) {
  .{{ $instId }} .p-pt-grid { grid-template-columns: 1fr; }
}

.{{ $instId }} .p-pt-card {
  position: relative;
  background: {{ $cardBg }};
  border: 1px solid {{ $cardBorder }};
  border-radius: 16px;
  padding: 36px 32px;
  display: flex;
  flex-direction: column;
  color: {{ $textColor }};
  transition: transform 0.18s, box-shadow 0.18s;
}

/* Featured card emphasis */
@if($featStyle === 'border')
.{{ $instId }} .p-pt-card--featured {
  border-color: {{ $accentColor }};
  box-shadow: 0 0 0 1px {{ $accentColor }} inset;
}
@elseif($featStyle === 'elevated')
.{{ $instId }} .p-pt-card--featured {
  box-shadow: 0 16px 40px rgba(0,0,0,0.25), 0 0 0 1px {{ $accentColor }} inset;
  transform: translateY(-6px);
}
@elseif($featStyle === 'scale')
.{{ $instId }} .p-pt-card--featured {
  border-color: {{ $accentColor }};
  transform: scale(1.04);
  z-index: 2;
}
@endif

.{{ $instId }} .p-pt-badge {
  position: absolute;
  top: 0;
  left: 24px;
  transform: translateY(-50%);
  background: {{ $accentColor }};
  color: {{ $bgMode === 'gradient' ? $gradF : ($bgMode === 'color' ? $bgColor : '#0a0f1a') }};
  font-family: ui-monospace, monospace;
  font-size: 10.5px;
  font-weight: 600;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  padding: 6px 12px;
  border-radius: 4px;
}

.{{ $instId }} .p-pt-card-eyebrow {
  font-family: ui-monospace, monospace;
  font-size: 11px;
  letter-spacing: 0.1em;
  color: {{ $textColorBody }};
  text-transform: uppercase;
  margin: 0 0 18px;
}
.{{ $instId }} .p-pt-card-title {
  font-size: 28px;
  font-weight: 700;
  letter-spacing: -.02em;
  line-height: 1.1;
  margin: 0 0 22px;
  color: {{ $textColor }};
}
.{{ $instId }} .p-pt-price-row {
  display: flex;
  align-items: baseline;
  gap: 8px;
  margin: 0 0 26px;
}
.{{ $instId }} .p-pt-price {
  font-family: ui-monospace, monospace;
  font-size: 26px;
  font-weight: 600;
  color: {{ $accentColor }};
  letter-spacing: -.01em;
}
.{{ $instId }} .p-pt-price-suffix {
  font-family: ui-monospace, monospace;
  font-size: 13px;
  color: {{ $textColorBody }};
  text-transform: lowercase;
}

.{{ $instId }} .p-pt-feats {
  list-style: none;
  margin: 0 0 24px;
  padding: 0;
  flex: 1;
}
.{{ $instId }} .p-pt-feat {
  display: grid;
  grid-template-columns: 18px 1fr;
  gap: 12px;
  align-items: start;
  padding: 14px 0;
  border-bottom: {{ $featDivCss }};
  font-size: 14.5px;
  line-height: 1.5;
  color: {{ $textColor }};
}
.{{ $instId }} .p-pt-feat:first-child { padding-top: 0; }
.{{ $instId }} .p-pt-feat:last-child { border-bottom: 0; }
.{{ $instId }} .p-pt-feat svg {
  width: 16px; height: 16px;
  color: {{ $accentColor }};
  flex-shrink: 0;
  margin-top: 2px;
}

.{{ $instId }} .p-pt-cta {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 12px 22px;
  font-size: 14px;
  font-weight: 500;
  border-radius: 8px;
  text-decoration: none;
  background: {{ $accentColor }};
  color: {{ $bgMode === 'color' ? $bgColor : '#0a0f1a' }};
  border: 0;
  transition: filter 0.15s;
}
.{{ $instId }} .p-pt-cta:hover { filter: brightness(1.08); }
.{{ $instId }} .p-pt-card:not(.p-pt-card--featured) .p-pt-cta {
  background: transparent;
  color: {{ $textColor }};
  border: 1px solid {{ $cardBorder }};
}
.{{ $instId }} .p-pt-card:not(.p-pt-card--featured) .p-pt-cta:hover {
  border-color: {{ $accentColor }};
  color: {{ $accentColor }};
}

.{{ $instId }} .p-pt-footnote {
  margin-top: 28px;
  text-align: center;
  font-size: 13px;
  color: {{ $textColorBody }};
  opacity: .8;
}

@if($hideMobile)
@media (max-width: 768px) { .{{ $instId }} { display: none; } }
@endif
@if($hideDesktop)
@media (min-width: 769px) { .{{ $instId }} { display: none; } }
@endif
</style>

<section class="{{ $instId }} p-pricing-table {{ $customClass }}" @if($anchorId) id="{{ $anchorId }}" @endif>
  <div class="p-pt-wrap">

    @if(!empty($c['eyebrow']) || !empty($c['heading']) || !empty($c['subheading']))
      <div class="p-pt-head">
        @if(!empty($c['eyebrow']))
          <div class="p-pt-eyebrow">{{ $c['eyebrow'] }}</div>
        @endif
        @if(!empty($c['heading']))
          <h2 class="p-pt-heading">{!! $headingHtml !!}</h2>
        @endif
        @if(!empty($c['subheading']))
          <p class="p-pt-sub">{{ $c['subheading'] }}</p>
        @endif
      </div>
    @endif

    @if(!empty($plans))
      <div class="p-pt-grid">
        @foreach($plans as $plan)
          @php
            $isFeatured = !empty($plan['featured']);
            $features = $plan['features'] ?? [];
            if (!is_array($features)) $features = [];
            $badgeLabel = trim($plan['badge_label'] ?? '');
            if ($isFeatured && $badgeLabel === '') $badgeLabel = 'MOST BOOKED';
          @endphp
          <div class="p-pt-card {{ $isFeatured ? 'p-pt-card--featured' : '' }}">
            @if($isFeatured && $badgeLabel !== '')
              <span class="p-pt-badge">{{ $badgeLabel }}</span>
            @endif

            @if(!empty($plan['eyebrow']))
              <p class="p-pt-card-eyebrow">{{ $plan['eyebrow'] }}</p>
            @endif

            @if(!empty($plan['title']))
              <h3 class="p-pt-card-title">{{ $plan['title'] }}</h3>
            @endif

            @if(!empty($plan['price']) || !empty($plan['price_suffix']))
              <div class="p-pt-price-row">
                @if(!empty($plan['price']))
                  <span class="p-pt-price">{{ $plan['price'] }}</span>
                @endif
                @if(!empty($plan['price_suffix']))
                  <span class="p-pt-price-suffix">{{ $plan['price_suffix'] }}</span>
                @endif
              </div>
            @endif

            @if(!empty($features))
              <ul class="p-pt-feats">
                @foreach($features as $f)
                  @php $ftext = is_string($f) ? $f : ($f['text'] ?? ''); @endphp
                  @if(trim($ftext) !== '')
                    <li class="p-pt-feat">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <polyline points="20 6 9 17 4 12"/>
                      </svg>
                      <span>{{ $ftext }}</span>
                    </li>
                  @endif
                @endforeach
              </ul>
            @endif

            @if(!empty($plan['cta_label']))
              <a href="{{ $plan['cta_url'] ?? '#' }}" class="p-pt-cta">{{ $plan['cta_label'] }}</a>
            @endif
          </div>
        @endforeach
      </div>
    @endif

    @if(!empty($c['footnote']))
      <p class="p-pt-footnote">{{ $c['footnote'] }}</p>
    @endif

  </div>
</section>
