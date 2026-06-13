{{-- MARKER-PATCH-158-G22 — services public renderer (v2) --}}
@php
  $c = $c ?? [];

  // Layout
  $cols       = (int)($c['columns'] ?? 3);
  if ($cols < 1 || $cols > 4) $cols = 3;
  $cardStyle  = $c['card_style'] ?? 'card';
  $showHeaders= (bool)($c['show_category_headers'] ?? true);
  $showPrices = (bool)($c['show_prices'] ?? true);
  $showDescs  = (bool)($c['show_descriptions'] ?? true);
  $showAddons = (bool)($c['show_addons'] ?? false);
  $hAlign     = $c['text_align'] ?? 'left';

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
  $textColorBody = ($c['text_color_body'] ?? '') ?: 'rgba(0,0,0,0.6)';
  $accentColor   = ($c['accent_color']    ?? '') ?: null;
  $cardBg        = ($c['card_bg']         ?? '') ?: null;
  $cardBorder    = ($c['card_border']     ?? '') ?: null;
  $cardHover     = $c['card_hover_effect'] ?? 'lift';

  // Heading with accent phrase
  $headingHtml = e($c['heading'] ?? '');
  $accentWords = trim($c['accent_words'] ?? '');
  if ($accentWords !== '' && stripos($headingHtml, e($accentWords)) !== false) {
      $headingHtml = str_ireplace(
          e($accentWords),
          '<span class="p-svc-accent">' . e($accentWords) . '</span>',
          $headingHtml
      );
  }

  // Category filter
  $selectedIds = $c['category_ids'] ?? [];
  if (is_string($selectedIds)) { $d = json_decode($selectedIds, true); $selectedIds = is_array($d) ? $d : []; }
  if (!is_array($selectedIds)) $selectedIds = [];

  // Apply filter if any IDs are selected; otherwise show all
  $filteredCatalog = isset($catalog)
      ? (empty($selectedIds) ? $catalog : $catalog->whereIn('id', $selectedIds)->values())
      : collect();

  // Max per category
  $maxPerCat = (int)($c['max_per_category'] ?? 0);

  // Advanced
  $anchorId    = trim($c['anchor_id'] ?? '');
  $customClass = trim($c['custom_classes'] ?? '');
  $hideMobile  = !empty($c['hide_on_mobile']);
  $hideDesktop = !empty($c['hide_on_desktop']);

  $instId = 'p-svc-' . ($section->id ?? uniqid());

  // Card style mapping
  $cardBgVal     = $cardBg     ?? '#ffffff';
  $cardBorderVal = $cardBorder ?? 'rgba(0,0,0,0.1)';
@endphp

<style>
.{{ $instId }} {
  padding-top: {{ $padTop }};
  padding-bottom: {{ $padBot }};
  @if($bgMode === 'color') background: {{ $bgColor }};
  @elseif($bgMode === 'gradient') background: linear-gradient({{ (int)($c['bg_gradient_angle'] ?? 135) }}deg, {{ $gradF }} 0%, {{ $gradT }} 100%);
  @endif
}
.{{ $instId }} .p-svc-wrap {
  max-width: 1200px;
  margin: 0 auto;
  padding: 0 clamp(20px, 5vw, 48px);
}
.{{ $instId }} .p-svc-head {
  text-align: {{ $hAlign }};
  margin-bottom: 40px;
  color: {{ $textColor }};
}
.{{ $instId }} .p-svc-eyebrow {
  font-family: ui-monospace, monospace;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  color: {{ $accentColor ?? $textColor }};
  margin-bottom: 12px;
  opacity: .9;
}
.{{ $instId }} .p-svc-heading {
  font-size: clamp(22px, 3vw, 36px);
  font-weight: 500;
  letter-spacing: -.02em;
  margin: 0 0 12px;
  line-height: 1.15;
  color: {{ $textColor }};
}
.{{ $instId }} .p-svc-accent { color: {{ $accentColor ?? '#BEF264' }}; }
.{{ $instId }} .p-svc-sub {
  font-size: 16px;
  line-height: 1.6;
  color: {{ $textColorBody }};
  margin: 0;
  max-width: 640px;
  @if($hAlign === 'center') margin-left: auto; margin-right: auto;
  @endif
}

.{{ $instId }} .p-svc-cat {
  margin-bottom: 48px;
}
.{{ $instId }} .p-svc-cat-name {
  font-family: ui-monospace, monospace;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  font-weight: 500;
  color: {{ $textColorBody }};
  margin-bottom: 18px;
}

.{{ $instId }} .p-svc-grid {
  display: grid;
  grid-template-columns: repeat({{ $cols }}, 1fr);
  gap: 16px;
}
@if($cardStyle === 'list')
.{{ $instId }} .p-svc-grid { grid-template-columns: 1fr; gap: 0; }
@endif

