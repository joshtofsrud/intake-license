{{-- MARKER-PATCH-158-G34 — step_timeline public renderer (v2) --}}
@php
  $c = $c ?? [];

  // Steps normalize
  $rawSteps = $c['steps'] ?? [];
  if (is_string($rawSteps)) { $d = json_decode($rawSteps, true); $rawSteps = is_array($d) ? $d : []; }
  if (!is_array($rawSteps)) $rawSteps = [];

  $steps = [];
  foreach ($rawSteps as $s) {
      if (!is_array($s)) continue;
      $title = trim($s['title'] ?? '');
      $desc  = trim($s['desc']  ?? '');
      $icon  = trim($s['icon']  ?? '');
      if ($title === '' && $desc === '') continue;
      $steps[] = ['title'=>$title, 'desc'=>$desc, 'icon'=>$icon];
  }

  // Layout
  $layout       = $c['layout']       ?? 'horizontal'; // horizontal | vertical | cards
  $connector    = $c['connector']    ?? 'line';        // line | dots | arrow | none
  $showNumbers  = (bool)($c['show_numbers'] ?? true);
  $numberStyle  = $c['number_style'] ?? 'circle';      // circle | square | underline
  $hAlign       = $c['text_align']   ?? 'center';

  $padTokens = ['none'=>'0','compact'=>'40px','normal'=>'80px','spacious'=>'120px'];
  $padTop = $padTokens[$c['padding_top']    ?? 'normal'] ?? '80px';
  $padBot = $padTokens[$c['padding_bottom'] ?? 'normal'] ?? '80px';

  // Background
  $bgMode  = $c['bg_mode']  ?? 'none';
  $bgColor = $c['bg_color'] ?? '#ffffff';
  $gradF   = $c['bg_gradient_from'] ?? '#ffffff';
  $gradT   = $c['bg_gradient_to']   ?? '#fafafa';

  // Colors
  $textColor     = ($c['text_color']      ?? '') ?: '#0a0a0a';
  $textColorBody = ($c['text_color_body'] ?? '') ?: 'rgba(0,0,0,0.65)';
  $accentColor   = ($c['accent_color']    ?? '') ?: '#BEF264';

  // Heading with accent
  $headingHtml = e($c['heading'] ?? '');
  $accentWords = trim($c['accent_words'] ?? '');
  if ($accentWords !== '' && stripos($headingHtml, e($accentWords)) !== false) {
      $headingHtml = str_ireplace(
          e($accentWords),
          '<span class="p-st-accent">' . e($accentWords) . '</span>',
          $headingHtml
      );
  }

  // Advanced
  $anchorId    = trim($c['anchor_id'] ?? '');
  $customClass = trim($c['custom_classes'] ?? '');
  $hideMobile  = !empty($c['hide_on_mobile']);
  $hideDesktop = !empty($c['hide_on_desktop']);

  $instId = 'p-st-' . ($section->id ?? uniqid());

  $stepCount = count($steps);
@endphp

<style>
.{{ $instId }} {
  padding-top: {{ $padTop }};
  padding-bottom: {{ $padBot }};
  @if($bgMode === 'color') background: {{ $bgColor }};
  @elseif($bgMode === 'gradient') background: linear-gradient(135deg, {{ $gradF }} 0%, {{ $gradT }} 100%);
  @endif
}
.{{ $instId }} .p-st-wrap {
  max-width: 1200px;
  margin: 0 auto;
  padding: 0 clamp(20px, 5vw, 48px);
}
.{{ $instId }} .p-st-head {
  text-align: {{ $hAlign }};
  margin-bottom: 56px;
  color: {{ $textColor }};
}
.{{ $instId }} .p-st-eyebrow {
  font-family: ui-monospace, monospace;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  color: {{ $accentColor }};
  margin-bottom: 12px;
  opacity: .95;
}
.{{ $instId }} .p-st-heading {
  font-size: clamp(24px, 3vw, 38px);
  font-weight: 500;
  letter-spacing: -.02em;
  margin: 0 0 12px;
  line-height: 1.15;
  color: {{ $textColor }};
}
.{{ $instId }} .p-st-accent { color: {{ $accentColor }}; }
.{{ $instId }} .p-st-sub {
  font-size: 16px;
  line-height: 1.55;
  color: {{ $textColorBody }};
  margin: 0;
  max-width: 580px;
  @if($hAlign === 'center') margin-left: auto; margin-right: auto; @endif
}

