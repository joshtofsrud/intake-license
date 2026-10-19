{{--
  MARKER-SHOP-PORT4: comparison table editor. Replaces the raw JSON box: a list of
  columns (the first one is you and is highlighted) and a list of rows, each row a
  feature with one value per column: Yes, No, Add-on, or your own short text.
  Wired by initCtEditor() in pages/edit.blade.php; saved as competitors[] and
  rows[{feature, values[]}], the same shape as before.
--}}
@php
  $c   = $c ?? ($section->content ?? []);
  $get = fn($k, $d = '') => $c[$k] ?? $d;
  $ctCols = $c['competitors'] ?? [];
  if (is_string($ctCols)) { $d = json_decode($ctCols, true); $ctCols = is_array($d) ? $d : []; }
  $ctCols = array_values(array_map(fn ($x) => (string) $x, is_array($ctCols) ? $ctCols : []));
  // same fallback the renderers use when no column names were saved
  if (! array_filter($ctCols, fn ($x) => trim($x) !== '')) $ctCols = ($isMarketing ?? false) ? ['Intake', 'Other'] : ['Us', 'Others'];
  if (count($ctCols) < 2) $ctCols = array_pad($ctCols, 2, '');
  $ctRows = $c['rows'] ?? [];
  if (is_string($ctRows)) { $d = json_decode($ctRows, true); $ctRows = is_array($d) ? $d : []; }
  $ctRows = array_values(array_filter(is_array($ctRows) ? $ctRows : [], 'is_array'));
@endphp
<style>
  .pb2-ct-col { display: flex; gap: 6px; align-items: center; margin-bottom: 6px; }
  .pb2-ct-col .pb2-input { flex: 1; }
  .pb2-ct-col em { font-style: normal; font-size: 10.5px; opacity: .55; width: 28px; flex: none; }
  .pb2-ct-val { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; align-items: center; margin-top: 6px; }
  .pb2-ct-val span { font-size: 12px; opacity: .7; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .pb2-ct-val > div { display: grid; gap: 4px; min-width: 0; }
  .pb2-ctrow .pb2-input { box-sizing: border-box; width: 100%; min-width: 0; }
  .pb2-ct-txt[hidden] { display: none; }
</style>

<input type="checkbox" data-field="is_visible" value="1" {{ $section->is_visible ? 'checked' : '' }} style="display:none">

<div class="pb2-tab-panel" data-tab="content">
  <div class="pb2-group">
    <div class="pb2-group-title">Heading</div>
    <div class="pb2-field"><label class="pb2-field-label">Eyebrow</label><input type="text" class="pb2-input" data-field="eyebrow" value="{{ $get('eyebrow') }}" placeholder="Optional kicker"></div>
    <div class="pb2-field"><label class="pb2-field-label">Heading</label><input type="text" class="pb2-input" data-field="heading" value="{{ $get('heading') }}"></div>
    <div class="pb2-field"><label class="pb2-field-label">Highlight phrase</label><input type="text" class="pb2-input" data-field="accent_words" value="{{ $get('accent_words') }}" placeholder="Optional"></div>
    <div class="pb2-field"><label class="pb2-field-label">Subheading</label><textarea class="pb2-input pb2-textarea" data-field="subheading" rows="2" placeholder="Optional">{{ $get('subheading') }}</textarea></div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Columns <span class="pb2-group-meta" id="pb2-ct-colcount">{{ count($ctCols) }} / 4</span></div>
    <div class="pb2-field-hint" style="display:block;text-align:left;margin-bottom:8px">The first column is you; it's highlighted in the table.</div>
    <div id="pb2-ct-cols">
      @foreach($ctCols as $ci => $name)
        <div class="pb2-ct-col"><em>{{ $ci === 0 ? 'You' : '' }}</em><input type="text" class="pb2-input pb2-input-sm" data-ct-col value="{{ $name }}" placeholder="{{ $ci === 0 ? 'Your business' : 'Column name' }}"><button type="button" class="pb2-navlist-remove" data-ct-colrm title="Remove column" aria-label="Remove column" @if($ci === 0) style="visibility:hidden" @endif>×</button></div>
      @endforeach
    </div>
    <button type="button" class="pb2-addrow" id="pb2-ct-coladd">+ Add column</button>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Rows <span class="pb2-group-meta" id="pb2-ct-rowcount">{{ count($ctRows) }} / 20</span></div>
    <div id="pb2-ct-rows" data-rows="{{ json_encode($ctRows) }}"></div>
    <button type="button" class="pb2-addrow" id="pb2-ct-rowadd">+ Add row</button>
    <input type="hidden" data-field="competitors" id="pb2-ct-cols-json" value="{{ json_encode($ctCols) }}">
    <input type="hidden" data-field="rows" id="pb2-ct-rows-json" value="{{ json_encode($ctRows) }}">
  </div>
</div>

<div class="pb2-tab-panel" data-tab="style" hidden>
  {{-- the standard Section background controls --}}
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
            <input type="color" data-field="bg_gradient_to" value="{{ $get('bg_gradient_to', '#f4f4f2') }}" class="pb2-color-swatch">
            <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="bg_gradient_to_text" value="{{ $get('bg_gradient_to') }}">
          </div>
        </div>
      </div>
    </div>
  </div>
  <div class="pb2-group">
    <div class="pb2-group-title">Accent</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Accent color</label>
      <div class="pb2-color-row">
        <input type="color" data-field="accent_color" value="{{ $get('accent_color') ?: '#BEF264' }}" class="pb2-color-swatch">
        <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="accent_color_text" value="{{ $get('accent_color') }}" placeholder="theme default">
      </div>
    </div>
  </div>
  <div class="pb2-group">
    <div class="pb2-group-title">Spacing</div>
    <div class="pb2-field">
      <div class="pb2-seg" data-field-seg="padding_override">
        @foreach(['compact'=>'Compact','normal'=>'Normal','spacious'=>'Spacious'] as $v => $n)
          <button type="button" class="pb2-seg-btn {{ $get('padding_override', 'normal') === $v ? 'active' : '' }}" data-seg-value="{{ $v }}">{{ $n }}</button>
        @endforeach
      </div>
      <input type="hidden" data-field="padding_override" value="{{ $get('padding_override', 'normal') }}">
    </div>
  </div>
</div>

<div class="pb2-tab-panel" data-tab="advanced" hidden>
  <div class="pb2-group">
    <div class="pb2-group-title">Anchor &amp; classes</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Anchor ID</label>
      <input type="text" class="pb2-input pb2-input-mono" data-field="anchor_id" value="{{ $get('anchor_id') }}" placeholder="compare">
    </div>
    <div class="pb2-field">
      <label class="pb2-field-label">Custom classes</label>
      <input type="text" class="pb2-input pb2-input-mono" data-field="custom_classes" value="{{ $get('custom_classes') }}" placeholder="space-separated">
    </div>
  </div>
  <div class="pb2-group">
    <div class="pb2-group-title">Visibility</div>
    <label class="pb2-checkbox-row"><input type="checkbox" data-field="hide_on_mobile" value="1" {{ $get('hide_on_mobile') ? 'checked' : '' }}><span>Hide on mobile</span></label>
    <label class="pb2-checkbox-row"><input type="checkbox" data-field="hide_on_desktop" value="1" {{ $get('hide_on_desktop') ? 'checked' : '' }}><span>Hide on desktop</span></label>
  </div>
</div>
