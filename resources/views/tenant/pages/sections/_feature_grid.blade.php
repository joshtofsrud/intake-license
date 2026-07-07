{{--
  MARKER-PATCH-158-G31 — feature_grid editor (Phase 2)
  Two layouts: standard 'grid' and 'intro_split' (large intro left,
  compact cards right). Per-feature fields: icon, title, price, body,
  optional CTA.
--}}
@php
  $c   = $c ?? ($section->content ?? []);
  $get = fn($k, $d = '') => $c[$k] ?? $d;

  // Features normalize
  $features = $c['features'] ?? [];
  if (is_string($features)) { $d = json_decode($features, true); $features = is_array($d) ? $d : []; }
  if (!is_array($features)) $features = [];
@endphp

<input type="checkbox" data-field="is_visible" value="1" {{ $section->is_visible ? 'checked' : '' }} style="display:none">

{{--=================== CONTENT ===================--}}
<div class="pb2-tab-panel" data-tab="content">
@include('tenant.pages.sections._booking_slot_field') {{-- MARKER-PATCH-602 --}}


  <div class="pb2-group">
    <div class="pb2-group-title">Intro text</div>

    <div class="pb2-field-hint" style="text-align:left;margin-bottom:8px;display:block">
      In "intro split" layout, these appear as the large block on the left.
      In "grid" layout, they sit centered above the cards.
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Eyebrow</label>
      <input type="text" class="pb2-input" data-field="eyebrow" value="{{ $get('eyebrow') }}" placeholder="e.g. SUSPENSION SPECIALTY">
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Heading</label>
      <textarea class="pb2-input pb2-textarea" data-field="heading" rows="2" placeholder="Forks, shocks &amp; dropper posts.">{{ $get('heading') }}</textarea>
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Highlight phrase <span class="pb2-field-hint">rendered in accent / muted color</span></label>
      <input type="text" class="pb2-input" data-field="accent_words" value="{{ $get('accent_words') }}" placeholder="e.g. dropper posts.">
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Body text</label>
      <textarea class="pb2-input pb2-textarea" data-field="subheading" rows="3" placeholder="Out-of-area suspension shipments welcome — we'll send you a label.">{{ $get('subheading') }}</textarea>
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">
      Features
      <span class="pb2-group-meta" id="pb2-feat-count">{{ count($features) }} / 12</span>
    </div>

    <div id="pb2-feat-list">
      @foreach($features as $i => $f)
        <div class="pb2-feat" data-feat-idx="{{ $i }}">
          <div class="pb2-feat-head">
            <span class="pb2-navlist-handle">⋮⋮</span>
            <input type="text" class="pb2-input pb2-input-sm pb2-feat-icon" data-feat-field="icon" value="{{ $f['icon'] ?? '' }}" placeholder="✓" maxlength="4">
            <input type="text" class="pb2-input pb2-input-sm" data-feat-field="title" value="{{ $f['title'] ?? '' }}" placeholder="Title (e.g. Lower service)">
            <button type="button" class="pb2-navlist-remove" data-feat-remove title="Remove">×</button>
          </div>
          <div class="pb2-feat-fields">
            <select class="pb2-input pb2-input-sm pb2-feat-service" data-feat-field="service_id">
              <option value="">— Link a service (optional) —</option>
              @foreach(($services ?? []) as $svc)
                <option value="{{ $svc->id }}"
                        data-name="{{ $svc->name }}"
                        data-price="{{ '$' . number_format($svc->price_cents / 100, $svc->price_cents % 100 ? 2 : 0) }}"
                        data-body="{{ $svc->description }}"
                        {{ (string)($f['service_id'] ?? '') === (string)$svc->id ? 'selected' : '' }}>{{ $svc->name }}</option>
              @endforeach
            </select>
            <input type="text" class="pb2-input pb2-input-sm" data-feat-field="price" value="{{ $f['price'] ?? '' }}" placeholder="Price (optional, e.g. $90 & up)">
            <textarea class="pb2-input pb2-input-sm pb2-textarea" data-feat-field="body" rows="2" placeholder="Description">{{ $f['body'] ?? '' }}</textarea>
            <div class="pb2-feat-cta-row">
              <input type="text" class="pb2-input pb2-input-sm" data-feat-field="cta_label" value="{{ $f['cta_label'] ?? '' }}" placeholder="Optional CTA label">
              <input type="text" class="pb2-input pb2-input-sm" data-feat-field="cta_url" value="{{ $f['cta_url'] ?? '' }}" placeholder="/url">
            </div>
          </div>
        </div>
      @endforeach
    </div>

    <button type="button" class="pb2-addrow" id="pb2-feat-add">+ Add feature</button>

    <input type="hidden" data-field="features" id="pb2-feat-json" value="{{ json_encode($features) }}">

    {{-- MARKER-PATCH-293 — option source cloned into new cards by the add-JS --}}
    <select id="pb2-feat-service-template" style="display:none">
      <option value="">— Link a service (optional) —</option>
      @foreach(($services ?? []) as $svc)
        <option value="{{ $svc->id }}"
                data-name="{{ $svc->name }}"
                data-price="{{ '$' . number_format($svc->price_cents / 100, $svc->price_cents % 100 ? 2 : 0) }}"
                data-body="{{ $svc->description }}">{{ $svc->name }}</option>
      @endforeach
    </select>
  </div>

