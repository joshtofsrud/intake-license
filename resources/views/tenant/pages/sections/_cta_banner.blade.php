{{--
  MARKER-PATCH-158-G20 — cta_banner editor (Phase 2)
--}}
@php
  $c   = $c ?? ($section->content ?? []);
  $get = fn($k, $d = '') => $c[$k] ?? $d;

  $buttons = $c['buttons'] ?? [];
  if (is_string($buttons)) { $decoded = json_decode($buttons, true); $buttons = is_array($decoded) ? $decoded : []; }
  if (!is_array($buttons)) $buttons = [];

  // Backward compat
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
      <label class="pb2-field-label">Eyebrow</label>
      <input type="text" class="pb2-input" data-field="eyebrow" value="{{ $get('eyebrow') }}" placeholder="Optional kicker">
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Headline <span class="pb2-field-hint">multi-line OK</span></label>
      <textarea class="pb2-input pb2-textarea" data-field="headline" rows="2" placeholder="Ready to book?">{{ $get('headline') }}</textarea>
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Highlight phrase</label>
      <input type="text" class="pb2-input" data-field="accent_words" value="{{ $get('accent_words') }}" placeholder="Optional accent">
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Subheading</label>
      <textarea class="pb2-input pb2-textarea" data-field="subheading" rows="2" placeholder="Optional supporting text">{{ $get('subheading') }}</textarea>
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Footnote</label>
      <input type="text" class="pb2-input" data-field="note" value="{{ $get('note') }}" placeholder="Small note below buttons">
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">
      Buttons
      <span class="pb2-group-meta" id="pb2-cta-btn-count">{{ count($buttons) }} / 3</span>
    </div>

    <div class="pb2-btnlist" id="pb2-cta-btnlist">
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

    <button type="button" class="pb2-addrow" id="pb2-cta-addbtn">+ Add button</button>

    <input type="hidden" data-field="buttons" id="pb2-cta-buttons-json" value="{{ json_encode($buttons) }}">
    <input type="hidden" data-field="cta_label" value="{{ $get('cta_label') }}">
    <input type="hidden" data-field="cta_url" value="{{ $get('cta_url') }}">
  </div>

</div>

