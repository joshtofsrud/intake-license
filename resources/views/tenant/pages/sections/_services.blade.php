{{--
  MARKER-PATCH-158-G22 — services editor (Phase 2)
  The services section pulls live data from the tenant catalog; this editor
  controls *presentation* of that catalog. Notable: category filter is a
  checkbox list that serializes to category_ids[] in JSON.
--}}
@php
  $c   = $c ?? ($section->content ?? []);
  $get = fn($k, $d = '') => $c[$k] ?? $d;

  // Categories passed from the controller; empty if not (defensive)
  $categories = $categories ?? collect();

  // category_ids: normalize JSON string if needed
  $selectedCategoryIds = $c['category_ids'] ?? [];
  if (is_string($selectedCategoryIds)) {
      $decoded = json_decode($selectedCategoryIds, true);
      $selectedCategoryIds = is_array($decoded) ? $decoded : [];
  }
  if (!is_array($selectedCategoryIds)) $selectedCategoryIds = [];
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
      <input type="text" class="pb2-input" data-field="heading" value="{{ $get('heading', 'Our services') }}">
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
      <label class="pb2-field-label">Empty-state message <span class="pb2-field-hint">shown when no services match</span></label>
      <input type="text" class="pb2-input" data-field="empty_state_text" value="{{ $get('empty_state_text', 'No services available yet.') }}">
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">
      Categories
      <span class="pb2-group-meta" id="pb2-svc-cat-count">
        {{ empty($selectedCategoryIds) ? 'all' : count($selectedCategoryIds) }} selected
      </span>
    </div>

    @if($categories->isEmpty())
      <div class="pb2-field-hint" style="text-align:left">
        No service categories defined yet. Add some in the Services section of your admin to populate this filter.
      </div>
    @else
      <div class="pb2-field-hint" style="text-align:left;margin-bottom:8px;display:block">
        Leave all unchecked to show every category. Check specific ones to limit which appear on this page.
      </div>
      <div id="pb2-svc-catlist">
        @foreach($categories as $cat)
          <label class="pb2-checkbox-row">
            <input type="checkbox"
              data-svc-cat-id="{{ $cat->id }}"
              {{ in_array($cat->id, $selectedCategoryIds, true) ? 'checked' : '' }}>
            <span>{{ $cat->name }}</span>
          </label>
        @endforeach
      </div>
      <input type="hidden" data-field="category_ids" id="pb2-svc-catids-json" value="{{ json_encode($selectedCategoryIds) }}">
    @endif

    <div class="pb2-field" style="margin-top:14px">
      <label class="pb2-field-label">Max items per category <span class="pb2-field-hint">0 = no limit</span></label>
      <input type="number" class="pb2-input" data-field="max_per_category" value="{{ (int)$get('max_per_category', 0) }}" min="0" max="100" step="1">
    </div>
  </div>

</div>

{{--=================== LAYOUT ===================--}}
<div class="pb2-tab-panel" data-tab="content">

  <div class="pb2-group">
    <div class="pb2-group-title">Grid</div>

    <div class="pb2-field">
      <label class="pb2-field-label">Columns</label>
      <div class="pb2-seg" data-field-seg="columns">
        @foreach([1=>'1',2=>'2',3=>'3',4=>'4'] as $val => $name)
          <button type="button" class="pb2-seg-btn {{ (int)$get('columns', 3) === $val ? 'active' : '' }}" data-seg-value="{{ $val }}">{{ $name }}</button>
        @endforeach
      </div>
      <input type="hidden" data-field="columns" value="{{ $get('columns', 3) }}">
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Card style</label>
      <select class="pb2-input" data-field="card_style">
        @foreach(['card'=>'Card (bordered)','list'=>'List (rows)','minimal'=>'Minimal (no border)'] as $v => $n)
          <option value="{{ $v }}" {{ $get('card_style', 'card') === $v ? 'selected' : '' }}>{{ $n }}</option>
        @endforeach
      </select>
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Show / hide</div>

    <label class="pb2-checkbox-row">
      <input type="checkbox" data-field="show_category_headers" value="1" {{ $get('show_category_headers', true) ? 'checked' : '' }}>
      <span>Show category headers</span>
    </label>
    <label class="pb2-checkbox-row">
      <input type="checkbox" data-field="show_prices" value="1" {{ $get('show_prices', true) ? 'checked' : '' }}>
      <span>Show prices</span>
    </label>
    <label class="pb2-checkbox-row">
      <input type="checkbox" data-field="show_descriptions" value="1" {{ $get('show_descriptions', true) ? 'checked' : '' }}>
      <span>Show item descriptions</span>
    </label>
    <label class="pb2-checkbox-row">
      <input type="checkbox" data-field="show_addons" value="1" {{ $get('show_addons', false) ? 'checked' : '' }}>
      <span>Show add-ons under each item</span>
    </label>
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
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Heading alignment</label>
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
    <div class="pb2-group-title">Card appearance</div>

    <div class="pb2-field">
      <label class="pb2-field-label">Card background</label>
      <div class="pb2-color-row">
        <input type="color" data-field="card_bg" value="{{ $get('card_bg') ?: '#ffffff' }}" class="pb2-color-swatch">
        <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="card_bg_text" value="{{ $get('card_bg') }}" placeholder="auto">
      </div>
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Card border color</label>
      <div class="pb2-color-row">
        <input type="color" data-field="card_border" value="{{ $get('card_border') ?: '#e5e5e5' }}" class="pb2-color-swatch">
        <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="card_border_text" value="{{ $get('card_border') }}" placeholder="auto">
      </div>
    </div>

    <div class="pb2-field">
      <label class="pb2-field-label">Hover effect</label>
      <select class="pb2-input" data-field="card_hover_effect">
        @foreach(['none'=>'None','lift'=>'Lift (translate up)','accent-border'=>'Accent border on hover'] as $v => $n)
          <option value="{{ $v }}" {{ $get('card_hover_effect', 'lift') === $v ? 'selected' : '' }}>{{ $n }}</option>
        @endforeach
      </select>
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
      <label class="pb2-field-label">Accent <span class="pb2-field-hint">highlight phrase + price color</span></label>
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
      <input type="text" class="pb2-input pb2-input-mono" data-field="anchor_id" value="{{ $get('anchor_id') }}" placeholder="e.g. services">
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

{{-- MARKER-PATCH-158-G22 — category checkbox serializer is wired up by
     initInspectorControls() in edit.blade.php after the partial is injected.
     Inline <script> tags don't execute via innerHTML, so we centralize. --}}
