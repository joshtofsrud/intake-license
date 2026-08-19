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
        </div>
      @empty
        <div class="pb2-field-hint">No fleet categories yet — add them under Rentals → Fleet.</div>
      @endforelse
    </div>
    <input type="hidden" data-field="category_ids" id="pb2-rcat-json" value="{{ json_encode(array_values($picked)) }}">
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
