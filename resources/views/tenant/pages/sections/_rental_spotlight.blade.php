{{--
  MARKER-RENTAL-SECTIONS — rental_spotlight editor.
  One model, hero treatment. Rates/sizes/counts come live from the fleet;
  the image is section content (the fleet has no photos yet).
--}}
@php
  $c   = $c ?? ($section->content ?? []);
  $get = fn($k, $d = '') => $c[$k] ?? $d;
  $rentalModels = $rentalModels ?? collect();
@endphp

<input type="checkbox" data-field="is_visible" value="1" {{ $section->is_visible ? 'checked' : '' }} style="display:none">

{{--=================== CONTENT ===================--}}
<div class="pb2-tab-panel" data-tab="content">

  <div class="pb2-group">
    <div class="pb2-group-title">Model</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Rental model</label>
      <select class="pb2-input" data-field="model_id">
        <option value="">— pick a model —</option>
        @foreach($rentalModels as $m)
          <option value="{{ $m->id }}" {{ $get('model_id') === (string) $m->id ? 'selected' : '' }}>{{ $m->name }}{{ $m->category ? ' · ' . $m->category->name : '' }}</option>
        @endforeach
      </select>
      <div class="pb2-field-hint">Rates, sizes, and availability pull live from your Fleet.</div>
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label" style="display:flex;gap:8px;align-items:center;cursor:pointer">
        <input type="checkbox" data-field="show_rates" value="1" {{ $get('show_rates', '1') === '1' ? 'checked' : '' }}> Show rates
      </label>
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label" style="display:flex;gap:8px;align-items:center;cursor:pointer">
        <input type="checkbox" data-field="show_deposit" value="1" {{ $get('show_deposit') === '1' ? 'checked' : '' }}> Show deposit
      </label>
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Text</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Eyebrow</label>
      <input type="text" class="pb2-input" data-field="eyebrow" value="{{ $get('eyebrow') }}" placeholder="Optional kicker">
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">Heading</label>
      <input type="text" class="pb2-input" data-field="heading" value="{{ $get('heading') }}" placeholder="Defaults to the model name">
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">Body</label>
      <textarea class="pb2-input pb2-textarea" data-field="body" rows="3" placeholder="Why rent this one?">{{ $get('body') }}</textarea>
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
      <label class="pb2-field-label">Alt text</label>
      <input type="text" class="pb2-input" data-field="image_alt" value="{{ $get('image_alt') }}" placeholder="Brief description of the image">
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Call to action</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Button label</label>
      <input type="text" class="pb2-input" data-field="cta_label" value="{{ $get('cta_label', 'Reserve') }}">
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">Button URL</label>
      <input type="text" class="pb2-input" data-field="cta_url" value="{{ $get('cta_url') }}" placeholder="Defaults to the reserve page for this model">
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
          <input type="color" data-field="bg_color" value="{{ $get('bg_color') ?: '#ffffff' }}" class="pb2-color-swatch">
          <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="bg_color_text" value="{{ $get('bg_color') }}">
        </div>
      </div>
    </div>
    <div class="pb2-bg-pane" data-bg-mode="gradient">
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
            <input type="color" data-field="bg_gradient_from" value="{{ $get('bg_gradient_from') ?: '#ffffff' }}" class="pb2-color-swatch">
            <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="bg_gradient_from_text" value="{{ $get('bg_gradient_from') }}">
          </div>
        </div>
        <div class="pb2-field">
          <label class="pb2-field-label">To</label>
          <div class="pb2-color-row">
            <input type="color" data-field="bg_gradient_to" value="{{ $get('bg_gradient_to') ?: '#f4f4f4' }}" class="pb2-color-swatch">
            <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="bg_gradient_to_text" value="{{ $get('bg_gradient_to') }}">
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Image</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Image side</label>
      <div class="pb2-seg" data-field-seg="image_position">
        @foreach(['left'=>'Left','right'=>'Right'] as $val => $name)
          <button type="button" class="pb2-seg-btn {{ $get('image_position', 'left') === $val ? 'active' : '' }}" data-seg-value="{{ $val }}">{{ $name }}</button>
        @endforeach
      </div>
      <input type="hidden" data-field="image_position" value="{{ $get('image_position', 'left') }}">
    </div>
    <div class="pb2-field">
      <div class="pb2-slider-row">
        <label class="pb2-field-label" style="margin:0">Corner radius</label>
        <span class="pb2-slider-value pb2-rsp-rad">{{ $get('image_radius', 14) }}px</span>
      </div>
      <input type="range" min="0" max="28" value="{{ $get('image_radius', 14) }}" data-field="image_radius" oninput="this.parentNode.querySelector('.pb2-rsp-rad').textContent=this.value+'px'">
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Cards</div>
    <div class="pb2-field-row">
      <div class="pb2-field">
        <label class="pb2-field-label">Card background</label>
        <div class="pb2-color-row">
          <input type="color" data-field="card_bg" value="{{ $get('card_bg') ?: '#ffffff' }}" class="pb2-color-swatch">
          <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="card_bg_text" value="{{ $get('card_bg') }}" placeholder="auto">
        </div>
      </div>
      <div class="pb2-field">
        <label class="pb2-field-label">Card border</label>
        <div class="pb2-color-row">
          <input type="color" data-field="card_border" value="{{ $get('card_border') ?: '#e5e5e5' }}" class="pb2-color-swatch">
          <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="card_border_text" value="{{ $get('card_border') }}" placeholder="auto">
        </div>
      </div>
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Text & accent</div>
    <div class="pb2-field-row">
      <div class="pb2-field">
        <label class="pb2-field-label">Heading</label>
        <div class="pb2-color-row">
          <input type="color" data-field="text_color" value="{{ $get('text_color') ?: '#111111' }}" class="pb2-color-swatch">
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
      <label class="pb2-field-label">Accent <span class="pb2-field-hint">eyebrow & links</span></label>
      <div class="pb2-color-row">
        <input type="color" data-field="accent_color" value="{{ $get('accent_color') ?: '#111111' }}" class="pb2-color-swatch">
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
      <input type="text" class="pb2-input pb2-input-mono" data-field="anchor_id" value="{{ $get('anchor_id') }}" placeholder="e.g. rentals">
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
