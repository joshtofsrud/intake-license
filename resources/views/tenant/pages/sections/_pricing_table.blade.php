{{--
  MARKER-PATCH-158-G30 — pricing_table editor (Phase 2)

  Plans list editor: each plan is a card with eyebrow / title / price /
  suffix / features (sub-list) / featured toggle / optional CTA. Bespoke
  editor because the shape is too deep for the button list framework.
--}}
@php
  $c   = $c ?? ($section->content ?? []);
  $get = fn($k, $d = '') => $c[$k] ?? $d;

  // Plans normalize
  $plans = $c['plans'] ?? [];
  if (is_string($plans)) { $d = json_decode($plans, true); $plans = is_array($d) ? $d : []; }
  if (!is_array($plans)) $plans = [];

  // Seed default if blank
  if (empty($plans)) {
      $plans = [
          ['eyebrow'=>'01 · BASIC','title'=>'Basic','price'=>'$0','price_suffix'=>'','featured'=>false,'features'=>['Feature one','Feature two'],'cta_label'=>'','cta_url'=>''],
      ];
  }
@endphp

<input type="checkbox" data-field="is_visible" value="1" {{ $section->is_visible ? 'checked' : '' }} style="display:none">

{{--=================== CONTENT ===================--}}
<div class="pb2-tab-panel" data-tab="content">

  <div class="pb2-group">
    <div class="pb2-group-title">Text</div>

    <div class="pb2-field">
      <label class="pb2-field-label">Eyebrow</label>
      <input type="text" class="pb2-input" data-field="eyebrow" value="{{ $get('eyebrow') }}" placeholder="Optional kicker">
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Heading</label>
      <input type="text" class="pb2-input" data-field="heading" value="{{ $get('heading') }}" placeholder="Optional heading above plans">
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Highlight phrase</label>
      <input type="text" class="pb2-input" data-field="accent_words" value="{{ $get('accent_words') }}" placeholder="Optional">
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Subheading</label>
      <textarea class="pb2-input pb2-textarea" data-field="subheading" rows="2" placeholder="Optional supporting text">{{ $get('subheading') }}</textarea>
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Footnote <span class="pb2-field-hint">small text below cards</span></label>
      <input type="text" class="pb2-input" data-field="footnote" value="{{ $get('footnote') }}" placeholder="e.g. Prices include parts & labor">
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">
      Plans
      <span class="pb2-group-meta" id="pb2-plans-count">{{ count($plans) }} / 6</span>
    </div>

    <div class="pb2-field-hint" style="text-align:left;margin-bottom:8px;display:block">
      Toggle "Featured" on one plan to give it the accent border and badge.
    </div>

    <div id="pb2-plans-list">
      @foreach($plans as $i => $p)
        <div class="pb2-plan" data-plan-idx="{{ $i }}">
          <div class="pb2-plan-head">
            <span class="pb2-navlist-handle">⋮⋮</span>
            <span class="pb2-plan-pos">Plan {{ $i + 1 }}</span>
            <label class="pb2-plan-featured" title="Mark featured (only one plan can be featured)">
              <input type="checkbox" data-plan-field="featured" {{ ($p['featured'] ?? false) ? 'checked' : '' }}>
              <span>★ Featured</span>
            </label>
            <button type="button" class="pb2-navlist-remove" data-plan-remove title="Remove plan">×</button>
          </div>

          <div class="pb2-plan-fields">
            <input type="text" class="pb2-input pb2-input-sm" data-plan-field="eyebrow" value="{{ $p['eyebrow'] ?? '' }}" placeholder="01 · BASIC">
            <input type="text" class="pb2-input pb2-input-sm" data-plan-field="title" value="{{ $p['title'] ?? '' }}" placeholder="Plan name">

            <div class="pb2-plan-price-row">
              <input type="text" class="pb2-input pb2-input-sm" data-plan-field="price" value="{{ $p['price'] ?? '' }}" placeholder="$90">
              <input type="text" class="pb2-input pb2-input-sm" data-plan-field="price_suffix" value="{{ $p['price_suffix'] ?? '' }}" placeholder="& up">
            </div>

            <input type="text" class="pb2-input pb2-input-sm" data-plan-field="badge_label" value="{{ $p['badge_label'] ?? '' }}" placeholder="Badge label (e.g. MOST BOOKED) — only shown when featured">

            <div class="pb2-plan-features">
              <div class="pb2-plan-features-label">Features</div>
              <div class="pb2-plan-feature-list">
                @foreach((array)($p['features'] ?? []) as $f)
                  <div class="pb2-plan-feature">
                    <input type="text" class="pb2-input pb2-input-sm" data-feat-field="text" value="{{ is_string($f) ? $f : ($f['text'] ?? '') }}" placeholder="Feature text">
                    <button type="button" class="pb2-navlist-remove" data-feat-remove title="Remove">×</button>
                  </div>
                @endforeach
              </div>
              <button type="button" class="pb2-addrow pb2-plan-addfeat" data-plan-addfeat>+ Add feature</button>
            </div>

            <div class="pb2-plan-cta-row">
              <input type="text" class="pb2-input pb2-input-sm" data-plan-field="cta_label" value="{{ $p['cta_label'] ?? '' }}" placeholder="Optional CTA label">
              <input type="text" class="pb2-input pb2-input-sm" data-plan-field="cta_url" value="{{ $p['cta_url'] ?? '' }}" placeholder="/url">
            </div>
          </div>
        </div>
      @endforeach
    </div>

    <button type="button" class="pb2-addrow" id="pb2-plans-add">+ Add plan</button>

    <input type="hidden" data-field="plans" id="pb2-plans-json" value="{{ json_encode($plans) }}">
  </div>

