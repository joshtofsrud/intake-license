{{--
  MARKER-PATCH-158-G20 — text_image editor (Phase 2)
  4-tab editor reusing the Hero framework. Buttons list now supports
  multiple buttons (was a single cta_label/cta_url pair in v1).
--}}
@php
  $c   = $c ?? ($section->content ?? []);
  $get = fn($k, $d = '') => $c[$k] ?? $d;

  // Buttons array — normalize JSON string if needed
  $buttons = $c['buttons'] ?? [];
  if (is_string($buttons)) { $decoded = json_decode($buttons, true); $buttons = is_array($decoded) ? $decoded : []; }
  if (!is_array($buttons)) $buttons = [];

  // Backward compat: if buttons[] empty AND legacy cta_label set, synthesize one
  if (empty($buttons) && !empty($c['cta_label'] ?? '')) {
      $buttons = [['label' => $c['cta_label'], 'url' => $c['cta_url'] ?? '#', 'style' => 'primary']];
  }
@endphp

<input type="checkbox" data-field="is_visible" value="1" {{ $section->is_visible ? 'checked' : '' }} style="display:none">

{{--=================== CONTENT ===================--}}
<div class="pb2-tab-panel" data-tab="content">

  <div class="pb2-group">
    <div class="pb2-group-title">Text</div>

    <div class="pb2-field">
      <label class="pb2-field-label">Eyebrow <span class="pb2-field-hint">small label above heading</span></label>
      <input type="text" class="pb2-input" data-field="eyebrow" value="{{ $get('eyebrow') }}" placeholder="Optional kicker text">
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Heading</label>
      <input type="text" class="pb2-input" data-field="heading" value="{{ $get('heading') }}" placeholder="Your section heading">
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Highlight phrase <span class="pb2-field-hint">accent color inside heading</span></label>
      <input type="text" class="pb2-input" data-field="accent_words" value="{{ $get('accent_words') }}" placeholder="Optional">
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Body <span class="pb2-field-hint">line breaks preserved</span></label>
      <textarea class="pb2-input pb2-textarea" data-field="body" rows="6" placeholder="Your content. Multiple lines welcome.">{{ $get('body') }}</textarea>
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Image</div>

    <div class="pb2-field">
      <label class="pb2-field-label">Image</label>
      @if(!empty($get('image_url')))
        <div class="pb2-image-tile">
          <div class="pb2-image-tile-thumb" style="background-image: url('{{ $get('image_url') }}'); background-size: cover; background-position: center;"></div>
          <div class="pb2-image-tile-info">
            <div class="pb2-image-tile-name">{{ basename(parse_url($get('image_url'), PHP_URL_PATH) ?? 'image') }}</div>
            <div class="pb2-image-tile-actions">
              <button type="button" class="pb2-textlink" data-image-replace="image_url">Replace</button>
              <button type="button" class="pb2-textlink pb2-textlink-danger" data-image-remove="image_url">Remove</button>
            </div>
          </div>
        </div>
      @else
        <button type="button" class="pb2-image-empty" data-image-upload="image_url">
          <span class="pb2-image-empty-icon">⬆</span>
          <span>Upload an image</span>
          <span class="pb2-field-hint">JPG, PNG, WebP, or SVG · 5 MB max</span>
        </button>
      @endif
      <input type="hidden" data-field="image_url" value="{{ $get('image_url') }}">
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Image caption <span class="pb2-field-hint">alt text</span></label>
      <input type="text" class="pb2-input" data-field="image_alt" value="{{ $get('image_alt') }}" placeholder="Brief description of the image">
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">
      Buttons
      <span class="pb2-group-meta" id="pb2-ti-btn-count">{{ count($buttons) }} / 3</span>
    </div>

    <div class="pb2-btnlist" id="pb2-ti-btnlist">
      @foreach($buttons as $i => $b)
        <div class="pb2-btnlist-item" data-btn-idx="{{ $i }}">
          <span class="pb2-btnlist-handle">⋮⋮</span>
          <div class="pb2-btnlist-fields">
            <input type="text" class="pb2-input pb2-input-sm" data-btn-field="label" value="{{ $b['label'] ?? '' }}" placeholder="Label">
            <input type="text" class="pb2-input pb2-input-sm" data-btn-field="url" value="{{ $b['url'] ?? '' }}" placeholder="URL">
            <select class="pb2-input pb2-input-sm" data-btn-field="style">
              @foreach(['primary'=>'Primary','outline'=>'Outline','ghost'=>'Ghost','link'=>'Link'] as $val => $name)
                <option value="{{ $val }}" {{ ($b['style'] ?? 'primary') === $val ? 'selected' : '' }}>{{ $name }}</option>
              @endforeach
            </select>
          </div>
          <button type="button" class="pb2-btnlist-remove" title="Remove">×</button>
        </div>
      @endforeach
    </div>

    <button type="button" class="pb2-addrow" id="pb2-ti-addbtn">+ Add button</button>

    <input type="hidden" data-field="buttons" id="pb2-ti-buttons-json" value="{{ json_encode($buttons) }}">
    {{-- Legacy compat --}}
    <input type="hidden" data-field="cta_label" value="{{ $get('cta_label') }}">
    <input type="hidden" data-field="cta_url" value="{{ $get('cta_url') }}">
  </div>

</div>