/* Number / icon marker (shared base) */
.{{ $instId }} .p-st-marker {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-family: ui-monospace, monospace;
  font-size: 14px;
  font-weight: 600;
  width: 40px;
  height: 40px;
  flex-shrink: 0;
  @if($numberStyle === 'circle')
  background: {{ $accentColor }};
  color: #0a0a0a;
  border-radius: 50%;
  @elseif($numberStyle === 'square')
  background: {{ $accentColor }};
  color: #0a0a0a;
  border-radius: 10px;
  @else
  background: transparent;
  color: {{ $accentColor }};
  border-bottom: 2px solid {{ $accentColor }};
  border-radius: 0;
  width: auto; height: auto;
  padding: 4px 2px;
  @endif
}
.{{ $instId }} .p-st-marker-icon { font-size: 18px; font-family: inherit; }

.{{ $instId }} .p-st-title {
  font-size: 17px;
  font-weight: 600;
  letter-spacing: -.01em;
  line-height: 1.3;
  color: {{ $textColor }};
  margin: 0 0 8px;
}
.{{ $instId }} .p-st-desc {
  font-size: 14.5px;
  line-height: 1.55;
  color: {{ $textColorBody }};
  margin: 0;
}

@if($layout === 'horizontal')
/* ===== Horizontal layout ===== */
.{{ $instId }} .p-st-list {
  display: grid;
  grid-template-columns: repeat({{ $stepCount }}, 1fr);
  gap: 24px;
  position: relative;
}
.{{ $instId }} .p-st-item {
  display: flex;
  flex-direction: column;
  align-items: {{ $hAlign === 'center' ? 'center' : 'flex-start' }};
  text-align: {{ $hAlign === 'center' ? 'center' : 'left' }};
  position: relative;
}
@if($connector !== 'none' && $stepCount > 1)
.{{ $instId }} .p-st-item:not(:last-child)::after {
  content: '';
  position: absolute;
  top: 20px; /* center of 40px marker */
  left: calc(50% + 28px);
  right: calc(-50% + 28px);
  height: 2px;
  @if($connector === 'line')
  background: {{ $accentColor }};
  opacity: .4;
  @elseif($connector === 'dots')
  background-image: linear-gradient(90deg, {{ $accentColor }} 33%, transparent 0);
  background-size: 8px 2px;
  background-repeat: repeat-x;
  opacity: .55;
  @elseif($connector === 'arrow')
  background: {{ $accentColor }};
  opacity: .4;
  @endif
}
@if($connector === 'arrow')
.{{ $instId }} .p-st-item:not(:last-child)::before {
  content: '';
  position: absolute;
  top: 14px;
  right: calc(-50% + 28px);
  width: 0; height: 0;
  border-top: 6px solid transparent;
  border-bottom: 6px solid transparent;
  border-left: 8px solid {{ $accentColor }};
  opacity: .55;
  z-index: 1;
}
@endif
@endif
.{{ $instId }} .p-st-marker { margin-bottom: 18px; }
.{{ $instId }} .p-st-item-body { max-width: 220px; }
@media (max-width: 800px) {
  .{{ $instId }} .p-st-list { grid-template-columns: 1fr; gap: 36px; }
  .{{ $instId }} .p-st-item { flex-direction: row; align-items: flex-start; text-align: left; gap: 16px; }
  .{{ $instId }} .p-st-marker { margin-bottom: 0; }
  .{{ $instId }} .p-st-item::after,
  .{{ $instId }} .p-st-item::before { display: none; }
}