</div>

{{--=================== LAYOUT ===================--}}
<div class="pb2-tab-panel" data-tab="content">

  <div class="pb2-group">
    <div class="pb2-group-title">Grid</div>

    <div class="pb2-field">
      <label class="pb2-field-label">Columns</label>
      <select class="pb2-input" data-field="columns">
        @foreach(['auto'=>'Auto (fit to plans)','2'=>'2','3'=>'3','4'=>'4'] as $v => $n)
          <option value="{{ $v }}" {{ (string)$get('columns', 'auto') === $v ? 'selected' : '' }}>{{ $n }}</option>
        @endforeach
      </select>
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Featured card emphasis</label>
      <select class="pb2-input" data-field="featured_style">
        @foreach(['border'=>'Accent border + badge','elevated'=>'Raised + accent badge','scale'=>'Slightly larger'] as $v => $n)
          <option value="{{ $v }}" {{ $get('featured_style', 'border') === $v ? 'selected' : '' }}>{{ $n }}</option>
        @endforeach
      </select>
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Card dividers between features</label>
      <select class="pb2-input" data-field="feature_divider">
        @foreach(['none'=>'None','solid'=>'Solid hairline','dashed'=>'Dashed hairline'] as $v => $n)
          <option value="{{ $v }}" {{ $get('feature_divider', 'dashed') === $v ? 'selected' : '' }}>{{ $n }}</option>
        @endforeach
      </select>
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Heading & spacing</div>

    <div class="pb2-field">
      <label class="pb2-field-label">Heading alignment</label>
      <div class="pb2-seg" data-field-seg="text_align">
        @foreach(['left'=>'Left','center'=>'Center'] as $val => $name)
          <button type="button" class="pb2-seg-btn {{ $get('text_align', 'center') === $val ? 'active' : '' }}" data-seg-value="{{ $val }}">{{ $name }}</button>
        @endforeach
      </div>
      <input type="hidden" data-field="text_align" value="{{ $get('text_align', 'center') }}">
    </div>

    <div class="pb2-field-row">
      <div class="pb2-field">
        <label class="pb2-field-label">Padding top</label>
        <select class="pb2-input" data-field="padding_top">
          @foreach(['none'=>'None','compact'=>'Compact','normal'=>'Normal','spacious'=>'Spacious'] as $v => $n)
            <option value="{{ $v }}" {{ $get('padding_top', 'normal') === $v ? 'selected' : '' }}>{{ $n }}</option>
          @endforeach
        </select>
      </div>
      <div class="pb2-field">
        <label class="pb2-field-label">Padding bottom</label>
        <select class="pb2-input" data-field="padding_bottom">
          @foreach(['none'=>'None','compact'=>'Compact','normal'=>'Normal','spacious'=>'Spacious'] as $v => $n)
            <option value="{{ $v }}" {{ $get('padding_bottom', 'normal') === $v ? 'selected' : '' }}>{{ $n }}</option>
          @endforeach
        </select>
      </div>
    </div>
  </div>

</div>

