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
      <textarea class="pb2-textarea" data-field="body" rows="3" placeholder="Why rent this one?">{{ $get('body') }}</textarea>
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
    <div class="pb2-group-title">Background</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Background color</label>
      <div class="pb2-field-row">
        <input type="color" data-field="bg_color" value="{{ $get('bg_color') ?: '#ffffff' }}" {{ $get('bg_color') ? '' : 'data-blank=1' }}>
        <input type="text" class="pb2-input" data-field="bg_color_text" value="{{ $get('bg_color') }}" placeholder="default">
      </div>
    </div>
  </div>
</div>
