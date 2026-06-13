{{-- MARKER-PATCH-158-G33 — faq_accordion public renderer (v2) --}}
@php
  $c = $c ?? [];

  // Items normalize — v1 used q/a keys, v2 uses question/answer (with backward compat)
  $rawItems = $c['items'] ?? [];
  if (is_string($rawItems)) { $d = json_decode($rawItems, true); $rawItems = is_array($d) ? $d : []; }
  if (!is_array($rawItems)) $rawItems = [];

  $items = [];
  foreach ($rawItems as $item) {
      if (!is_array($item)) continue;
      $q = trim($item['question'] ?? ($item['q'] ?? ''));
      $a = trim($item['answer']   ?? ($item['a'] ?? ''));
      if ($q === '' && $a === '') continue;
      $items[] = [
          'question'     => $q,
          'answer'       => $a,
          'open_default' => !empty($item['open_default']),
      ];
  }

  // Layout
  $openMode  = $c['open_mode']  ?? 'multiple';   // multiple | single
  $style     = $c['style']      ?? 'divider';    // bordered | divider | minimal
  $width     = $c['width']      ?? 'medium';
  $iconStyle = $c['icon_style'] ?? 'chevron';    // chevron | plus | arrow | none
  $hAlign    = $c['text_align'] ?? 'center';

  $widthMap = ['narrow'=>'640px','medium'=>'800px','wide'=>'960px'];
  $wrapMax  = $widthMap[$width] ?? '800px';

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
          '<span class="p-faq-accent">' . e($accentWords) . '</span>',
          $headingHtml
      );
  }

  // Advanced
  $anchorId    = trim($c['anchor_id'] ?? '');
  $customClass = trim($c['custom_classes'] ?? '');
  $hideMobile  = !empty($c['hide_on_mobile']);
  $hideDesktop = !empty($c['hide_on_desktop']);

  $instId = 'p-faq-' . ($section->id ?? uniqid());

  // Generate group name (for single-mode it doubles as a "named group" via JS)
  $groupName = 'faq-group-' . ($section->id ?? uniqid());
@endphp

<style>
.{{ $instId }} {
  padding-top: {{ $padTop }};
  padding-bottom: {{ $padBot }};
  @if($bgMode === 'color') background: {{ $bgColor }};
  @elseif($bgMode === 'gradient') background: linear-gradient({{ (int)($c['bg_gradient_angle'] ?? 135) }}deg, {{ $gradF }} 0%, {{ $gradT }} 100%);
  @endif
}
.{{ $instId }} .p-faq-wrap {
  max-width: {{ $wrapMax }};
  margin: 0 auto;
  padding: 0 clamp(20px, 5vw, 32px);
}
.{{ $instId }} .p-faq-head {
  text-align: {{ $hAlign }};
  margin-bottom: 40px;
  color: {{ $textColor }};
}
.{{ $instId }} .p-faq-eyebrow {
  font-family: ui-monospace, monospace;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  color: {{ $accentColor }};
  margin-bottom: 12px;
  opacity: .95;
}
.{{ $instId }} .p-faq-heading {
  font-size: clamp(24px, 3vw, 38px);
  font-weight: 500;
  letter-spacing: -.02em;
  margin: 0 0 12px;
  line-height: 1.15;
  color: {{ $textColor }};
}
.{{ $instId }} .p-faq-accent { color: {{ $accentColor }}; }
.{{ $instId }} .p-faq-sub {
  font-size: 16px;
  line-height: 1.55;
  color: {{ $textColorBody }};
  margin: 0;
  max-width: 580px;
  @if($hAlign === 'center') margin-left: auto; margin-right: auto; @endif
}

/* List container */
.{{ $instId }} .p-faq-list {
  display: flex;
  flex-direction: column;
  @if($style === 'bordered')
  gap: 12px;
  @else
  gap: 0;
  @endif
}

/* Item */
.{{ $instId }} .p-faq-item {
  @if($style === 'bordered')
  background: rgba(0,0,0,0.02);
  border: 1px solid rgba(0,0,0,0.08);
  border-radius: 10px;
  padding: 0;
  @elseif($style === 'divider')
  border-top: 1px solid rgba(0,0,0,0.08);
  @endif
}
.{{ $instId }} .p-faq-item:last-child {
  @if($style === 'divider') border-bottom: 1px solid rgba(0,0,0,0.08); @endif
}