{{--=================== LAYOUT ===================--}}
<div class="pb2-tab-panel" data-tab="content">

  <div class="pb2-group">
    <div class="pb2-group-title">Alignment & spacing</div>

    <div class="pb2-field">
      <label class="pb2-field-label">Content alignment</label>
      <div class="pb2-seg" data-field-seg="text_align">
        @foreach(['left'=>'Left','center'=>'Center','right'=>'Right'] as $val => $name)
          <button type="button" class="pb2-seg-btn {{ $get('text_align', 'center') === $val ? 'active' : '' }}" data-seg-value="{{ $val }}">{{ $name }}</button>
        @endforeach
      </div>
      <input type="hidden" data-field="text_align" value="{{ $get('text_align', 'center') }}">
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Content max-width <span class="pb2-field-hint">px</span></label>
      <input type="number" class="pb2-input" data-field="content_max_width" value="{{ $get('content_max_width', 640) }}" min="320" max="1200" step="20">
    </div>

    <div class="pb2-field-row">
      <div class="pb2-field">
        <label class="pb2-field-label">Padding top</label>
        <select class="pb2-input" data-field="padding_top">
          @foreach(['compact'=>'Compact','normal'=>'Normal','spacious'=>'Spacious'] as $v => $n)
            <option value="{{ $v }}" {{ $get('padding_top', 'normal') === $v ? 'selected' : '' }}>{{ $n }}</option>
          @endforeach
        </select>
      </div>
      <div class="pb2-field">
        <label class="pb2-field-label">Padding bottom</label>
        <select class="pb2-input" data-field="padding_bottom">
          @foreach(['compact'=>'Compact','normal'=>'Normal','spacious'=>'Spacious'] as $v => $n)
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
    <div class="pb2-group-title">Background</div>

    <div class="pb2-field">
      <div class="pb2-seg" data-field-seg="bg_mode">
        @foreach(['color'=>'Color','image'=>'Image','gradient'=>'Gradient'] as $val => $name)
          <button type="button" class="pb2-seg-btn {{ $get('bg_mode', 'color') === $val ? 'active' : '' }}" data-seg-value="{{ $val }}">{{ $name }}</button>
        @endforeach
      </div>
      <input type="hidden" data-field="bg_mode" value="{{ $get('bg_mode', 'color') }}">
    </div>

    <div class="pb2-bg-pane" data-bg-mode="color">
      <div class="pb2-field">
        <label class="pb2-field-label">Background color</label>
        <div class="pb2-color-row">
          <input type="color" data-field="bg_color" value="{{ $get('bg_color', '#0a0a0a') }}" class="pb2-color-swatch">
          <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="bg_color_text" value="{{ $get('bg_color') }}">
        </div>
      </div>
    </div>

    <div class="pb2-bg-pane" data-bg-mode="image">
      <div class="pb2-field">
        <label class="pb2-field-label">Background image</label>
        @if(!empty($get('bg_image_url')))
          <div class="pb2-image-tile">
            <div class="pb2-image-tile-thumb" style="background-image: url('{{ $get('bg_image_url') }}'); background-size: cover; background-position: center;"></div>
            <div class="pb2-image-tile-info">
              <div class="pb2-image-tile-name">{{ basename(parse_url($get('bg_image_url'), PHP_URL_PATH) ?? 'image') }}</div>
              <div class="pb2-image-tile-actions">
                <button type="button" class="pb2-textlink" data-image-replace="bg_image_url">Replace</button>
                <button type="button" class="pb2-textlink pb2-textlink-danger" data-image-remove="bg_image_url">Remove</button>
              </div>
            </div>
          </div>
        @else
          <button type="button" class="pb2-image-empty" data-image-upload="bg_image_url">
            <span class="pb2-image-empty-icon">⬆</span>
            <span>Upload an image</span>
          </button>
        @endif
        <input type="hidden" data-field="bg_image_url" value="{{ $get('bg_image_url') }}">
      </div>

      <div class="pb2-field">
        <div class="pb2-slider-row">
          <label class="pb2-field-label" style="margin:0">Overlay opacity</label>
          <span class="pb2-slider-value" id="pb2-cta-overlay-val">{{ $get('bg_overlay_opacity', 50) }}%</span>
        </div>
        <input type="range" min="0" max="100" value="{{ $get('bg_overlay_opacity', 50) }}" data-field="bg_overlay_opacity" oninput="document.getElementById('pb2-cta-overlay-val').textContent=this.value+'%'">
      </div>

      <div class="pb2-field">
        <label class="pb2-field-label">Overlay color</label>
        <div class="pb2-color-row">
          <input type="color" data-field="bg_overlay_color" value="{{ $get('bg_overlay_color', '#000000') }}" class="pb2-color-swatch">
          <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="bg_overlay_color_text" value="{{ $get('bg_overlay_color', '#000000') }}">
        </div>
      </div>

      {{-- MARKER-PATCH-250 — motion + blur (image backgrounds). --}}
      <div class="pb2-field">
        <label class="pb2-checkbox-row">
          <input type="checkbox" data-field="bg_parallax" value="1" {{ $get('bg_parallax', '0') === '1' ? 'checked' : '' }}>
          <span>Parallax scroll</span>
        </label>
        <div class="pb2-field-hint">Background drifts slower than the page. Reduced-motion visitors see it static.</div>
      </div>
      <div class="pb2-field">
        <div class="pb2-slider-row">
          <label class="pb2-field-label" style="margin:0">Parallax depth</label>
          <span class="pb2-slider-value" id="pb2-pdepth-val">{{ $get('bg_parallax_depth', 35) }}</span>
        </div>
        <input type="range" min="0" max="70" value="{{ $get('bg_parallax_depth', 35) }}" data-field="bg_parallax_depth" oninput="document.getElementById('pb2-pdepth-val').textContent=this.value">
      </div>
      <div class="pb2-field">
        <div class="pb2-slider-row">
          <label class="pb2-field-label" style="margin:0">Background blur</label>
          <span class="pb2-slider-value" id="pb2-blur-val">{{ $get('bg_blur', 0) }}px</span>
        </div>
        <input type="range" min="0" max="14" value="{{ $get('bg_blur', 0) }}" data-field="bg_blur" oninput="document.getElementById('pb2-blur-val').textContent=this.value+'px'">
      </div>
    </div>

    <div class="pb2-bg-pane" data-bg-mode="gradient">
      <div class="pb2-field-row">
        <div class="pb2-field">
          <label class="pb2-field-label">From</label>
          <div class="pb2-color-row">
            <input type="color" data-field="bg_gradient_from" value="{{ $get('bg_gradient_from', '#0a0a0a') }}" class="pb2-color-swatch">
            <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="bg_gradient_from_text" value="{{ $get('bg_gradient_from') }}">
          </div>
        </div>
        <div class="pb2-field">
          <label class="pb2-field-label">To</label>
          <div class="pb2-color-row">
            <input type="color" data-field="bg_gradient_to" value="{{ $get('bg_gradient_to', '#1a1a1a') }}" class="pb2-color-swatch">
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
        <label class="pb2-field-label">Headline</label>
        <div class="pb2-color-row">
          <input type="color" data-field="text_color" value="{{ $get('text_color') ?: '#ffffff' }}" class="pb2-color-swatch">
          <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="text_color_text" value="{{ $get('text_color') }}" placeholder="auto">
        </div>
      </div>
      <div class="pb2-field">
        <label class="pb2-field-label">Body</label>
        <div class="pb2-color-row">
          <input type="color" data-field="text_color_body" value="{{ $get('text_color_body') ?: '#cccccc' }}" class="pb2-color-swatch">
          <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="text_color_body_text" value="{{ $get('text_color_body') }}" placeholder="auto">
        </div>
      </div>
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Accent <span class="pb2-field-hint">highlight + primary button</span></label>
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
      <input type="text" class="pb2-input pb2-input-mono" data-field="anchor_id" value="{{ $get('anchor_id') }}" placeholder="e.g. final-cta">
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