{{--=================== LAYOUT ===================--}}
<div class="pb2-tab-panel" data-tab="content">

  <div class="pb2-group">
    <div class="pb2-group-title">Image placement</div>

    <div class="pb2-field">
      <label class="pb2-field-label">Image side</label>
      <div class="pb2-seg" data-field-seg="image_position">
        @foreach(['left'=>'Left','right'=>'Right'] as $val => $name)
          <button type="button" class="pb2-seg-btn {{ $get('image_position', 'right') === $val ? 'active' : '' }}" data-seg-value="{{ $val }}">{{ $name }}</button>
        @endforeach
      </div>
      <input type="hidden" data-field="image_position" value="{{ $get('image_position', 'right') }}">
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Image-to-text ratio</label>
      <select class="pb2-input" data-field="image_ratio">
        @foreach(['equal'=>'50 / 50','wide_text'=>'60 text / 40 image','wide_image'=>'40 text / 60 image'] as $v => $n)
          <option value="{{ $v }}" {{ $get('image_ratio', 'equal') === $v ? 'selected' : '' }}>{{ $n }}</option>
        @endforeach
      </select>
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Image aspect ratio</label>
      <select class="pb2-input" data-field="image_aspect">
        @foreach(['4/3'=>'4:3 (landscape)','1/1'=>'1:1 (square)','3/4'=>'3:4 (portrait)','16/9'=>'16:9 (wide)','auto'=>'Auto (original)'] as $v => $n)
          <option value="{{ $v }}" {{ $get('image_aspect', '4/3') === $v ? 'selected' : '' }}>{{ $n }}</option>
        @endforeach
      </select>
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Image border radius</label>
      <select class="pb2-input" data-field="image_radius">
        @foreach(['none'=>'None','small'=>'Small (4px)','medium'=>'Medium (8px)','large'=>'Large (16px)','full'=>'Pill'] as $v => $n)
          <option value="{{ $v }}" {{ $get('image_radius', 'medium') === $v ? 'selected' : '' }}>{{ $n }}</option>
        @endforeach
      </select>
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Spacing</div>

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

    <div class="pb2-field">
      <label class="pb2-field-label">Content alignment</label>
      <div class="pb2-seg" data-field-seg="text_align">
        @foreach(['left'=>'Left','center'=>'Center','right'=>'Right'] as $val => $name)
          <button type="button" class="pb2-seg-btn {{ $get('text_align', 'left') === $val ? 'active' : '' }}" data-seg-value="{{ $val }}">{{ $name }}</button>
        @endforeach
      </div>
      <input type="hidden" data-field="text_align" value="{{ $get('text_align', 'left') }}">
    </div>
  </div>

</div>

{{--=================== STYLE ===================--}}
<div class="pb2-tab-panel" data-tab="style" hidden>

  <div class="pb2-group">
    <div class="pb2-group-title">Background</div>

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
          <input type="color" data-field="bg_color" value="{{ $get('bg_color', '#ffffff') }}" class="pb2-color-swatch">
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
            <input type="color" data-field="bg_gradient_from" value="{{ $get('bg_gradient_from', '#ffffff') }}" class="pb2-color-swatch">
            <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="bg_gradient_from_text" value="{{ $get('bg_gradient_from') }}">
          </div>
        </div>
        <div class="pb2-field">
          <label class="pb2-field-label">To</label>
          <div class="pb2-color-row">
            <input type="color" data-field="bg_gradient_to" value="{{ $get('bg_gradient_to', '#fafafa') }}" class="pb2-color-swatch">
            <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="bg_gradient_to_text" value="{{ $get('bg_gradient_to') }}">
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Text color</div>

    <div class="pb2-field-row">
      <div class="pb2-field">
        <label class="pb2-field-label">Heading</label>
        <div class="pb2-color-row">
          <input type="color" data-field="text_color" value="{{ $get('text_color') ?: '#0a0a0a' }}" class="pb2-color-swatch">
          <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="text_color_text" value="{{ $get('text_color') }}" placeholder="auto">
        </div>
      </div>
      <div class="pb2-field">
        <label class="pb2-field-label">Body</label>
        <div class="pb2-color-row">
          <input type="color" data-field="text_color_body" value="{{ $get('text_color_body') ?: '#555555' }}" class="pb2-color-swatch">
          <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="text_color_body_text" value="{{ $get('text_color_body') }}" placeholder="auto">
        </div>
      </div>
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Accent <span class="pb2-field-hint">highlight phrase + primary button</span></label>
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
      <input type="text" class="pb2-input pb2-input-mono" data-field="anchor_id" value="{{ $get('anchor_id') }}" placeholder="e.g. about-section">
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Custom classes</label>
      <input type="text" class="pb2-input pb2-input-mono" data-field="custom_classes" value="{{ $get('custom_classes') }}" placeholder="space-separated">
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Visibility</div>

    <label class="pb2-checkbox-row">
      <input type="checkbox" data-field="hide_on_mobile" value="1" {{ ($get('hide_on_mobile') ? 'checked' : '') }}>
      <span>Hide on mobile</span>
    </label>
    <label class="pb2-checkbox-row">
      <input type="checkbox" data-field="hide_on_desktop" value="1" {{ ($get('hide_on_desktop') ? 'checked' : '') }}>
      <span>Hide on desktop</span>
    </label>
  </div>

</div>