</div>

{{--=================== LAYOUT ===================--}}
<div class="pb2-tab-panel" data-tab="content">

  <div class="pb2-group">
    <div class="pb2-group-title">Arrangement</div>

    <div class="pb2-field">
      <label class="pb2-field-label">Layout</label>
      <select class="pb2-input" data-field="layout">
        @foreach(['grid'=>'Grid (intro above, even cells below)','intro_split'=>'Intro split (intro left, cards right)'] as $v => $n)
          <option value="{{ $v }}" {{ $get('layout', 'grid') === $v ? 'selected' : '' }}>{{ $n }}</option>
        @endforeach
      </select>
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Columns <span class="pb2-field-hint">grid layout only</span></label>
      <div class="pb2-seg" data-field-seg="columns">
        @foreach([2=>'2',3=>'3',4=>'4'] as $val => $name)
          <button type="button" class="pb2-seg-btn {{ (int)$get('columns', 3) === $val ? 'active' : '' }}" data-seg-value="{{ $val }}">{{ $name }}</button>
        @endforeach
      </div>
      <input type="hidden" data-field="columns" value="{{ $get('columns', 3) }}">
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Card style</label>
      <select class="pb2-input" data-field="card_style">
        @foreach(['card'=>'Card (bordered)','minimal'=>'Minimal (no border)'] as $v => $n)
          <option value="{{ $v }}" {{ $get('card_style', 'card') === $v ? 'selected' : '' }}>{{ $n }}</option>
        @endforeach
      </select>
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Show icons</label>
      <label class="pb2-checkbox-row">
        <input type="checkbox" data-field="show_icons" value="1" {{ $get('show_icons', true) ? 'checked' : '' }}>
        <span>Show icon column</span>
      </label>
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Alignment & spacing</div>

    <div class="pb2-field">
      <label class="pb2-field-label">Heading alignment <span class="pb2-field-hint">grid layout only</span></label>
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
      {{-- MARKER-PATCH-271 — content width (max content area; container stays centered with a side gutter) --}}
      <div class="pb2-field">
        <div class="pb2-slider-row">
          <label class="pb2-field-label" style="margin:0">Content width</label>
          <span class="pb2-slider-value pb2-cw-val">{{ $get('content_max_width', 1200) }}px</span>
        </div>
        <input type="range" min="480" max="1600" step="20" value="{{ $get('content_max_width', 1200) }}" data-field="content_max_width" oninput="this.parentNode.querySelector('.pb2-cw-val').textContent=this.value+'px'">
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
      <label class="pb2-field-label">Accent <span class="pb2-field-hint">eyebrow, price, icons</span></label>
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
      <input type="text" class="pb2-input pb2-input-mono" data-field="anchor_id" value="{{ $get('anchor_id') }}" placeholder="e.g. features">
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