@elseif($layout === 'vertical')
/* ===== Vertical layout ===== */
.{{ $instId }} .p-st-list {
  display: flex;
  flex-direction: column;
  gap: 0;
  max-width: 680px;
  margin: 0 auto;
}
.{{ $instId }} .p-st-item {
  display: grid;
  grid-template-columns: 40px 1fr;
  gap: 20px;
  position: relative;
  padding-bottom: 32px;
}
.{{ $instId }} .p-st-item:last-child { padding-bottom: 0; }

@if($connector !== 'none')
.{{ $instId }} .p-st-item:not(:last-child) .p-st-marker-wrap::after {
  content: '';
  position: absolute;
  left: 19px; /* center of 40px - 1px line */
  top: 44px;
  bottom: -4px;
  width: 2px;
  @if($connector === 'line')
  background: {{ $accentColor }};
  opacity: .4;
  @elseif($connector === 'dots')
  background-image: linear-gradient(180deg, {{ $accentColor }} 33%, transparent 0);
  background-size: 2px 8px;
  background-repeat: repeat-y;
  opacity: .55;
  @elseif($connector === 'arrow')
  background: {{ $accentColor }};
  opacity: .4;
  @endif
}
@endif

.{{ $instId }} .p-st-marker-wrap {
  position: relative;
  display: flex;
  justify-content: center;
}
.{{ $instId }} .p-st-item-body { padding-top: 6px; }

@elseif($layout === 'cards')
/* ===== Cards layout ===== */
.{{ $instId }} .p-st-list {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: 18px;
}
.{{ $instId }} .p-st-item {
  background: rgba(0,0,0,0.02);
  border: 1px solid rgba(0,0,0,0.06);
  border-radius: 12px;
  padding: 26px;
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.{{ $instId }} .p-st-marker {
  font-size: 24px;
  width: 48px;
  height: 48px;
}
.{{ $instId }} .p-st-item-body { flex: 1; }
@endif

@if($hideMobile)
@media (max-width: 768px) { .{{ $instId }} { display: none; } }
@endif
@if($hideDesktop)
@media (min-width: 769px) { .{{ $instId }} { display: none; } }
@endif
</style>

<section class="{{ $instId }} p-step-timeline {{ $customClass }}" @if($anchorId) id="{{ $anchorId }}" @endif>
  <div class="p-st-wrap">

    @if(!empty($c['eyebrow']) || !empty($c['heading']) || !empty($c['subheading']))
      <div class="p-st-head">
        @if(!empty($c['eyebrow']))
          <div class="p-st-eyebrow">{{ $c['eyebrow'] }}</div>
        @endif
        @if(!empty($c['heading']))
          <h2 class="p-st-heading">{!! $headingHtml !!}</h2>
        @endif
        @if(!empty($c['subheading']))
          <p class="p-st-sub">{{ $c['subheading'] }}</p>
        @endif
      </div>
    @endif

    @if(!empty($steps))
      <div class="p-st-list">
        @foreach($steps as $i => $step)
          <div class="p-st-item">
            @if($layout === 'vertical')
              {{-- Wrap marker so the connector line can pseudo-element off it --}}
              <div class="p-st-marker-wrap">
                @if($step['icon'] !== '' || $showNumbers)
                  <div class="p-st-marker">
                    @if($step['icon'] !== '')
                      <span class="p-st-marker-icon">{{ $step['icon'] }}</span>
                    @elseif($showNumbers)
                      {{ $i + 1 }}
                    @endif
                  </div>
                @endif
              </div>
            @else
              @if($step['icon'] !== '' || $showNumbers)
                <div class="p-st-marker">
                  @if($step['icon'] !== '')
                    <span class="p-st-marker-icon">{{ $step['icon'] }}</span>
                  @elseif($showNumbers)
                    {{ $i + 1 }}
                  @endif
                </div>
              @endif
            @endif

            <div class="p-st-item-body">
              @if($step['title'] !== '')
                <h3 class="p-st-title">{{ $step['title'] }}</h3>
              @endif
              @if($step['desc'] !== '')
                <p class="p-st-desc">{{ $step['desc'] }}</p>
              @endif
            </div>
          </div>
        @endforeach
      </div>
    @endif

  </div>
</section>
