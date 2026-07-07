{{-- MARKER-PATCH-306 — custom_html editor --}}
@php
  $c   = $c ?? ($section->content ?? []);
  $get = fn($k, $d = '') => $c[$k] ?? $d;
@endphp

<input type="checkbox" data-field="is_visible" value="1" {{ $section->is_visible ? 'checked' : '' }} style="display:none">

{{--=================== CONTENT ===================--}}
<div class="pb2-tab-panel" data-tab="content">


  <div class="pb2-group">
    <div class="pb2-group-title">HTML</div>

    <div class="pb2-field">
      <label class="pb2-field-label">
        Markup
        <span class="pb2-field-hint">rendered as-is on your page</span>
      </label>
      <textarea
        class="pb2-input pb2-textarea pb2-input-mono"
        data-field="html"
        rows="16"
        spellcheck="false"
        placeholder="&lt;div&gt;&#10;  Your HTML here&#10;&lt;/div&gt;"
        style="line-height:1.55;tab-size:2;white-space:pre">{{ $get('html') }}</textarea>
      <div class="pb2-field-hint" style="margin-top:8px;line-height:1.5">
        Styles and scripts inside this block run in your visitors' browsers.
        Only paste markup you trust. This section fills the full content width —
        control layout from inside your own HTML.
      </div>
    </div>
  </div>

</div>

{{--=================== DESIGN ===================--}}
<div class="pb2-tab-panel" data-tab="style" hidden>

  <div class="pb2-group">
    <div class="pb2-group-title">Background</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Section background</label>
      <div class="pb2-color-row">
        <input type="color" data-field="bg_color" value="{{ $get('bg_color') ?: '#0a0a0a' }}" class="pb2-color-swatch">
        <input type="text" class="pb2-input pb2-input-sm pb2-input-mono" data-field="bg_color_text" value="{{ $get('bg_color') }}" placeholder="transparent">
      </div>
      <div class="pb2-field-hint">Sits behind your markup. Leave blank for transparent.</div>
    </div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">Spacing</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Vertical padding</label>
      <select class="pb2-input" data-field="padding_y">
        @foreach(['none'=>'None','compact'=>'Compact','normal'=>'Normal','spacious'=>'Spacious'] as $v => $n)
          <option value="{{ $v }}" {{ $get('padding_y', 'normal') === $v ? 'selected' : '' }}>{{ $n }}</option>
        @endforeach
      </select>
      <div class="pb2-field-hint">Set to None if your HTML manages its own spacing.</div>
    </div>
  </div>

</div>

{{--=================== ADVANCED ===================--}}
<div class="pb2-tab-panel" data-tab="advanced" hidden>

  <div class="pb2-group">
    <div class="pb2-group-title">Anchor & classes</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Anchor ID</label>
      <input type="text" class="pb2-input pb2-input-mono" data-field="anchor_id" value="{{ $get('anchor_id') }}" placeholder="e.g. embed-block">
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

