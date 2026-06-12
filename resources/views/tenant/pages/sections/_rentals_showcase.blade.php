{{--
  MARKER-PATCH-239 — rentals_showcase editor.
  Content tab: copy + category filter + limits + CTA.
  Style tab: background color (bg_color stays in content[] per G23).
  The public partial pulls live models, so there's nothing to curate here —
  the fleet is the source of truth.
--}}
@php
  $c   = $c ?? ($section->content ?? []);
  $get = fn($k, $d = '') => $c[$k] ?? $d;
  $rentalCategories = $rentalCategories ?? collect();
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
      <input type="text" class="pb2-input" data-field="heading" value="{{ $get('heading') }}" placeholder="Rent the good stuff">
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">Body</label>
      <textarea class="pb2-textarea" data-field="body" rows="3" placeholder="Optional intro line">{{ $get('body') }}</textarea>
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Fleet</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Category</label>
      <select class="pb2-input" data-field="category_id">
        <option value="">All categories</option>
        @foreach($rentalCategories as $cat)
          <option value="{{ $cat->id }}" {{ $get('category_id') === (string) $cat->id ? 'selected' : '' }}>{{ $cat->name }}</option>
        @endforeach
      </select>
      <div class="pb2-field-hint">Models come straight from your Fleet — edit rates there.</div>
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">Max models shown</label>
      <input type="number" class="pb2-input" data-field="max_models" value="{{ $get('max_models', 6) }}" min="1" max="24">
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label" style="display:flex;gap:8px;align-items:center;cursor:pointer">
        <input type="checkbox" data-field="show_rates" value="1" {{ $get('show_rates', '1') === '1' ? 'checked' : '' }}> Show rates
      </label>
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label" style="display:flex;gap:8px;align-items:center;cursor:pointer">
        <input type="checkbox" data-field="show_deposit" value="1" {{ $get('show_deposit') === '1' ? 'checked' : '' }}> Show deposit amounts
      </label>
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Call to action</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Button label</label>
      <input type="text" class="pb2-input" data-field="cta_label" value="{{ $get('cta_label', 'Check availability') }}">
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">Button URL</label>
      <input type="text" class="pb2-input" data-field="cta_url" value="{{ $get('cta_url', '/rentals') }}" placeholder="/rentals">
      <div class="pb2-field-hint">/rentals is the live availability browse page.</div>
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