{{--=================== STYLE ===================--}}
<div class="pb2-tab-panel" data-tab="style" hidden>

  <div class="pb2-group">
    <div class="pb2-group-title">Section background</div>

    <div class="pb2-field">
      <div class="pb2-seg" data-field-seg="bg_mode">
        @foreach(['none'=>'None','color'=>'Color','gradient'=>'Gradient'] as $val => $name)
          <button type="button" class="pb2-seg-btn {{ $get('bg_mode', 'none') === $val ? 'active' : '' }}" data-seg-value="{{ $val }}">{{ $name }}</button>
        @endforeach
      </div>
      <input type="hidden" data-field="bg_mode" value="{{ $get('bg_mode', 'none') }}">
    </div>

    <div class="pb2-bg-pane" data-bg-mode="color">
      <div class="pb2-field">
        <label class="pb2-field-label">Background color</label>
        <div class="pb2-color-row">
          <input type="color" data-field="bg_color" value="{{ $get('bg_color', '#0a0f1a') }}" class="pb2-color-swatch">
          <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="bg_color_text" value="{{ $get('bg_color') }}">
        </div>
      </div>
    </div>

    <div class="pb2-bg-pane" data-bg-mode="gradient">
        {{-- MARKER-PATCH-269 — gradient angle --}}
        <div class="pb2-field">
          <div class="pb2-slider-row">
            <label class="pb2-field-label" style="margin:0">Angle</label>
            <span class="pb2-slider-value pb2-grad-deg">{{ $get('bg_gradient_angle', 135) }}°</span>
          </div>
          <input type="range" min="0" max="360" value="{{ $get('bg_gradient_angle', 135) }}" data-field="bg_gradient_angle" oninput="this.parentNode.querySelector('.pb2-grad-deg').textContent=this.value+'°'">
        </div>
      <div class="pb2-field-row">
        <div class="pb2-field">
          <label class="pb2-field-label">From</label>
          <div class="pb2-color-row">
            <input type="color" data-field="bg_gradient_from" value="{{ $get('bg_gradient_from', '#0a0f1a') }}" class="pb2-color-swatch">
            <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="bg_gradient_from_text" value="{{ $get('bg_gradient_from') }}">
          </div>
        </div>
        <div class="pb2-field">
          <label class="pb2-field-label">To</label>
          <div class="pb2-color-row">
            <input type="color" data-field="bg_gradient_to" value="{{ $get('bg_gradient_to', '#0f1828') }}" class="pb2-color-swatch">
            <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="bg_gradient_to_text" value="{{ $get('bg_gradient_to') }}">
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Cards</div>

    <div class="pb2-field">
      <label class="pb2-field-label">Card background</label>
      <div class="pb2-color-row">
        <input type="color" data-field="card_bg" value="{{ $get('card_bg') ?: '#111a2b' }}" class="pb2-color-swatch">
        <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="card_bg_text" value="{{ $get('card_bg') }}" placeholder="auto">
      </div>
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Card border</label>
      <div class="pb2-color-row">
        <input type="color" data-field="card_border" value="{{ $get('card_border') ?: '#1f2940' }}" class="pb2-color-swatch">
        <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="card_border_text" value="{{ $get('card_border') }}" placeholder="auto">
      </div>
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Text & accent</div>

    <div class="pb2-field-row">
      <div class="pb2-field">
        <label class="pb2-field-label">Heading</label>
        <div class="pb2-color-row">
          <input type="color" data-field="text_color" value="{{ $get('text_color') ?: '#ffffff' }}" class="pb2-color-swatch">
          <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="text_color_text" value="{{ $get('text_color') }}" placeholder="auto">
        </div>
      </div>
      <div class="pb2-field">
        <label class="pb2-field-label">Body</label>
        <div class="pb2-color-row">
          <input type="color" data-field="text_color_body" value="{{ $get('text_color_body') ?: '#aab2c5' }}" class="pb2-color-swatch">
          <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="text_color_body_text" value="{{ $get('text_color_body') }}" placeholder="auto">
        </div>
      </div>
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Accent <span class="pb2-field-hint">price, checkmark, featured border</span></label>
      <div class="pb2-color-row">
        <input type="color" data-field="accent_color" value="{{ $get('accent_color') ?: '#BEF264' }}" class="pb2-color-swatch">
        <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="accent_color_text" value="{{ $get('accent_color') }}" placeholder="theme default">
      </div>
    </div>
  </div>

</div>

{{--=================== ADVANCED ===================--}}
<div class="pb2-tab-panel" data-tab="advanced" hidden>
  <div class="pb2-group">
    <div class="pb2-group-title">Anchor & classes</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Anchor ID</label>
      <input type="text" class="pb2-input pb2-input-mono" data-field="anchor_id" value="{{ $get('anchor_id') }}" placeholder="e.g. pricing">
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">Custom classes</label>
      <input type="text" class="pb2-input pb2-input-mono" data-field="custom_classes" value="{{ $get('custom_classes') }}" placeholder="space-separated">
    </div>
  </div>
  <div class="pb2-group">
    <div class="pb2-group-title">Visibility</div>
    <label class="pb2-checkbox-row">
      <input type="checkbox" data-field="hide_on_mobile" value="1" {{ $get('hide_on_mobile') ? 'checked' : '' }}>
      <span>Hide on mobile</span>
    </label>
    <label class="pb2-checkbox-row">
      <input type="checkbox" data-field="hide_on_desktop" value="1" {{ $get('hide_on_desktop') ? 'checked' : '' }}>
      <span>Hide on desktop</span>
    </label>
  </div>
</div>