/* Question (summary) */
.{{ $instId }} .p-faq-q {
  list-style: none;
  cursor: pointer;
  padding: {{ $style === 'bordered' ? '18px 22px' : '20px 4px' }};
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  font-size: 16px;
  font-weight: 500;
  color: {{ $textColor }};
  line-height: 1.4;
  transition: color 0.15s;
}
.{{ $instId }} .p-faq-q::-webkit-details-marker { display: none; }
.{{ $instId }} .p-faq-q:hover { color: {{ $accentColor }}; }

.{{ $instId }} .p-faq-icon {
  flex-shrink: 0;
  width: 18px; height: 18px;
  color: {{ $textColorBody }};
  transition: transform 0.2s, color 0.15s;
}
.{{ $instId }} .p-faq-item[open] .p-faq-icon {
  color: {{ $accentColor }};
  @if($iconStyle === 'chevron' || $iconStyle === 'arrow')
  transform: rotate(180deg);
  @endif
}
.{{ $instId }} .p-faq-icon--plus { display: inline-flex; align-items: center; justify-content: center; font-size: 18px; font-weight: 300; line-height: 1; width: 18px; height: 18px; }
.{{ $instId }} .p-faq-item[open] .p-faq-icon--plus::before { content: '−'; }
.{{ $instId }} .p-faq-item:not([open]) .p-faq-icon--plus::before { content: '+'; }

/* Answer */
.{{ $instId }} .p-faq-a {
  padding: {{ $style === 'bordered' ? '0 22px 20px' : '0 4px 22px' }};
  font-size: 15px;
  line-height: 1.65;
  color: {{ $textColorBody }};
  white-space: pre-wrap;
  margin: 0;
}

@if($hideMobile)
@media (max-width: 768px) { .{{ $instId }} { display: none; } }
@endif
@if($hideDesktop)
@media (min-width: 769px) { .{{ $instId }} { display: none; } }
@endif
</style>

<section class="{{ $instId }} p-faq {{ $customClass }}" @if($anchorId) id="{{ $anchorId }}" @endif>
  <div class="p-faq-wrap">

    @if(!empty($c['eyebrow']) || !empty($c['heading']) || !empty($c['subheading']))
      <div class="p-faq-head">
        @if(!empty($c['eyebrow']))
          <div class="p-faq-eyebrow">{{ $c['eyebrow'] }}</div>
        @endif
        @if(!empty($c['heading']))
          <h2 class="p-faq-heading">{!! $headingHtml !!}</h2>
        @endif
        @if(!empty($c['subheading']))
          <p class="p-faq-sub">{{ $c['subheading'] }}</p>
        @endif
      </div>
    @endif

    @if(!empty($items))
      <div class="p-faq-list" data-faq-mode="{{ $openMode }}">
        @foreach($items as $item)
          <details class="p-faq-item" @if($item['open_default']) open @endif>
            <summary class="p-faq-q">
              <span>{{ $item['question'] }}</span>
              @if($iconStyle === 'chevron')
                <svg class="p-faq-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <polyline points="6 9 12 15 18 9"/>
                </svg>
              @elseif($iconStyle === 'plus')
                <span class="p-faq-icon p-faq-icon--plus" aria-hidden="true"></span>
              @elseif($iconStyle === 'arrow')
                <svg class="p-faq-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <line x1="5" y1="12" x2="19" y2="12"/>
                  <polyline points="12 5 19 12 12 19"/>
                </svg>
              @endif
            </summary>
            @if($item['answer'] !== '')
              <p class="p-faq-a">{{ $item['answer'] }}</p>
            @endif
          </details>
        @endforeach
      </div>

      @if($openMode === 'single')
        <script>
        // MARKER-PATCH-158-G33 — single-open enforcement. Closes other items when
        // one is opened. Scoped to this section instance to avoid bleed.
        (function() {
          var list = document.currentScript.previousElementSibling;
          if (!list || !list.matches('.p-faq-list')) return;
          var items = list.querySelectorAll('.p-faq-item');
          items.forEach(function(d) {
            d.addEventListener('toggle', function() {
              if (d.open) {
                items.forEach(function(o) { if (o !== d && o.open) o.open = false; });
              }
            });
          });
        })();
        </script>
      @endif
    @endif

  </div>
</section>
