{{--
  MARKER-RENTAL-SECTIONS — rental_categories editor.
  Every fleet category is a row: checkbox = include, drag handle = order.
  Saved as an ordered JSON array of category ids in content.category_ids.
--}}
@php
  $c   = $c ?? ($section->content ?? []);
  $get = fn($k, $d = '') => $c[$k] ?? $d;
  $rentalCategories = $rentalCategories ?? collect();
  $pickedRaw = $get('category_ids', '[]');
  $picked = is_array($pickedRaw) ? $pickedRaw : (json_decode((string) $pickedRaw, true) ?: []);
  $imgRaw = $get('category_images', '{}');
  $imgMap = is_array($imgRaw) ? $imgRaw : (json_decode((string) $imgRaw, true) ?: []);
  $rentalModelPhotos = $rentalModelPhotos ?? collect();
  // Render picked rows first, in saved order, then the rest.
  $ordered = collect($picked)->map(fn ($id) => $rentalCategories->firstWhere('id', $id))->filter()
      ->concat($rentalCategories->filter(fn ($cat) => !in_array((string) $cat->id, array_map('strval', $picked), true)));
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
      <input type="text" class="pb2-input" data-field="heading" value="{{ $get('heading', 'Rent by category') }}">
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">Body</label>
      <textarea class="pb2-textarea" data-field="body" rows="3" placeholder="Optional intro line">{{ $get('body') }}</textarea>
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Categories</div>
    <div class="pb2-field-hint" style="margin-bottom:8px">Check the categories to show. Drag ⋮⋮ to set the order.</div>
    <div class="pb2-navlist" id="pb2-rcat-list">
      @forelse($ordered as $cat)
        <div class="pb2-navlist-item pb2-rcat" data-cat-id="{{ $cat->id }}">
          <span class="pb2-navlist-handle" title="Drag to reorder">⋮⋮</span>
          <label style="display:flex;gap:8px;align-items:center;cursor:pointer;flex:1;font-size:13px">
            <input type="checkbox" data-rcat-check {{ in_array((string) $cat->id, array_map('strval', $picked), true) ? 'checked' : '' }}>
            <span>{{ $cat->name }}</span>
            <span style="margin-left:auto;font-size:11px;opacity:.5">{{ $cat->live_unit_count ?? 0 }} rentable</span>
          </label>
          {{-- MARKER-RENTAL-SECTIONS — tile photo, picked from the fleet --}}
          @php $catModels = $rentalModelPhotos[$cat->id] ?? collect(); @endphp
          @if($catModels->isNotEmpty())
            <select class="pb2-input pb2-input-sm" data-rcat-photo style="max-width:150px" title="Tile photo">
              <option value="">Auto photo</option>
              @foreach($catModels as $pm)
                <option value="{{ $pm->id }}" {{ ($imgMap[(string) $cat->id] ?? '') === (string) $pm->id ? 'selected' : '' }}>{{ $pm->name }}</option>
              @endforeach
            </select>
          @endif
        </div>
      @empty
        <div class="pb2-field-hint">No fleet categories yet — add them under Rentals → Fleet.</div>
      @endforelse
    </div>
    <input type="hidden" data-field="category_ids" id="pb2-rcat-json" value="{{ json_encode(array_values($picked)) }}">
    <input type="hidden" data-field="category_images" id="pb2-rcat-img-json" value="{{ json_encode((object) $imgMap) }}">
    <div class="pb2-field" style="margin-top:10px">
      <label class="pb2-field-label" style="display:flex;gap:8px;align-items:center;cursor:pointer">
        <input type="checkbox" data-field="show_counts" value="1" {{ $get('show_counts', '1') === '1' ? 'checked' : '' }}> Show unit counts on tiles
      </label>
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
    <div class="pb2-group-title">Tiles</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Columns</label>
      <div class="pb2-seg" data-field-seg="columns">
        @foreach(['auto'=>'Auto','2'=>'2','3'=>'3','4'=>'4'] as $val => $name)
          <button type="button" class="pb2-seg-btn {{ $get('columns', 'auto') === $val ? 'active' : '' }}" data-seg-value="{{ $val }}">{{ $name }}</button>
        @endforeach
      </div>
      <input type="hidden" data-field="columns" value="{{ $get('columns', 'auto') }}">
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">Tile style</label>
      <div class="pb2-seg" data-field-seg="tile_style">
        @foreach(['photo'=>'Photo header','compact'=>'Compact'] as $val => $name)
          <button type="button" class="pb2-seg-btn {{ $get('tile_style', 'photo') === $val ? 'active' : '' }}" data-seg-value="{{ $val }}">{{ $name }}</button>
        @endforeach
      </div>
      <input type="hidden" data-field="tile_style" value="{{ $get('tile_style', 'photo') }}">
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
