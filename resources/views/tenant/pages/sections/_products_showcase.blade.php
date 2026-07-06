{{--
  MARKER-PATCH-576 — products_showcase editor.
  Content: copy + category filter + limits + display toggles + CTA.
  Style: background color. The public partial pulls live show_online items,
  so nothing is curated here — the storefront is the source of truth.
--}}
@php
  $c   = $c ?? ($section->content ?? []);
  $get = fn($k, $d = '') => $c[$k] ?? $d;
  $productCategories = $productCategories ?? collect();
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
      <input type="text" class="pb2-input" data-field="heading" value="{{ $get('heading', 'From the shop') }}">
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">Body</label>
      <textarea class="pb2-input" data-field="body" rows="2" placeholder="Optional intro line">{{ $get('body') }}</textarea>
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Products</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Category</label>
      <select class="pb2-input" data-field="category_id">
        <option value="">All published items</option>
        @foreach($productCategories as $cat)
          <option value="{{ $cat->id }}" @selected($get('category_id') === $cat->id)>{{ $cat->name }}</option>
        @endforeach
      </select>
      <div class="pb2-field-hint">Only categories with items published to your online store appear here.</div>
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">How many to show</label>
      <input type="number" class="pb2-input" data-field="max_items" min="1" max="24" value="{{ $get('max_items', 8) }}">
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label" style="display:flex;gap:8px;align-items:center;cursor:pointer">
        <input type="checkbox" data-field="in_stock_only" value="1" {{ $get('in_stock_only', '0') === '1' ? 'checked' : '' }}> In-stock items only
      </label>
      <label class="pb2-field-label" style="display:flex;gap:8px;align-items:center;cursor:pointer">
        <input type="checkbox" data-field="show_prices" value="1" {{ $get('show_prices', '1') === '1' ? 'checked' : '' }}> Show prices
      </label>
      <label class="pb2-field-label" style="display:flex;gap:8px;align-items:center;cursor:pointer">
        <input type="checkbox" data-field="show_search" value="1" {{ $get('show_search', '0') === '1' ? 'checked' : '' }}> Show a shop search bar
      </label>
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">Search placeholder</label>
      <input type="text" class="pb2-input" data-field="search_placeholder" value="{{ $get('search_placeholder') }}" placeholder="Search the shop…">
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Button</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Label</label>
      <input type="text" class="pb2-input" data-field="cta_label" value="{{ $get('cta_label', 'Browse the shop') }}" placeholder="Leave empty to hide">
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">URL</label>
      <input type="text" class="pb2-input" data-field="cta_url" value="{{ $get('cta_url', '/shop') }}">
    </div>
  </div>

</div>

{{--=================== STYLE ===================--}}
<div class="pb2-tab-panel" data-tab="style">
  <div class="pb2-group">
    <div class="pb2-group-title">Background</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Background color</label>
      <input type="text" class="pb2-input" data-field="bg_color" value="{{ $get('bg_color') }}" placeholder="Inherits page background">
    </div>
  </div>
</div>