.{{ $instId }} .p-svc-card {
  padding: 22px;
  border-radius: 10px;
  transition: all 0.18s;
  color: {{ $textColor }};
  @if($cardStyle === 'card')
  background: {{ $cardBgVal }};
  border: 1px solid {{ $cardBorderVal }};
  @elseif($cardStyle === 'list')
  border-bottom: 1px solid {{ $cardBorderVal }};
  border-radius: 0;
  padding: 18px 0;
  @elseif($cardStyle === 'minimal')
  padding: 0;
  @endif
}
@if($cardStyle === 'card' && $cardHover === 'lift')
.{{ $instId }} .p-svc-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,0.06); }
@elseif($cardStyle === 'card' && $cardHover === 'accent-border')
.{{ $instId }} .p-svc-card:hover { border-color: {{ $accentColor ?? '#BEF264' }}; }
@endif

.{{ $instId }} .p-svc-item-name {
  font-size: 17px;
  font-weight: 500;
  line-height: 1.3;
  margin: 0 0 6px;
  color: {{ $textColor }};
}
.{{ $instId }} .p-svc-desc {
  font-size: 14px;
  line-height: 1.55;
  color: {{ $textColorBody }};
  margin: 0 0 12px;
}
.{{ $instId }} .p-svc-price {
  font-size: 15px;
  font-weight: 500;
  color: {{ $accentColor ?? $textColor }};
  margin-top: auto;
}
.{{ $instId }} .p-svc-addons {
  margin-top: 10px;
  padding-top: 10px;
  border-top: 1px dashed {{ $cardBorderVal }};
}
.{{ $instId }} .p-svc-addon-row {
  display: flex;
  justify-content: space-between;
  font-size: 12px;
  color: {{ $textColorBody }};
  padding: 3px 0;
}
.{{ $instId }} .p-svc-empty {
  font-size: 14px;
  color: {{ $textColorBody }};
  text-align: {{ $hAlign }};
  padding: 24px 0;
}

@media (max-width: 720px) { .{{ $instId }} .p-svc-grid { grid-template-columns: 1fr 1fr; } }
@media (max-width: 480px) { .{{ $instId }} .p-svc-grid { grid-template-columns: 1fr; } }

@if($hideMobile)
@media (max-width: 768px) { .{{ $instId }} { display: none; } }
@endif
@if($hideDesktop)
@media (min-width: 769px) { .{{ $instId }} { display: none; } }
@endif
</style>

<section class="{{ $instId }} p-services {{ $customClass }}" @if($anchorId) id="{{ $anchorId }}" @endif>
  <div class="p-svc-wrap">

    @if(!empty($c['eyebrow']) || !empty($c['heading']) || !empty($c['subheading']))
      <div class="p-svc-head">
        @if(!empty($c['eyebrow']))
          <div class="p-svc-eyebrow">{{ $c['eyebrow'] }}</div>
        @endif
        @if(!empty($c['heading']))
          <h2 class="p-svc-heading">{!! $headingHtml !!}</h2>
        @endif
        @if(!empty($c['subheading']))
          <p class="p-svc-sub">{{ $c['subheading'] }}</p>
        @endif
      </div>
    @endif

    @php
      // Determine if there's anything to show after filtering/limits
      $hasAny = false;
      foreach ($filteredCatalog as $cat) {
          if ($cat->items && $cat->items->isNotEmpty()) { $hasAny = true; break; }
      }
    @endphp

    @if(!$hasAny)
      <p class="p-svc-empty">{{ $c['empty_state_text'] ?? 'No services available yet.' }}</p>
    @else
      @foreach($filteredCatalog as $category)
        @php
          $items = $category->items ?? collect();
          if ($maxPerCat > 0) $items = $items->take($maxPerCat);
        @endphp
        @if($items->isNotEmpty())
          <div class="p-svc-cat">
            @if($showHeaders)
              <h3 class="p-svc-cat-name">{{ $category->name }}</h3>
            @endif

            <div class="p-svc-grid">
              @foreach($items as $item)
                <div class="p-svc-card">
                  <div class="p-svc-item-name">{{ $item->name }}</div>
                  @if($showDescs && !empty($item->description))
                    <p class="p-svc-desc">{{ $item->description }}</p>
                  @endif
                  @if($showPrices && $item->price_cents !== null)
                    <div class="p-svc-price">{{ format_money($item->price_cents) }}</div>
                  @endif
                  @if($showAddons && $item->serviceAddons && $item->serviceAddons->isNotEmpty())
                    <div class="p-svc-addons">
                      @foreach($item->serviceAddons as $sa)
                        @if($sa->addon)
                          <div class="p-svc-addon-row">
                            <span>+ {{ $sa->addon->name }}</span>
                            @if($showPrices && $sa->addon->price_cents !== null)
                              <span>{{ format_money($sa->addon->price_cents) }}</span>
                            @endif
                          </div>
                        @endif
                      @endforeach
                    </div>
                  @endif
                </div>
              @endforeach
            </div>
          </div>
        @endif
      @endforeach
    @endif

  </div>
</section>
